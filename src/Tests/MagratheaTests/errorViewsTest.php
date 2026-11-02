<?php

use Magrathea\Config;
use Magrathea\Exceptions\MagratheaConfigException;

// the HTML error page ErrorManager shows outside the CLI: rendered here without the `die`
class errorViewsTest extends \PHPUnit\Framework\TestCase {

	private $folder;
	private $previousPath;
	private $previousFile;

	protected function setUp(): void {
		parent::setUp();
		$this->folder = sys_get_temp_dir()."/magrathea-error-views-".getmypid();
		system('rm -rf -- '.escapeshellarg($this->folder));
		mkdir($this->folder, 0777, true);
		$this->previousPath = Config::Instance()->GetPath();
		$this->previousFile = Config::Instance()->GetConfigFile();
	}

	protected function tearDown(): void {
		Config::Instance()->SetConfig(null)->SetPath($this->previousPath)->SetConfigFile($this->previousFile);
		system('rm -rf -- '.escapeshellarg($this->folder));
		parent::tearDown();
	}

	// renders view-exception.php as ErrorManager does: $errorMessage, and $ex/$extraMessagePage when given
	private function Render(string $errorMessage, ?\Exception $ex = null, ?string $extraMessagePage = null): string {
		ob_start();
		try {
			(function() use ($errorMessage, $ex, $extraMessagePage) {
				include(__DIR__."/../../Errors/views/view-exception.php");
			})();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	function testTheMessageIsEscaped() {
		$html = $this->Render("<script>alert(1)</script> & more");
		$this->assertStringContainsString("&lt;script&gt;alert(1)&lt;/script&gt; &amp; more", $html);
		$this->assertStringNotContainsString("<script>alert(1)", $html);
	}

	function testTheExceptionIsEscaped() {
		$ex = new \Exception("<img src=x onerror=alert(1)>");
		$html = $this->Render($ex->getMessage(), $ex);
		$this->assertStringNotContainsString("<img src=x", $html);
		$this->assertSame(2, substr_count($html, "&lt;img src=x onerror=alert(1)&gt;"), "the message, and the message in print_r()");
	}

	function testMissingConfigPageDoesntThrow() {
		Config::Instance()->SetConfig(null)->SetPath($this->folder."/<b>configs")->SetConfigFile("magrathea.conf");
		$ex = new MagratheaConfigException("File could not be found", null, 424041);
		$html = $this->Render($ex->getMessage(), $ex, "error-magratheaconf.php");
		$this->assertStringContainsString("Magrathea Configuration does not exists!", $html);
		$this->assertStringContainsString(htmlspecialchars($this->folder."/<b>configs/magrathea.conf"), $html);
		$this->assertStringNotContainsString("/<b>configs", $html);
		// the sample, as text
		$sample = file_get_contents(__DIR__."/../../Bootstrap/docs/magrathea.conf.sample");
		$this->assertStringContainsString(htmlspecialchars($sample), $html);
	}

	function testUnreadableConfigPage() {
		file_put_contents($this->folder."/broken.conf", "[general\nnot = an = ini");
		Config::Instance()->SetConfig(null)->SetPath($this->folder)->SetConfigFile("broken.conf");
		$html = $this->Render("There was an error trying to load the config file", null, "error-magratheaconf.php");
		$this->assertStringContainsString("Magrathea Configuration could not be read!", $html);
		$this->assertStringContainsString($this->folder."/broken.conf", $html);
	}

	function testGetConfigFile() {
		$this->assertSame("app.conf", Config::Instance()->SetConfigFile("app.conf")->GetConfigFile());
	}

}
