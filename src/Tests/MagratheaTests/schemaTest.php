<?php

use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;

// Database that records QueryAll()/QueryRow() instead of running them: the table lookup answers with
// the names in $tables (as stored in the database, case-insensitively), the rest with $all and $row
class SchemaRecordingDatabase extends Database {
	public $log = [];
	public $tables = [];
	public $all = [];
	public $row = [];
	public function Escape($value): string {
		return addslashes((string)$value);
	}
	public function QueryAll(string $sql) {
		$this->log[] = $sql;
		return $this->all;
	}
	public function QueryRow(string $sql): array|object {
		$this->log[] = $sql;
		if(str_starts_with($sql, "SELECT TABLE_NAME AS `name` FROM information_schema.TABLES")) {
			foreach($this->tables as $t) {
				if(str_ends_with(strtolower($sql), "table_name = '".strtolower(addslashes($t))."'")) return ["name" => $t];
			}
			return [];
		}
		return $this->row;
	}
}

class schemaTest extends \PHPUnit\Framework\TestCase {

	private SchemaRecordingDatabase $db;
	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->db = SchemaRecordingDatabase::Instance();
		$this->db->log = [];
		$this->db->tables = ["articles", "Weird`Name"];
		$this->db->all = [];
		$this->db->row = [];
		$this->schema = new Schema($this->db);
	}

	function testTables() {
		TestsHelper::Print("testing Schema::Tables() reads information_schema.TABLES...");
		$this->db->all = [
			["name" => "articles", "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => "12", "collation" => "utf8mb4_general_ci", "comment" => ""],
			["name" => "articles_view", "type" => "VIEW", "engine" => null, "rows" => null, "collation" => null, "comment" => "VIEW"],
		];
		$tables = $this->schema->Tables();
		$this->assertSame(
			"SELECT TABLE_NAME AS `name`, TABLE_TYPE AS `type`, ENGINE AS `engine`, TABLE_ROWS AS `rows`, ".
			"TABLE_COLLATION AS `collation`, TABLE_COMMENT AS `comment` ".
			"FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME",
			$this->db->log[0]
		);
		$this->assertSame(12, $tables[0]["rows"]);
		$this->assertNull($tables[1]["rows"]);
		$this->assertSame("articles_view", $tables[1]["name"]);
	}

	function testTablesWithoutFramework() {
		TestsHelper::Print("testing Schema::Tables(false) leaves out _magrathea_* tables...");
		$this->schema->Tables(false);
		// `_` escaped: it's a LIKE wildcard
		$this->assertStringContainsString(" AND TABLE_NAME NOT LIKE '\\_magrathea\\_%' ORDER BY TABLE_NAME", $this->db->log[0]);
	}

	function testColumns() {
		TestsHelper::Print("testing Schema::Columns() reads information_schema.COLUMNS...");
		$this->db->all = [
			["name" => "id", "type" => "int(10) unsigned", "nullable" => "NO", "default" => null, "extra" => "auto_increment", "key" => "PRI"],
			["name" => "title", "type" => "varchar(255)", "nullable" => "YES", "default" => null, "extra" => "", "key" => ""],
		];
		$columns = $this->schema->Columns("articles");
		$this->assertCount(2, $this->db->log);
		$this->assertSame(
			"SELECT COLUMN_NAME AS `name`, COLUMN_TYPE AS `type`, IS_NULLABLE AS `nullable`, ".
			"COLUMN_DEFAULT AS `default`, EXTRA AS `extra`, COLUMN_KEY AS `key` ".
			"FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' ".
			"ORDER BY ORDINAL_POSITION",
			$this->db->log[1]
		);
		$this->assertFalse($columns[0]["nullable"]);
		$this->assertTrue($columns[1]["nullable"]);
		$this->assertSame("int(10) unsigned", $columns[0]["type"]);
	}

	function testIndexesAreGroupedPerIndex() {
		TestsHelper::Print("testing Schema::Indexes() groups information_schema.STATISTICS per index...");
		$this->db->all = [
			["name" => "PRIMARY", "non_unique" => "0", "type" => "BTREE", "column" => "id"],
			["name" => "slug_lang", "non_unique" => "0", "type" => "BTREE", "column" => "slug"],
			["name" => "slug_lang", "non_unique" => "0", "type" => "BTREE", "column" => "lang"],
			["name" => "title_ft", "non_unique" => "1", "type" => "FULLTEXT", "column" => "title"],
		];
		$indexes = $this->schema->Indexes("articles");
		$this->assertSame(
			"SELECT INDEX_NAME AS `name`, NON_UNIQUE AS `non_unique`, INDEX_TYPE AS `type`, COLUMN_NAME AS `column` ".
			"FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' ".
			"ORDER BY INDEX_NAME = 'PRIMARY' DESC, INDEX_NAME, SEQ_IN_INDEX",
			$this->db->log[1]
		);
		$this->assertSame([
			["name" => "PRIMARY", "unique" => true, "type" => "BTREE", "columns" => ["id"]],
			["name" => "slug_lang", "unique" => true, "type" => "BTREE", "columns" => ["slug", "lang"]],
			["name" => "title_ft", "unique" => false, "type" => "FULLTEXT", "columns" => ["title"]],
		], $indexes);
	}

	function testCreateStatement() {
		TestsHelper::Print("testing Schema::CreateStatement() runs SHOW CREATE TABLE with the quoted name...");
		$this->db->row = ["table" => "Weird`Name", "create table" => "CREATE TABLE `Weird``Name` (…)"];
		$this->assertSame("CREATE TABLE `Weird``Name` (…)", $this->schema->CreateStatement("Weird`Name"));
		$this->assertSame("SHOW CREATE TABLE `Weird``Name`", $this->db->log[1]);
	}

	function testCreateStatementOfAView() {
		TestsHelper::Print("testing Schema::CreateStatement() of a view...");
		$this->db->row = ["view" => "articles", "create view" => "CREATE VIEW `articles` AS select 1", "character_set_client" => "utf8mb4"];
		$this->assertSame("CREATE VIEW `articles` AS select 1", $this->schema->CreateStatement("articles"));
	}

	function testTheDatabasesNameIsUsed() {
		TestsHelper::Print("testing Schema uses the table name as the database stores it...");
		$this->schema->Columns("ARTICLES");
		$this->assertStringContainsString("TABLE_NAME = 'articles'", $this->db->log[1]);
	}

	function testTableNamesAreEscaped() {
		TestsHelper::Print("testing Schema escapes table names in information_schema queries...");
		$this->assertFalse($this->schema->HasTable("x' OR '1'='1"));
		$this->assertStringContainsString("TABLE_NAME = 'x\\' OR \\'1\\'=\\'1'", $this->db->log[0]);
		$this->assertTrue($this->schema->HasTable("articles"));
	}

	function testUnknownTableThrows() {
		TestsHelper::Print("testing Schema rejects an unknown table before reading it...");
		foreach(["Columns", "Indexes", "CreateStatement"] as $method) {
			$this->db->log = [];
			try {
				$this->schema->$method("missing");
				$this->fail($method." accepted an unknown table");
			} catch(MagratheaDBException $ex) {
				$this->assertSame("Unknown table [missing]", $ex->getMessage());
			}
			$this->assertCount(1, $this->db->log, $method." ran a query after the lookup");
		}
	}

	function testDefaultsToTheDatabaseInstance() {
		TestsHelper::Print("testing Schema defaults to Database::Instance()...");
		$previous = Database::Instance();
		Database::MockClass($this->db);
		try {
			(new Schema())->Tables();
			$this->assertCount(1, $this->db->log);
		} finally {
			Database::MockClass($previous);
		}
	}

	function testMagratheaType() {
		TestsHelper::Print("testing Schema::MagratheaType() maps SQL column types...");
		$expected = [
			"tinyint(1)" => "boolean",
			"TINYINT(1) UNSIGNED" => "boolean",
			"bit(1)" => "boolean",
			"tinyint(4)" => "int",
			"tinyint" => "int",
			"smallint(6)" => "int",
			"mediumint" => "int",
			"int(11)" => "int",
			"int(10) unsigned" => "int",
			"INT" => "int",
			"bigint(20) unsigned zerofill" => "int",
			"year(4)" => "int",
			"decimal(10,2)" => "decimal",
			"numeric(5,0)" => "decimal",
			"float" => "float",
			"double" => "float",
			"double precision" => "float",
			"real" => "float",
			"char(36)" => "uuid",
			"char(2)" => "string",
			"char" => "string",
			"varchar(255)" => "string",
			"enum('a','b')" => "string",
			"set('x','y')" => "string",
			"time" => "string",
			"time(3)" => "string",
			"tinytext" => "text",
			"text" => "text",
			"mediumtext" => "text",
			"longtext" => "text",
			"json" => "text",
			"date" => "date",
			"datetime" => "datetime",
			"datetime(6)" => "datetime",
			"timestamp" => "datetime",
			" Timestamp " => "datetime",
		];
		foreach($expected as $columnType => $type) {
			$this->assertSame($type, Schema::MagratheaType($columnType), $columnType);
		}
	}

	function testMagratheaTypeWithoutSuggestion() {
		TestsHelper::Print("testing Schema::MagratheaType() gives null for blobs, binary and spatial types...");
		foreach(["blob", "longblob", "binary(16)", "varbinary(255)", "bit(8)", "geometry", "point", "", "(11)"] as $columnType) {
			$this->assertNull(Schema::MagratheaType($columnType), $columnType);
		}
	}

}
