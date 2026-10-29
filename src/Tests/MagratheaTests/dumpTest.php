<?php

use Magrathea\Config;
use Magrathea\DB\Database;
use Magrathea\DB\Dump;
use Magrathea\DB\SqlSplitter;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;

// Database answering the php driver's queries from fixture tables, and recording what it runs
class DumpFakeDatabase extends Database {
	public $log = [];
	/** name => ["type", "create", "columns", "rows"] */
	public $tables = [];
	public $triggers = [];
	public $routines = [];
	/** statements that fail in QueryMulti(): statement => errno */
	public $failing = [];
	/** QueryStream() throws after this many rows (null: never) */
	public $streamFailsAfter = null;
	public function Escape($value): string {
		return addslashes((string)$value);
	}
	public function Query(string $sql) {
		$this->log[] = $sql;
		return true;
	}
	public function QueryMulti($queries, $killable=true) {
		foreach($queries as $query) {
			$this->log[] = $query;
			if(isset($this->failing[$query])) throw new MagratheaDBException("failed: ".$query, $query, $this->failing[$query]);
		}
		return [];
	}
	public function QueryAll(string $sql) {
		$this->log[] = $sql;
		if(str_contains($sql, "FROM information_schema.TABLES")) {
			$rows = [];
			foreach($this->tables as $name => $t) {
				$rows[] = ["name" => $name, "type" => $t["type"], "engine" => null, "rows" => null, "collation" => null, "comment" => ""];
			}
			return $rows;
		}
		if(str_contains($sql, "FROM information_schema.COLUMNS")) return $this->tables[$this->TableIn($sql)]["columns"];
		if(str_contains($sql, "FROM information_schema.TRIGGERS")) {
			return array_map(fn($t) => ["name" => $t["name"], "table" => $t["table"]], $this->triggers);
		}
		if(str_contains($sql, "FROM information_schema.ROUTINES")) return $this->routines;
		return [];
	}
	public function QueryRow(string $sql): array|object {
		$this->log[] = $sql;
		if(str_starts_with($sql, "SELECT @@session.time_zone")) return ["time_zone" => "Europe/Lisbon", "foreign_key_checks" => "1"];
		if(str_contains($sql, "FROM information_schema.TABLES")) {
			$name = $this->TableIn($sql);
			return isset($this->tables[$name]) ? ["name" => $name] : [];
		}
		if(preg_match('/^SHOW CREATE TABLE `(.*)`$/', $sql, $m)) {
			$t = $this->tables[$m[1]];
			return [$t["type"] === "VIEW" ? "create view" : "create table" => $t["create"]];
		}
		if(preg_match('/^SHOW CREATE TRIGGER `(.*)`$/', $sql, $m)) {
			foreach($this->triggers as $t) if($t["name"] === $m[1]) return ["sql original statement" => $t["sql"]];
		}
		return [];
	}
	public function QueryStream(string $sql): \Generator {
		$this->log[] = $sql;
		preg_match('/ FROM `(.*)`$/', $sql, $m);
		foreach($this->tables[$m[1]]["rows"] as $n => $row) {
			if($this->streamFailsAfter !== null && $n >= $this->streamFailsAfter) throw new MagratheaDBException("lost the result", $sql, 2013);
			yield $row;
		}
	}
	private function TableIn(string $sql): string {
		preg_match("/TABLE_NAME = '(.*)'/U", $sql, $m);
		return stripslashes($m[1]);
	}
}

// a Dump whose mysqldump/mysql don't answer --version
class NoBinaryDump extends Dump {
	public $commands = [];
	protected function RunProcess(array $command, ?string $stdinFile = null): array {
		$this->commands[] = $command;
		return ["code" => 127, "stdout" => "", "stderr" => "not found"];
	}
}

class dumpTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private DumpFakeDatabase $db;

	const ARTICLES_CREATE = "CREATE TABLE `articles` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
	const TRIGGER_SQL = "CREATE TRIGGER `articles_bi` BEFORE INSERT ON `articles` FOR EACH ROW BEGIN SET NEW.title = TRIM(NEW.title); END";

	protected function setUp(): void {
		parent::setUp();
		$this->folder = sys_get_temp_dir()."/magrathea-dump-test-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->folder));
		mkdir($this->folder);
		Config::Instance()->SetConfig([
			"general" => ["use_environment" => "test"],
			"test" => ["backups_path" => $this->folder],
		])->SetEnvironment(null);

		$this->db = DumpFakeDatabase::Instance();
		$this->db->SetConnection("localhost", "shop", "user", "pass");
		$this->db->log = [];
		$this->db->failing = [];
		$this->db->streamFailsAfter = null;
		$this->db->tables = [
			"articles" => [
				"type" => "BASE TABLE",
				"create" => self::ARTICLES_CREATE,
				"columns" => [
					["name" => "id", "type" => "int(11)", "nullable" => false, "default" => null, "extra" => "auto_increment", "key" => "PRI"],
					["name" => "title", "type" => "varchar(255)", "nullable" => false, "default" => null, "extra" => "", "key" => ""],
					["name" => "slug", "type" => "varchar(255)", "nullable" => true, "default" => null, "extra" => "VIRTUAL GENERATED", "key" => ""],
					["name" => "body", "type" => "text", "nullable" => true, "default" => null, "extra" => "", "key" => ""],
					["name" => "created_at", "type" => "timestamp", "nullable" => false, "default" => "CURRENT_TIMESTAMP", "extra" => "DEFAULT_GENERATED", "key" => ""],
				],
				"rows" => [
					["id" => "1", "title" => "it's \"q\" \\ ;", "body" => null, "created_at" => "2026-10-29 12:00:00"],
					["id" => "2", "title" => "two", "body" => "-- not a comment", "created_at" => "2026-10-29 12:00:01"],
				],
			],
			"files" => [
				"type" => "BASE TABLE",
				"create" => "CREATE TABLE `files` (`id` int, `data` blob, `flag` bit(1))",
				"columns" => [
					["name" => "id", "type" => "int(11)", "nullable" => false, "default" => null, "extra" => "", "key" => "PRI"],
					["name" => "data", "type" => "blob", "nullable" => true, "default" => null, "extra" => "", "key" => ""],
					["name" => "flag", "type" => "bit(1)", "nullable" => true, "default" => null, "extra" => "", "key" => ""],
				],
				"rows" => [
					["id" => "1", "data" => "\x00\xffab", "flag" => "\x01"],
					["id" => "2", "data" => "", "flag" => null],
				],
			],
			"recent" => [
				"type" => "VIEW",
				"create" => "CREATE VIEW `recent` AS select `articles`.`id` AS `id` from `articles`",
				"columns" => [],
				"rows" => [],
			],
		];
		$this->db->triggers = [
			["name" => "articles_bi", "table" => "articles", "sql" => self::TRIGGER_SQL],
			["name" => "files_bi", "table" => "files", "sql" => "CREATE TRIGGER `files_bi` BEFORE INSERT ON `files` FOR EACH ROW SET NEW.flag = 1"],
		];
		$this->db->routines = [["type" => "PROCEDURE", "name" => "cleanup"]];
	}
	protected function tearDown(): void {
		system('rm -rf -- '.escapeshellarg($this->folder));
		Config::Instance()->SetConfig(null)->SetEnvironment(null);
		parent::tearDown();
	}

	private function Dump(): Dump {
		return new Dump($this->db);
	}
	// the dump without its date line
	private function Content(string $file): string {
		return preg_replace('/^(-- database: `shop`), .*$/m', '$1', file_get_contents($this->folder."/".$file));
	}

	function testPhpExport() {
		TestsHelper::Print("testing the php driver exports the header, tables, rows, views and triggers, and reports routines...");
		$rs = $this->Dump()->Export("php_dump", ["driver" => "php"]);
		$this->assertSame([
			"file" => "php_dump.sql",
			"size" => filesize($this->folder."/php_dump.sql"),
			"driver" => "php",
			"skipped" => ["PROCEDURE cleanup"],
		], $rs);
		$this->assertSame(
			"-- Magrathea dump (php driver)\n".
			"-- database: `shop`\n\n".
			"SET NAMES utf8mb4;\n".
			"SET time_zone = '+00:00';\n".
			"SET FOREIGN_KEY_CHECKS = 0;\n".
			"\n--\n-- Table `articles`\n--\n\n".
			"DROP TABLE IF EXISTS `articles`;\n".
			self::ARTICLES_CREATE.";\n".
			"INSERT INTO `articles` (`id`, `title`, `body`, `created_at`) VALUES\n".
			"('1', 'it\\'s \\\"q\\\" \\\\ ;', NULL, '2026-10-29 12:00:00'),\n".
			"('2', 'two', '-- not a comment', '2026-10-29 12:00:01');\n".
			"\n--\n-- Table `files`\n--\n\n".
			"DROP TABLE IF EXISTS `files`;\n".
			"CREATE TABLE `files` (`id` int, `data` blob, `flag` bit(1));\n".
			"INSERT INTO `files` (`id`, `data`, `flag`) VALUES\n".
			"('1', 0x00ff6162, 0x01),\n".
			"('2', '', NULL);\n".
			"\n--\n-- View `recent`\n--\n\n".
			"DROP VIEW IF EXISTS `recent`;\n".
			"CREATE VIEW `recent` AS select `articles`.`id` AS `id` from `articles`;\n".
			"\n--\n-- Triggers\n--\n\n".
			"DELIMITER ;;\n".
			"DROP TRIGGER IF EXISTS `articles_bi`;;\n".
			self::TRIGGER_SQL.";;\n".
			"DROP TRIGGER IF EXISTS `files_bi`;;\n".
			"CREATE TRIGGER `files_bi` BEFORE INSERT ON `files` FOR EACH ROW SET NEW.flag = 1;;\n".
			"DELIMITER ;\n".
			"\n-- not exported (the php driver can't export routines):\n-- PROCEDURE cleanup\n".
			"\nSET FOREIGN_KEY_CHECKS = 1;\n",
			$this->Content("php_dump.sql")
		);
		// the session reads in UTC, and gets its zone back
		$this->assertStringStartsWith("SELECT @@session.time_zone", $this->db->log[0]);
		$this->assertSame("SET time_zone = '+00:00'", $this->db->log[1]);
		$this->assertSame("SET time_zone = 'Europe/Lisbon', foreign_key_checks = 1", end($this->db->log));
		$this->assertContains("SELECT `id`, `title`, `body`, `created_at` FROM `articles`", $this->db->log, "the generated column is left out");
	}

	function testPhpExportReimportsStatementByStatement() {
		TestsHelper::Print("testing a php export splits back into its statements (the trigger with ;) and imports one by one...");
		$this->Dump()->Export("round", ["driver" => "php"]);
		$statements = iterator_to_array(SqlSplitter::FromFile($this->folder."/round.sql", 7), false);
		$this->assertCount(16, $statements);
		$this->assertSame("SET NAMES utf8mb4", $statements[0]);
		$this->assertContains(self::TRIGGER_SQL, $statements);
		$this->assertSame("SET FOREIGN_KEY_CHECKS = 1", end($statements));

		$this->db->log = [];
		$rs = $this->Dump()->Import("round", ["driver" => "php"]);
		$this->assertSame(["driver" => "php", "statements" => 16], $rs);
		$this->assertSame($statements, array_slice($this->db->log, 1, 16));
		$this->assertSame("SET time_zone = 'Europe/Lisbon', foreign_key_checks = 1", end($this->db->log));
	}

	function testPhpExportOnlyGivenTables() {
		TestsHelper::Print("testing a php export of some tables leaves out the rest, their triggers and the routines...");
		$rs = $this->Dump()->Export("files_only", ["driver" => "php", "tables" => ["FILES"]]);
		$this->assertArrayNotHasKey("skipped", $rs);
		$content = $this->Content("files_only.sql");
		$this->assertStringContainsString("CREATE TABLE `files`", $content);
		$this->assertStringContainsString("`files_bi`", $content);
		$this->assertStringNotContainsString("articles", $content);
		$this->assertStringNotContainsString("recent", $content);
		$this->assertStringNotContainsString("cleanup", $content);
	}

	function testPhpExportSplitsBigInserts() {
		TestsHelper::Print("testing the php driver starts a new INSERT past INSERT_BYTES...");
		$row = str_repeat("a", 400000);
		$this->db->tables = ["big" => [
			"type" => "BASE TABLE",
			"create" => "CREATE TABLE `big` (`v` longtext)",
			"columns" => [["name" => "v", "type" => "longtext", "nullable" => true, "default" => null, "extra" => "", "key" => ""]],
			"rows" => [["v" => $row], ["v" => $row], ["v" => $row]],
		]];
		$this->db->triggers = [];
		$this->db->routines = [];
		$this->Dump()->Export("big", ["driver" => "php"]);
		$statements = SqlSplitter::Split(file_get_contents($this->folder."/big.sql"));
		$inserts = array_values(array_filter($statements, fn($s) => str_starts_with($s, "INSERT")));
		$this->assertCount(2, $inserts);
		$this->assertSame(2, substr_count($inserts[0], "('a"));
		$this->assertSame(1, substr_count($inserts[1], "('a"));
		foreach($inserts as $insert) $this->assertLessThanOrEqual(Dump::INSERT_BYTES + 100, strlen($insert));
	}

	function testPhpExportFailureRemovesFile() {
		TestsHelper::Print("testing a failed php export removes its partial file and restores the session...");
		$this->db->streamFailsAfter = 1;
		try {
			$this->Dump()->Export("broken", ["driver" => "php"]);
			$this->fail("no exception");
		} catch(MagratheaDBException $ex) {
			$this->assertSame("lost the result", $ex->getMessage());
		}
		$this->assertFileDoesNotExist($this->folder."/broken.sql");
		$this->assertSame("SET time_zone = 'Europe/Lisbon', foreign_key_checks = 1", end($this->db->log));
	}

	function testDefaultDriverFallsBackToPhp() {
		TestsHelper::Print("testing the php driver is used when mysqldump and mysql don't answer --version...");
		$dump = new NoBinaryDump($this->db);
		$this->assertSame("php", $dump->Export("fallback")["driver"]);
		$this->assertSame(["driver" => "php", "statements" => 16], $dump->Import("fallback"));
		$this->assertSame([["mysqldump", "--version"], ["mysql", "--version"]], $dump->commands);
	}

	function testPhpImportStopsAtFirstError() {
		TestsHelper::Print("testing a php import stops at the first failing statement, naming it, and restores the session...");
		file_put_contents($this->folder."/bad.sql", "SET FOREIGN_KEY_CHECKS = 0;\r\nINSERT INTO nope VALUES (1);\nINSERT INTO t VALUES ('a;b');\n");
		$this->db->failing = ["INSERT INTO nope VALUES (1)" => 1146];
		try {
			$this->Dump()->Import("bad", ["driver" => "php"]);
			$this->fail("no exception");
		} catch(MagratheaDBException $ex) {
			$this->assertSame("import stopped at statement 2: failed: INSERT INTO nope VALUES (1)", $ex->getMessage());
			$this->assertSame(1146, $ex->getCode());
			$this->assertSame("INSERT INTO nope VALUES (1)", $ex->query);
		}
		$this->assertNotContains("INSERT INTO t VALUES ('a;b')", $this->db->log, "nothing runs after the error");
		$this->assertSame("SET time_zone = 'Europe/Lisbon', foreign_key_checks = 1", end($this->db->log));
	}

	function testPhpImportContinue() {
		TestsHelper::Print("testing a php import with continue runs everything and lists the errors...");
		file_put_contents($this->folder."/bad.sql", "SET FOREIGN_KEY_CHECKS = 0;\nINSERT INTO nope VALUES (1);\nINSERT INTO t VALUES ('a;b');\n");
		$this->db->failing = ["INSERT INTO nope VALUES (1)" => 1146];
		$rs = $this->Dump()->Import("bad", ["driver" => "php", "continue" => true]);
		$this->assertSame([
			"driver" => "php",
			"statements" => 3,
			"errors" => [["statement" => 2, "error" => "failed: INSERT INTO nope VALUES (1)"]],
		], $rs);
		$this->assertContains("INSERT INTO t VALUES ('a;b')", $this->db->log);
	}

}
