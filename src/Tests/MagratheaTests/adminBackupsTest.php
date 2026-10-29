<?php

use Magrathea\Admin\Api\DatabaseApi;
use Magrathea\Config;
use Magrathea\DB\Database;
use Magrathea\DB\Dump;
use Magrathea\Exceptions\MagratheaConfigException;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaPHP;
use Magrathea\Tests\TestsHelper;

// Dump with a recorded RunProcess(): no mysqldump or mysql needed
class FakeBinaryDump extends Dump {
	public $commands = [];
	public $stdin = [];
	public $versionChecks = [];
	public $defaultsSeen = [];
	public $installed = true;
	public $exitCode = 0;
	public $stderr = "mysqldump: Got error: 1045";
	public $writeResult = true;
	protected function RunProcess(array $command, ?string $stdinFile = null): array {
		if(($command[1] ?? null) === "--version") {
			$this->versionChecks[] = $command[0];
			return ["code" => $this->installed ? 0 : 127, "stdout" => "", "stderr" => ""];
		}
		$this->commands[] = $command;
		$this->stdin[] = $stdinFile;
		$defaults = substr($command[1], strlen("--defaults-extra-file="));
		$this->defaultsSeen[] = [
			"file" => $defaults,
			"content" => @file_get_contents($defaults),
			"perms" => @fileperms($defaults) & 0777,
		];
		foreach($command as $arg) {
			if($this->writeResult && str_starts_with($arg, "--result-file=")) {
				file_put_contents(substr($arg, strlen("--result-file=")), "-- dump\n");
			}
		}
		return ["code" => $this->exitCode, "stdout" => "", "stderr" => $this->exitCode ? $this->stderr : ""];
	}
	// the real one, for the no-shell tests
	public function RealRunProcess(array $command, ?string $stdinFile = null): array {
		return parent::RunProcess($command, $stdinFile);
	}
}

// Database answering Schema::Tables() with $tables
class BackupTablesDatabase extends Database {
	public $tables = [];
	public function QueryAll(string $sql) {
		return array_map(fn($t) => ["name" => $t, "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => "1", "collation" => null, "comment" => ""], $this->tables);
	}
}

// the backup API with the fake Dump
class FakeDumpDatabaseApi extends DatabaseApi {
	public $dump;
	protected function GetDump(): Dump {
		return $this->dump;
	}
}

class adminBackupsTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private $magRoot;
	private $db = [
		"db_host" => "localhost",
		"db_name" => "magrathea",
		"db_user" => "root",
		"db_pass" => "s3cr3t-pass",
		"db_port" => "3306",
	];

	protected function setUp(): void {
		parent::setUp();
		$this->folder = sys_get_temp_dir()."/magrathea-backups-test-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->folder));
		mkdir($this->folder);
		$this->SetDbConfig($this->db);
		$this->magRoot = MagratheaPHP::Instance()->magRoot;
	}
	protected function tearDown(): void {
		MagratheaPHP::Instance()->magRoot = $this->magRoot;
		system('rm -rf -- '.escapeshellarg($this->folder));
		Config::Instance()->SetConfig(null)->SetEnvironment(null);
		parent::tearDown();
	}

	private function SetDbConfig(array $db) {
		Config::Instance()->SetConfig([
			"general" => ["use_environment" => "test"],
			"test" => $db + ["backups_path" => $this->folder],
		])->SetEnvironment(null);
	}
	private function Fake(?Database $db = null): FakeBinaryDump {
		return new FakeBinaryDump($db);
	}
	private function Command(string $destination, array $tables = []): array {
		return array_merge([
			"--single-transaction",
			"--routines",
			"--triggers",
			"--default-character-set=utf8mb4",
			"--result-file=".$destination,
			"magrathea",
		], $tables);
	}

	function testInvalidNamesAreRejected() {
		TestsHelper::Print("testing backup file names that could leave the folder or reach a shell are rejected...");
		foreach(["../x", "a/b.sql", "x;rm -rf /", "`id`", ".sql", "", "a\\b.sql", "a..b.sql", "-opt.sql", "x.sql\0"] as $name) {
			try {
				Dump::ValidateBackupFileName($name);
				$this->fail("accepted: ".$name);
			} catch(MagratheaException $ex) {
				$this->assertSame("invalid file name", $ex->getMessage(), $name);
			}
		}
		$this->expectException(MagratheaException::class);
		Dump::ValidateBackupFileName(["x.sql"]);
	}

	function testValidNames() {
		TestsHelper::Print("testing backup file names get .sql appended...");
		$this->assertSame("backup_20261023.sql", Dump::ValidateBackupFileName("backup_20261023"));
		$this->assertSame("backup_20261023.sql", Dump::ValidateBackupFileName("backup_20261023.sql"));
		$this->assertSame("my-db.2026.sql", Dump::ValidateBackupFileName("my-db.2026"));
		$this->assertSame("backup_".date("Ymd").".sql", Dump::GetDefaultFileName());
	}

	function testFolder() {
		TestsHelper::Print("testing Dump::Folder() is backups_path, or <magRoot>/backups...");
		$this->assertSame($this->folder, Dump::Folder());
		Config::Instance()->SetConfig(["general" => ["use_environment" => "test"], "test" => $this->db])->SetEnvironment(null);
		MagratheaPHP::Instance()->magRoot = "/srv/app";
		$this->assertSame("/srv/app/backups", Dump::Folder());
		MagratheaPHP::Instance()->magRoot = "";
		$this->expectException(MagratheaConfigException::class);
		Dump::Folder();
	}

	function testExportRunsWithoutShellOrPassword() {
		TestsHelper::Print("testing Export() runs mysqldump with an argument array and the password in a 0600 defaults file...");
		$dump = $this->Fake();
		$rs = $dump->Export("backup_20261023");
		$this->assertSame(["file" => "backup_20261023.sql", "size" => 8, "driver" => "binary"], $rs);
		$this->assertSame(["mysqldump"], $dump->versionChecks);
		$this->assertCount(1, $dump->commands);
		$command = $dump->commands[0];
		$this->assertSame("mysqldump", $command[0]);
		$this->assertStringStartsWith("--defaults-extra-file=", $command[1]);
		$this->assertSame($this->Command($this->folder."/backup_20261023.sql"), array_slice($command, 2));
		$this->assertSame([null], $dump->stdin);
		foreach($command as $arg) {
			$this->assertStringNotContainsString("s3cr3t-pass", $arg);
		}
		$seen = $dump->defaultsSeen[0];
		$this->assertSame(0600, $seen["perms"]);
		$this->assertSame(
			"[client]\nuser=\"root\"\npassword=\"s3cr3t-pass\"\nhost=\"localhost\"\nport=\"3306\"\n",
			$seen["content"]
		);
		$this->assertFileDoesNotExist($seen["file"], "defaults file deleted after success");
		$this->assertFileExists($this->folder."/backup_20261023.sql");
	}

	function testExportOnlyGivenTables() {
		TestsHelper::Print("testing Export() with tables passes the names the database has, and rejects unknown ones before running anything...");
		$db = BackupTablesDatabase::Instance();
		$db->tables = ["articles", "Users"];
		$dump = $this->Fake($db);
		$dump->Export("some", ["tables" => ["articles", "users", "articles"]]);
		$this->assertSame($this->Command($this->folder."/some.sql", ["articles", "Users"]), array_slice($dump->commands[0], 2));

		foreach([["--result-file=/tmp/x"], [], "articles", [["articles"]]] as $tables) {
			$dump = $this->Fake($db);
			try {
				$dump->Export("other", ["tables" => $tables]);
				$this->fail("accepted tables: ".json_encode($tables));
			} catch(MagratheaException $ex) {
				$this->assertSame([], $dump->commands);
			}
		}
		$this->assertFileDoesNotExist($this->folder."/other.sql");
	}

	function testExistingFileIsRefused() {
		TestsHelper::Print("testing Export() refuses to overwrite a backup...");
		$dump = $this->Fake();
		file_put_contents($this->folder."/old.sql", "keep me");
		try {
			$dump->Export("old");
			$this->fail("overwrote old.sql");
		} catch(MagratheaException $ex) {
			$this->assertSame("file already exists", $ex->getMessage());
		}
		$this->assertSame([], $dump->commands);
		$this->assertSame("keep me", file_get_contents($this->folder."/old.sql"));
	}

	function testFailedDumpRemovesPartialFile() {
		TestsHelper::Print("testing a failed mysqldump removes the partial file and the defaults file, and throws...");
		$dump = $this->Fake();
		$dump->exitCode = 2;
		try {
			$dump->Export("broken");
			$this->fail("no exception");
		} catch(MagratheaException $ex) {
			$this->assertSame("mysqldump failed (exit code 2): mysqldump: Got error: 1045", $ex->getMessage());
		}
		$this->assertFileDoesNotExist($this->folder."/broken.sql");
		$this->assertFileDoesNotExist($dump->defaultsSeen[0]["file"], "defaults file deleted after failure");
	}

	function testDefaultsFileQuoting() {
		TestsHelper::Print("testing the defaults file quotes values and doubles backslashes...");
		$this->SetDbConfig(["db_pass" => 'a"b\\c', "db_user" => "o'neil", "db_port" => null] + $this->db);
		$dump = $this->Fake();
		$dump->Export("quotes");
		$this->assertSame(
			"[client]\nuser=\"o'neil\"\npassword='a\"b\\\\c'\nhost=\"localhost\"\n",
			$dump->defaultsSeen[0]["content"]
		);
	}

	function testDefaultsFileRejectsUnquotableValues() {
		TestsHelper::Print("testing a password with both quote characters or a line break throws, without running mysqldump...");
		foreach(['a"b\'c', "a\nb"] as $pass) {
			$this->SetDbConfig(["db_pass" => $pass] + $this->db);
			$dump = $this->Fake();
			try {
				$dump->Export("nope");
				$this->fail("accepted the password");
			} catch(MagratheaException $ex) {
				$this->assertStringContainsString("password", $ex->getMessage());
			}
			$this->assertSame([], $dump->commands);
			$this->assertFileDoesNotExist($this->folder."/nope.sql");
		}
	}

	function testImportRunsMysqlWithTheFileAsStdin() {
		TestsHelper::Print("testing Import() runs mysql with the file as stdin, and --force with continue...");
		file_put_contents($this->folder."/in.sql", "SELECT 1;\n");
		$dump = $this->Fake();
		$this->assertSame(["driver" => "binary", "statements" => null], $dump->Import("in"));
		$this->assertSame(["mysql"], $dump->versionChecks);
		$this->assertSame("mysql", $dump->commands[0][0]);
		$this->assertStringStartsWith("--defaults-extra-file=", $dump->commands[0][1]);
		$this->assertSame(["magrathea"], array_slice($dump->commands[0], 2));
		$this->assertSame([$this->folder."/in.sql"], $dump->stdin);
		$this->assertFileDoesNotExist($dump->defaultsSeen[0]["file"]);

		$dump = $this->Fake();
		$dump->exitCode = 1;
		$dump->stderr = "ERROR 1146 (42S02) at line 3: Table 'x' doesn't exist\r\nERROR 1064 (42000) at line 7: syntax\n";
		$rs = $dump->Import("in.sql", ["continue" => true]);
		$this->assertSame(["--force", "magrathea"], array_slice($dump->commands[0], 2));
		$this->assertSame([
			"driver" => "binary",
			"statements" => null,
			"errors" => ["ERROR 1146 (42S02) at line 3: Table 'x' doesn't exist", "ERROR 1064 (42000) at line 7: syntax"],
		], $rs);

		$dump->stderr = "ERROR 1064 (42000) at line 7: syntax";
		try {
			$dump->Import("in");
			$this->fail("no exception");
		} catch(MagratheaException $ex) {
			$this->assertSame("mysql failed (exit code 1): ERROR 1064 (42000) at line 7: syntax", $ex->getMessage());
		}
	}

	function testImportMissingFile() {
		TestsHelper::Print("testing Import() refuses a file that isn't there, and invalid names...");
		$dump = $this->Fake();
		foreach(["missing" => "file not found: missing.sql", "../etc/passwd" => "invalid file name"] as $name => $message) {
			try {
				$dump->Import($name);
				$this->fail("imported ".$name);
			} catch(MagratheaException $ex) {
				$this->assertSame($message, $ex->getMessage());
			}
		}
		$this->assertSame([], $dump->commands);
	}

	function testDriverOption() {
		TestsHelper::Print("testing the driver option: binary when asked must be there, unknown drivers are refused...");
		$dump = $this->Fake();
		$dump->installed = false;
		try {
			$dump->Export("x", ["driver" => "binary"]);
			$this->fail("ran a missing mysqldump");
		} catch(MagratheaException $ex) {
			$this->assertStringStartsWith("mysqldump is not available", $ex->getMessage());
		}
		try {
			$dump->Export("x", ["driver" => "shell"]);
			$this->fail("accepted an unknown driver");
		} catch(MagratheaException $ex) {
			$this->assertSame("invalid driver: use \"binary\" or \"php\"", $ex->getMessage());
		}
		$this->assertSame([], $dump->commands);
		$this->assertSame(["mysqldump"], $dump->versionChecks, "--version is asked once");
	}

	function testRunProcessUsesNoShell() {
		TestsHelper::Print("testing RunProcess() passes arguments as they are, without a shell...");
		$dump = $this->Fake();
		$arg = "a; echo injected `id` \$(id) 'q' \"d\"";
		$rs = $dump->RealRunProcess([PHP_BINARY, "-r", 'fwrite(STDERR, "err"); echo $argv[1]; exit(3);', $arg]);
		$this->assertSame(["code" => 3, "stdout" => $arg, "stderr" => "err"], $rs);
	}

	function testRunProcessStdinAndBigOutput() {
		TestsHelper::Print("testing RunProcess() gives the file as stdin, and reads big stdout and stderr without hanging...");
		$dump = $this->Fake();
		file_put_contents($this->folder."/stdin.sql", "select 1;");
		$rs = $dump->RealRunProcess([PHP_BINARY, "-r", 'echo strtoupper(stream_get_contents(STDIN));'], $this->folder."/stdin.sql");
		$this->assertSame(["code" => 0, "stdout" => "SELECT 1;", "stderr" => ""], $rs);

		$rs = $dump->RealRunProcess([PHP_BINARY, "-r", 'for($i = 0; $i < 20; $i++) { fwrite(STDOUT, str_repeat("o", 20000)); fwrite(STDERR, str_repeat("e", 20000)); }']);
		$this->assertSame(400000, strlen($rs["stdout"]));
		$this->assertSame(400000, strlen($rs["stderr"]));
	}

	function testCreateBackupFileResponse() {
		TestsHelper::Print("testing the backup API answers the file, size and driver, never the command...");
		$api = new FakeDumpDatabaseApi();
		$api->dump = $this->Fake();
		$rs = $api->CreateBackupFile(["filename" => "api_backup"]);
		$this->assertSame(["success" => true, "file" => "api_backup.sql", "size" => 8, "driver" => "binary"], $rs);
		$this->assertArrayNotHasKey("command", $rs);
		$this->assertFalse(method_exists(Dump::class, "GetCommand"));
	}

}
