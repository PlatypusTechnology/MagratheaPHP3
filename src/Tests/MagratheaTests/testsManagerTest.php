<?php

use Magrathea\Tests\TestsManager;
use Magrathea\Tests\TestsHelper;
use Magrathea\Exceptions\MagratheaException;

// records the shell commands instead of running PHPUnit from inside PHPUnit
class RecordingTestsManager extends TestsManager {
	public array $commands = [];
	protected function Execute(string $command): array {
		$this->commands[] = $command;
		return ["ran"];
	}
	public function Reset(): RecordingTestsManager {
		$this->commands = [];
		// $tests is private to TestsManager
		\Closure::bind(function() { $this->tests = []; }, $this, TestsManager::class)();
		return $this;
	}
}

// a RecordingTestsManager that looks for PHPUnit in the given files
class CandidatesTestsManager extends RecordingTestsManager {
	public array $candidates = [];
	protected function PhpUnitCandidates(): array {
		return $this->candidates;
	}
	public function DefaultCandidates(): array {
		return parent::PhpUnitCandidates();
	}
}

class testsManagerTest extends \PHPUnit\Framework\TestCase {

	private RecordingTestsManager $manager;
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = RecordingTestsManager::Instance()->Reset();
		$this->root = sys_get_temp_dir()."/magrathea-tests-manager-".bin2hex(random_bytes(4));
		mkdir($this->root."/tests/models", 0777, true);
		mkdir($this->root."/outside", 0777, true);
		file_put_contents($this->root."/tests/models/ArticleTest.php", "<?php class ArticleTest { function setUp() {} function testSave() {} function helper() {} }");
		file_put_contents($this->root."/tests/models/helpers.php", "<?php");
		file_put_contents($this->root."/outside/EvilTest.php", "<?php class EvilTest { function testEvil() {} }");
		symlink($this->root."/outside", $this->root."/tests/link");
	}

	protected function tearDown(): void {
		@unlink($this->root."/tests/link");
		@unlink($this->root."/tests/models/ArticleTest.php");
		@unlink($this->root."/tests/models/helpers.php");
		@unlink($this->root."/outside/EvilTest.php");
		@rmdir($this->root."/tests/models");
		@rmdir($this->root."/tests");
		@rmdir($this->root."/outside");
		@rmdir($this->root);
		parent::tearDown();
	}

	private function assertInvalidPath(\Closure $fn, string $message = "invalid test path") {
		try {
			$fn();
			$this->fail("expected MagratheaException");
		} catch(MagratheaException $ex) {
			$this->assertSame($message, $ex->getMessage());
		}
		$this->assertSame([], $this->manager->commands);
	}

	public function testAddTestKeepsGivenName() {
		TestsHelper::Print("Testing AddTest() keeps the name it's given");
		$this->manager->AddTest(["/app/tests/models"], "Models");
		$this->assertSame([["name" => "Models", "folders" => ["/app/tests/models"]]], $this->manager->GetTests());
	}

	public function testAddTestWithoutNameUsesFirstFolderName() {
		TestsHelper::Print("Testing AddTest() without a name uses the first folder's name");
		$this->manager->AddTest(["/app/tests/models/", "/app/tests/other"]);
		$this->manager->AddTest([]);
		$tests = $this->manager->GetTests();
		$this->assertSame("models", $tests[0]["name"]);
		$this->assertSame("", $tests[1]["name"]);
	}

	public function testRunAllTestsRunsEveryFolderInOrder() {
		TestsHelper::Print("Testing RunAllTests() runs every registered folder");
		$models = $this->root."/tests/models";
		$this->manager
			->AddTest([$this->root."/tests", $models], "first")
			->AddTest([$this->root."/outside"], "second");
		$response = $this->manager->RunAllTests();
		$this->assertCount(3, $this->manager->commands);
		$this->assertStringEndsWith(" ".escapeshellarg(realpath($this->root."/tests")), $this->manager->commands[0]);
		$this->assertStringEndsWith(" ".escapeshellarg(realpath($models)), $this->manager->commands[1]);
		$this->assertStringEndsWith(" ".escapeshellarg(realpath($this->root."/outside")), $this->manager->commands[2]);
		$this->assertSame([
			">> $ ".$this->manager->commands[0], "ran",
			">> $ ".$this->manager->commands[1], "ran",
			">> $ ".$this->manager->commands[2], "ran",
		], $response);
	}

	public function testRunAllTestsWithNoTests() {
		TestsHelper::Print("Testing RunAllTests() with nothing registered");
		$this->assertSame([], $this->manager->RunAllTests());
		$this->assertSame([], $this->manager->commands);
	}

	public function testRunsPathsInsideRegisteredFolders() {
		TestsHelper::Print("Testing the run methods accept registered folders and test files inside them");
		$this->manager->AddTest([$this->root."/tests"], "app");
		$file = $this->root."/tests/models/ArticleTest.php";
		$this->manager->RunFolder($this->root."/tests/models");
		$this->manager->RunFile($this->root."/tests/models/../models/ArticleTest.php");
		$this->manager->RunFunction($file, "testSave");
		$this->assertStringEndsWith(" ".escapeshellarg(realpath($this->root."/tests/models")), $this->manager->commands[0]);
		$this->assertStringEndsWith(" ".escapeshellarg(realpath($file)), $this->manager->commands[1]);
		$this->assertStringEndsWith(" --filter 'testSave' ".escapeshellarg(realpath($file)), $this->manager->commands[2]);
	}

	public function testRejectsPathsOutsideRegisteredFolders() {
		TestsHelper::Print("Testing the run methods reject paths outside the registered folders");
		$this->manager->AddTest([$this->root."/tests"], "app");
		$outside = $this->root."/outside/EvilTest.php";
		$this->assertInvalidPath(fn() => $this->manager->RunFolder($this->root."/outside"));
		$this->assertInvalidPath(fn() => $this->manager->RunFolder($this->root."/tests/../outside"));
		$this->assertInvalidPath(fn() => $this->manager->RunFile($outside));
		$this->assertInvalidPath(fn() => $this->manager->RunFile($this->root."/tests/link/EvilTest.php"));
		$this->assertInvalidPath(fn() => $this->manager->RunFunction($outside, "testEvil"));
		$this->assertInvalidPath(fn() => $this->manager->RunFolder($this->root."/tests/missing"));
		$this->assertInvalidPath(fn() => $this->manager->RunFolder(""));
		$this->assertInvalidPath(fn() => $this->manager->RunFolder(["/"]));
	}

	public function testRejectsShellInjection() {
		TestsHelper::Print("Testing the run methods don't pass shell metacharacters through");
		$this->manager->AddTest([$this->root."/tests"], "app");
		$file = $this->root."/tests/models/ArticleTest.php";
		$this->assertInvalidPath(fn() => $this->manager->RunFolder($this->root."/tests; touch /tmp/pwned"));
		$this->assertInvalidPath(fn() => $this->manager->RunFile($file." && id"));
		$this->assertInvalidPath(fn() => $this->manager->RunFunction($file, "testSave; id"), "invalid test function");
		$this->assertInvalidPath(fn() => $this->manager->RunFunction($file, "test.*"), "invalid test function");
	}

	public function testRunFileOnlyRunsTestFiles() {
		TestsHelper::Print("Testing RunFile()/RunFunction() only run *Test.php files");
		$this->manager->AddTest([$this->root."/tests"], "app");
		$this->assertInvalidPath(fn() => $this->manager->RunFile($this->root."/tests/models/helpers.php"));
		$this->assertInvalidPath(fn() => $this->manager->RunFile($this->root."/tests/models"));
		$this->assertInvalidPath(fn() => $this->manager->RunFunction($this->root."/tests/models/helpers.php", "testX"));
	}

	public function testListingIsLimitedToRegisteredFolders() {
		TestsHelper::Print("Testing GetTestsFromFolder()/GetMethodsFromTest() stay inside the registered folders");
		$this->manager->AddTest([$this->root."/tests"], "app");
		$this->assertSame(["ArticleTest.php"], array_values($this->manager->GetTestsFromFolder($this->root."/tests/models")));
		$this->assertSame(["testSave"], array_values($this->manager->GetMethodsFromTest($this->root."/tests/models/ArticleTest.php")));
		$this->assertInvalidPath(fn() => $this->manager->GetTestsFromFolder($this->root."/outside"));
		$this->assertInvalidPath(fn() => $this->manager->GetMethodsFromTest($this->root."/outside/EvilTest.php"));
		$this->assertInvalidPath(fn() => $this->manager->GetMethodsFromTest("/etc/passwd"));
	}

	public function testDefaultPhpUnitCandidates() {
		TestsHelper::Print("Testing PHPUnit is looked for in Composer's vendor/bin, then the framework's phpunit.phar");
		$candidates = CandidatesTestsManager::Instance()->DefaultCandidates();
		$vendor = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2);
		$this->assertSame([$vendor."/bin/phpunit", dirname(__DIR__, 3)."/phpunit.phar"], $candidates);
	}

	public function testRunsTheFirstPhpUnitFound() {
		TestsHelper::Print("Testing the run methods use the first PHPUnit that exists");
		$manager = CandidatesTestsManager::Instance()->Reset();
		$manager->AddTest([$this->root."/tests"], "app");
		$bin = $this->root."/phpunit";
		$phar = $this->root."/phpunit.phar";
		file_put_contents($phar, "");
		try {
			$manager->candidates = [$bin, $phar];
			$manager->RunFolder($this->root."/tests");
			$this->assertStringStartsWith("php -d display_errors=on ".escapeshellarg($phar)." ", $manager->commands[0]);

			file_put_contents($bin, "");
			$manager->RunFolder($this->root."/tests");
			$this->assertStringStartsWith("php -d display_errors=on ".escapeshellarg($bin)." ", $manager->commands[1]);
			$this->assertSame(">> $ phpunit .../models/ArticleTest.php", $manager->RunFunction($this->root."/tests/models/ArticleTest.php", "testSave", false)[0]);
		} finally {
			@unlink($bin);
			@unlink($phar);
		}
	}

	public function testRunWithoutPhpUnitSaysSo() {
		TestsHelper::Print("Testing the run methods throw an explicit error without PHPUnit, and listing still works");
		$manager = CandidatesTestsManager::Instance()->Reset();
		$manager->AddTest([$this->root."/tests"], "app");
		$manager->candidates = [$this->root."/vendor/bin/phpunit", $this->root."/phpunit.phar"];
		foreach([
			fn() => $manager->RunFolder($this->root."/tests"),
			fn() => $manager->RunFile($this->root."/tests/models/ArticleTest.php"),
			fn() => $manager->RunFunction($this->root."/tests/models/ArticleTest.php", "testSave"),
			fn() => $manager->RunAllTests(),
		] as $run) {
			try {
				$run();
				$this->fail("expected MagratheaException");
			} catch(MagratheaException $ex) {
				$this->assertStringStartsWith("PHPUnit not found", $ex->getMessage());
				$this->assertStringContainsString("composer require --dev phpunit/phpunit", $ex->getMessage());
				$this->assertStringContainsString($this->root."/vendor/bin/phpunit", $ex->getMessage());
			}
		}
		$this->assertSame([], $manager->commands);
		$this->assertSame(["ArticleTest.php"], array_values($manager->GetTestsFromFolder($this->root."/tests/models")));
	}

	public function testNothingIsAllowedWithoutRegisteredTests() {
		TestsHelper::Print("Testing nothing runs when no test folder is registered");
		$this->assertInvalidPath(fn() => $this->manager->RunFolder($this->root."/tests"));
	}

}
