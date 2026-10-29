<?php

use Magrathea\Config;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Logger;
use Magrathea\MagratheaPHP;
use Magrathea\ProjectCheck;
use Magrathea\Tests\TestsHelper;
use Magrathea\TimezoneCheck;

// ProjectCheck with the environment overridden: PHP's version, extensions, functions and the web root
class TestableProjectCheck extends ProjectCheck {
	public static $php = "8.3.0";
	public static $extensions = ["mysqli"];
	public static $functions = ["proc_open"];
	public static $documentRoot = null;
	protected static function PhpVersion(): string { return self::$php; }
	protected static function HasExtension(string $name): bool { return in_array($name, self::$extensions, true); }
	protected static function HasFunction(string $name): bool { return in_array($name, self::$functions, true); }
	protected static function DocumentRoot(): ?string { return self::$documentRoot; }
}

// Database that never connects: OpenConnectionPlease() fails with $connectError, the table lookup answers
// with $tables, the timezone query with $clock, QueryAll() with nothing (or fails with $queryAllError)
class ProjectCheckFakeDatabase extends Database {
	public $log = [];
	public $connectError = null;
	public $queryAllError = null;
	public $tables = [];
	public $clock = [];
	public function Reset(): static {
		$this->SetConnectionArray(null);
		$this->log = [];
		$this->connectError = null;
		$this->queryAllError = null;
		$this->tables = [ProjectCheck::ADMIN_TABLE];
		$this->clock = ["global_tz" => "SYSTEM", "session_tz" => "SYSTEM", "mysql_now" => "2026-10-29 10:00:00", "mysql_offset" => date("Z")];
		return $this;
	}
	public function OpenConnectionPlease(): bool {
		$this->log[] = "connect";
		if($this->connectError) throw new MagratheaDBException($this->connectError);
		return true;
	}
	public function Escape($value): string {
		return addslashes((string)$value);
	}
	public function QueryAll(string $sql) {
		$this->log[] = $sql;
		if($this->queryAllError) throw new MagratheaDBException($this->queryAllError);
		return [];
	}
	public function QueryRow(string $sql): array|object {
		$this->log[] = $sql;
		if(str_starts_with($sql, "SELECT TABLE_NAME AS `name` FROM information_schema.TABLES")) {
			foreach($this->tables as $t) {
				if(str_ends_with($sql, "TABLE_NAME = '".$t."'")) return ["name" => $t];
			}
			return [];
		}
		return $this->clock;
	}
}

class projectCheckTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private $previousDb;
	private $previousConfigPath;
	private $previousMagRoot;
	private $previousCodeFolder;
	private $previousZone;
	private ProjectCheckFakeDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->folder = __DIR__."/test-dump/project-check";
		system('rm -rf -- '.escapeshellarg($this->folder));
		foreach(["configs", "logs", "cache", "backups", "web"] as $dir) mkdir($this->folder."/".$dir, 0777, true);
		$this->folder = realpath($this->folder);

		TestableProjectCheck::$php = "8.3.0";
		TestableProjectCheck::$extensions = ["mysqli"];
		TestableProjectCheck::$functions = ["proc_open"];
		TestableProjectCheck::$documentRoot = null;

		$this->previousZone = date_default_timezone_get();
		date_default_timezone_set("America/Sao_Paulo");
		$this->previousDb = Database::Instance();
		$this->db = ProjectCheckFakeDatabase::Instance()->Reset();
		Database::MockClass($this->db);
		$this->previousConfigPath = Config::Instance()->GetPath();
		$this->previousMagRoot = MagratheaPHP::Instance()->magRoot;
		$this->previousCodeFolder = MagratheaPHP::Instance()->codeFolder;
		// no models: SchemaCheck reports that, and nothing else
		MagratheaPHP::Instance()->codeFolder = [];
	}

	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		Config::Instance()->SetConfig(null)->SetEnvironment(null)->SetPath($this->previousConfigPath)->SetConfigFile("magrathea.conf");
		MagratheaPHP::Instance()->magRoot = $this->previousMagRoot;
		MagratheaPHP::Instance()->codeFolder = $this->previousCodeFolder;
		Logger::Instance()->SetLogPath(null)->SetLogFile(null);
		date_default_timezone_set($this->previousZone);
		@chmod($this->folder."/backups", 0777);
		system('rm -rf -- '.escapeshellarg($this->folder));
		parent::tearDown();
	}

	private function Healthy(): array {
		return [
			"db_host" => "localhost", "db_name" => "test_db", "db_user" => "user", "db_pass" => "pass",
			"timezone" => "America/Sao_Paulo",
			"logs_path" => $this->folder."/logs",
			"cache_path" => $this->folder."/cache",
			"backups_path" => $this->folder."/backups",
		];
	}

	// writes configs/magrathea.conf with [general] and the sections, and loads it from disk
	private function WriteConfig(array $dev, array $general = ["use_environment" => "dev"], array $sections = []): void {
		$ini = "";
		foreach(["general" => $general, "dev" => $dev] + $sections as $section => $values) {
			$ini .= "[".$section."]\n";
			foreach($values as $key => $value) $ini .= $key." = \"".$value."\"\n";
		}
		file_put_contents($this->folder."/configs/magrathea.conf", $ini);
		$this->LoadConfig();
	}
	private function LoadConfig(): void {
		Config::Instance()->SetConfig(null)->SetEnvironment(null)->SetPath($this->folder."/configs")->SetConfigFile("magrathea.conf");
		// it caches the path it loads
		Logger::Instance()->SetLogPath(null);
	}

	private function GroupsOf(array $findings): array {
		return array_values(array_unique(array_map(fn($f) => $f["group"], $findings)));
	}
	private function InGroup(array $findings, string $group): array {
		return array_values(array_filter($findings, fn($f) => $f["group"] === $group));
	}
	private function With(array $findings, string $text): array {
		return array_values(array_filter($findings, fn($f) => str_contains($f["message"], $text)));
	}
	private function Levels(array $findings): array {
		return array_map(fn($f) => $f["level"], $findings);
	}
	private function AssertOne(array $findings, string $text, string $level, ?string $group = null): array {
		$found = $this->With($findings, $text);
		$this->assertCount(1, $found, "one finding with [".$text."] in ".json_encode($findings));
		$this->assertSame($level, $found[0]["level"], $found[0]["message"]);
		if($group) $this->assertSame($group, $found[0]["group"]);
		return $found[0];
	}

	// A HEALTHY PROJECT

	function testHealthyProject() {
		TestsHelper::Print("testing ProjectCheck::Run() on a healthy project: every group, no error...");
		$this->WriteConfig($this->Healthy());
		$findings = TestableProjectCheck::Run();
		$this->assertSame(["config", "folders", "database", "timezone", "schema"], $this->GroupsOf($findings));
		$this->assertNotContains(TimezoneCheck::ERROR, $this->Levels($findings), json_encode($findings));
		foreach($findings as $f) {
			$this->assertContains($f["level"], [TimezoneCheck::OK, TimezoneCheck::INFO, TimezoneCheck::WARNING, TimezoneCheck::ERROR]);
		}
		// a passing check is an OK: the list reads as a checklist
		$this->AssertOne($findings, "PHP 8.3.0", TimezoneCheck::OK, "config");
		$this->AssertOne($findings, "`mysqli`", TimezoneCheck::OK, "config");
		$this->AssertOne($findings, "Logs: `".$this->folder."/logs` exists and is writable", TimezoneCheck::OK, "folders");
		$this->AssertOne($findings, "Connected to the database", TimezoneCheck::OK, "database");
		$this->AssertOne($findings, "admin's tables are installed", TimezoneCheck::OK, "database");
		$this->AssertOne($findings, "PHP and MySQL are in sync", TimezoneCheck::OK, "timezone");
		// the only warning: no models in the (empty) code folders
		$this->AssertOne($findings, "No model found", TimezoneCheck::WARNING, "schema");
	}

	// CONFIG

	function testOldPhp() {
		TestsHelper::Print("testing ProjectCheck: PHP older than 8.1 is an ERROR...");
		$this->WriteConfig($this->Healthy());
		TestableProjectCheck::$php = "8.0.30";
		$this->AssertOne(TestableProjectCheck::Run(false), "needs PHP 8.1.0 or later", TimezoneCheck::ERROR, "config");
	}

	function testNoProcOpen() {
		TestsHelper::Print("testing ProjectCheck: no proc_open() is an INFO...");
		$this->WriteConfig($this->Healthy());
		TestableProjectCheck::$functions = [];
		$this->AssertOne(TestableProjectCheck::Run(false), "`proc_open()` is disabled", TimezoneCheck::INFO, "config");
	}

	function testNoConfigFileStopsTheChecks() {
		TestsHelper::Print("testing ProjectCheck: no config file is an ERROR, and nothing else runs...");
		$this->LoadConfig();
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "No config file", TimezoneCheck::ERROR, "config");
		$this->assertSame(["config"], $this->GroupsOf($findings));
		$this->assertSame([], $this->db->log);
	}

	function testUnparsableConfig() {
		TestsHelper::Print("testing ProjectCheck: a config that can't be parsed is an ERROR...");
		file_put_contents($this->folder."/configs/magrathea.conf", "[dev\nthis = \"isn't\nini");
		$this->LoadConfig();
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "can't be parsed", TimezoneCheck::ERROR, "config");
		$this->assertSame(["config"], $this->GroupsOf($findings));
	}

	function testUseEnvironment() {
		TestsHelper::Print("testing ProjectCheck: use_environment missing or naming no section is an ERROR...");
		$this->WriteConfig($this->Healthy(), ["other" => "1"]);
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "no `use_environment`", TimezoneCheck::ERROR, "config");
		$this->assertSame(["config"], $this->GroupsOf($findings));

		$this->WriteConfig($this->Healthy(), ["use_environment" => "staging"]);
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "no `[staging]` section", TimezoneCheck::ERROR, "config");
		$this->assertSame(["config"], $this->GroupsOf($findings));
	}

	function testActiveEnvironmentOverride() {
		TestsHelper::Print("testing ProjectCheck: an active environment (SetEnvironment()) with no section is an ERROR...");
		$this->WriteConfig($this->Healthy());
		Config::Instance()->SetEnvironment("staging");
		$this->AssertOne(TestableProjectCheck::Run(), "active environment is `staging`", TimezoneCheck::ERROR, "config");
	}

	function testNoDatabase() {
		TestsHelper::Print("testing ProjectCheck: no db keys is a project without a database (INFO, database checks skipped)...");
		$env = $this->Healthy();
		unset($env["db_host"], $env["db_name"], $env["db_user"]);
		$this->WriteConfig($env);
		TestableProjectCheck::$extensions = [];
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "No database configured", TimezoneCheck::INFO, "config");
		$this->assertSame([], $this->With($findings, "mysqli"));
		$this->assertSame(["config", "folders"], $this->GroupsOf($findings));
		$this->assertNotContains(TimezoneCheck::ERROR, $this->Levels($findings));
		$this->assertSame([], $this->db->log);
	}

	function testPartialDatabase() {
		TestsHelper::Print("testing ProjectCheck: a partly configured database is an ERROR per missing key...");
		$env = $this->Healthy();
		unset($env["db_name"], $env["db_user"]);
		$this->WriteConfig($env);
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "`db_name` is empty", TimezoneCheck::ERROR, "config");
		$this->AssertOne($findings, "`db_user` is empty", TimezoneCheck::ERROR, "config");
		$this->assertSame([], $this->With($findings, "`db_host` is empty"));
		$this->assertSame(["config", "folders"], $this->GroupsOf($findings));
		$this->assertSame([], $this->db->log);
	}

	function testNoMysqli() {
		TestsHelper::Print("testing ProjectCheck: a configured database without mysqli is an ERROR, and nothing connects...");
		$this->WriteConfig($this->Healthy());
		TestableProjectCheck::$extensions = [];
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "`mysqli` extension isn't loaded", TimezoneCheck::ERROR, "config");
		$this->assertSame(["config", "folders"], $this->GroupsOf($findings));
		$this->assertSame([], $this->db->log);
	}

	function testTimezoneReportedOnce() {
		TestsHelper::Print("testing ProjectCheck: an invalid timezone is one ERROR, in config, even without the database...");
		$env = ["timezone" => "America/Sao_Pualo"] + $this->Healthy();
		$this->WriteConfig($env);
		$this->AssertOne(TestableProjectCheck::Run(), "is not a valid timezone", TimezoneCheck::ERROR, "config");
		$this->AssertOne(TestableProjectCheck::Run(false), "is not a valid timezone", TimezoneCheck::ERROR, "config");

		$env = $this->Healthy();
		unset($env["timezone"]);
		$this->WriteConfig($env);
		$this->AssertOne(TestableProjectCheck::Run(false), "No `timezone` in the config", TimezoneCheck::INFO, "config");
	}

	function testBootstrapUnlocked() {
		TestsHelper::Print("testing ProjectCheck: bootstrap_unlocked outside dev is a WARNING...");
		$this->WriteConfig($this->Healthy());
		$this->AssertOne(TestableProjectCheck::Run(false), "Setup Wizard is locked", TimezoneCheck::OK, "config");

		$this->WriteConfig(["bootstrap_unlocked" => "true"] + $this->Healthy());
		$this->AssertOne(TestableProjectCheck::Run(false), "Setup Wizard is unlocked", TimezoneCheck::INFO, "config");

		$this->WriteConfig([], ["use_environment" => "production"], ["production" => ["bootstrap_unlocked" => "1"] + $this->Healthy()]);
		$this->AssertOne(TestableProjectCheck::Run(false), "remove `bootstrap_unlocked` from `[production]`", TimezoneCheck::WARNING, "config");
	}

	// FOLDERS

	function testMissingFolders() {
		TestsHelper::Print("testing ProjectCheck: a missing logs folder is an ERROR, cache and backups are WARNINGs...");
		$env = [
			"logs_path" => $this->folder."/nope-logs",
			"cache_path" => $this->folder."/nope-cache",
			"backups_path" => $this->folder."/nope-backups",
		] + $this->Healthy();
		$this->WriteConfig($env);
		$findings = TestableProjectCheck::Run(false);
		$this->AssertOne($findings, "Logs: `".$this->folder."/nope-logs` doesn't exist", TimezoneCheck::ERROR, "folders");
		$this->AssertOne($findings, "Cache: `".$this->folder."/nope-cache` doesn't exist", TimezoneCheck::WARNING, "folders");
		$this->AssertOne($findings, "Backups: `".$this->folder."/nope-backups` doesn't exist", TimezoneCheck::WARNING, "folders");
	}

	function testFolderNotWritable() {
		TestsHelper::Print("testing ProjectCheck: a folder that isn't writable is reported...");
		chmod($this->folder."/backups", 0555);
		if(is_writable($this->folder."/backups")) $this->markTestSkipped("running as a user that writes anywhere");
		$this->WriteConfig($this->Healthy());
		$this->AssertOne(TestableProjectCheck::Run(false), "Backups: `".$this->folder."/backups` isn't writable", TimezoneCheck::WARNING, "folders");
	}

	function testFolderDefaults() {
		TestsHelper::Print("testing ProjectCheck: logs and backups fall back to their defaults, an empty cache_path is a WARNING...");
		$env = $this->Healthy();
		unset($env["logs_path"], $env["cache_path"], $env["backups_path"]);
		MagratheaPHP::Instance()->magRoot = $this->folder;
		$this->WriteConfig($env);
		$findings = TestableProjectCheck::Run(false);
		$this->AssertOne($findings, "Logs: `".$this->folder."/logs` (the default: `logs_path` isn't set) exists", TimezoneCheck::OK, "folders");
		$this->AssertOne($findings, "Backups: `".$this->folder."/backups` (the default: `backups_path` isn't set) exists", TimezoneCheck::OK, "folders");
		$this->AssertOne($findings, "`cache_path` isn't set", TimezoneCheck::WARNING, "folders");
	}

	function testConfigsUnderDocumentRoot() {
		TestsHelper::Print("testing ProjectCheck: configs/ under the web root is a WARNING, and the CLI doesn't check it...");
		$this->WriteConfig($this->Healthy());
		$this->assertSame([], $this->With(TestableProjectCheck::Run(false), "document root"));

		TestableProjectCheck::$documentRoot = $this->folder;
		$this->AssertOne(TestableProjectCheck::Run(false), "is under the document root", TimezoneCheck::WARNING, "folders");

		TestableProjectCheck::$documentRoot = $this->folder."/web";
		$this->AssertOne(TestableProjectCheck::Run(false), "outside the document root", TimezoneCheck::OK, "folders");
	}

	// DATABASE

	function testWithoutDatabaseNeverConnects() {
		TestsHelper::Print("testing ProjectCheck::Run(false): no database, timezone or schema groups, and no query...");
		$this->WriteConfig($this->Healthy());
		$findings = TestableProjectCheck::Run(false);
		$this->assertSame(["config", "folders"], $this->GroupsOf($findings));
		$this->assertSame([], $this->db->log);
	}

	function testConnectsWithTheConfig() {
		TestsHelper::Print("testing ProjectCheck: a database with no connection details connects with the config's...");
		$this->WriteConfig($this->Healthy());
		$this->assertNull($this->db->getDatabaseName());
		TestableProjectCheck::Run();
		$this->assertSame("test_db", $this->db->getDatabaseName());
		$this->assertSame("connect", $this->db->log[0]);
	}

	function testConnectionFails() {
		TestsHelper::Print("testing ProjectCheck: a failed connection is one ERROR, and the timezone and schema checks are skipped...");
		$this->WriteConfig($this->Healthy());
		$this->db->connectError = "Access denied for user 'user'";
		$findings = TestableProjectCheck::Run();
		$this->AssertOne($findings, "Can't connect to the database: Access denied", TimezoneCheck::ERROR, "database");
		$this->assertSame(["config", "folders", "database"], $this->GroupsOf($findings));
		$this->assertCount(1, array_filter($findings, fn($f) => $f["level"] === TimezoneCheck::ERROR));
	}

	function testAdminTablesMissing() {
		TestsHelper::Print("testing ProjectCheck: no _magrathea_config is a WARNING...");
		$this->WriteConfig($this->Healthy());
		$this->db->tables = [];
		$finding = $this->AssertOne(TestableProjectCheck::Run(), "the admin isn't installed", TimezoneCheck::WARNING, "database");
		$this->assertSame("_magrathea_config", $finding["table"]);
	}

	function testClocksOutOfSync() {
		TestsHelper::Print("testing ProjectCheck: PHP and MySQL out of sync is an ERROR, in timezone...");
		$this->WriteConfig($this->Healthy());
		$this->db->clock["mysql_offset"] = 0;
		$this->AssertOne(TestableProjectCheck::Run(), "PHP and MySQL are out of sync", TimezoneCheck::ERROR, "timezone");
	}

	function testSchemaCheckFails() {
		TestsHelper::Print("testing ProjectCheck: a SchemaCheck that throws is one ERROR, in schema...");
		$this->WriteConfig($this->Healthy());
		$this->db->queryAllError = "SELECT command denied";
		$this->AssertOne(TestableProjectCheck::Run(), "can't be checked against the database: SELECT command denied", TimezoneCheck::ERROR, "schema");
	}

	// TIMEZONECHECK, SPLIT

	function testTimezoneCheckRunIsBothHalves() {
		TestsHelper::Print("testing TimezoneCheck::Run() is ConfigFindings() and DatabaseFindings(), and ConfigFindings() doesn't connect...");
		$this->WriteConfig($this->Healthy());
		$config = TimezoneCheck::ConfigFindings();
		$this->assertSame([], $this->db->log);
		// messages carry now(): compared by level
		$this->assertSame(
			$this->Levels(array_merge($config, TimezoneCheck::DatabaseFindings())),
			$this->Levels(TimezoneCheck::Run())
		);
	}

}
