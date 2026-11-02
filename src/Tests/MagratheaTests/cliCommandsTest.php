<?php

use Magrathea\Admin\DatabaseExplorer;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Admin\QueryRunner;
use Magrathea\Cli\Commands\AdminResetPasswordCommand;
use Magrathea\Cli\Commands\CheckCommand;
use Magrathea\Cli\Commands\DbDescribeCommand;
use Magrathea\Cli\Commands\DbDumpCommand;
use Magrathea\Cli\Commands\DbImportCommand;
use Magrathea\Cli\Commands\DbQueryCommand;
use Magrathea\Cli\Commands\DbTablesCommand;
use Magrathea\Cli\Commands\HelpCommand;
use Magrathea\Cli\Commands\ModelsVerifyCommand;
use Magrathea\Cli\Commands\VersionCommand;
use Magrathea\Cli\Console;
use Magrathea\Cli\Text;
use Magrathea\Config;
use Magrathea\DB\Database;
use Magrathea\DB\Dump;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaPHP;
use Magrathea\ProjectCheck;
use Magrathea\TimezoneCheck;

// Console running the command instances in $commands, from $cwd
class CliCmdConsole extends Console {
	public static $cwd = "/";
	public static $commands = [];
	protected static function Sapi(): string { return "cli"; }
	protected static function WorkingDirectory(): string { return self::$cwd; }
	public static function Commands(): array {
		$commands = [];
		foreach(self::$commands as $command) $commands[$command->Name()] = $command;
		return ["help" => new HelpCommand($commands)] + $commands;
	}
}

// Database that records what runs: QueryStream() yields $rows, QueryMulti() succeeds, the SQL in $errors throws
class CliCmdDatabase extends Database {
	public $log = [];
	public $rows = [];
	public $affected = 0;
	public $errors = [];
	public function QueryStream(string $sql): \Generator {
		$this->log[] = "stream:".$sql;
		if(in_array($sql, $this->errors, true)) throw new MagratheaDBException("failed: ".$sql);
		foreach($this->rows as $row) yield $row;
	}
	public function QueryMulti($queries, $killable=true) {
		$this->log[] = "multi:".implode(" | ", (array)$queries);
		if(in_array($queries[0], $this->errors, true)) throw new MagratheaDBException("failed: ".$queries[0]);
		return [["query" => $queries[0], "success" => true, "result" => true]];
	}
	public function LastAffectedRows(): int {
		return $this->affected;
	}
	public function QueryAll(string $sql) {
		$this->log[] = "all:".$sql;
		return [];
	}
}

// Schema with fixed tables: name => ["type", "columns", "indexes", "create"]
class CliCmdSchema extends Schema {
	public $tables = [];
	public function __construct() {}
	public function Tables(bool $withFramework = true): array {
		$rows = [];
		foreach($this->tables as $name => $t) {
			$rows[] = ["name" => $name, "type" => $t["type"] ?? "BASE TABLE", "engine" => "InnoDB", "rows" => 3, "collation" => "", "comment" => ""];
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
	public function Columns(string $table): array { return $this->Get($table)["columns"] ?? []; }
	public function Indexes(string $table): array { return $this->Get($table)["indexes"] ?? []; }
	public function CreateStatement(string $table): string { return $this->Get($table)["create"] ?? ""; }
}

// SchemaCheck with fixed results; records what it checked
class CliCmdCheck extends SchemaCheck {
	public $models = [];
	public $modelFindings = [];
	public $timestampFindings = [];
	public $allFindings = [];
	public $checked = [];
	public function __construct() {}
	public function FindModels(): array {
		return ["models" => $this->models, "findings" => []];
	}
	public function CheckModel(string $class): array {
		$this->checked[] = "model:".$class;
		return $this->modelFindings[$class] ?? [];
	}
	public function CheckTimestamps(string $table): array {
		$this->checked[] = "timestamps:".$table;
		if(!isset($this->timestampFindings[$table])) throw new MagratheaDBException("Unknown table [".$table."]");
		return $this->timestampFindings[$table];
	}
	public function CheckAll(): array {
		$this->checked[] = "all";
		return $this->allFindings;
	}
}

// Dump that records Export() and Import() and runs nothing
class CliCmdDump extends Dump {
	public $calls = [];
	public $importResult = ["driver" => Dump::PHP, "statements" => 3];
	public function __construct() {}
	public function Export($file, array $options = []): array {
		$this->calls[] = ["export", $file, $options];
		return ["file" => Dump::ValidateBackupFileName($file), "size" => 8, "driver" => $options["driver"] ?? Dump::PHP];
	}
	public function Import($file, array $options = []): array {
		$this->calls[] = ["import", $file, $options];
		return $this->importResult;
	}
}

// ProjectCheck answering $findings
class CliCmdProjectCheck extends ProjectCheck {
	public static $findings = [];
	public static $calls = [];
	public static function Run(bool $withDatabase = true): array {
		self::$calls[] = $withDatabase;
		return self::$findings;
	}
}

// users in $users (email => AdminUser); SetNewPassword() records and refuses short passwords, without saving
class CliCmdUserControl extends AdminUserControl {
	public $users = [];
	public $set = [];
	public $saved = true;
	public function GetByEmail(string $email): AdminUser|null {
		return $this->users[$email] ?? null;
	}
	public function SetNewPassword(AdminUser $user, string $pwd) {
		if(strlen($pwd) < 8) return ["success" => false, "error" => "Password must be at least 8 chars long"];
		$this->set[] = [$user->email, $pwd];
		$user->SetPassword($pwd);
		return ["success" => $this->saved];
	}
}

class cliCommandsTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private $magRoot;
	private CliCmdDatabase $db;
	private CliCmdSchema $schema;
	private CliCmdCheck $check;
	private CliCmdDump $dump;
	private CliCmdUserControl $users;

	protected function setUp(): void {
		parent::setUp();
		$this->folder = sys_get_temp_dir()."/magrathea-cli-commands-test-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->folder));
		mkdir($this->folder."/app", 0777, true);
		mkdir($this->folder."/backups", 0777, true);
		$this->folder = realpath($this->folder);
		file_put_contents($this->folder."/composer.json", '{"name": "test/project"}');
		file_put_contents($this->folder."/app/_inc.php", "<?php\n\$GLOBALS['cliCmdBootstrap'] = (\$GLOBALS['cliCmdBootstrap'] ?? 0) + 1;\n");
		$GLOBALS["cliCmdBootstrap"] = 0;
		CliCmdConsole::$cwd = $this->folder;
		Config::Instance()->SetConfig([
			"general" => ["use_environment" => "test"],
			"test" => ["backups_path" => $this->folder."/backups"],
		])->SetEnvironment(null);

		$this->db = CliCmdDatabase::Instance();
		$this->db->log = [];
		$this->db->rows = [];
		$this->db->affected = 0;
		$this->db->errors = [];
		$this->schema = new CliCmdSchema();
		$this->check = new CliCmdCheck();
		$this->dump = new CliCmdDump();
		$this->users = new CliCmdUserControl();
		CliCmdProjectCheck::$findings = [];
		CliCmdProjectCheck::$calls = [];
		CliCmdConsole::$commands = [
			new CheckCommand(CliCmdProjectCheck::class),
			new DbTablesCommand($this->schema),
			new DbDescribeCommand(new DatabaseExplorer($this->db, $this->schema, $this->check)),
			new DbQueryCommand(new QueryRunner($this->db)),
			new DbDumpCommand($this->dump),
			new DbImportCommand($this->dump),
			new ModelsVerifyCommand($this->check),
			new AdminResetPasswordCommand($this->users),
			new VersionCommand(),
		];
		$this->magRoot = MagratheaPHP::Instance()->magRoot;
	}

	protected function tearDown(): void {
		MagratheaPHP::Instance()->magRoot = $this->magRoot;
		Config::Instance()->SetConfig(null)->SetEnvironment(null);
		unset($GLOBALS["cliCmdBootstrap"]);
		system('rm -rf -- '.escapeshellarg($this->folder));
		parent::tearDown();
	}

	// runs the console; returns [exit code, stdout, stderr]
	private function Cli(string ...$args): array {
		$out = fopen("php://memory", "w+");
		$err = fopen("php://memory", "w+");
		$code = CliCmdConsole::Run(array_merge(["magrathea"], $args), $out, $err);
		rewind($out);
		rewind($err);
		return [$code, stream_get_contents($out), stream_get_contents($err)];
	}

	private function CliJson(string ...$args): array {
		[$code, $out, $err] = $this->Cli(...array_merge($args, ["--json"]));
		$this->assertSame("", $err);
		$doc = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($code, $doc["exit"]);
		return [$code, $doc];
	}

	private static function Finding(string $level, string $message, array $extra = []): array {
		return ["level" => $level, "message" => $message] + $extra;
	}

	// the real list

	function testConsoleListsEveryCommand() {
		$this->assertSame(
			["help", "check", "db:tables", "db:describe", "db:query", "db:dump", "db:import", "models:verify", "admin:reset-password", "version"],
			array_keys(Console::Commands())
		);
	}

	function testUsageLines() {
		$usages = array_map(fn($c) => $c->Usage(), Console::Commands());
		$this->assertSame("check [--no-db]", $usages["check"]);
		$this->assertSame("db:describe <table>", $usages["db:describe"]);
		$this->assertSame("db:query <sql> [--write]", $usages["db:query"]);
		$this->assertSame("db:dump [file] [--tables=…] [--driver=…]", $usages["db:dump"]);
		$this->assertSame("db:import <file> [--yes] [--driver=…] [--continue]", $usages["db:import"]);
		$this->assertSame("models:verify [class]", $usages["models:verify"]);
		$this->assertSame("admin:reset-password <email> [--password=…]", $usages["admin:reset-password"]);
	}

	// item 25: writes need their flag

	function testAWriteQueryIsRefusedWithoutWrite() {
		[$code, $out, $err] = $this->Cli("db:query", "DELETE FROM t");
		$this->assertSame(Console::USAGE, $code);
		$this->assertSame("", $out);
		$this->assertStringContainsString("[db:query] writes: it runs only with --write", $err);
		$this->assertSame([], $this->db->log);
		$this->assertSame(0, $GLOBALS["cliCmdBootstrap"], "refused before the bootstrap");
	}

	function testAWriteQueryRunsWithWrite() {
		$this->db->affected = 4;
		[$code, $out] = $this->Cli("db:query", "DELETE FROM t", "--write");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["multi:DELETE FROM t"], $this->db->log);
		$this->assertSame("Affected rows: 4\n", $out);
	}

	function testWhatNeedsWrite() {
		foreach(["SELECT 1; SELECT 2", "SELECT * INTO OUTFILE '/tmp/x' FROM t", "UPDATE t SET a = 1", "DELIMITER"] as $sql) {
			[$code, $doc] = $this->CliJson("db:query", $sql);
			$this->assertSame(Console::USAGE, $code, $sql);
			$this->assertStringContainsString("--write", $doc["error"], $sql);
		}
		$this->assertSame([], $this->db->log);
	}

	function testReadOnlyQueriesDontNeedWrite() {
		$this->db->rows = [["id" => 1, "name" => "a"], ["id" => 2, "name" => null]];
		[$code, $out] = $this->Cli("db:query", "SELECT * FROM t");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["stream:SELECT * FROM t"], $this->db->log);
		$this->assertSame("id  name\n--  ----\n1   a\n2   NULL\n(2 rows)\n", $out);
	}

	function testWriteOnReadOnlySqlIsAccepted() {
		[$code] = $this->Cli("db:query", "SELECT 1", "--write");
		$this->assertSame(Console::OK, $code);
	}

	function testImportIsRefusedWithoutYes() {
		[$code, $doc] = $this->CliJson("db:import", "x.sql");
		$this->assertSame(Console::USAGE, $code);
		$this->assertSame("[db:import] writes: it runs only with --yes", $doc["error"]);
		$this->assertSame([], $this->dump->calls);
		$this->assertSame(0, $GLOBALS["cliCmdBootstrap"]);
	}

	function testImportRunsWithYes() {
		[$code, $out] = $this->Cli("db:import", "x.sql", "--yes");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([["import", "x.sql", []]], $this->dump->calls);
		$this->assertSame("Imported x.sql (php driver, 3 statements)\n", $out);
		$this->assertSame(1, $GLOBALS["cliCmdBootstrap"]);
	}

	function testHelpForAWriteCommandNeedsNoFlag() {
		[$code, $out] = $this->Cli("db:import", "x.sql", "--help");
		$this->assertSame(Console::OK, $code);
		$this->assertStringContainsString("Usage: magrathea db:import <file>", $out);
		$this->assertSame([], $this->dump->calls);
	}

	// db:query

	function testEmptySqlIsAUsageError() {
		[$code, $out, $err] = $this->Cli("db:query", "--", "-- just a comment");
		$this->assertSame(Console::USAGE, $code);
		$this->assertStringContainsString("magrathea: No SQL to run", $err);
		$this->assertSame(1, $GLOBALS["cliCmdBootstrap"]);
		[$code, $doc] = $this->CliJson("db:query", "-- just a comment");
		$this->assertSame(Console::USAGE, $code);
		$this->assertStringContainsString("goes after a `--`", $doc["error"], "SQL starting with a comment needs `--` before it");
	}

	function testAFailingStatementExitsWithOne() {
		$this->db->errors = ["UPDATE b SET x = 1"];
		[$code, $out] = $this->Cli("db:query", "UPDATE a SET x = 1; UPDATE b SET x = 1; UPDATE c SET x = 1", "--write");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame(["multi:UPDATE a SET x = 1", "multi:UPDATE b SET x = 1"], $this->db->log);
		$this->assertStringContainsString("> UPDATE b SET x = 1\nError: failed: UPDATE b SET x = 1", $out);
		$this->assertStringContainsString("1 statement after it didn't run.", $out);
	}

	function testQueryAsJsonIsTheRunnersResult() {
		$this->db->rows = [["id" => 1]];
		[$code, $doc] = $this->CliJson("db:query", "SELECT id FROM t");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(QueryRunner::OK, $doc["data"]["status"]);
		$this->assertTrue($doc["data"]["readOnly"]);
		$this->assertSame([["id" => 1]], $doc["data"]["statements"][0]["rows"]);
	}

	// check

	function testCheckExitsWithOneOnAnError() {
		CliCmdProjectCheck::$findings = [
			self::Finding(TimezoneCheck::OK, "PHP 8.4.", ["group" => "config"]),
			self::Finding(TimezoneCheck::ERROR, "No `logs` folder.", ["group" => "folders"]),
		];
		[$code, $out] = $this->Cli("check");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame([true], CliCmdProjectCheck::$calls);
		$this->assertSame("OK      [config] PHP 8.4.\nERROR   [folders] No `logs` folder.\n\n1 error, 1 ok\n", $out);
	}

	function testCheckWithoutTheDatabase() {
		CliCmdProjectCheck::$findings = [self::Finding(TimezoneCheck::WARNING, "w", ["group" => "config"])];
		[$code, $doc] = $this->CliJson("check", "--no-db");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([false], CliCmdProjectCheck::$calls);
		$this->assertSame(TimezoneCheck::WARNING, $doc["data"]["level"]);
		$this->assertSame(1, $doc["data"]["counts"][TimezoneCheck::WARNING]);
		$this->assertSame(CliCmdProjectCheck::$findings, $doc["data"]["findings"]);
	}

	// db:tables, db:describe

	function testDbTables() {
		$this->schema->tables = ["users" => [], "v_users" => ["type" => "VIEW"]];
		[$code, $out] = $this->Cli("db:tables");
		$this->assertSame(Console::OK, $code);
		$this->assertSame("name     type        engine  rows\n-------  ----------  ------  ----\nusers    BASE TABLE  InnoDB  3\nv_users  VIEW        InnoDB  3\n", $out);
		[, $doc] = $this->CliJson("db:tables");
		$this->assertSame("v_users", $doc["data"][1]["name"]);
	}

	function testDbDescribe() {
		$this->schema->tables = ["users" => [
			"columns" => [["name" => "id", "type" => "int(11)", "nullable" => false, "default" => null, "extra" => "auto_increment", "key" => "PRI"]],
			"indexes" => [["name" => "PRIMARY", "unique" => true, "type" => "BTREE", "columns" => ["id"]]],
			"create" => "CREATE TABLE `users` (…)",
		]];
		$this->check->models = [["class" => "App\\User", "file" => "User.php", "table" => "users", "pk" => "id", "values" => []]];
		$this->check->modelFindings["App\\User"] = [self::Finding(TimezoneCheck::ERROR, "no `created_at`")];
		$this->check->timestampFindings["users"] = [];
		[$code, $out] = $this->Cli("db:describe", "users");
		$this->assertSame(Console::OK, $code, "findings don't change the exit code");
		$this->assertStringContainsString("users (BASE TABLE)", $out);
		$this->assertStringContainsString("id    int(11)  no        NULL     auto_increment  PRI  int", $out);
		$this->assertStringContainsString("PRIMARY  yes     BTREE  id", $out);
		$this->assertStringContainsString("Models: App\\User", $out);
		$this->assertStringContainsString("ERROR   no `created_at`", $out);
		$this->assertStringContainsString("CREATE TABLE `users` (…)", $out);
		$this->assertSame([], $this->db->log, "no rows are read");

		[, $doc] = $this->CliJson("db:describe", "users");
		$this->assertArrayNotHasKey("preview", $doc["data"]);
		$this->assertSame("int", $doc["data"]["columns"][0]["magrathea_type"]);
	}

	function testDbDescribeUnknownTable() {
		[$code, $doc] = $this->CliJson("db:describe", "nope");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame("Unknown table [nope]", $doc["error"]);
	}

	function testTheExplorerStillPreviewsByDefault() {
		$this->schema->tables = ["users" => []];
		$table = (new DatabaseExplorer($this->db, $this->schema, $this->check))->Table("users");
		$this->assertArrayHasKey("preview", $table);
		$this->assertSame(["all:SELECT * FROM `users` LIMIT ".DatabaseExplorer::PREVIEW_ROWS], $this->db->log);
	}

	// db:dump, db:import

	function testDbDumpWithTheDefaultName() {
		[$code, $doc] = $this->CliJson("db:dump");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([["export", Dump::GetDefaultFileName(), []]], $this->dump->calls);
		$this->assertSame($this->folder."/backups", $doc["data"]["folder"]);
	}

	function testDbDumpOptions() {
		[$code, $out] = $this->Cli("db:dump", "mine", "--tables=a, b,,c", "--driver=php");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([["export", "mine", ["driver" => "php", "tables" => ["a", "b", "c"]]]], $this->dump->calls);
		$this->assertSame("Exported mine.sql (8 bytes, php driver) to ".$this->folder."/backups\n", $out);
	}

	function testDumpUsageErrors() {
		foreach([
			["db:dump", "--tables=,"],
			["db:dump", "--driver=sh"],
			["db:dump", "../x.sql"],
			["db:import", "/tmp/x.sql", "--yes"],
			["db:import", "x.sql", "--yes", "--driver=pg"],
		] as $args) {
			[$code] = $this->CliJson(...$args);
			$this->assertSame(Console::USAGE, $code, implode(" ", $args));
		}
		$this->assertSame([], $this->dump->calls);
	}

	function testImportOptions() {
		$this->Cli("db:import", "x.sql", "--yes", "--driver=binary", "--continue");
		$this->assertSame([["import", "x.sql", ["driver" => "binary", "continue" => true]]], $this->dump->calls);
	}

	function testImportErrorsWithContinueExitWithOne() {
		$this->dump->importResult = ["driver" => Dump::PHP, "statements" => 5, "errors" => [["statement" => 2, "error" => "Duplicate entry"]]];
		[$code, $out] = $this->Cli("db:import", "x.sql", "--yes", "--continue");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("Errors (1):\n  statement 2: Duplicate entry", $out);
	}

	// models:verify

	function testModelsVerifyEverything() {
		$this->check->allFindings = [self::Finding(TimezoneCheck::INFO, "No model maps the table `x`.")];
		[$code, $doc] = $this->CliJson("models:verify");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["all"], $this->check->checked);
		$this->assertNull($doc["data"]["class"]);
		$this->assertSame(TimezoneCheck::INFO, $doc["data"]["level"]);
	}

	function testModelsVerifyOneByShortName() {
		$this->check->models = [["class" => "App\\Models\\Article", "file" => "Article.php", "table" => "articles", "pk" => "id", "values" => []]];
		$this->check->modelFindings["App\\Models\\Article"] = [self::Finding(TimezoneCheck::ERROR, "bad type")];
		$this->check->timestampFindings["articles"] = [self::Finding(TimezoneCheck::ERROR, "no ON UPDATE", ["fix" => "ALTER TABLE …"])];
		[$code, $out] = $this->Cli("models:verify", "article");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame(["model:App\\Models\\Article", "timestamps:articles"], $this->check->checked);
		$this->assertSame("ERROR   bad type\nERROR   no ON UPDATE\n        ALTER TABLE …\n\n2 errors\n", $out);
	}

	function testModelsVerifyByFullNameAndMissingTable() {
		$this->check->models = [["class" => "App\\Article", "file" => "Article.php", "table" => "articles", "pk" => "id", "values" => []]];
		[$code, $doc] = $this->CliJson("models:verify", "\\App\\Article");
		$this->assertSame(Console::OK, $code);
		$this->assertSame("App\\Article", $doc["data"]["class"]);
		$this->assertSame(["model:App\\Article", "timestamps:articles"], $this->check->checked);
	}

	function testModelsVerifyAmbiguousShortName() {
		$this->check->models = [
			["class" => "A\\Article", "file" => "a.php", "table" => "a", "pk" => "id", "values" => []],
			["class" => "B\\Article", "file" => "b.php", "table" => "b", "pk" => "id", "values" => []],
		];
		[$code, $doc] = $this->CliJson("models:verify", "Article");
		$this->assertSame(Console::USAGE, $code);
		$this->assertSame("[Article] is ambiguous: A\\Article, B\\Article", $doc["error"]);
	}

	function testModelsVerifyAClassNotFound() {
		$this->check->modelFindings["Nope"] = [self::Finding(TimezoneCheck::ERROR, "`Nope` can't be checked: class not found")];
		[$code] = $this->CliJson("models:verify", "Nope");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame(["model:Nope"], $this->check->checked);
	}

	// admin:reset-password

	private function User(string $email, bool $active = true): AdminUser {
		$user = new AdminUser();
		$user->id = 7;
		$user->email = $email;
		$user->active = $active;
		$this->users->users[$email] = $user;
		return $user;
	}

	function testResetPasswordGeneratesOne() {
		$user = $this->User("a@b.c");
		[$code, $doc] = $this->CliJson("admin:reset-password", "a@b.c");
		$this->assertSame(Console::OK, $code);
		$password = $doc["data"]["password"];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $password);
		$this->assertTrue($doc["data"]["generated"]);
		$this->assertSame([["a@b.c", $password]], $this->users->set);
		$this->assertTrue($user->CheckPassword($password));
	}

	function testResetPasswordWithAGivenOne() {
		$this->User("a@b.c", false);
		[$code, $out] = $this->Cli("admin:reset-password", "a@b.c", "--password=long enough");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([["a@b.c", "long enough"]], $this->users->set);
		$this->assertStringNotContainsString("long enough", $out, "a given password isn't printed");
		$this->assertStringContainsString("The user is inactive", $out);
		[, $doc] = $this->CliJson("admin:reset-password", "a@b.c", "--password=long enough");
		$this->assertArrayNotHasKey("password", $doc["data"]);
	}

	function testResetPasswordFailures() {
		[$code, $doc] = $this->CliJson("admin:reset-password", "who@b.c");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame("No admin user with the e-mail [who@b.c]", $doc["error"]);

		$this->User("a@b.c");
		[$code, $doc] = $this->CliJson("admin:reset-password", "a@b.c", "--password=short");
		$this->assertSame(Console::USAGE, $code);
		$this->assertSame("Password must be at least 8 chars long", $doc["error"]);

		$this->users->saved = false;
		[$code] = $this->CliJson("admin:reset-password", "a@b.c");
		$this->assertSame(Console::FAILED, $code);
	}

	// version

	function testVersion() {
		file_put_contents($this->folder."/version", "1.2.3\n");
		MagratheaPHP::Instance()->magRoot = $this->folder;
		[$code, $doc] = $this->CliJson("version");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["magrathea" => MagratheaPHP::Version(), "app" => "1.2.3", "php" => PHP_VERSION], $doc["data"]);
		[, $out] = $this->Cli("version");
		$this->assertSame("MagratheaPHP ".MagratheaPHP::Version()."\nApp 1.2.3\nPHP ".PHP_VERSION."\n", $out);
	}

	// Text

	function testTextTable() {
		$this->assertSame("(no rows)", Text::Table([]));
		$this->assertSame("a     b\n----  --\nx\\ny  ok\n",
			Text::Table([["a" => "x\ny", "b" => "ok"]])."\n");
		$this->assertSame("é  b\n-  -\né  1", Text::Table([["é" => "é", "b" => 1]]), "multibyte widths");
	}

	function testTextFindings() {
		$this->assertSame("No findings.", Text::Findings([]));
		$this->assertSame(Console::OK, Text::ExitCode([self::Finding(TimezoneCheck::WARNING, "w")]));
		$this->assertSame(Console::FAILED, Text::ExitCode([self::Finding(TimezoneCheck::ERROR, "e")]));
		$this->assertSame(TimezoneCheck::OK, Text::WorstLevel([]));
	}

}
