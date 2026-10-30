<?php

use Magrathea\Admin\QueryEditor;
use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;

// Schema with fixed tables: $tables is [name => column names]
class QueryEditorFakeSchema extends Schema {
	public $tables = [];
	public function __construct() {}
	public function Tables(bool $withFramework = true): array {
		return array_map(fn($name) => ["name" => $name, "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => 0, "collation" => "", "comment" => ""],
			array_keys($this->tables));
	}
	public function Columns(string $table): array {
		if(!isset($this->tables[$table])) throw new MagratheaDBException("Unknown table [".$table."]");
		return array_map(fn($name) => ["name" => $name, "type" => "varchar(255)", "nullable" => true, "default" => null, "extra" => "", "key" => ""],
			$this->tables[$table]);
	}
}

// Database that escapes like mysqli, without a connection
class QueryEditorEscapingDatabase extends Database {
	public function Escape($value): string {
		return addcslashes((string)$value, "\x00\n\r\\'\"\x1a");
	}
}

class adminQueryEditorTest extends \PHPUnit\Framework\TestCase {

	private QueryEditorFakeSchema $schema;
	private $previousDb;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		Database::MockClass(QueryEditorEscapingDatabase::Instance());
		$this->schema = new QueryEditorFakeSchema();
		$this->schema->tables = [
			"articles" => ["id", "title", "price", "author_id", "created_at"],
			"order" => ["id", "key"],
		];
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		parent::tearDown();
	}

	/**
	* Build(), checking that its PHP chain, run, gives the same SQL
	*/
	private function Build(array $spec): array {
		$built = QueryEditor::Build($spec, $this->schema);
		$fromPhp = eval("return \\Magrathea\\DB\\".$built["php"].";");
		$this->assertSame($built["sql"], $fromPhp->SQL(), "the PHP chain gives the same SQL: ".$built["php"]);
		return $built;
	}
	private function assertRejected(array $spec, string $exception, string $message) {
		try {
			QueryEditor::Build($spec, $this->schema);
			$this->fail("expected ".$exception." for ".json_encode($spec));
		} catch(\PHPUnit\Framework\AssertionFailedError $ex) {
			throw $ex;
		} catch(\Throwable $ex) {
			$this->assertInstanceOf($exception, $ex, json_encode($spec));
			$this->assertStringContainsString($message, $ex->getMessage(), json_encode($spec));
		}
	}

	function testSelectAll() {
		TestsHelper::Print("testing QueryEditor builds a bare select...");
		$built = $this->Build(["type" => "select", "table" => "articles"]);
		$this->assertSame('Query::Select()->Table("articles")', $built["php"]);
		$this->assertSame("SELECT * FROM `articles`", $built["sql"]);
	}

	function testSelectWithEverything() {
		TestsHelper::Print("testing QueryEditor builds a select with fields, where, order, limit and page...");
		$built = $this->Build([
			"type" => "select", "table" => "articles",
			"fields" => ["id", "title"],
			"where" => [["column" => "author_id", "operator" => "=", "value" => "3"], ["column" => "price", "operator" => ">", "value" => "10"]],
			"order" => ["column" => "price", "direction" => "desc"],
			"limit" => "20", "page" => 2,
		]);
		$this->assertSame(
			'Query::Select("`id`, `title`")->Table("articles")->Where(["author_id" => "3"])->Where("`price` > \'10\'")'.
			'->Order("`price` DESC")->Limit(20)->Page(2)',
			$built["php"]);
		$this->assertSame("SELECT `id`, `title` FROM `articles` WHERE  `author_id` = '3'  AND `price` > '10' ORDER BY `price` DESC LIMIT 40, 20", $built["sql"]);
	}

	function testEveryOperator() {
		TestsHelper::Print("testing QueryEditor builds every operator...");
		$expected = [
			"=" => " `title` = 'a' ",
			"!=" => "`title` != 'a'",
			"<" => "`title` < 'a'",
			">" => "`title` > 'a'",
			"<=" => "`title` <= 'a'",
			">=" => "`title` >= 'a'",
			"LIKE" => "`title` LIKE 'a'",
			"IS NULL" => "`title` IS NULL",
			"IS NOT NULL" => "`title` IS NOT NULL",
			"IN" => "`title` IN ('a')",
		];
		$this->assertSame(QueryEditor::OPERATORS, array_keys($expected), "every operator is tested");
		foreach($expected as $operator => $condition) {
			$built = $this->Build(["type" => "select", "table" => "articles", "where" => [["column" => "title", "operator" => $operator, "value" => "a"]]]);
			$this->assertSame("SELECT * FROM `articles` WHERE ".$condition, $built["sql"], $operator);
		}
		$built = $this->Build(["type" => "select", "table" => "articles", "where" => [["column" => "id", "operator" => "in", "value" => " 1, 2,,3 "]]]);
		$this->assertSame("SELECT * FROM `articles` WHERE `id` IN ('1', '2', '3')", $built["sql"], "IN trims and drops empty items; operators are case-insensitive");
		$built = $this->Build(["type" => "select", "table" => "articles", "where" => [["column" => "id", "value" => "1"]]]);
		$this->assertSame("SELECT * FROM `articles` WHERE  `id` = '1' ", $built["sql"], "the operator defaults to =");
	}

	function testValuesAreEscaped() {
		TestsHelper::Print("testing QueryEditor escapes values in conditions, and its PHP chain survives them...");
		$value = "O'Brien \"\$x\" \\ \n end";
		foreach(["=", "!=", "LIKE", "IN"] as $operator) {
			$built = $this->Build(["type" => "select", "table" => "articles", "where" => [["column" => "title", "operator" => $operator, "value" => $value]]]);
			$this->assertStringContainsString("'O\\'Brien \\\"\$x\\\" \\\\ \\n end'", $built["sql"], $operator);
		}
	}

	function testInsert() {
		TestsHelper::Print("testing QueryEditor builds an insert, with NULL...");
		$built = $this->Build(["type" => "insert", "table" => "articles", "values" => ["title" => "Hi", "price" => null, "author_id" => 3]]);
		$this->assertSame('Query::Insert()->Table("articles")->Values(["title" => "Hi", "price" => null, "author_id" => 3])', $built["php"]);
		$this->assertSame("INSERT INTO `articles` (`title`, `price`, `author_id`)  VALUES  ('Hi', NULL, '3') ", $built["sql"]);
	}

	function testUpdate() {
		TestsHelper::Print("testing QueryEditor builds an update, with NULL...");
		$built = $this->Build(["type" => "update", "table" => "articles", "values" => ["title" => "Hi", "price" => null],
			"where" => [["column" => "id", "operator" => "=", "value" => "5"]]]);
		$this->assertSame('Query::Update()->Table("articles")->SetArray(["title" => "Hi", "price" => null])->Where(["id" => "5"])', $built["php"]);
		$this->assertSame("UPDATE articles SET title = 'Hi', price = NULL WHERE  `id` = '5' ", $built["sql"]);
	}

	function testDelete() {
		TestsHelper::Print("testing QueryEditor builds a delete...");
		$built = $this->Build(["type" => "delete", "table" => "articles", "where" => [["column" => "price", "operator" => "IS NULL", "value" => ""]]]);
		$this->assertSame('Query::Delete()->Table("articles")->Where("`price` IS NULL")', $built["php"]);
		$this->assertSame("DELETE FROM articles WHERE `price` IS NULL", $built["sql"]);
	}

	function testWritesNeedWhereAndValues() {
		TestsHelper::Print("testing QueryEditor refuses an update or delete without where, and writes without values...");
		$this->assertRejected(["type" => "update", "table" => "articles", "values" => ["title" => "x"]], \InvalidArgumentException::class, "at least one where row");
		$this->assertRejected(["type" => "update", "table" => "articles", "values" => ["title" => "x"], "where" => []], \InvalidArgumentException::class, "at least one where row");
		$this->assertRejected(["type" => "delete", "table" => "articles"], \InvalidArgumentException::class, "at least one where row");
		$this->assertRejected(["type" => "insert", "table" => "articles", "values" => []], \InvalidArgumentException::class, "at least one value");
		$this->assertRejected(["type" => "update", "table" => "articles", "where" => [["column" => "id", "value" => "1"]]], \InvalidArgumentException::class, "at least one value");
		$this->assertRejected(["type" => "insert", "table" => "articles", "values" => ["x"]], \InvalidArgumentException::class, "[column => value]");
	}

	function testWritesRejectSelectKeys() {
		TestsHelper::Print("testing QueryEditor refuses order, limit, page and fields on writes (QueryUpdate/QueryDelete would drop them)...");
		$where = [["column" => "id", "value" => "1"]];
		foreach(["order" => ["column" => "id"], "limit" => 1, "page" => 0, "fields" => ["id"]] as $key => $value) {
			$this->assertRejected(["type" => "delete", "table" => "articles", "where" => $where, $key => $value], \InvalidArgumentException::class, "doesn't take [".$key."]");
			$this->assertRejected(["type" => "update", "table" => "articles", "values" => ["title" => "x"], "where" => $where, $key => $value], \InvalidArgumentException::class, "doesn't take [".$key."]");
		}
		$this->assertRejected(["type" => "insert", "table" => "articles", "values" => ["title" => "x"], "where" => $where], \InvalidArgumentException::class, "doesn't take [where]");
		$this->assertRejected(["type" => "select", "table" => "articles", "values" => ["title" => "x"]], \InvalidArgumentException::class, "doesn't take [values]");
		// empty ones are the form's defaults
		$built = $this->Build(["type" => "delete", "table" => "articles", "where" => $where, "limit" => "", "order" => null, "fields" => []]);
		$this->assertSame("DELETE FROM articles WHERE  `id` = '1' ", $built["sql"]);
		$this->assertRejected(["type" => "select", "table" => "articles", "feilds" => ["id"]], \InvalidArgumentException::class, "Unknown key [feilds]");
	}

	function testUnknownTablesAndColumns() {
		TestsHelper::Print("testing QueryEditor refuses tables and columns Schema doesn't list...");
		$this->assertRejected(["type" => "select", "table" => "nope"], MagratheaDBException::class, "Unknown table [nope]");
		$this->assertRejected(["type" => "select", "table" => "Articles"], MagratheaDBException::class, "Unknown table [Articles]");
		$this->assertRejected(["type" => "select"], MagratheaDBException::class, "Unknown table");
		$this->assertRejected(["type" => "select", "table" => ["articles"]], MagratheaDBException::class, "Unknown table");
		$this->assertRejected(["type" => "select", "table" => "articles", "fields" => ["id", "id`; DROP TABLE x; --"]], MagratheaDBException::class, "Unknown column");
		$this->assertRejected(["type" => "select", "table" => "articles", "where" => [["column" => "nope", "value" => "1"]]], MagratheaDBException::class, "Unknown column [nope] in [articles]");
		$this->assertRejected(["type" => "select", "table" => "articles", "order" => ["column" => "price; DROP"]], MagratheaDBException::class, "Unknown column");
		$this->assertRejected(["type" => "insert", "table" => "articles", "values" => ["nope" => "x"]], MagratheaDBException::class, "Unknown column [nope]");
		$this->assertRejected(["type" => "update", "table" => "articles", "values" => ["title" => "x"], "where" => [["column" => "key", "value" => "1"]]], MagratheaDBException::class, "Unknown column [key]");
	}

	function testInvalidSpec() {
		TestsHelper::Print("testing QueryEditor refuses unknown types, operators, directions and bad numbers...");
		$this->assertRejected(["table" => "articles"], \InvalidArgumentException::class, "Unknown query type");
		$this->assertRejected(["type" => "truncate", "table" => "articles"], \InvalidArgumentException::class, "Unknown query type [truncate]");
		$this->assertRejected(["type" => "select", "table" => "articles", "where" => [["column" => "id", "operator" => "; DROP", "value" => "1"]]], \InvalidArgumentException::class, "Unknown operator");
		$this->assertRejected(["type" => "select", "table" => "articles", "where" => [["column" => "id", "operator" => "IN", "value" => " , "]]], \InvalidArgumentException::class, "IN needs a value");
		$this->assertRejected(["type" => "select", "table" => "articles", "where" => [["column" => "id", "value" => ["1"]]]], \InvalidArgumentException::class, "string or a number");
		$this->assertRejected(["type" => "select", "table" => "articles", "where" => ["id = 1"]], \InvalidArgumentException::class, "where row");
		$this->assertRejected(["type" => "select", "table" => "articles", "order" => ["column" => "id", "direction" => "SIDEWAYS"]], \InvalidArgumentException::class, "direction");
		$this->assertRejected(["type" => "select", "table" => "articles", "limit" => "0"], \InvalidArgumentException::class, "limit must be at least 1");
		$this->assertRejected(["type" => "select", "table" => "articles", "limit" => "1; DROP"], \InvalidArgumentException::class, "whole number");
		$this->assertRejected(["type" => "select", "table" => "articles", "limit" => -1], \InvalidArgumentException::class, "limit must be at least 1");
		$this->assertRejected(["type" => "select", "table" => "articles", "page" => 1], \InvalidArgumentException::class, "page needs a limit");
		$this->assertRejected(["type" => "insert", "table" => "articles", "values" => ["title" => true]], \InvalidArgumentException::class, "string or a number");
	}

	function testReservedWordTable() {
		TestsHelper::Print("testing QueryEditor quotes what it builds, and leaves Table()/Set() names to Query...");
		$built = $this->Build(["type" => "select", "table" => "order", "fields" => ["key"], "order" => ["column" => "key"]]);
		$this->assertSame("SELECT `key` FROM `order` ORDER BY `key` ASC", $built["sql"]);
	}

}
