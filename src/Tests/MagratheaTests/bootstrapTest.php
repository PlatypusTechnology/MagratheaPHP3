<?php

use Magrathea\Admin\AdminCsrf;
use Magrathea\Admin\DumpManager;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Install;
use Magrathea\Bootstrap\SetupToken;
use Magrathea\Bootstrap\Start;
use Magrathea\Bootstrap\Steps;
use Magrathea\Config;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaPHP;
use Magrathea\ProjectCheck;
use Magrathea\Tests\TestsHelper;

// Database that never connects: OpenConnectionPlease() fails with $connectError; the table lookup answers with $tables
class SetupFakeDatabase extends Database {
	public $log = [];
	public $connectError = null;
	public $tables = [];
	public function Reset(): static {
		$this->SetConnectionArray(null);
		$this->log = [];
		$this->connectError = null;
		$this->tables = [];
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
		if(str_contains($sql, "FROM information_schema.TABLES")) {
			return array_map(fn($t) => ["name" => $t, "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => "3", "collation" => "", "comment" => ""], $this->tables);
		}
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
		return ["global_tz" => "SYSTEM", "session_tz" => "SYSTEM", "mysql_now" => date("Y-m-d H:i:s"), "mysql_offset" => date("Z")];
	}
}

// the admin's install, recorded
class SetupFakeInstall extends Install {
	public $calls = [];
	public $rows = [];
	public function InstallDatabase() {
		$this->calls[] = ["InstallDatabase"];
		return $this->rows;
	}
	public function CreateFirstUser($username, $password): AdminUser {
		$this->calls[] = ["CreateFirstUser", $username, $password];
		return new AdminUser();
	}
}

// the admin's Start with a controllable user count
class SetupFakeAdminStart extends \Magrathea\Admin\Start {
	public $users = 0;
	protected function Initialize() { return $this; }
	public function HasUsers(): bool { return $this->users > 0; }
}

// dumps, recorded
class SetupFakeDumpManager extends DumpManager {
	public $calls = [];
	public $importResult = null;
	public function __construct() { }
	public function Files(): array { return [["name" => "a.sql", "size" => 10, "date" => 0, "usable" => true]]; }
	public function Import($file, $confirm = null, $driver = null, bool $continue = false): array {
		$this->calls[] = ["Import", $file, $confirm, $driver, $continue];
		return $this->importResult;
	}
	public function Upload($upload, $name = null): array {
		$this->calls[] = ["Upload", $upload, $name];
		return ["file" => "up.sql", "size" => 5];
	}
}

class bootstrapTest extends \PHPUnit\Framework\TestCase {

	private $root;
	private $previousConfigPath;
	private $previousMagRoot;
	private $previousAppRoot;
	private $previousZone;
	private $previousDb;
	private SetupFakeDatabase $db;
	private SetupFakeInstall $install;
	private SetupFakeAdminStart $admin;
	private SetupFakeDumpManager $dumps;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir()."/magrathea-setup-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->root));
		mkdir($this->root."/app", 0777, true);
		$this->previousConfigPath = Config::Instance()->GetPath();
		$this->previousMagRoot = MagratheaPHP::Instance()->magRoot;
		$this->previousAppRoot = MagratheaPHP::Instance()->appRoot;
		$this->previousZone = date_default_timezone_get();
		$this->previousDb = Database::Instance();
		$this->db = SetupFakeDatabase::Instance()->Reset();
		Database::MockClass($this->db);
		$_SESSION = [];
		$_SERVER["REQUEST_URI"] = "/setup.php?step=x";

		$this->install = new SetupFakeInstall();
		$this->admin = SetupFakeAdminStart::Instance();
		$this->admin->users = 0;
		$this->dumps = new SetupFakeDumpManager();
		$start = Start::Instance()->SetMagRoot($this->root);
		$start->SetSteps(new Steps($start, $this->dumps, $this->install, $this->admin));
	}

	protected function tearDown(): void {
		Start::Instance()->SetSteps(null);
		Database::MockClass($this->previousDb);
		Config::Instance()->SetConfig(null)->SetEnvironment(null)->SetPath($this->previousConfigPath)->SetConfigFile("magrathea.conf");
		MagratheaPHP::Instance()->magRoot = $this->previousMagRoot;
		MagratheaPHP::Instance()->appRoot = $this->previousAppRoot;
		date_default_timezone_set($this->previousZone);
		$_SESSION = [];
		@chmod($this->root."/configs", 0777);
		system('rm -rf -- '.escapeshellarg($this->root));
		parent::tearDown();
	}

	private function Start(): Start {
		return Start::Instance();
	}
	private function Token(): SetupToken {
		return $this->Start()->Token();
	}
	private function Csrf(): string {
		return AdminCsrf::Instance()->GetToken();
	}

	// writes configs/magrathea.conf; the wizard reads it from disk
	private function WriteConfig(array $sections, string $environment = "dev"): void {
		@mkdir($this->root."/configs", 0777, true);
		$ini = "[general]\n\tuse_environment = \"".$environment."\"\n";
		foreach($sections as $section => $values) {
			$ini .= "[".$section."]\n";
			foreach($values as $key => $value) $ini .= "\t".$key." = ".(is_bool($value) ? ($value ? "true" : "false") : "\"".$value."\"")."\n";
		}
		file_put_contents($this->root."/configs/magrathea.conf", $ini);
		$this->Start()->ReloadConfig();
	}
	private function ReadConfig(): array {
		return parse_ini_file($this->root."/configs/magrathea.conf", true);
	}

	// a verified session: the token issued, typed, accepted
	private function VerifiedSession(): void {
		$this->Token()->Issue();
		$this->assertTrue($this->Start()->Verify($this->Token()->Token()));
		$this->assertTrue($this->Start()->IsVerified());
	}

	private function Post(array $post, array $get = []): array {
		return $this->Start()->Handle("POST", $get, $post + ["csrf" => $this->Csrf()]);
	}

	private function Render(array $page): string {
		ob_start();
		try {
			$this->Start()->Render($page);
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	private function ValidConfigForm(array $overrides = []): array {
		return array_merge([
			"action" => "save-config",
			"environment" => "dev",
			"db_host" => "localhost", "db_port" => "3306", "db_name" => "app_db", "db_user" => "app", "db_pass" => "s3cret!pass",
			"timezone" => "America/Sao_Paulo",
			"app_url" => "https://app.example.com",
			"logs_path" => $this->root."/logs", "cache_path" => $this->root."/cache", "backups_path" => $this->root."/backups",
			"jwt_key" => "abc123",
		], $overrides);
	}

	// THE LOCK (2.4.0's bootstrap_unlocked, still the maintenance mode's flag)

	private function MockConfig(array $dev, array $production = [], array $general = []) {
		Config::Instance()->SetConfig([
			"general" => array_merge(["use_environment" => "dev"], $general),
			"dev" => $dev,
			"production" => $production,
		])->SetEnvironment(null);
	}

	function testLockedWithFalsyValues() {
		TestsHelper::Print("testing the wizard's flag: missing and falsy values lock...");
		$this->MockConfig(["db_host" => "localhost"]);
		$this->assertFalse($this->Start()->IsUnlocked());
		// parse_ini_file turns a bare `false` into ""; quoted strings stay as written
		foreach(["", "0", "false", "off", "no", false, null] as $value) {
			$this->MockConfig(["bootstrap_unlocked" => $value]);
			$this->assertFalse($this->Start()->IsUnlocked(), "unlocked with ".var_export($value, true));
		}
	}

	function testOnlyTheActiveEnvironmentUnlocks() {
		TestsHelper::Print("testing the wizard's flag: only the active environment's counts...");
		$this->MockConfig([], ["bootstrap_unlocked" => "1"]);
		$this->assertFalse($this->Start()->IsUnlocked());
		$this->MockConfig([], [], ["bootstrap_unlocked" => "1"]);
		$this->assertFalse($this->Start()->IsUnlocked(), "[general]");
		$this->MockConfig(["bootstrap_unlocked" => "1"]);
		Config::Instance()->SetEnvironment("staging");
		$this->assertFalse($this->Start()->IsUnlocked(), "a missing environment");
		foreach(["1", "true", true] as $value) {
			$this->MockConfig(["bootstrap_unlocked" => $value]);
			$this->assertTrue($this->Start()->IsUnlocked(), "locked with ".var_export($value, true));
		}
	}

	function testSampleConfigUnlocksDevOnly() {
		TestsHelper::Print("testing sample config unlocks dev only...");
		$config = Config::Instance();
		$config->SetConfig(null)->SetEnvironment(null)
			->SetPath(__DIR__."/../../Bootstrap/docs")->SetConfigFile("magrathea.conf.sample");
		$this->assertSame("dev", $config->GetEnvironment());
		$this->assertTrue($this->Start()->IsUnlocked());
		$config->SetEnvironment("production");
		$this->assertFalse($this->Start()->IsUnlocked());
	}

	// ENTRY AND MODES

	function testAppPathSetsTheRootAndTheConfigPath() {
		TestsHelper::Print("testing Start::AppPath(): magRoot is the app's parent, Config reads <magRoot>/configs...");
		$this->Start()->AppPath($this->root."/app");
		$this->assertSame(realpath($this->root), MagratheaPHP::Instance()->magRoot);
		$this->assertSame(realpath($this->root)."/configs/magrathea.conf", $this->Start()->ConfigFilePath());
		$this->assertSame(realpath($this->root)."/configs", Config::Instance()->GetPath());
	}

	function testModes() {
		TestsHelper::Print("testing the wizard's modes: no config is setup, a config needs the flag...");
		$this->assertSame(Start::SETUP, $this->Start()->Mode());
		$this->WriteConfig(["dev" => ["db_host" => "x"]]);
		$this->assertSame(Start::LOCKED, $this->Start()->Mode());
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true]]);
		$this->assertSame(Start::MAINTENANCE, $this->Start()->Mode());
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true]], "production");
		$this->assertSame(Start::LOCKED, $this->Start()->Mode(), "the flag in another environment");
	}

	function testLockedAnswers403AndIssuesNoToken() {
		TestsHelper::Print("testing a locked wizard: 403, the locked page, no token...");
		$this->WriteConfig(["dev" => ["db_host" => "<b>x</b>"]]);
		$page = $this->Start()->Handle("GET");
		$this->assertSame(403, $page["status"]);
		$this->assertSame("locked", $page["view"]);
		$this->assertFileDoesNotExist($this->root."/configs/.setup-token");
		$html = $this->Render($page);
		$this->assertStringContainsString("The Setup Wizard is locked", $html);
		$this->assertStringContainsString("<b>dev</b>", $html);
		$this->assertStringNotContainsString("<b>x</b>", $html);
		$this->assertStringNotContainsString("<form", $html);
		// a locked wizard verifies nothing, even with the right token
		$this->assertFalse($this->Start()->Verify("0000-0000"));
	}

	function testSetupNeedsOnlyTheToken() {
		TestsHelper::Print("testing setup mode: no config, the token opens it...");
		$page = $this->Start()->Handle("GET");
		$this->assertSame(200, $page["status"]);
		$this->assertSame("token", $page["view"]);
		$this->assertSame(Start::SETUP, $page["mode"]);
		$this->assertFileExists($this->root."/configs/.setup-token");
		$html = $this->Render($page);
		$this->assertStringContainsString('name="token"', $html);
		$this->assertStringContainsString('name="csrf" value="'.$this->Csrf().'"', $html);
		$this->assertStringNotContainsString($this->root, $html, "no absolute path before the token");
		$this->assertStringNotContainsString($this->Token()->Token(), $html);

		$page = $this->Post(["action" => "verify", "token" => $this->Token()->Token()]);
		$this->assertSame(303, $page["status"]);
		$this->assertSame("/setup.php?step=requirements", $page["location"]);
		$this->assertTrue($this->Start()->IsVerified());
		$this->assertSame(hash("sha256", $this->Token()->Token()), $_SESSION[Start::SESSION_KEY]);
	}

	function testMaintenanceNeedsTheFlagAndTheToken() {
		TestsHelper::Print("testing maintenance mode: the flag and the token...");
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true]]);
		$page = $this->Start()->Handle("GET", ["step" => "requirements"]);
		$this->assertSame(403, $page["status"], "the flag alone isn't enough");
		$this->assertSame("token", $page["view"]);
		$this->VerifiedSession();
		$page = $this->Start()->Handle("GET", ["step" => "requirements"]);
		$this->assertSame(200, $page["status"]);
		$this->assertSame("step", $page["view"]);
		// removing the flag locks a verified session out
		$this->WriteConfig(["dev" => ["db_host" => "x"]]);
		$this->assertFalse($this->Start()->IsVerified());
		$this->assertSame(403, $this->Start()->Handle("GET", ["step" => "requirements"])["status"]);
	}

	// THE TOKEN

	function testTheTokenFile() {
		TestsHelper::Print("testing the token file: xxxx-xxxx, 0 failures, mode 0600, the folder 0750...");
		$this->assertTrue($this->Token()->Issue());
		$this->assertFalse($this->Token()->Issue(), "issued once");
		$file = $this->root."/configs/.setup-token";
		$this->assertMatchesRegularExpression('/^\d{4}-\d{4}\n0\n$/D', file_get_contents($file));
		$this->assertSame("0600", substr(sprintf("%o", fileperms($file)), -4));
		$this->assertSame("0750", substr(sprintf("%o", fileperms($this->root."/configs")), -4));
		$this->assertSame(0, $this->Token()->Failures());
	}

	function testUnwritableFolderExplainsWhatToCreate() {
		TestsHelper::Print("testing a configs folder the wizard can't write: a page that explains it...");
		if(function_exists("posix_geteuid") && posix_geteuid() === 0) $this->markTestSkipped("root writes anywhere");
		mkdir($this->root."/configs", 0555);
		$page = $this->Start()->Handle("GET");
		$this->assertSame(503, $page["status"]);
		$this->assertSame("no-folder", $page["view"]);
		$this->assertStringContainsString("mkdir -p", $this->Render($page));
	}

	function testNormalization() {
		TestsHelper::Print("testing the token's normalization: dashes and spaces are ignored...");
		foreach(["1234-5678", "12345678", " 1234 5678 ", "1234 - 5678", "12-34-56-78"] as $typed) {
			$this->assertSame("12345678", SetupToken::Normalize($typed), $typed);
		}
		foreach(["1234567", "123456789", "1234-567a", "", null, 12345678, ["12345678"], "１２３４５６７８", "1234\n5678"] as $typed) {
			$this->assertNull(SetupToken::Normalize($typed), var_export($typed, true));
		}
		$this->assertSame("0000-0042", SetupToken::Format("00000042"));
	}

	function testCheckComparesTheDigits() {
		TestsHelper::Print("testing SetupToken::Check(): both forms are accepted, a wrong one counts...");
		$this->Token()->Issue();
		$token = $this->Token()->Token();
		$digits = str_replace("-", "", $token);
		$this->assertTrue($this->Token()->Check($token));
		$this->assertTrue($this->Token()->Check($digits));
		$this->assertTrue($this->Token()->Check(substr($digits, 0, 4)." ".substr($digits, 4)));
		$this->assertSame(0, $this->Token()->Failures());
		$wrong = str_pad((string)(((int)$digits + 1) % 100000000), 8, "0", STR_PAD_LEFT);
		$this->assertFalse($this->Token()->Check($wrong));
		$this->assertFalse($this->Token()->Check("not a token"));
		$this->assertSame(2, $this->Token()->Failures());
		$this->assertSame($token, $this->Token()->Token(), "the token itself doesn't change");
	}

	function testTenFailuresLockAndDeletingTheLockIssuesANewToken() {
		TestsHelper::Print("testing 10 wrong tokens lock the wizard, and deleting the lock issues a new one...");
		$this->Start()->Handle("GET");
		$token = $this->Token()->Token();
		for($i = 1; $i < SetupToken::MAX_FAILURES; $i++) {
			$page = $this->Post(["action" => "verify", "token" => "wrong"]);
			$this->assertSame("token", $page["view"]);
			$this->assertStringContainsString((SetupToken::MAX_FAILURES - $i)." tries left", $page["error"]);
		}
		$page = $this->Post(["action" => "verify", "token" => "wrong"]);
		$this->assertSame(403, $page["status"]);
		$this->assertSame("locked-out", $page["view"]);
		$this->assertFileDoesNotExist($this->root."/configs/.setup-token");
		$this->assertFileExists($this->root."/configs/.setup-locked");

		// not even the right token, and nothing issued
		$this->assertSame("locked-out", $this->Post(["action" => "verify", "token" => $token])["view"]);
		$this->assertFalse($this->Start()->IsVerified());
		$this->assertSame("locked-out", $this->Start()->Handle("GET")["view"]);
		$this->assertFileDoesNotExist($this->root."/configs/.setup-token");
		$this->assertStringContainsString(".setup-locked", $this->Render($this->Start()->Handle("GET")));

		unlink($this->root."/configs/.setup-locked");
		$this->assertSame("token", $this->Start()->Handle("GET")["view"]);
		$this->assertFileExists($this->root."/configs/.setup-token");
		$this->assertSame(0, $this->Token()->Failures());
	}

	function testASpentTokenRejectsEvenTheRightOne() {
		TestsHelper::Print("testing a token at 10 failures (a request that waited for the lock) rejects the right one...");
		@mkdir($this->root."/configs", 0750);
		file_put_contents($this->root."/configs/.setup-token", "1234-5678\n".SetupToken::MAX_FAILURES."\n");
		$this->assertFalse($this->Token()->Check("1234-5678"));
	}

	function testATokenWithoutSessionIsntVerified() {
		TestsHelper::Print("testing a session is verified only with the token's hash...");
		$this->Token()->Issue();
		$this->assertFalse($this->Start()->IsVerified());
		$_SESSION[Start::SESSION_KEY] = "x";
		$this->assertFalse($this->Start()->IsVerified());
		$_SESSION[Start::SESSION_KEY] = $this->Token()->Token();
		$this->assertFalse($this->Start()->IsVerified(), "the token itself isn't its hash");
		$_SESSION[Start::SESSION_KEY] = ["array"];
		$this->assertFalse($this->Start()->IsVerified());
	}

	function testChangingTheTokenFileRevokesTheSession() {
		TestsHelper::Print("testing deleting or replacing the token file revokes the session...");
		$this->VerifiedSession();
		file_put_contents($this->root."/configs/.setup-token", "9999-9999\n0\n");
		if($this->Token()->Token() === "9999-9999") $this->assertFalse($this->Start()->IsVerified(), "replaced");
		$this->VerifiedSession();
		unlink($this->root."/configs/.setup-token");
		$this->assertFalse($this->Start()->IsVerified(), "deleted");
		// the next request issues a new token, which the old session doesn't have
		$page = $this->Start()->Handle("GET", ["step" => "requirements"]);
		$this->assertSame(403, $page["status"]);
		$this->assertSame("token", $page["view"]);
		$this->assertFalse($this->Start()->IsVerified());
	}

	// REQUESTS

	function testStepsAndActionsWithoutASessionGet403() {
		TestsHelper::Print("testing every step and action without a verified session: 403, nothing runs...");
		foreach(array_keys(Start::STEPS) as $step) {
			$page = $this->Start()->Handle("GET", ["step" => $step]);
			$this->assertSame(403, $page["status"], $step);
			$this->assertSame("token", $page["view"], $step);
		}
		foreach(array_keys(Start::ACTIONS) as $action) {
			$page = $this->Post(["action" => $action] + $this->ValidConfigForm(["action" => $action]));
			$this->assertSame(403, $page["status"], $action);
			$this->assertSame("token", $page["view"], $action);
		}
		$this->assertFileDoesNotExist($this->root."/configs/magrathea.conf");
		$this->assertSame([], $this->install->calls);
		$this->assertSame([], $this->dumps->calls);
		$this->assertFileExists($this->root."/configs/.setup-token", "finish didn't run");
	}

	function testPostsWithoutTheCsrfTokenAreRefused() {
		TestsHelper::Print("testing POSTs without the CSRF token are refused...");
		$this->Token()->Issue();
		$token = $this->Token()->Token();
		foreach([[], ["csrf" => ""], ["csrf" => "wrong"], ["csrf" => ["x"]]] as $csrf) {
			$page = $this->Start()->Handle("POST", [], ["action" => "verify", "token" => $token] + $csrf);
			$this->assertSame(403, $page["status"]);
			$this->assertStringContainsString("The form expired", $page["error"]);
		}
		$this->assertFalse($this->Start()->IsVerified(), "verify didn't run");
		$this->assertSame(0, $this->Token()->Failures(), "and counted nothing");

		$this->VerifiedSession();
		$page = $this->Start()->Handle("POST", [], $this->ValidConfigForm(["csrf" => "wrong"]));
		$this->assertSame(403, $page["status"]);
		$this->assertSame("step", $page["view"]);
		$this->assertFileDoesNotExist($this->root."/configs/magrathea.conf");
	}

	function testUnknownStepsAndActionsAre404() {
		TestsHelper::Print("testing unknown steps and actions: 404...");
		$this->VerifiedSession();
		foreach(["structure", "../../Start", "tools", ["x"]] as $step) {
			$this->assertSame(404, $this->Start()->Handle("GET", ["step" => $step])["status"], var_export($step, true));
		}
		foreach(["run-query", "user-reset", "users-get", "show-tables", "install-admin-preview", "database-test", "../x", "", null] as $action) {
			$this->assertSame(404, $this->Post(["action" => $action])["status"], var_export($action, true));
		}
		$this->assertSame(200, $this->Start()->Handle("GET")["status"], "no step: the first one");
	}

	function testStepOrder() {
		TestsHelper::Print("testing the steps' order...");
		$this->assertSame(["requirements", "configuration", "database", "import", "admin", "checks", "finish"], array_keys(Start::STEPS));
		foreach(Start::ACTIONS as $action => $step) {
			$this->assertArrayHasKey($step, Start::STEPS, $action);
		}
	}

	function testEveryHandlerRefusesWithoutASession() {
		TestsHelper::Print("testing every Steps handler refuses a session that isn't verified...");
		$steps = $this->Start()->Steps();
		$this->Token()->Issue();
		$calls = [
			"View" => fn() => $steps->View("requirements"),
			"Run" => fn() => $steps->Run("save-config", $this->ValidConfigForm()),
			"Requirements" => fn() => $steps->Requirements(),
			"ConfigurationForm" => fn() => $steps->ConfigurationForm(),
			"SaveConfiguration" => fn() => $steps->SaveConfiguration($this->ValidConfigForm()),
			"Database" => fn() => $steps->Database(),
			"Dumps" => fn() => $steps->Dumps(),
			"Upload" => fn() => $steps->Upload(["name" => "a.sql"]),
			"Import" => fn() => $steps->Import("a.sql", "app_db"),
			"Admin" => fn() => $steps->Admin(),
			"InstallAdmin" => fn() => $steps->InstallAdmin(),
			"CreateAdmin" => fn() => $steps->CreateAdmin("a@b.com", "123456789012", "123456789012"),
			"Checks" => fn() => $steps->Checks(),
			"FinishForm" => fn() => $steps->FinishForm(),
			"Finish" => fn() => $steps->Finish(),
		];
		// every public handler is listed
		$public = array_map(fn($m) => $m->getName(), (new \ReflectionClass(Steps::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
		$this->assertEqualsCanonicalizing(array_merge(array_keys($calls), ["__construct", "Start", "Defaults"]), $public);
		foreach($calls as $name => $call) {
			try {
				$call();
				$this->fail($name." ran without a verified session");
			} catch(MagratheaException $ex) {
				$this->assertSame(403, $ex->getCode(), $name);
			}
		}
		$this->assertFileDoesNotExist($this->root."/configs/magrathea.conf");
		$this->assertSame([], $this->install->calls);
		$this->assertSame([], $this->dumps->calls);
		$this->assertFileExists($this->root."/configs/.setup-token");
	}

	function testEveryStepRenders() {
		TestsHelper::Print("testing every step renders, without and with a config...");
		$this->VerifiedSession();
		foreach(array_keys(Start::STEPS) as $step) {
			$page = $this->Start()->Handle("GET", ["step" => $step]);
			$this->assertSame(200, $page["status"], $step);
			$this->assertNull($page["error"], $step.": ".$page["error"]);
			$html = $this->Render($page);
			$this->assertStringContainsString("<h2>".Start::STEPS[$step], $html, $step);
			$this->assertStringNotContainsString("cdn.", $html, "no external assets");
			$this->assertStringNotContainsString("<script", $html, "no JavaScript");
			$this->assertDoesNotMatchRegularExpression('/level-[A-Z]/', $html, "levels are lowercase, as the CSS");
		}
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true, "db_host" => "h", "db_name" => "app_db", "db_user" => "u",
			"backups_path" => $this->root, "timezone" => "America/Sao_Paulo"]]);
		$this->db->tables = ["<i>articles</i>", ProjectCheck::ADMIN_TABLE];
		foreach(array_keys(Start::STEPS) as $step) {
			$page = $this->Start()->Handle("GET", ["step" => $step]);
			$this->assertSame(200, $page["status"], $step);
			$html = $this->Render($page);
			$this->assertStringContainsString("<h2>".Start::STEPS[$step], $html, $step);
			$this->assertStringNotContainsString("<i>articles</i>", $html, $step.": escaped");
		}
	}

	function testNoConfigInLogModeDoesntDie() {
		TestsHelper::Print("testing every step without a config, Debugger LOG: a config exception is shown, not fatal...");
		// in LOG mode a killer MagratheaConfigException calls ErrorManager::DisplayException() from its constructor
		$previous = \Magrathea\Debugger::Instance()->GetType();
		\Magrathea\Debugger::Instance()->SetType(\Magrathea\Debugger::LOG);
		try {
			$this->VerifiedSession();
			foreach(array_keys(Start::STEPS) as $step) {
				$page = $this->Start()->Handle("GET", ["step" => $step]);
				$this->assertSame(200, $page["status"], $step);
				$this->Render($page);
			}
			$findings = array_merge(...array_column($this->Start()->Handle("GET", ["step" => "checks"])["data"]["sections"], "findings"));
			$this->assertStringContainsString("No config file", implode("\n", array_column($findings, "message")));
			$this->assertFalse(\Magrathea\Errors\ErrorManager::Instance()->ThrowErrors(false), "restored");
		} finally {
			\Magrathea\Debugger::Instance()->SetType($previous);
		}
	}

	// 1. REQUIREMENTS

	function testRequirementsNeedNoConfig() {
		TestsHelper::Print("testing the Requirements step: findings without a config...");
		$this->VerifiedSession();
		$findings = $this->Start()->Steps()->Requirements()["findings"];
		$messages = implode("\n", array_column($findings, "message"));
		$this->assertStringContainsString("PHP ".PHP_VERSION, $messages);
		$this->assertStringContainsString("The configs folder `".$this->root."/configs` exists and is writable", $messages);
	}

	// 2. CONFIGURATION

	function testSetupWritesTheConfig() {
		TestsHelper::Print("testing the Configuration step writes magrathea.conf in setup mode...");
		$this->VerifiedSession();
		$form = $this->Start()->Steps()->ConfigurationForm();
		$this->assertTrue($form["environmentEditable"]);
		$this->assertSame($this->root."/logs", $form["values"]["logs_path"]);
		$this->assertSame($this->root."/backups", $form["values"]["backups_path"]);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $form["values"]["jwt_key"]);
		$this->assertContains("Europe/Lisbon", $form["timezones"]);

		$page = $this->Post($this->ValidConfigForm(["environment" => "production", "db_pass" => "p\$ss;#=word"]));
		$this->assertSame(200, $page["status"]);
		$this->assertTrue($page["result"]["saved"], json_encode($page["result"]["errors"]));
		$config = $this->ReadConfig();
		$this->assertSame(["use_environment" => "production"], $config["general"]);
		$this->assertSame("p\$ss;#=word", $config["production"]["db_pass"]);
		$this->assertSame("America/Sao_Paulo", $config["production"]["timezone"]);
		$this->assertSame("3306", $config["production"]["db_port"]);
		$this->assertSame("1", $config["production"]["bootstrap_unlocked"], "so the next steps open");
		// now maintenance mode, and still verified
		$this->assertSame(Start::MAINTENANCE, $page["mode"]);
		$this->assertTrue($this->Start()->IsVerified());
		$this->assertSame("production", Config::Instance()->GetEnvironment());
	}

	function testMaintenanceEditsOnlyTheActiveSection() {
		TestsHelper::Print("testing the Configuration step edits only the active section in maintenance mode...");
		$this->WriteConfig([
			"dev" => ["bootstrap_unlocked" => true, "db_pass" => "old-pass", "custom_key" => "kept"],
			"production" => ["db_host" => "prod-host", "db_pass" => "prod-pass"],
		]);
		$this->VerifiedSession();
		$form = $this->Start()->Steps()->ConfigurationForm();
		$this->assertFalse($form["environmentEditable"]);
		$this->assertSame("dev", $form["environment"]);
		$this->assertSame("", $form["values"]["db_pass"], "the password is never sent back");
		$this->assertTrue($form["hasPassword"]);

		$rs = $this->Start()->Steps()->SaveConfiguration($this->ValidConfigForm(["environment" => "production", "db_pass" => ""]));
		$this->assertTrue($rs["saved"], json_encode($rs["errors"]));
		$this->assertSame("dev", $rs["environment"], "the environment can't change");
		$config = $this->ReadConfig();
		$this->assertSame(["use_environment" => "dev"], $config["general"]);
		$this->assertSame(["db_host" => "prod-host", "db_pass" => "prod-pass"], $config["production"]);
		$this->assertSame("old-pass", $config["dev"]["db_pass"], "an empty password keeps the current one");
		$this->assertSame("kept", $config["dev"]["custom_key"]);
		$this->assertSame("app_db", $config["dev"]["db_name"]);
	}

	function testValuesThatCantBeStoredAreRejected() {
		TestsHelper::Print("testing the Configuration step rejects \", line breaks and values that don't read back...");
		$this->VerifiedSession();
		$steps = $this->Start()->Steps();
		foreach(["db_pass" => "a\"b", "db_name" => "a\nb", "db_user" => "a\rb", "app_url" => "https://x/\${HOME}", "db_host" => "yes", "jwt_key" => ["x"]] as $field => $value) {
			$rs = $steps->SaveConfiguration($this->ValidConfigForm([$field => $value]));
			$this->assertFalse($rs["saved"], $field);
			$this->assertArrayHasKey($field, $rs["errors"], $field);
			$this->assertFileDoesNotExist($this->root."/configs/magrathea.conf", $field);
		}
		$this->assertArrayNotHasKey("db_pass", $steps->SaveConfiguration($this->ValidConfigForm(["db_pass" => "a\"b"]))["values"], "the password isn't sent back");
	}

	function testOtherInvalidValues() {
		TestsHelper::Print("testing the Configuration step: timezone outside the list, port, environment, partial database...");
		$this->VerifiedSession();
		$steps = $this->Start()->Steps();
		$cases = [
			["timezone", ["timezone" => "Mars/Olympus_Mons"]],
			["timezone", ["timezone" => ""]],
			["db_port", ["db_port" => "70000"]],
			["db_port", ["db_port" => "33a"]],
			["environment", ["environment" => "general"]],
			["environment", ["environment" => "my env"]],
			["environment", ["environment" => "../x"]],
			["db_name", ["db_name" => ""]],
			["app_url", ["app_url" => "javascript:alert(1)"]],
		];
		foreach($cases as [$field, $overrides]) {
			$rs = $steps->SaveConfiguration($this->ValidConfigForm($overrides));
			$this->assertFalse($rs["saved"], json_encode($overrides));
			$this->assertArrayHasKey($field, $rs["errors"], json_encode($overrides));
		}
		$this->assertFileDoesNotExist($this->root."/configs/magrathea.conf");
		// no database at all is fine
		$rs = $steps->SaveConfiguration($this->ValidConfigForm(["db_host" => "", "db_name" => "", "db_user" => "", "db_pass" => "", "db_port" => ""]));
		$this->assertTrue($rs["saved"], json_encode($rs["errors"]));
	}

	// 3. DATABASE

	function testDatabaseStep() {
		TestsHelper::Print("testing the Database step: connects, the timezone check, the tables...");
		$this->VerifiedSession();
		$this->assertTrue($this->Start()->Steps()->Database()["needsConfig"]);
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true]]);
		$rs = $this->Start()->Steps()->Database();
		$this->assertFalse($rs["configured"], "no database in the config");
		$this->assertSame([], $this->db->log);

		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true, "db_host" => "h", "db_name" => "app_db", "db_user" => "u", "timezone" => "America/Sao_Paulo"]]);
		$this->db->tables = ["articles"];
		$rs = $this->Start()->Steps()->Database();
		$this->assertTrue($rs["connected"]);
		$this->assertSame("app_db", $rs["database"]);
		$this->assertSame(["articles"], array_column($rs["tables"], "name"));
		$this->assertNotEmpty($rs["timezone"]);

		$this->db->connectError = "Access denied for user 'u'";
		$rs = $this->Start()->Steps()->Database();
		$this->assertFalse($rs["connected"]);
		$this->assertStringContainsString("Access denied", $rs["error"]);
	}

	// 4. IMPORT

	function testImportStep() {
		TestsHelper::Print("testing the Import step: through DumpManager, the first error...");
		$this->VerifiedSession();
		try {
			$this->Start()->Steps()->Import("a.sql", "app_db");
			$this->fail("imported without a config");
		} catch(MagratheaException $ex) {
			$this->assertStringContainsString("Save the configuration first", $ex->getMessage());
		}
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true, "db_host" => "h", "db_name" => "app_db", "db_user" => "u", "backups_path" => $this->root]]);
		$this->dumps->importResult = ["status" => DumpManager::OK, "file" => "a.sql", "database" => "app_db", "error" => null,
			"result" => ["driver" => "php", "statements" => 12, "errors" => [["statement" => 3, "error" => "Table 'x' exists"], ["statement" => 5, "error" => "other"]]]];
		$page = $this->Post(["action" => "import-dump", "file" => "a.sql", "confirm" => "app_db", "driver" => "", "continue" => "1"]);
		$this->assertSame(["Import", "a.sql", "app_db", null, true], $this->dumps->calls[0]);
		$this->assertSame("statement 3: Table 'x' exists", $page["result"]["firstError"]);
		$html = $this->Render($page);
		$this->assertStringContainsString("12 statements ran", $html);
		$this->assertStringContainsString("Table &#039;x&#039; exists", $html);

		$page = $this->Post(["action" => "upload-dump", "name" => "up.sql"], ["step" => "import"]);
		$this->assertSame("Upload", $this->dumps->calls[1][0]);
		$this->assertStringContainsString("Nothing ran", $this->Render($page));
	}

	// 5. ADMIN

	function testInstallAdminOnlyWhenMissing() {
		TestsHelper::Print("testing the Admin step installs the tables only when _magrathea_config is missing...");
		$this->VerifiedSession();
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true, "db_host" => "h", "db_name" => "app_db", "db_user" => "u"]]);
		$this->install->rows = [["query" => "CREATE TABLE a", "success" => true, "result" => []], ["query" => "CREATE TABLE b", "success" => false, "result" => "boom"]];
		$rs = $this->Start()->Steps()->InstallAdmin();
		$this->assertSame(2, $rs["queries"]);
		$this->assertSame([["query" => "CREATE TABLE b", "error" => "boom"]], $rs["errors"]);
		$this->db->tables = [ProjectCheck::ADMIN_TABLE];
		$page = $this->Post(["action" => "install-admin"]);
		$this->assertStringContainsString("already installed", $page["error"]);
		$this->assertCount(1, $this->install->calls);
	}

	function testCanCreateFirstUserGuardsTheFirstUser() {
		TestsHelper::Print("testing the Admin step: CanCreateFirstUser() guards the first super admin, 12+ characters...");
		$this->VerifiedSession();
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true, "db_host" => "h", "db_name" => "app_db", "db_user" => "u"]]);
		$this->db->tables = [ProjectCheck::ADMIN_TABLE];
		$steps = $this->Start()->Steps();
		$this->assertTrue($steps->Admin()["canCreateFirstUser"]);

		$this->assertArrayHasKey("email", $steps->CreateAdmin("not-an-email", "123456789012", "123456789012")["errors"]);
		$this->assertArrayHasKey("password", $steps->CreateAdmin("a@b.com", "12345678901", "12345678901")["errors"]);
		$this->assertArrayHasKey("password_confirm", $steps->CreateAdmin("a@b.com", "123456789012", "123456789013")["errors"]);
		$this->assertArrayHasKey("password", $steps->CreateAdmin("a@b.com", ["x"], null)["errors"]);
		$this->assertSame([], $this->install->calls);

		$rs = $steps->CreateAdmin(" admin@example.com ", "a long password", "a long password");
		$this->assertTrue($rs["created"]);
		$this->assertSame([["CreateFirstUser", "admin@example.com", "a long password"]], $this->install->calls);

		$this->admin->users = 1;
		$this->assertFalse($steps->Admin()["canCreateFirstUser"]);
		try {
			$steps->CreateAdmin("other@example.com", "a long password", "a long password");
			$this->fail("created a second first user");
		} catch(MagratheaException $ex) {
			$this->assertStringContainsString("already has users", $ex->getMessage());
		}
		$this->assertCount(1, $this->install->calls);
	}

	// 7. FINISH

	function testFinishRemovesTheFlagAndTheToken() {
		TestsHelper::Print("testing Finish: removes bootstrap_unlocked outside dev, deletes the token, ends the session...");
		$this->WriteConfig([
			"dev" => ["bootstrap_unlocked" => true, "db_host" => "d"],
			"production" => ["bootstrap_unlocked" => true, "db_host" => "p"],
			"staging" => ["db_host" => "s"],
		], "production");
		$this->VerifiedSession();
		$form = $this->Start()->Steps()->FinishForm();
		$this->assertSame(["dev", "production"], $form["unlocked"]);
		$this->assertTrue($form["canKeepDev"]);

		$page = $this->Post(["action" => "finish", "keep_unlocked" => "1"]);
		$this->assertSame(["production"], $page["result"]["removed"]);
		$this->assertSame(["dev"], $page["result"]["kept"]);
		$this->assertTrue($page["result"]["tokenDeleted"]);
		$config = $this->ReadConfig();
		$this->assertArrayNotHasKey("bootstrap_unlocked", $config["production"]);
		$this->assertSame("1", $config["dev"]["bootstrap_unlocked"]);
		$this->assertSame("p", $config["production"]["db_host"]);
		$this->assertFileDoesNotExist($this->root."/configs/.setup-token");
		$this->assertArrayNotHasKey(Start::SESSION_KEY, $_SESSION);
		$this->assertFalse($this->Start()->IsVerified());
		$this->assertStringContainsString("Deleted the token", $this->Render($page));
		// production is locked now
		$this->assertSame("locked", $this->Start()->Handle("GET")["view"]);
	}

	function testFinishRemovesDevsFlagUnlessKept() {
		TestsHelper::Print("testing Finish removes [dev]'s flag too unless it's kept...");
		$this->WriteConfig(["dev" => ["bootstrap_unlocked" => true]]);
		$this->VerifiedSession();
		$rs = $this->Start()->Steps()->Finish(false);
		$this->assertSame(["dev"], $rs["removed"]);
		$this->assertArrayNotHasKey("bootstrap_unlocked", $this->ReadConfig()["dev"]);
		$this->assertSame(Start::LOCKED, $this->Start()->Mode());
	}

}
