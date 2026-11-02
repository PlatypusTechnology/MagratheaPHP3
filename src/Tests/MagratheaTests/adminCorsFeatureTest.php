<?php

use Magrathea\MagratheaApi;
use Magrathea\MagratheaPHP;
use Magrathea\Admin\AdminUsers;
use Magrathea\Admin\Features\Cors\AdminFeatureCors;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Tests\TestsHelper;

class adminCorsFeatureTest extends \PHPUnit\Framework\TestCase {

	private $root;
	private $file;
	private $previousMagRoot;

	protected function setUp(): void {
		parent::setUp();
		$this->root = __DIR__."/test-dump/admin-cors";
		system('rm -rf -- '.escapeshellarg($this->root));
		mkdir($this->root."/configs", 0777, true);
		$this->previousMagRoot = MagratheaPHP::Instance()->magRoot;
		MagratheaPHP::Instance()->magRoot = $this->root;
		$this->file = $this->root."/configs/cors-origins.txt";
		$_POST = [];
		$_SERVER["REQUEST_METHOD"] = "POST";
	}
	protected function tearDown(): void {
		$this->logUserOut();
		MagratheaPHP::Instance()->magRoot = $this->previousMagRoot;
		system('rm -rf -- '.escapeshellarg($this->root));
		$_POST = [];
		unset($_SERVER["REQUEST_METHOD"]);
		http_response_code(200);
		parent::tearDown();
	}

	/** The page header prints the logged user's card */
	private function logUserIn(): void {
		$user = new AdminUser();
		$user->id = 1;
		$user->email = "admin@example.com";
		$user->role_id = 1;
		$user->active = true;
		(function() use ($user) {
			$_SESSION[$this->sessionName] = 1;
			$this->loggedUser = $user;
			$this->loggedUserId = 1;
		})->call(AdminUsers::Instance());
	}
	private function logUserOut(): void {
		(function() {
			unset($_SESSION[$this->sessionName]);
			$this->loggedUser = null;
			$this->loggedUserId = null;
		})->call(AdminUsers::Instance());
	}

	private function run_action(string $action, array $post = []): string {
		$_POST = $post;
		ob_start();
		try {
			(new AdminFeatureCors())->$action();
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}

	public static function origins(): array {
		return [
			"https" => ["https://app.example.com", true],
			"http with port" => ["http://localhost:3000", true],
			"capacitor" => ["capacitor://localhost", true],
			"ionic" => ["ionic://localhost", true],
			"ipv6 with port" => ["http://[::1]:3000", true],
			"ipv4" => ["http://127.0.0.1:8080", true],
			"scheme with +.-" => ["chrome-extension+x.y://abcdef", true],
			"star" => ["*", false],
			"null" => ["null", false],
			"trailing slash" => ["https://a.com/", false],
			"path" => ["https://a.com/app", false],
			"query" => ["https://a.com?x=1", false],
			"uppercase scheme" => ["HTTPS://a.com", false],
			"uppercase host" => ["https://A.com", false],
			"no scheme" => ["a.com", false],
			"empty host" => ["https://", false],
			"port too long" => ["https://a.com:123456", false],
			"empty port" => ["https://a.com:", false],
			"user info" => ["https://u@a.com", false],
			"trailing newline" => ["https://a.com\n", false],
			"surrounding space" => [" https://a.com", false],
			"double dot" => ["https://a..com", false],
		];
	}

	/** @dataProvider origins */
	function testIsValidOrigin(string $origin, bool $valid) {
		$this->assertSame($valid, AdminFeatureCors::IsValidOrigin($origin), json_encode($origin));
	}

	function testFeatureIdentity() {
		TestsHelper::Print("testing the CORS feature's id and name...");
		$feature = new AdminFeatureCors();
		$this->assertSame("AdminFeatureCors", $feature->featureId);
		$this->assertSame("CORS Origins", $feature->featureName);
		$this->assertTrue($feature->IsDispatchable("Save"));
		$this->assertFalse($feature->IsDispatchable("IsValidOrigin"));
	}

	function testSaveRequiresPost() {
		TestsHelper::Print("testing CORS Save() answers 405 to GET and writes nothing...");
		$_SERVER["REQUEST_METHOD"] = "GET";
		$html = $this->run_action("Save", ["origins" => "https://app.example.com"]);
		$this->assertSame(405, http_response_code());
		$this->assertStringContainsString("alert-danger", $html);
		$this->assertFileDoesNotExist($this->file);
	}

	function testSaveWritesValidLinesAndKeepsComments() {
		TestsHelper::Print("testing CORS Save() writes trimmed lines, keeps comments, drops blank lines...");
		$html = $this->run_action("Save", ["origins" =>
			"# production\r\n  https://app.example.com  \r\n\r\n\rhttp://localhost:3000\n   \n#  dev\ncapacitor://localhost"
		]);
		$this->assertStringContainsString("alert-success", $html);
		$this->assertStringContainsString("Saved 3 origins", $html);
		$this->assertSame(
			"# production\nhttps://app.example.com\nhttp://localhost:3000\n#  dev\ncapacitor://localhost\n",
			file_get_contents($this->file)
		);
		$this->assertSame(
			["https://app.example.com", "http://localhost:3000", "capacitor://localhost"],
			MagratheaApi::ReadCorsOrigins()
		);
	}

	function testSaveEmptyClearsTheFile() {
		TestsHelper::Print("testing CORS Save() with nothing posted empties the file...");
		file_put_contents($this->file, "https://app.example.com\n");
		$html = $this->run_action("Save", ["origins" => " \n "]);
		$this->assertStringContainsString("alert-success", $html);
		$this->assertSame("", file_get_contents($this->file));
	}

	function testSaveRefusesInvalidLinesAndEscapesThem() {
		TestsHelper::Print("testing CORS Save() writes nothing when a line is invalid, and escapes it...");
		file_put_contents($this->file, "https://old.example.com\n");
		$html = $this->run_action("Save", ["origins" =>
			"https://app.example.com\nhttps://a.com/\n<img src=x onerror=alert(1)>\n# fine comment"
		]);
		$this->assertSame(200, http_response_code());
		$this->assertStringContainsString("alert-danger", $html);
		$this->assertStringContainsString("<code>https://a.com/</code>", $html);
		$this->assertStringContainsString("&lt;img src=x onerror=alert(1)&gt;", $html);
		$this->assertStringNotContainsString("<img src=x", $html);
		$this->assertStringNotContainsString("<code>https://app.example.com</code>", $html);
		$this->assertStringNotContainsString("fine comment", $html);
		$this->assertSame("https://old.example.com\n", file_get_contents($this->file));
	}

	function testSaveReportsWriteFailure() {
		TestsHelper::Print("testing CORS Save() reports a file it can't write...");
		MagratheaPHP::Instance()->magRoot = $this->root."/missing";
		$html = $this->run_action("Save", ["origins" => "https://app.example.com"]);
		$this->assertStringContainsString("alert-danger", $html);
		$this->assertStringContainsString(htmlspecialchars($this->root."/missing/configs/cors-origins.txt"), $html);
	}

	function testIndexShowsMissingFile() {
		TestsHelper::Print("testing the CORS page says the file isn't created yet...");
		$this->logUserIn();
		$html = $this->run_action("Index");
		$this->assertStringContainsString("not created yet", $html);
		$this->assertStringContainsString(htmlspecialchars($this->file), $html);
		$this->assertStringContainsString('id="cors-origins"', $html);
		$this->assertStringContainsString("saveCorsOrigins(", $html);
	}

	function testIndexEscapesContent() {
		TestsHelper::Print("testing the CORS page escapes the file's content...");
		file_put_contents($this->file, "https://app.example.com\n</textarea><script>alert(1)</script>\n");
		$this->logUserIn();
		$html = $this->run_action("Index");
		$this->assertStringNotContainsString("not created yet", $html);
		$this->assertStringContainsString("https://app.example.com", $html);
		$this->assertStringContainsString("&lt;/textarea&gt;&lt;script&gt;", $html);
		$this->assertStringNotContainsString("<script>alert(1)", $html);
	}

}
