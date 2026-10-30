<?php

use Magrathea\Admin\DumpManager;
use Magrathea\Config;
use Magrathea\DB\Dump;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\Tests\TestsHelper;

// Dump that records Export() and Import() and runs nothing
class DumpsFakeDump extends Dump {
	public $calls = [];
	public function __construct() {}
	public function Export($file, array $options = []): array {
		$this->calls[] = ["export", $file, $options];
		return ["file" => Dump::ValidateBackupFileName($file), "size" => 8, "driver" => $options["driver"] ?? Dump::PHP];
	}
	public function Import($file, array $options = []): array {
		$this->calls[] = ["import", $file, $options];
		return ["driver" => $options["driver"] ?? Dump::PHP, "statements" => 3];
	}
}

// DumpManager whose uploads are moved with rename(): no real upload in the CLI
class DumpsTestManager extends DumpManager {
	public $moved = [];
	protected function MoveUploadedFile(string $from, string $to): bool {
		$this->moved[] = [$from, $to];
		return rename($from, $to);
	}
}

class adminDumpsTest extends \PHPUnit\Framework\TestCase {

	private $root;
	private $folder;
	private DumpsFakeDump $dump;
	private DumpsTestManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir()."/magrathea-dumps-test-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->root));
		// the realpath cache still maps the last test's link.sql to outside.sql: symlink() would resolve onto it
		clearstatcache(true);
		$this->folder = $this->root."/backups";
		mkdir($this->folder, 0777, true);
		file_put_contents($this->root."/outside.sql", "-- outside");
		Config::Instance()->SetConfig([
			"general" => ["use_environment" => "test"],
			"test" => ["db_name" => "magrathea", "backups_path" => $this->folder],
		])->SetEnvironment(null);
		$this->dump = new DumpsFakeDump();
		$this->manager = new DumpsTestManager($this->dump);
	}
	protected function tearDown(): void {
		system('rm -rf -- '.escapeshellarg($this->root));
		Config::Instance()->SetConfig(null)->SetEnvironment(null);
		parent::tearDown();
	}

	private function Rejects(callable $fn, string $message, string $label) {
		try {
			$fn();
			$this->fail("accepted: ".$label);
		} catch(MagratheaException $ex) {
			$this->assertSame($message, $ex->getMessage(), $label);
		}
	}
	// names that must never reach a file outside the folder
	private function BadNames(): array {
		symlink($this->root."/outside.sql", $this->folder."/link.sql");
		return [
			"../outside.sql" => "invalid file name",
			"../outside" => "invalid file name",
			"a/b.sql" => "invalid file name",
			"/etc/passwd" => "invalid file name",
			"a\\b.sql" => "invalid file name",
			"x.sql\0" => "invalid file name",
			"" => "invalid file name",
			"link.sql" => "file not found: link.sql",
			"missing" => "file not found: missing.sql",
		];
	}
	private function Upload(string $name, string $content = "SELECT 1;\n", int $error = UPLOAD_ERR_OK): array {
		$tmp = tempnam($this->root, "upload-");
		file_put_contents($tmp, $content);
		return ["name" => $name, "tmp_name" => $tmp, "error" => $error, "size" => strlen($content), "type" => "application/sql"];
	}

	function testFiles() {
		TestsHelper::Print("testing Files() lists the folder's .sql files, the most recent first, and flags names the page can't use...");
		file_put_contents($this->folder."/old.sql", "12345");
		touch($this->folder."/old.sql", 1000);
		file_put_contents($this->folder."/new.sql", "1");
		touch($this->folder."/new.sql", 2000);
		file_put_contents($this->folder."/My Backup.sql", "");
		touch($this->folder."/My Backup.sql", 1500);
		file_put_contents($this->folder."/notes.txt", "");
		mkdir($this->folder."/dir.sql");
		symlink($this->root."/outside.sql", $this->folder."/link.sql");
		touch($this->root."/outside.sql", 500);

		$files = $this->manager->Files();
		$this->assertSame([
			["name" => "new.sql", "size" => 1, "date" => 2000, "usable" => true],
			["name" => "My Backup.sql", "size" => 0, "date" => 1500, "usable" => false],
			["name" => "old.sql", "size" => 5, "date" => 1000, "usable" => true],
			["name" => "link.sql", "size" => 10, "date" => 500, "usable" => false],
		], $files);

		system('rm -rf -- '.escapeshellarg($this->folder));
		$this->Rejects(fn() => $this->manager->Files(), "the backups folder doesn't exist: ".$this->folder, "missing folder");
	}

	function testDownloadNames() {
		TestsHelper::Print("testing Download() refuses names that leave the folder, symlinks out of it and missing files...");
		foreach($this->BadNames() as $name => $message) {
			$this->Rejects(fn() => $this->manager->Download($name), $message, $name);
		}
		$this->Rejects(fn() => $this->manager->Download(["x.sql"]), "invalid file name", "array");

		file_put_contents($this->folder."/backup_1.sql", "-- dump\n");
		$rs = $this->manager->Download("backup_1");
		$this->assertSame(realpath($this->folder."/backup_1.sql"), $rs["path"]);
		$this->assertSame("backup_1.sql", $rs["file"]);
		$this->assertSame(8, $rs["size"]);
		$this->assertSame([
			"Content-Type: application/sql",
			"Content-Disposition: attachment; filename=\"backup_1.sql\"",
			"Content-Length: 8",
			"X-Content-Type-Options: nosniff",
			"Cache-Control: no-store",
		], $rs["headers"]);
	}

	function testDeleteNames() {
		TestsHelper::Print("testing Delete() refuses bad names without touching anything, and deletes a file in the folder...");
		foreach($this->BadNames() as $name => $message) {
			$this->Rejects(fn() => $this->manager->Delete($name), $message, $name);
		}
		$this->assertFileExists($this->root."/outside.sql");
		$this->assertTrue(is_link($this->folder."/link.sql"));

		file_put_contents($this->folder."/gone.sql", "x");
		$this->assertSame(["file" => "gone.sql"], $this->manager->Delete("gone"));
		$this->assertFileDoesNotExist($this->folder."/gone.sql");
		$this->assertSame([], $this->dump->calls);
	}

	function testUploadSavesWithoutRunning() {
		TestsHelper::Print("testing Upload() saves the file under its validated name and runs nothing...");
		$rs = $this->manager->Upload($this->Upload("my-dump.sql", "DROP TABLE x;\n"));
		$this->assertSame(["file" => "my-dump.sql", "size" => 14], $rs);
		$this->assertSame("DROP TABLE x;\n", file_get_contents($this->folder."/my-dump.sql"));

		$rs = $this->manager->Upload($this->Upload("Some File.SQL"), "renamed");
		$this->assertSame("renamed.sql", $rs["file"]);
		$this->assertFileExists($this->folder."/renamed.sql");
		$this->assertSame(realpath($this->folder)."/renamed.sql", $this->manager->moved[1][1]);
		$this->assertSame([], $this->dump->calls, "nothing imported");
	}

	function testUploadNames() {
		TestsHelper::Print("testing Upload() refuses bad names, existing files, non-.sql files and upload errors...");
		file_put_contents($this->folder."/keep.sql", "keep me");
		$cases = [
			["../evil.sql", null, "invalid file name"],
			["ok.sql", "../evil", "invalid file name"],
			["ok.sql", "a/b", "invalid file name"],
			["ok.sql", "-opt", "invalid file name"],
			["My Backup.sql", null, "invalid file name"],
			["keep.sql", null, "file already exists"],
			["ok.sql", "keep", "file already exists"],
			["dump.sql.gz", null, "only .sql files can be uploaded"],
			["shell.php", "shell.sql", "only .sql files can be uploaded"],
		];
		foreach($cases as [$original, $name, $message]) {
			$this->Rejects(fn() => $this->manager->Upload($this->Upload($original), $name), $message, $original." as ".$name);
		}
		$this->assertSame("keep me", file_get_contents($this->folder."/keep.sql"));
		$this->assertFileDoesNotExist($this->root."/evil.sql");
		$this->assertSame([], $this->manager->moved, "nothing was moved");

		$this->Rejects(fn() => $this->manager->Upload(null), "no file uploaded", "null");
		$this->Rejects(fn() => $this->manager->Upload(["name" => ["a.sql"], "tmp_name" => ["x"], "error" => [0]]), "no file uploaded", "multiple");
		$this->Rejects(fn() => $this->manager->Upload($this->Upload("a.sql", "", UPLOAD_ERR_NO_FILE)), "no file uploaded", "no file");
		$this->Rejects(fn() => $this->manager->Upload($this->Upload("a.sql", "", UPLOAD_ERR_PARTIAL)), "the file was only partially uploaded", "partial");
		try {
			$this->manager->Upload($this->Upload("a.sql", "", UPLOAD_ERR_INI_SIZE));
			$this->fail("accepted a too big file");
		} catch(MagratheaException $ex) {
			$this->assertStringStartsWith("the file is bigger than the upload limit", $ex->getMessage());
		}
	}

	function testUploadOnlyMovesUploadedFiles() {
		TestsHelper::Print("testing the real MoveUploadedFile() refuses a file PHP didn't receive in this request...");
		$manager = new DumpManager($this->dump);
		$this->Rejects(fn() => $manager->Upload($this->Upload("a.sql")), "could not save the uploaded file", "not uploaded");
		$this->assertFileDoesNotExist($this->folder."/a.sql");
	}

	function testExport() {
		TestsHelper::Print("testing Export() passes the tables and driver to Dump only when they're given...");
		$this->assertSame(["file" => "a.sql", "size" => 8, "driver" => Dump::PHP], $this->manager->Export("a", [], ""));
		$this->manager->Export("b", ["articles", "users"], Dump::BINARY);
		$this->manager->Export("c", null, null);
		$this->assertSame([
			["export", "a", []],
			["export", "b", ["tables" => ["articles", "users"], "driver" => Dump::BINARY]],
			["export", "c", []],
		], $this->dump->calls);
	}

	function testImportNeedsTheDatabaseName() {
		TestsHelper::Print("testing Import() runs nothing until it's confirmed with the database's exact name...");
		file_put_contents($this->folder."/in.sql", "SELECT 1;\n");
		$confirm = ["status" => DumpManager::CONFIRM, "file" => "in.sql", "database" => "magrathea", "error" => null, "result" => null];
		$this->assertSame($confirm, $this->manager->Import("in"));
		$this->assertSame($confirm, $this->manager->Import("in.sql", ""));
		$mismatch = ["error" => "the database name doesn't match: nothing ran"] + $confirm;
		foreach(["Magrathea", "magrathea ", "other", ["magrathea"], true] as $typed) {
			$this->assertEquals($mismatch, $this->manager->Import("in", $typed), json_encode($typed));
		}
		$this->assertSame([], $this->dump->calls);

		$rs = $this->manager->Import("in", "magrathea");
		$this->assertSame(DumpManager::OK, $rs["status"]);
		$this->assertSame(["driver" => Dump::PHP, "statements" => 3], $rs["result"]);
		$this->manager->Import("in", "magrathea", Dump::BINARY, true);
		$this->assertSame([
			["import", "in.sql", ["continue" => false]],
			["import", "in.sql", ["continue" => true, "driver" => Dump::BINARY]],
		], $this->dump->calls);
	}

	function testImportNames() {
		TestsHelper::Print("testing Import() refuses bad names before asking, and never imports into a database without a name...");
		foreach($this->BadNames() as $name => $message) {
			$this->Rejects(fn() => $this->manager->Import($name, "magrathea"), $message, $name);
		}
		file_put_contents($this->folder."/in.sql", "SELECT 1;\n");
		$manager = new DumpManager($this->dump, "");
		$this->assertSame(DumpManager::CONFIRM, $manager->Import("in", "")["status"]);
		$this->assertSame(DumpManager::CONFIRM, $manager->Import("in", "x")["status"]);
		$this->assertSame([], $this->dump->calls);
		$this->assertSame("other_db", (new DumpManager($this->dump, "other_db"))->DatabaseName());
		$this->assertSame("magrathea", (new DumpManager($this->dump))->DatabaseName());
	}

}
