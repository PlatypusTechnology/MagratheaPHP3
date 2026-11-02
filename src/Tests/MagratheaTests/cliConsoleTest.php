<?php

use Magrathea\Cli\Command;
use Magrathea\Cli\Console;
use Magrathea\Cli\Result;
use Magrathea\Cli\UsageException;
use Magrathea\Errors\ErrorManager;
use Magrathea\Exceptions\MagratheaConfigException;

// Console with the SAPI, the current folder and the commands overridden
class TestableConsole extends Console {
	public static $sapi = "cli";
	public static $cwd = "/";
	protected static function Sapi(): string { return self::$sapi; }
	protected static function WorkingDirectory(): string { return self::$cwd; }
	protected static function CommandClasses(): array { return [CliEchoCommand::class, CliRawCommand::class]; }
}

// `echo <name> [extra] [--loud] [--times=n]`: answers what it got; `fail`/`error`/`usage` as the name fail
class CliEchoCommand extends Command {
	public static $ran = [];
	public function Name(): string { return "echo"; }
	public function Summary(): string { return "Echoes its arguments"; }
	public function Arguments(): array { return ["name" => true, "extra" => false]; }
	public function Options(): array { return ["loud" => false, "times" => true]; }
	public function Execute(array $arguments, array $options): Result {
		self::$ran[] = [$arguments, $options];
		if($arguments["name"] === "fail") throw new \RuntimeException("it failed");
		if($arguments["name"] === "usage") throw new UsageException("bad name");
		if($arguments["name"] === "error") return new Result(["level" => "error"], "an ERROR finding", Console::FAILED);
		if($arguments["name"] === "nan") return new Result(NAN, "not a number");
		$text = str_repeat($arguments["name"].(isset($options["loud"]) ? "!" : ""), (int)($options["times"] ?? 1));
		return new Result(["arguments" => $arguments, "options" => $options], $text);
	}
}

// `raw`: no arguments, no bootstrap
class CliRawCommand extends Command {
	public function Name(): string { return "raw"; }
	public function Summary(): string { return "Runs without the bootstrap"; }
	public function NeedsBootstrap(): bool { return false; }
	public function Execute(array $arguments, array $options): Result { return new Result("raw", "raw"); }
}

class cliConsoleTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private $obLevel;
	private $lastErr = "";

	protected function setUp(): void {
		parent::setUp();
		$this->obLevel = ob_get_level();
		$this->folder = sys_get_temp_dir()."/magrathea-cli-test-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->folder));
		mkdir($this->folder."/project/app", 0777, true);
		mkdir($this->folder."/project/sub/deeper", 0777, true);
		mkdir($this->folder."/nocomposer", 0777, true);
		$this->folder = realpath($this->folder);
		file_put_contents($this->folder."/project/composer.json", '{"name": "test/project"}');
		$this->WriteBootstrap("app/_inc.php");
		TestableConsole::$sapi = "cli";
		TestableConsole::$cwd = $this->folder."/project/sub/deeper";
		CliEchoCommand::$ran = [];
		$GLOBALS["cliTestBootstrap"] = [];
	}

	protected function tearDown(): void {
		ErrorManager::Instance()->ThrowErrors(false);
		unset($GLOBALS["cliTestBootstrap"]);
		system('rm -rf -- '.escapeshellarg($this->folder));
		parent::tearDown();
	}

	// a bootstrap (relative to the project) that records it ran, then runs $code
	private function WriteBootstrap(string $file, string $code = ""): string {
		$path = $this->folder."/project/".$file;
		@mkdir(dirname($path), 0777, true);
		file_put_contents($path, "<?php\n\$GLOBALS['cliTestBootstrap'][] = __FILE__;\n".$code."\n");
		return $path;
	}

	// runs the console; returns [exit code, stdout, stderr]
	private function Cli(string ...$args): array {
		$out = fopen("php://memory", "w+");
		$err = fopen("php://memory", "w+");
		$code = TestableConsole::Run(array_merge(["magrathea"], $args), $out, $err);
		rewind($out);
		rewind($err);
		return [$code, stream_get_contents($out), stream_get_contents($err)];
	}

	private function CliJson(string ...$args): array {
		[$code, $out, $err] = $this->Cli(...array_merge($args, ["--json"]));
		$this->assertSame("", $err);
		// throws unless stdout is one JSON document
		return [$code, json_decode($out, true, 512, JSON_THROW_ON_ERROR)];
	}

	private function Loaded(): array {
		return $GLOBALS["cliTestBootstrap"];
	}

	// arguments

	function testParseSplitsNameArgumentsAndOptions() {
		$this->assertSame(
			["echo", ["a", "b"], ["loud" => true, "times" => "3", "bootstrap" => "x=y.php"]],
			Console::Parse(["--loud", "echo", "a", "--times=3", "b", "--bootstrap=x=y.php"])
		);
		$this->assertSame([null, [], []], Console::Parse([]));
		$this->assertSame(["echo", [], ["times" => ""]], Console::Parse(["echo", "--times="]));
	}

	function testParseDoubleDashEndsOptions() {
		$this->assertSame(["echo", ["--loud", "-x", "--"], ["json" => true]], Console::Parse(["--json", "echo", "--", "--loud", "-x", "--"]));
		$this->assertSame(["--json", [], []], Console::Parse(["--", "--json"]));
	}

	function testParseKeepsADashAlone() {
		$this->assertSame(["echo", ["-"], []], Console::Parse(["echo", "-"]));
	}

	function testParseRefusesShortAndMalformedOptions() {
		foreach([["-h"], ["echo", "-v"], ["--"."=x"], ["--Loud"], ["--9x"]] as $args) {
			try {
				Console::Parse($args);
				$this->fail("parsed ".implode(" ", $args));
			} catch(UsageException $ex) {
				$this->assertStringContainsString("option", $ex->getMessage());
			}
		}
	}

	function testRunsACommandWithItsArgumentsAndOptions() {
		[$code, $out, $err] = $this->Cli("echo", "hi", "--loud", "--times=2");
		$this->assertSame([Console::OK, "hi!hi!\n", ""], [$code, $out, $err]);
		$this->assertSame([[["name" => "hi"], ["loud" => true, "times" => "2"]]], CliEchoCommand::$ran);
	}

	function testOptionsMayComeFirst() {
		[$code, $out] = $this->Cli("--times=3", "echo", "a", "b");
		$this->assertSame([Console::OK, "aaa\n"], [$code, $out]);
		$this->assertSame([["name" => "a", "extra" => "b"], ["times" => "3"]], CliEchoCommand::$ran[0]);
	}

	function testGlobalOptionsAreNotPassedToTheCommand() {
		$bootstrap = $this->WriteBootstrap("boot.php");
		$this->Cli("echo", "a", "--bootstrap=".$bootstrap, "--json");
		$this->assertSame([], CliEchoCommand::$ran[0][1]);
	}

	function testUsageErrorsExitWithTwoAndRunNothing() {
		$cases = [
			[["echo"], "Missing argument <name>"],
			[["echo", "a", "b", "c"], "Too many arguments for [echo]: [c]"],
			[["echo", "a", "--nope"], "Unknown option [--nope] for [echo]"],
			[["echo", "a", "--loud=yes"], "Option [--loud] doesn't take a value"],
			[["echo", "a", "--times"], "Option [--times] needs a value"],
			[["echo", "a", "--times="], "Option [--times] needs a value"],
			[["echo", "a", "--bootstrap"], "Option [--bootstrap] needs a value"],
			[["echo", "a", "--json=1"], "Option [--json] doesn't take a value"],
			[["raw", "a"], "Too many arguments for [raw]"],
			[["echo", "-x"], "Unknown option [-x]"],
		];
		foreach($cases as [$args, $message]) {
			[$code, $out, $err] = $this->Cli(...$args);
			$this->assertSame(Console::USAGE, $code, implode(" ", $args));
			$this->assertSame("", $out);
			$this->assertStringContainsString("magrathea: ".$message, $err);
		}
		$this->assertSame([], CliEchoCommand::$ran);
		$this->assertSame([], $this->Loaded(), "a usage error doesn't load the bootstrap");
	}

	function testAUsageErrorShowsTheCommandsUsage() {
		[, , $err] = $this->Cli("echo");
		$this->assertStringContainsString("Usage: magrathea echo <name> [extra] [--loud] [--times=…]", $err);
	}

	function testUnknownCommand() {
		[$code, $out, $err] = $this->Cli("nope");
		$this->assertSame([Console::USAGE, ""], [$code, $out]);
		$this->assertSame("magrathea: Unknown command [nope]\nRun `magrathea help` for the commands.\n", $err);
	}

	function testACommandsUsageExceptionExitsWithTwo() {
		[$code, , $err] = $this->Cli("echo", "usage");
		$this->assertSame(Console::USAGE, $code);
		$this->assertStringContainsString("magrathea: bad name", $err);
	}

	// exit codes

	function testAFailedCommandExitsWithOne() {
		[$code, $out, $err] = $this->Cli("echo", "fail");
		$this->assertSame([Console::FAILED, "", "magrathea: it failed\n"], [$code, $out, $err]);
	}

	function testTheResultsCodeIsTheExitCode() {
		[$code, $out, $err] = $this->Cli("echo", "error");
		$this->assertSame([Console::FAILED, "an ERROR finding\n", ""], [$code, $out, $err]);
	}

	function testRefusesToRunOutsideTheCli() {
		TestableConsole::$sapi = "fpm-fcgi";
		[$code, $out, $err] = $this->Cli("raw");
		$this->assertSame([Console::USAGE, ""], [$code, $out]);
		$this->assertStringContainsString("magrathea runs only from the command line (this is [fpm-fcgi])", $err);
		[$code, $out, $err] = $this->Cli("help");
		$this->assertSame(Console::USAGE, $code);
	}

	// --json

	function testJsonPrintsOneDocument() {
		[$code, $doc] = $this->CliJson("echo", "hi", "--times=2");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["command" => "echo", "exit" => 0, "data" => ["arguments" => ["name" => "hi"], "options" => ["times" => "2"]]], $doc);
	}

	function testJsonFailures() {
		[$code, $doc] = $this->CliJson("echo", "fail");
		$this->assertSame(Console::FAILED, $code);
		$this->assertSame(["command" => "echo", "exit" => 1, "error" => "it failed"], $doc);

		[$code, $doc] = $this->CliJson("echo", "error");
		$this->assertSame(["command" => "echo", "exit" => 1, "data" => ["level" => "error"]], $doc);

		[$code, $doc] = $this->CliJson("nope");
		$this->assertSame(Console::USAGE, $code);
		$this->assertSame(["command" => "nope", "exit" => 2, "error" => "Unknown command [nope]"], $doc);

		[$code, $doc] = $this->CliJson("--bad_option");
		$this->assertSame(["command" => null, "exit" => 2, "error" => "Invalid option [--bad_option]: an argument that starts with -- goes after a `--`"], $doc);
	}

	function testJsonAfterDoubleDashIsAnArgument() {
		[$code, $out] = $this->Cli("echo", "--", "--json");
		$this->assertSame([Console::OK, "--json\n"], [$code, $out]);
	}

	function testJsonThatCantBeEncodedFails() {
		[$code, $doc] = $this->CliJson("echo", "nan");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringStartsWith("The result can't be encoded as JSON", $doc["error"]);
	}

	// help

	function testNoCommandIsHelp() {
		[$code, $out, $err] = $this->Cli();
		$this->assertSame([Console::OK, ""], [$code, $err]);
		$this->assertStringContainsString("Usage: magrathea <command> [arguments] [options]", $out);
		$this->assertMatchesRegularExpression('/^  echo  Echoes its arguments$/m', $out);
		$this->assertMatchesRegularExpression('/^  help  Lists the commands/m', $out);
		$this->assertStringContainsString("--bootstrap=<file>", $out);
		$this->assertSame([], $this->Loaded(), "help doesn't load the bootstrap");
	}

	function testHelpAsJson() {
		[$code, $doc] = $this->CliJson("help");
		$this->assertSame(Console::OK, $code);
		$this->assertSame(["help", "echo", "raw"], array_column($doc["data"]["commands"], "name"));
		$this->assertSame("magrathea echo <name> [extra] [--loud] [--times=…]", $doc["data"]["commands"][1]["usage"]);
		$this->assertSame(\Magrathea\MagratheaPHP::Version(), $doc["data"]["version"]);
	}

	function testHelpForACommand() {
		[$code, $out] = $this->Cli("help", "echo");
		$this->assertSame([Console::OK, "Usage: magrathea echo <name> [extra] [--loud] [--times=…]\n\nEchoes its arguments\n"], [$code, $out]);
		[$code, , $err] = $this->Cli("help", "nope");
		$this->assertSame(Console::USAGE, $code);
		$this->assertStringContainsString("Unknown command [nope]", $err);
	}

	function testHelpOptionShowsTheUsageWithoutRunning() {
		[$code, $out] = $this->Cli("echo", "--help");
		$this->assertSame(Console::OK, $code);
		$this->assertStringStartsWith("Usage: magrathea echo <name>", $out);
		$this->assertSame([], CliEchoCommand::$ran);
		$this->assertSame([], $this->Loaded());
		// its options are still checked
		[$code] = $this->Cli("echo", "--help", "--nope");
		$this->assertSame(Console::USAGE, $code);
	}

	// the bootstrap

	function testBootstrapDefaultIsAppIncNextToComposerJson() {
		[$code] = $this->Cli("echo", "a");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([$this->folder."/project/app/_inc.php"], $this->Loaded());
	}

	function testACommandWithoutTheBootstrapDoesntLoadIt() {
		[$code] = $this->Cli("raw");
		$this->assertSame(Console::OK, $code);
		$this->assertSame([], $this->Loaded());
	}

	function testBootstrapDeclaredInComposerJsonWins() {
		$declared = $this->WriteBootstrap("src/boot.php");
		file_put_contents($this->folder."/project/composer.json", '{"extra": {"magrathea": {"bootstrap": "src/boot.php"}}}');
		$this->Cli("echo", "a");
		$this->assertSame([$declared], $this->Loaded());
	}

	function testBootstrapOptionWinsAndIsRelativeToTheCurrentFolder() {
		$option = $this->WriteBootstrap("sub/deeper/mine.php");
		$this->WriteBootstrap("src/boot.php");
		file_put_contents($this->folder."/project/composer.json", '{"extra": {"magrathea": {"bootstrap": "src/boot.php"}}}');
		$this->Cli("echo", "a", "--bootstrap=mine.php");
		$this->assertSame([$option], $this->Loaded());
		$this->assertSame($option, TestableConsole::FindBootstrap($option), "an absolute path");
	}

	function testTheNearestComposerJsonCounts() {
		file_put_contents($this->folder."/project/sub/composer.json", '{}');
		$nearer = $this->WriteBootstrap("sub/app/_inc.php");
		$this->Cli("echo", "a");
		$this->assertSame([$nearer], $this->Loaded());
	}

	function testBootstrapNotFound() {
		[$code, , $err] = $this->Cli("echo", "a", "--bootstrap=missing.php");
		$this->assertSame(Console::USAGE, $code);
		$this->assertStringContainsString("Bootstrap [".$this->folder."/project/sub/deeper/missing.php] not found (--bootstrap)", $err);

		file_put_contents($this->folder."/project/composer.json", '{"extra": {"magrathea": {"bootstrap": "nope.php"}}}');
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("Bootstrap [".$this->folder."/project/nope.php] not found (extra.magrathea.bootstrap in [".$this->folder."/project/composer.json])", $err);

		file_put_contents($this->folder."/project/composer.json", '{"extra": {"magrathea": {"bootstrap": ""}}}');
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("extra.magrathea.bootstrap in [".$this->folder."/project/composer.json] must be a file path", $err);

		file_put_contents($this->folder."/project/composer.json", '{}');
		unlink($this->folder."/project/app/_inc.php");
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("Bootstrap [".$this->folder."/project/app/_inc.php] not found: pass --bootstrap=<file>", $err);

		file_put_contents($this->folder."/project/composer.json", '{not json');
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("[".$this->folder."/project/composer.json] isn't valid JSON", $err);

		$this->assertSame([], CliEchoCommand::$ran);
	}

	function testNoComposerJson() {
		if(is_file(dirname($this->folder)."/composer.json") || is_file("/composer.json")) $this->markTestSkipped("a composer.json above the temp folder");
		TestableConsole::$cwd = $this->folder."/nocomposer";
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("No composer.json in [".$this->folder."/nocomposer] or above it", $err);
	}

	function testABootstrapThatPrintsIsDiscardedWithAWarning() {
		$this->WriteBootstrap("app/_inc.php", "echo 'hello';");
		[$code, $doc] = $this->CliJsonAllowingStderr("echo", "a");
		$this->assertSame(Console::OK, $code);
		$this->assertSame("echo", $doc["command"]);
		$this->assertStringContainsString("printed 5 bytes, discarded: it must not print", $this->lastErr);
	}
	private function CliJsonAllowingStderr(string ...$args): array {
		[$code, $out, $this->lastErr] = $this->Cli(...array_merge($args, ["--json"]));
		return [$code, json_decode($out, true, 512, JSON_THROW_ON_ERROR)];
	}

	function testABootstrapThatThrowsFails() {
		$this->WriteBootstrap("app/_inc.php", "throw new \\Exception('no database');");
		[$code, , $err] = $this->Cli("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("magrathea: The bootstrap [".$this->folder."/project/app/_inc.php] failed: no database", $err);
		$this->assertSame([], CliEchoCommand::$ran);
		$this->assertSame(0, ob_get_level() - $this->obLevel, "the output buffer is closed");
	}

	function testFrameworkErrorPagesBecomeFailures() {
		$this->WriteBootstrap("app/_inc.php", "\\Magrathea\\Errors\\ErrorManager::Instance()->DisplayMesage('Magrathea version outdated');");
		[$code, $doc] = $this->CliJson("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("failed: Magrathea version outdated", $doc["error"]);

		$this->WriteBootstrap("app/_inc.php", "\\Magrathea\\Errors\\ErrorManager::Instance()->DisplayException(new \\Magrathea\\Exceptions\\MagratheaConfigException('File could not be found: x', null, 424041));");
		[$code, $doc] = $this->CliJson("echo", "a");
		$this->assertSame(Console::FAILED, $code);
		$this->assertStringContainsString("failed: File could not be found: x", $doc["error"]);

		// and it's turned off afterwards
		$this->assertFalse(ErrorManager::Instance()->ThrowErrors(false));
	}

	// ErrorManager in the CLI

	function testErrorManagerCliText() {
		$em = ErrorManager::Instance();
		$this->assertSame("Magrathea Fatal Error: something broke\n", $em->CliText("something broke"));

		$ex = new MagratheaConfigException("File could not be found: /x/magrathea.conf", "/x/magrathea.conf", 424041);
		$text = $em->CliText($ex->getMessage(), $ex);
		$lines = explode("\n", $text);
		$this->assertSame("Magrathea Fatal Error: File could not be found: /x/magrathea.conf", $lines[0]);
		$this->assertSame(MagratheaConfigException::class." (code 424041) at ".__FILE__.":".$ex->getLine(), $lines[1]);
		$this->assertStringContainsString("a sample is at ".realpath(__DIR__."/../../Bootstrap/docs/magrathea.conf.sample"), $lines[2]);
		$this->assertStringContainsString("#0 ", $text, "the trace");
		$this->assertStringNotContainsString("<", $lines[0].$lines[1].$lines[2], "no HTML");

		$text = $em->CliText("plain", new \RuntimeException("plain"));
		$this->assertStringContainsString("RuntimeException at ", $text, "no code, no sample");
		$this->assertStringNotContainsString("sample", $text);
	}

	function testErrorManagerThrowsWhenAsked() {
		$em = ErrorManager::Instance();
		$this->assertFalse($em->ThrowErrors(true));
		try {
			$em->DisplayMesage("a message");
			$this->fail("didn't throw");
		} catch(\RuntimeException $ex) {
			$this->assertSame("a message", $ex->getMessage());
		}
		$original = new \Exception("the exception", 7);
		try {
			$em->DisplayException($original);
			$this->fail("didn't throw");
		} catch(\Exception $ex) {
			$this->assertSame($original, $ex);
		}
		$this->assertTrue($em->ThrowErrors(false));
	}

	// in a separate process: what exits can't be tested in this one

	private function Process(array $command): array {
		if(!function_exists("proc_open")) $this->markTestSkipped("no proc_open()");
		$process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $this->folder);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $out, $err];
	}

	function testErrorManagerPrintsTextAndExitsWithOneInTheCli() {
		$autoload = realpath(__DIR__."/../../../vendor/autoload.php") ?: realpath(__DIR__."/../../../../../autoload.php");
		$script = "require ".var_export($autoload, true)."; \\Magrathea\\Errors\\ErrorManager::Instance()->DisplayMesage('config is broken'); echo 'still running';";
		[$code, $out, $err] = $this->Process([PHP_BINARY, "-r", $script]);
		$this->assertSame([1, "", "Magrathea Fatal Error: config is broken\n"], [$code, $out, $err]);

		$script = "require ".var_export($autoload, true)."; \\Magrathea\\Errors\\ErrorManager::Instance()->DisplayException(new \\Exception('boom', 3));";
		[$code, $out, $err] = $this->Process([PHP_BINARY, "-r", $script]);
		$this->assertSame([1, ""], [$code, $out]);
		$this->assertStringStartsWith("Magrathea Fatal Error: boom\nException (code 3) at ", $err);
	}

	function testBinMagrathea() {
		$bin = realpath(__DIR__."/../../../bin/magrathea");
		if(!$bin) $this->markTestSkipped("bin/magrathea isn't here (installed as a dependency)");
		$this->assertTrue(is_executable($bin));
		$this->assertStringStartsWith("#!/usr/bin/env php\n<?php\n", file_get_contents($bin));

		[$code, $out, $err] = $this->Process([PHP_BINARY, $bin, "help", "--json"]);
		$this->assertSame([0, ""], [$code, $err]);
		$this->assertSame("help", json_decode($out, true)["command"]);

		[$code, $out, $err] = $this->Process([PHP_BINARY, $bin, "nope"]);
		$this->assertSame([2, ""], [$code, $out]);
		$this->assertStringContainsString("Unknown command [nope]", $err);

		$this->WriteBootstrap("app/_inc.php", "throw new \\Exception('broken');");
		[$code, $out, $err] = $this->Process([PHP_BINARY, $bin, "help", "help", "--bootstrap=".$this->folder."/project/app/_inc.php"]);
		$this->assertSame(0, $code, "help doesn't load the bootstrap");
	}

	function testComposerDeclaresTheBin() {
		$composer = json_decode(file_get_contents(__DIR__."/../../../composer.json"), true);
		$this->assertSame(["bin/magrathea"], $composer["bin"]);
	}

}
