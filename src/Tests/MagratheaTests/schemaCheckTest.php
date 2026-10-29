<?php

use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\MagratheaPHP;
use Magrathea\Tests\TestsHelper;
use Magrathea\TimezoneCheck;

// Schema with fixed rows: $tables is [name => ["type" => …, "columns" => Columns() rows]]
class SchemaCheckFakeSchema extends Schema {
	public $tables = [];
	public function __construct() {}
	public function Tables(bool $withFramework = true): array {
		$rows = [];
		$tables = $this->tables;
		ksort($tables);
		foreach($tables as $name => $table) {
			if(!$withFramework && str_starts_with($name, "_magrathea_")) continue;
			$rows[] = ["name" => $name, "type" => $table["type"] ?? "BASE TABLE", "engine" => "InnoDB", "rows" => 0, "collation" => "", "comment" => ""];
		}
		return $rows;
	}
	public function HasTable(string $table): bool {
		return isset($this->tables[$table]);
	}
	public function Columns(string $table): array {
		if(!isset($this->tables[$table])) throw new MagratheaDBException("Unknown table [".$table."]");
		return $this->tables[$table]["columns"];
	}
}

class schemaCheckTest extends \PHPUnit\Framework\TestCase {

	private $dumpFolder;
	private $codeFolder;
	private $namespace;
	private SchemaCheckFakeSchema $schema;
	private SchemaCheck $check;

	protected function setUp(): void {
		parent::setUp();
		// declared classes and require_once'd paths outlive the test: each test gets its own namespace and folder
		$id = bin2hex(random_bytes(4));
		$this->namespace = "SchemaCheckTest\\T".$id;
		// a run that died skipped tearDown: start empty
		system('rm -rf -- '.escapeshellarg(__DIR__."/test-dump/schema-check"));
		$this->dumpFolder = __DIR__."/test-dump/schema-check/".$id;
		mkdir($this->dumpFolder, 0777, true);
		$this->codeFolder = MagratheaPHP::Instance()->codeFolder;
		MagratheaPHP::Instance()->codeFolder = [];
		$this->schema = new SchemaCheckFakeSchema();
		$this->check = new SchemaCheck($this->schema);
	}

	protected function tearDown(): void {
		MagratheaPHP::Instance()->codeFolder = $this->codeFolder;
		system('rm -rf -- '.escapeshellarg(__DIR__."/test-dump/schema-check"));
		parent::tearDown();
	}

	// writes <folder>/<file>.php with $code in the test's namespace, and adds the folder to the code folders
	private function WriteFile(string $folder, string $file, string $code): void {
		$dir = $this->dumpFolder."/".$folder;
		if(!is_dir($dir)) mkdir($dir, 0777, true);
		file_put_contents($dir."/".$file.".php", "<?php\nnamespace ".$this->namespace.";\n\n".$code."\n");
		if(!in_array($dir, MagratheaPHP::Instance()->codeFolder)) MagratheaPHP::Instance()->AddCodeFolder($dir);
	}

	private function Model(string $class, string $table, array $values, string $pk = "id", string $extends = "\\Magrathea\\MagratheaModel", string $modifier = ""): string {
		return $modifier."class ".$class." extends ".$extends." {\n".
			"\tprotected \$dbTable = ".var_export($table, true).";\n".
			"\tprotected \$dbPk = ".var_export($pk, true).";\n".
			"\tprotected \$dbValues = ".var_export($values, true).";\n".
			"}";
	}

	private function WriteModel(string $class, string $table, array $values, string $pk = "id"): string {
		$this->WriteFile("models", $class, $this->Model($class, $table, $values, $pk));
		return $this->namespace."\\".$class;
	}

	private static function Column(string $name, string $type, string $key = "", bool $nullable = false, $default = null, string $extra = ""): array {
		return ["name" => $name, "type" => $type, "nullable" => $nullable, "default" => $default, "extra" => $extra, "key" => $key];
	}

	// a table that follows the timestamp contract, with these columns before the timestamps
	private function Table(string $name, array $columns, string $type = "BASE TABLE"): void {
		$columns[] = self::Column("created_at", "datetime", "", false, "CURRENT_TIMESTAMP", "DEFAULT_GENERATED");
		$columns[] = self::Column("updated_at", "datetime", "", false, "CURRENT_TIMESTAMP", "DEFAULT_GENERATED on update CURRENT_TIMESTAMP");
		$this->schema->tables[$name] = ["type" => $type, "columns" => $columns];
	}

	private function ArticlesTable(): void {
		$this->Table("articles", [
			self::Column("id", "int(10) unsigned", "PRI", false, null, "auto_increment"),
			self::Column("title", "varchar(255)", "", true),
		]);
	}

	private static function Messages(array $findings): array {
		return array_map(fn($f) => $f["level"].": ".$f["message"], $findings);
	}

	function testFindModels() {
		TestsHelper::Print("testing SchemaCheck::FindModels() finds the concrete models of the code folders...");
		$this->WriteFile("models", "Article", $this->Model("Article", "articles", ["id" => "int", "title" => "string"]));
		$this->WriteFile("models", "Content", $this->Model("Content", "contents", ["id" => "int"], "id", "\\Magrathea\\MagratheaModel", "abstract "));
		$this->WriteFile("models", "Plain", "class Plain {}");
		$this->WriteFile("models", "NotAModel", "class NotAModel extends \\Exception {}");
		$this->WriteFile("models", "functions", "function helper() { return Article::class; }");
		$this->WriteFile("models/sub", "Deep", $this->Model("Deep", "deep", ["id" => "int"]));
		// only "models" is a code folder: the scan isn't recursive
		MagratheaPHP::Instance()->codeFolder = [$this->dumpFolder."/models"];

		$found = $this->check->FindModels();
		$this->assertSame([], $found["findings"]);
		$this->assertSame([[
			"file" => $this->dumpFolder."/models/Article.php",
			"class" => $this->namespace."\\Article",
			"table" => "articles",
			"pk" => "id",
			"values" => ["id" => "int", "title" => "string"],
		]], $found["models"]);
		$this->assertFalse(class_exists($this->namespace."\\Plain", false), "a class without extends was loaded");
	}

	function testFindModelsKeepsTheSubclassOfABase() {
		TestsHelper::Print("testing SchemaCheck::FindModels() checks Article, not its ArticleBase...");
		$this->WriteFile("models/Base", "ArticleBase", $this->Model("ArticleBase", "articles", ["id" => "int"]));
		$this->WriteFile("models", "Article", "class Article extends ArticleBase {}");
		$found = $this->check->FindModels();
		$this->assertSame([$this->namespace."\\Article"], array_column($found["models"], "class"));
		$this->assertSame("articles", $found["models"][0]["table"]);
	}

	function testFindModelsReadsGlobalAndBracedNamespaces() {
		TestsHelper::Print("testing SchemaCheck::FindModels() reads namespaces with token_get_all()...");
		$dir = $this->dumpFolder."/models";
		mkdir($dir);
		$class = "SchemaCheckGlobal".bin2hex(random_bytes(4));
		file_put_contents($dir."/".$class.".php", "<?php\n// class Fake extends Nothing\n".$this->Model($class, "globals", ["id" => "int"])."\n");
		$braced = $this->namespace."\\Braced";
		file_put_contents($dir."/Sheet.php", "<?php\nnamespace ".$braced." {\n\t/** class Fake extends Nothing */\n\tfinal ".$this->Model("Sheet", "sheets", ["id" => "int"])."\n\t\$x = Sheet::class;\n}\n");
		MagratheaPHP::Instance()->codeFolder = [$dir];
		$found = $this->check->FindModels();
		$this->assertSame([], $found["findings"]);
		$classes = array_column($found["models"], "class");
		sort($classes);
		$this->assertSame([$class, $braced."\\Sheet"], $classes);
	}

	function testFindModelsReportsWhatFailsAndNeverThrows() {
		TestsHelper::Print("testing SchemaCheck::FindModels() reports classes that fail to load or instantiate...");
		$this->WriteFile("models", "Article", $this->Model("Article", "articles", ["id" => "int"]));
		$this->WriteFile("models", "Broken", "class Broken extends \\Magrathea\\MagratheaModel {");
		$this->WriteFile("models", "Orphan", "class Orphan extends MissingParent {}");
		$this->WriteFile("models", "Misnamed", "class Renamed extends \\Magrathea\\MagratheaModel {}");
		$this->WriteFile("models", "Picky",
			"class Picky extends \\Magrathea\\MagratheaModel {\n\tpublic function MagratheaStart() { throw new \\RuntimeException(\"no\"); }\n}");
		// a second class in a model's file: not autoloadable by name (tried first, it fails), but loaded with Order
		$this->WriteFile("models", "Order", $this->Model("OrderLine", "order_lines", ["id" => "int"])."\n".$this->Model("Order", "orders", ["id" => "int"]));

		$found = $this->check->FindModels();
		$classes = array_column($found["models"], "class");
		sort($classes);
		$this->assertSame([$this->namespace."\\Article", $this->namespace."\\Order", $this->namespace."\\OrderLine"], $classes);

		$byModel = array_column($found["findings"], null, "model");
		$this->assertCount(4, $byModel);
		$this->assertSame(TimezoneCheck::WARNING, $byModel[$this->namespace."\\Broken"]["level"]);
		$this->assertStringContainsString("ParseError", $byModel[$this->namespace."\\Broken"]["message"]);
		$this->assertStringContainsString("Class \"".$this->namespace."\\MissingParent\" not found", $byModel[$this->namespace."\\Orphan"]["message"]);
		$this->assertStringContainsString("is its file named after the class?", $byModel[$this->namespace."\\Renamed"]["message"]);
		$this->assertSame(TimezoneCheck::ERROR, $byModel[$this->namespace."\\Picky"]["level"]);
		$this->assertSame("`".$this->namespace."\\Picky` can't be instantiated: no", $byModel[$this->namespace."\\Picky"]["message"]);
		foreach($found["findings"] as $finding) $this->assertSame("schema", $finding["group"]);
	}

	function testFindModelsWarnsWhenItFindsNone() {
		TestsHelper::Print("testing SchemaCheck::FindModels() warns, naming the folders, when it finds no model...");
		$this->WriteFile("models", "Plain", "class Plain extends \\Exception {}");
		$found = $this->check->FindModels();
		$this->assertSame([], $found["models"]);
		$this->assertSame([
			"level" => TimezoneCheck::WARNING,
			"message" => "No model found in the code folders [".$this->dumpFolder."/models].",
			"group" => "schema",
		], $found["findings"][0]);

		MagratheaPHP::Instance()->codeFolder = [];
		$this->assertStringContainsString("there are no code folders", $this->check->FindModels()["findings"][0]["message"]);
	}

	function testCheckModelOfAModelThatMatches() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() finds nothing when the model matches its table...");
		$this->ArticlesTable();
		$class = $this->WriteModel("Article", "articles", ["id" => "int", "TITLE" => "text"]);
		$this->assertSame([], $this->check->CheckModel($class));
	}

	function testCheckModelWithoutItsTable() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() reports a missing table, a missing \$dbTable and a class that isn't a model...");
		$class = $this->WriteModel("Article", "articles", ["id" => "int"]);
		$this->assertSame([[
			"level" => TimezoneCheck::ERROR,
			"message" => "`".$class."` maps the table `articles`, which doesn't exist.",
			"group" => "schema",
			"model" => $class,
			"table" => "articles",
		]], $this->check->CheckModel($class));

		$class = $this->WriteModel("Untabled", "", ["id" => "int"]);
		$this->assertSame(["error: `".$class."` has no `\$dbTable`."], self::Messages($this->check->CheckModel($class)));

		$this->assertSame(["error: `ArrayObject` can't be checked: it isn't a MagratheaModel"], self::Messages($this->check->CheckModel("ArrayObject")));
		$this->assertSame(["error: `Nope\\Nothing` can't be checked: class not found"], self::Messages($this->check->CheckModel("Nope\\Nothing")));
	}

	function testCheckModelFieldsAndColumns() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() compares \$dbValues with the columns...");
		$this->Table("articles", [
			self::Column("id", "int(11)", "PRI"),
			self::Column("title", "varchar(255)"),
			self::Column("views", "int(11)"),
		]);
		$class = $this->WriteModel("Article", "articles", ["id" => "int", "title" => "string", "subtitle" => "string"]);
		$findings = $this->check->CheckModel($class);
		$this->assertSame([
			"error: `".$class."::\$dbValues[subtitle]` has no column in `articles`: writes fail.",
			"info: `articles`.`views` isn't in `".$class."::\$dbValues`: the model doesn't read or write it.",
		], self::Messages($findings));
		$this->assertSame("subtitle", $findings[0]["field"]);
		$this->assertSame("views", $findings[1]["field"]);
		$this->assertSame("articles", $findings[1]["table"]);
	}

	function testCheckModelLeavesTimestampsToTheirOwnFinding() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() reports a missing timestamp once, even when \$dbValues lists it...");
		$this->schema->tables["articles"] = ["columns" => [
			self::Column("id", "int(11)", "PRI"),
			self::Column("updated_at", "datetime"),
		]];
		// a legacy model lists both timestamps
		$class = $this->WriteModel("Article", "articles", ["id" => "int", "created_at" => "datetime", "updated_at" => "datetime"]);
		$this->assertSame([
			"error: `articles` has no `created_at` column: every SELECT built from `".$class."` fails.",
		], self::Messages($this->check->CheckModel($class)));
	}

	function testCheckModelPrimaryKey() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() warns when \$dbPk isn't the table's primary key...");
		$class = $this->WriteModel("Article", "articles", ["id" => "int", "code" => "string"], "code");
		$this->Table("articles", [self::Column("id", "int(11)", "PRI"), self::Column("code", "varchar(20)", "UNI")]);
		$this->assertSame(["warning: `".$class."::\$dbPk` is `code`, but that's not `articles`'s primary key: it's (id)."], self::Messages($this->check->CheckModel($class)));

		$this->Table("articles", [self::Column("id", "int(11)", "PRI"), self::Column("code", "varchar(20)", "PRI")]);
		$this->assertSame(["warning: `".$class."::\$dbPk` is `code`, but that's not `articles`'s primary key: it's (id, code)."], self::Messages($this->check->CheckModel($class)));

		$this->Table("articles", [self::Column("id", "int(11)"), self::Column("code", "varchar(20)")]);
		$findings = $this->check->CheckModel($class);
		$this->assertSame(["warning: `".$class."::\$dbPk` is `code`, but that's not `articles`'s primary key: it has none."], self::Messages($findings));
		$this->assertSame("code", $findings[0]["field"]);
	}

	function testCheckModelTypes() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() compares field types with Schema::MagratheaType()...");
		$this->Table("things", [
			self::Column("id", "int(11)", "PRI"),
			self::Column("legacy", "char(10)"),
			self::Column("price", "decimal(10,2)"),
			self::Column("status", "tinyint(4)"),
			self::Column("published", "date"),
			self::Column("body", "text"),
			self::Column("name", "varchar(255)"),
			self::Column("ref", "char(36)"),
			self::Column("token", "varchar(36)"),
			self::Column("data", "blob"),
			self::Column("active", "tinyint(1)"),
		]);
		$class = $this->WriteModel("Thing", "things", [
			"id" => "int",
			"legacy" => "unknown",
			"price" => "float",
			"status" => "boolean",
			"published" => "datetime",
			// string, text and uuid behave the same: no finding
			"body" => "string",
			"name" => "text",
			"ref" => "string",
			"token" => "uuid",
			// no suggestion for a blob
			"data" => "string",
			"active" => "boolean",
		]);
		$name = "`".$class."::\$dbValues";
		$this->assertSame([
			"error: ".$name."[legacy]` has the unknown type [unknown]: use one of int, boolean, string, text, float, decimal, datetime, date, uuid. Writes and strict loads of it throw.",
			"warning: ".$name."[price]` is `float` on a `decimal(10,2)` column: it rounds under strict types. Use `decimal`.",
			"warning: ".$name."[status]` is `boolean`, but its column is `tinyint(4)`: use `int`.",
			"warning: ".$name."[published]` is `datetime`, but its column is `date`: use `date`.",
		], self::Messages($this->check->CheckModel($class)));
	}

	function testCheckModelUuidPrimaryKey() {
		TestsHelper::Print("testing SchemaCheck::CheckModel() warns about a char(36) PK not declared uuid...");
		$this->Table("tokens", [self::Column("id", "char(36)", "PRI")]);
		$class = $this->WriteModel("Token", "tokens", ["id" => "string"]);
		$this->assertSame([
			"warning: `".$class."::\$dbValues[id]` is `string`, but it's the PK and its column is `char(36)`: declare it `uuid`, or `Insert()` sends no PK.",
		], self::Messages($this->check->CheckModel($class)));
		$class = $this->WriteModel("UuidToken", "tokens", ["id" => "uuid"]);
		$this->assertSame([], $this->check->CheckModel($class));
	}

	function testCheckTimestampsOfATableThatFollowsTheContract() {
		TestsHelper::Print("testing SchemaCheck::CheckTimestamps() accepts MySQL's and MariaDB's CURRENT_TIMESTAMP...");
		$this->ArticlesTable();
		$this->assertSame([], $this->check->CheckTimestamps("articles"));
		$this->schema->tables["maria"] = ["columns" => [
			self::Column("created_at", "timestamp", "", true, "current_timestamp()"),
			self::Column("updated_at", "datetime(3)", "", false, "current_timestamp(3)", "on update current_timestamp(3)"),
		]];
		$this->assertSame([], $this->check->CheckTimestamps("maria"));
		// a missing column is CheckModel()'s
		$this->schema->tables["bare"] = ["columns" => [self::Column("id", "int(11)", "PRI")]];
		$this->assertSame([], $this->check->CheckTimestamps("bare"));
	}

	function testCheckTimestampsWithoutDefault() {
		TestsHelper::Print("testing SchemaCheck::CheckTimestamps() reports a timestamp without DEFAULT CURRENT_TIMESTAMP...");
		$this->schema->tables["posts"] = ["columns" => [
			self::Column("created_at", "datetime", "", false),
			self::Column("updated_at", "timestamp", "", true, null, "on update CURRENT_TIMESTAMP"),
		]];
		$this->assertSame([
			[
				"level" => TimezoneCheck::ERROR,
				"message" => "`posts`.`created_at` has no `DEFAULT CURRENT_TIMESTAMP` (inserts fail).",
				"group" => "schema",
				"table" => "posts",
				"field" => "created_at",
				"fix" => "ALTER TABLE `posts` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP;",
			],
			[
				"level" => TimezoneCheck::WARNING,
				"message" => "`posts`.`updated_at` has no `DEFAULT CURRENT_TIMESTAMP` (it's `NULL` after an insert).",
				"group" => "schema",
				"table" => "posts",
				"field" => "updated_at",
				"fix" => "ALTER TABLE `posts` MODIFY `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;",
			],
		], $this->check->CheckTimestamps("posts"));

		$this->schema->tables["posts"]["columns"][0]["default"] = "2000-01-01 00:00:00";
		$this->assertSame(TimezoneCheck::ERROR, $this->check->CheckTimestamps("posts")[0]["level"]);
		$this->assertSame("`posts`.`created_at` has no `DEFAULT CURRENT_TIMESTAMP` (inserts store `2000-01-01 00:00:00`).", $this->check->CheckTimestamps("posts")[0]["message"]);
	}

	function testCheckTimestampsOnUpdate() {
		TestsHelper::Print("testing SchemaCheck::CheckTimestamps() wants ON UPDATE on updated_at only...");
		$this->schema->tables["Weird`Name"] = ["columns" => [
			// MySQL 5.7's first bare TIMESTAMP column
			self::Column("created_at", "timestamp", "", false, "CURRENT_TIMESTAMP", "on update CURRENT_TIMESTAMP"),
			self::Column("updated_at", "datetime(6)", "", false),
		]];
		$this->assertSame([
			["error", "`Weird`Name`.`created_at` has `ON UPDATE CURRENT_TIMESTAMP` (it changes on every update).",
				"ALTER TABLE `Weird``Name` MODIFY `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;"],
			["error", "`Weird`Name`.`updated_at` has no `DEFAULT CURRENT_TIMESTAMP` (inserts fail) and no `ON UPDATE CURRENT_TIMESTAMP` (updates don't change it).",
				"ALTER TABLE `Weird``Name` MODIFY `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);"],
		], array_map(fn($f) => [$f["level"], $f["message"], $f["fix"]], $this->check->CheckTimestamps("Weird`Name")));
	}

	function testCheckTimestampsOfAnUnknownTable() {
		TestsHelper::Print("testing SchemaCheck::CheckTimestamps() throws on an unknown table...");
		$this->expectException(MagratheaDBException::class);
		$this->check->CheckTimestamps("missing");
	}

	function testCheckAll() {
		TestsHelper::Print("testing SchemaCheck::CheckAll() checks every model, its table once, and lists unmapped tables...");
		$this->ArticlesTable();
		$this->schema->tables["articles"]["columns"][3]["extra"] = "";
		$this->Table("tags", [self::Column("id", "int(11)", "PRI")]);
		$this->Table("tag_stats", [self::Column("id", "int(11)", "PRI")], "VIEW");
		$this->Table("_magrathea_users", [self::Column("id", "int(11)", "PRI")]);
		$article = $this->WriteModel("Article", "articles", ["id" => "int", "title" => "string"]);
		// a second model on the same table: its timestamps are checked once
		$draft = $this->WriteModel("Draft", "articles", ["id" => "int"]);
		$ghost = $this->WriteModel("Ghost", "ghosts", ["id" => "int"]);
		$this->WriteFile("models", "Broken", "class Broken extends \\Magrathea\\MagratheaModel {");

		$this->assertSame([
			"warning: `".$this->namespace."\\Broken` (".$this->dumpFolder."/models/Broken.php) couldn't be loaded, so it wasn't checked: ParseError: Unclosed '{' on line 4",
			"error: `articles`.`updated_at` has no `ON UPDATE CURRENT_TIMESTAMP` (updates don't change it).",
			"info: `articles`.`title` isn't in `".$draft."::\$dbValues`: the model doesn't read or write it.",
			"error: `".$ghost."` maps the table `ghosts`, which doesn't exist.",
			"info: No model maps the view `tag_stats`.",
			"info: No model maps the table `tags`.",
		], self::Messages($this->check->CheckAll()));
	}

	function testDefaultsToANewSchema() {
		TestsHelper::Print("testing SchemaCheck defaults to a Schema on Database::Instance()...");
		$property = new \ReflectionProperty(SchemaCheck::class, "schema");
		$this->assertInstanceOf(Schema::class, $property->getValue(new SchemaCheck()));
		$this->assertSame($this->schema, $property->getValue($this->check));
	}

}
