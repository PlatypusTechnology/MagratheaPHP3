<?php

use Magrathea\Admin\Install;
use Magrathea\Tests\TestsHelper;

class adminInstallTest extends \PHPUnit\Framework\TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir()."/magrathea-admin-install-".bin2hex(random_bytes(4));
		mkdir($this->root."/app", 0777, true);
		mkdir($this->root."/vendor", 0777, true);
		mkdir($this->root."/elsewhere", 0777, true);
		// stands in for Composer's autoloader: proves it was found, then stops before the app starts
		file_put_contents($this->root."/vendor/autoload.php", "<?php echo 'autoload found'; exit;");
	}

	protected function tearDown(): void {
		system('rm -rf -- '.escapeshellarg($this->root));
		parent::tearDown();
	}

	function testAdminCodeFindsAutoloadFromAnyFolder() {
		TestsHelper::Print("testing the generated admin entry file finds vendor/autoload.php whatever the current folder...");
		$entry = $this->root."/app/magrathea.php";
		file_put_contents($entry, (new Install())->GetAdminCode());
		// run from a folder that isn't the entry file's: a CWD-relative require would miss it
		$cmd = "cd ".escapeshellarg($this->root."/elsewhere")." && ".escapeshellarg(PHP_BINARY)." ".escapeshellarg($entry)." 2>&1";
		$this->assertSame("autoload found", (string)shell_exec($cmd));
	}

	function testInstallSchemaFollowsTheTimestampContract() {
		TestsHelper::Print("testing database.sql gives every table DATETIME created_at/updated_at with the database's defaults...");
		$sql = file_get_contents(__DIR__."/../../Admin/database.sql");
		preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\n\);/s', $sql, $tables, PREG_SET_ORDER);
		$this->assertCount(4, $tables);
		foreach($tables as [, $table, $body]) {
			$this->assertStringContainsString("`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", $body, $table);
			$this->assertStringContainsString("`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", $body, $table);
			// a bare TIMESTAMP column can pick up ON UPDATE CURRENT_TIMESTAMP (explicit_defaults_for_timestamp=OFF)
			$this->assertDoesNotMatchRegularExpression('/\bTIMESTAMP\b/i', str_replace("CURRENT_TIMESTAMP", "", $body), $table);
		}
		$this->assertStringContainsString("`last_login` DATETIME NULL,", $sql);
	}

}
