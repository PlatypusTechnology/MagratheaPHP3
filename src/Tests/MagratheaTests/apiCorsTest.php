<?php

use Magrathea\MagratheaApi;
use Magrathea\MagratheaPHP;
use Magrathea\Tests\TestsHelper;

class apiCorsTest extends \PHPUnit\Framework\TestCase {

	private $root;
	private $previousMagRoot;

	protected function setUp(): void {
		parent::setUp();
		$this->root = __DIR__."/test-dump/api-cors";
		system('rm -rf -- '.escapeshellarg($this->root));
		mkdir($this->root."/configs", 0777, true);
		$this->previousMagRoot = MagratheaPHP::Instance()->magRoot;
		MagratheaPHP::Instance()->magRoot = $this->root;
	}
	protected function tearDown(): void {
		MagratheaPHP::Instance()->magRoot = $this->previousMagRoot;
		system('rm -rf -- '.escapeshellarg($this->root));
		parent::tearDown();
	}

	private function acceptHeaders(MagratheaApi $api): array {
		return (fn() => $this->acceptControlAllowHeaders)->call($api);
	}

	function testCorsOriginsFileIsInTheConfigsFolder() {
		TestsHelper::Print("testing GetCorsOriginsFile() is configs/cors-origins.txt...");
		$this->assertSame("cors-origins.txt", MagratheaApi::CORS_ORIGINS_FILE);
		$this->assertSame($this->root."/configs/cors-origins.txt", MagratheaApi::GetCorsOriginsFile());
	}

	function testReadCorsOriginsSkipsCommentsAndBlankLines() {
		TestsHelper::Print("testing ReadCorsOrigins() trims lines and skips blanks and # comments...");
		file_put_contents(MagratheaApi::GetCorsOriginsFile(),
			"# production\r\n"
			."https://app.example.com\r\n"
			."\n"
			."   http://localhost:3000   \n"
			."\t# indented comment\n"
			."   \n"
			."capacitor://localhost"
		);
		$this->assertSame(
			["https://app.example.com", "http://localhost:3000", "capacitor://localhost"],
			MagratheaApi::ReadCorsOrigins()
		);
	}

	function testReadCorsOriginsFromAnotherFile() {
		TestsHelper::Print("testing ReadCorsOrigins(\$file) reads the given file...");
		$file = $this->root."/other.txt";
		file_put_contents($file, "https://other.example.com\n");
		$this->assertSame(["https://other.example.com"], MagratheaApi::ReadCorsOrigins($file));
	}

	function testReadCorsOriginsMissingFile() {
		TestsHelper::Print("testing ReadCorsOrigins() returns [] for a missing file or a folder...");
		$this->assertSame([], MagratheaApi::ReadCorsOrigins());
		$this->assertSame([], MagratheaApi::ReadCorsOrigins($this->root."/nope.txt"));
		$this->assertSame([], MagratheaApi::ReadCorsOrigins($this->root."/configs"));
	}

	function testAllowFromFileDoesntTouchAcceptedHeaders() {
		TestsHelper::Print("testing AllowFromFile() chains and leaves the accepted headers alone...");
		file_put_contents(MagratheaApi::GetCorsOriginsFile(), "https://app.example.com\n");
		$api = new MagratheaApi();
		$this->assertSame($api, $api->AllowFromFile());
		$this->assertSame($api, $api->AllowFromFile($this->root."/nope.txt"));
		$this->assertSame(["Authorization", "Content-Type"], $this->acceptHeaders($api));
	}

	function testAddAcceptHeadersAppendsWithoutDuplicates() {
		TestsHelper::Print("testing AddAcceptHeaders() appends and drops case-insensitive duplicates...");
		$api = new MagratheaApi();
		$this->assertSame($api, $api->AddAcceptHeaders("X-Api-Key"));
		$api->AddAcceptHeaders(["authorization", "Cache-Control", "x-api-key", "cache-control", "Pragma"]);
		$this->assertSame(
			["Authorization", "Content-Type", "X-Api-Key", "Cache-Control", "Pragma"],
			$this->acceptHeaders($api)
		);
	}

	function testSetAcceptHeadersReplaces() {
		TestsHelper::Print("testing SetAcceptHeaders() replaces the list...");
		$api = new MagratheaApi();
		$this->assertSame($api, $api->SetAcceptHeaders(["X-Api-Key"]));
		$this->assertSame(["X-Api-Key"], $this->acceptHeaders($api));
		$api->SetAcceptHeaders([]);
		$this->assertSame([], $this->acceptHeaders($api));
		$api->AddAcceptHeaders("Content-Type");
		$this->assertSame(["Content-Type"], $this->acceptHeaders($api));
	}

}
