<?php

use Magrathea\Admin\DatabaseExplorer;
use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;
use Magrathea\TimezoneCheck;

// Schema with fixed rows: $tables is [name => ["type", "engine", "rows", "columns", "indexes", "create"]]
class ExplorerFakeSchema extends Schema {
	public $tables = [];
	public function __construct() {}
	public function Tables(bool $withFramework = true): array {
		$rows = [];
		foreach($this->tables as $name => $t) {
			// array_key_exists: a null engine or row count is a value (views, unknown counts)
			$rows[] = ["name" => $name, "type" => $t["type"] ?? "BASE TABLE",
				"engine" => array_key_exists("engine", $t) ? $t["engine"] : "InnoDB",
				"rows" => array_key_exists("rows", $t) ? $t["rows"] : 0, "collation" => "", "comment" => ""];
		}
		return $rows;
	}
	public function HasTable(string $table): bool {
		return isset($this->tables[$table]);
	}
	private function Get(string $table): array {
		if(!isset($this->tables[$table])) throw new MagratheaDBException("Unknown table [".$table."]");
		return $this->tables[$table];
	}
	public function Columns(string $table): array {
		return $this->Get($table)["columns"] ?? [];
	}
	public function Indexes(string $table): array {
		return $this->Get($table)["indexes"] ?? [];
	}
	public function CreateStatement(string $table): string {
		return $this->Get($table)["create"] ?? "";
	}
}

// SchemaCheck with fixed results: $models (FindModels() rows), $modelFindings [class => findings], $timestampFindings [table => findings]
class ExplorerFakeCheck extends SchemaCheck {
	public $models = [];
	public $loadFindings = [];
	public $modelFindings = [];
	public $timestampFindings = [];
	public $findModelsCalls = 0;
	public $checked = [];
	public function __construct() {}
	public function FindModels(): array {
		$this->findModelsCalls++;
		return ["models" => $this->models, "findings" => $this->loadFindings];
	}
	public function CheckModel(string $class): array {
		$this->checked[] = "model:".$class;
		return $this->modelFindings[$class] ?? [];
	}
	public function CheckTimestamps(string $table): array {
		$this->checked[] = "timestamps:".$table;
		return $this->timestampFindings[$table] ?? [];
	}
}

// Database that records QueryAll() and answers $rows, or throws $error
class ExplorerRecordingDatabase extends Database {
	public $log = [];
	public $rows = [];
	public $error = null;
	public function QueryAll(string $sql) {
		$this->log[] = $sql;
		if($this->error) throw new MagratheaDBException($this->error);
		return $this->rows;
	}
}

class adminDatabaseExplorerTest extends \PHPUnit\Framework\TestCase {

	private ExplorerFakeSchema $schema;
	private ExplorerFakeCheck $check;
	private ExplorerRecordingDatabase $db;
	private DatabaseExplorer $explorer;

	protected function setUp(): void {
		parent::setUp();
		$this->schema = new ExplorerFakeSchema();
		$this->check = new ExplorerFakeCheck();
		$this->db = ExplorerRecordingDatabase::Instance();
		$this->db->log = [];
		$this->db->rows = [];
		$this->db->error = null;
		$this->explorer = new DatabaseExplorer($this->db, $this->schema, $this->check);
	}

	private static function Model(string $class, string $table): array {
		return ["file" => "/app/".$class.".php", "class" => $class, "table" => $table, "pk" => "id", "values" => ["id" => "int"]];
	}
	private static function Finding(string $level, string $message = "a finding"): array {
		return ["level" => $level, "message" => $message, "group" => "schema"];
	}

	function testTablesListsModelsAndStatus() {
		TestsHelper::Print("testing DatabaseExplorer::Tables() maps tables to models and their worst finding...");
		$this->schema->tables = [
			"articles" => ["rows" => 12],
			"authors" => ["rows" => null, "engine" => "MyISAM"],
			"logs" => [],
			"report" => ["type" => "VIEW", "engine" => null],
		];
		$this->check->models = [self::Model("App\\Article", "Articles"), self::Model("App\\Author", "authors"), self::Model("App\\Report", "report")];
		$this->check->modelFindings = [
			"App\\Article" => [self::Finding(TimezoneCheck::INFO), self::Finding(TimezoneCheck::WARNING)],
		];
		$this->check->timestampFindings = ["report" => [self::Finding(TimezoneCheck::ERROR)]];
		$this->check->loadFindings = [self::Finding(TimezoneCheck::WARNING, "`App\\Broken` couldn't be loaded")];

		$data = $this->explorer->Tables();
		$this->assertSame($this->check->loadFindings, $data["findings"]);
		$this->assertSame([
			["name" => "articles", "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => 12, "models" => ["App\\Article"], "status" => TimezoneCheck::WARNING],
			["name" => "authors", "type" => "BASE TABLE", "engine" => "MyISAM", "rows" => null, "models" => ["App\\Author"], "status" => TimezoneCheck::OK],
			["name" => "logs", "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => 0, "models" => [], "status" => null],
			["name" => "report", "type" => "VIEW", "engine" => null, "rows" => 0, "models" => ["App\\Report"], "status" => TimezoneCheck::ERROR],
		], $data["tables"]);
		$this->assertSame(1, $this->check->findModelsCalls, "FindModels() runs once");
		// a table no model maps isn't checked
		$this->assertNotContains("timestamps:logs", $this->check->checked);
		$this->assertSame([], $this->db->log, "listing tables reads no rows");
	}

	function testTablesWithTwoModelsOnOneTable() {
		TestsHelper::Print("testing DatabaseExplorer::Tables() lists every model of a table...");
		$this->schema->tables = ["articles" => []];
		$this->check->models = [self::Model("App\\Article", "articles"), self::Model("App\\Legacy\\Article", "articles")];
		$this->check->modelFindings = ["App\\Legacy\\Article" => [self::Finding(TimezoneCheck::ERROR)]];
		$table = $this->explorer->Tables()["tables"][0];
		$this->assertSame(["App\\Article", "App\\Legacy\\Article"], $table["models"]);
		$this->assertSame(TimezoneCheck::ERROR, $table["status"]);
		$this->assertSame(["model:App\\Article", "model:App\\Legacy\\Article", "timestamps:articles"], $this->check->checked);
	}

	function testTable() {
		TestsHelper::Print("testing DatabaseExplorer::Table() returns the structure, findings and first rows...");
		$this->schema->tables = ["articles" => [
			"columns" => [
				["name" => "id", "type" => "int(10) unsigned", "nullable" => false, "default" => null, "extra" => "auto_increment", "key" => "PRI"],
				["name" => "published", "type" => "tinyint(1)", "nullable" => false, "default" => "0", "extra" => "", "key" => ""],
				["name" => "cover", "type" => "blob", "nullable" => true, "default" => null, "extra" => "", "key" => ""],
			],
			"indexes" => [["name" => "PRIMARY", "unique" => true, "type" => "BTREE", "columns" => ["id"]]],
			"create" => "CREATE TABLE `articles` (…)",
		]];
		$this->check->models = [self::Model("App\\Article", "articles")];
		$this->check->modelFindings = ["App\\Article" => [self::Finding(TimezoneCheck::INFO, "model")]];
		$this->check->timestampFindings = ["articles" => [self::Finding(TimezoneCheck::ERROR, "timestamps")]];
		$this->db->rows = [["id" => "1", "published" => "1", "cover" => null]];

		$table = $this->explorer->Table("articles");
		$this->assertSame("articles", $table["name"]);
		$this->assertSame("BASE TABLE", $table["type"]);
		$this->assertSame(["int", "boolean", null], array_column($table["columns"], "magrathea_type"));
		$this->assertSame("int(10) unsigned", $table["columns"][0]["type"]);
		$this->assertSame($this->schema->tables["articles"]["indexes"], $table["indexes"]);
		$this->assertSame("CREATE TABLE `articles` (…)", $table["create"]);
		$this->assertSame(["App\\Article"], $table["models"]);
		$this->assertSame(["model", "timestamps"], array_column($table["findings"], "message"));
		$this->assertSame(["rows" => $this->db->rows, "error" => null], $table["preview"]);
		$this->assertSame(["SELECT * FROM `articles` LIMIT 20"], $this->db->log);
	}

	function testTableWithoutModel() {
		TestsHelper::Print("testing DatabaseExplorer::Table() runs no check on a table no model maps...");
		$this->schema->tables = ["logs" => []];
		$this->check->models = [self::Model("App\\Article", "articles")];
		$table = $this->explorer->Table("logs");
		$this->assertSame([], $table["models"]);
		$this->assertSame([], $table["findings"]);
		$this->assertSame([], $this->check->checked);
	}

	function testTableRejectsUnknownNames() {
		TestsHelper::Print("testing DatabaseExplorer::Table() takes only a name Schema lists, before any query...");
		$this->schema->tables = ["articles" => []];
		foreach(["missing", "ARTICLES", "articles`; DROP TABLE x; --", "articles "] as $name) {
			try {
				$this->explorer->Table($name);
				$this->fail("[".$name."] was accepted");
			} catch(MagratheaDBException $ex) {
				$this->assertSame("Unknown table [".$name."]", $ex->getMessage());
			}
		}
		$this->assertSame([], $this->db->log);
	}

	function testTablePreviewQuotesTheName() {
		TestsHelper::Print("testing DatabaseExplorer::Table() backtick-quotes the name in the preview...");
		$this->schema->tables = ["Weird`Name" => []];
		$this->explorer->Table("Weird`Name");
		$this->assertSame(["SELECT * FROM `Weird``Name` LIMIT 20"], $this->db->log);
	}

	function testTablePreviewError() {
		TestsHelper::Print("testing DatabaseExplorer::Table() returns a failing preview as an error...");
		$this->schema->tables = ["broken_view" => ["type" => "VIEW", "create" => "CREATE VIEW …"]];
		$this->db->error = "View 'broken_view' references invalid table(s)";
		$table = $this->explorer->Table("broken_view");
		$this->assertSame(["rows" => [], "error" => "View 'broken_view' references invalid table(s)"], $table["preview"]);
		$this->assertSame("CREATE VIEW …", $table["create"]);
	}

	function testWorstLevel() {
		TestsHelper::Print("testing DatabaseExplorer::WorstLevel()...");
		$this->assertSame(TimezoneCheck::OK, DatabaseExplorer::WorstLevel([]));
		$this->assertSame(TimezoneCheck::INFO, DatabaseExplorer::WorstLevel([self::Finding(TimezoneCheck::OK), self::Finding(TimezoneCheck::INFO)]));
		$this->assertSame(TimezoneCheck::ERROR, DatabaseExplorer::WorstLevel([self::Finding(TimezoneCheck::ERROR), self::Finding(TimezoneCheck::WARNING)]));
		$this->assertSame(TimezoneCheck::WARNING, DatabaseExplorer::WorstLevel([["level" => "bogus", "message" => ""], self::Finding(TimezoneCheck::WARNING)]));
	}

}
