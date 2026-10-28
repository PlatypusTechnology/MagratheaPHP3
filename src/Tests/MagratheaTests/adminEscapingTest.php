<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminForm;
use Magrathea\DB\Database;
use Magrathea\DB\DatabaseSimulate;
use Magrathea\MagratheaModel;
use Magrathea\Tests\TestsHelper;

class EscapingTestModel extends MagratheaModel {
	protected $dbTable = "escaping_test";
	protected $dbPk = "id";
	protected $dbValues = [ "id" => "int", "name" => "string" ];
	public $id, $name, $created_at, $updated_at;
	public function __construct($id = null, $name = null) {
		$this->id = $id;
		$this->name = $name;
	}
	public function Ref(): string { return (string)$this->name; }
}

class adminEscapingTest extends \PHPUnit\Framework\TestCase {

	const XSS = '"><script>alert(1)</script>';
	const XSS_HTML = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';

	private $previousDb;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		Database::MockClass(DatabaseSimulate::Instance());
		$_GET = [];
		$_POST = [];
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		$_GET = [];
		$_POST = [];
		parent::tearDown();
	}

	private function Buffer(callable $fn): string {
		$elements = AdminElements::Instance()->Buffer();
		try {
			$fn($elements);
		} finally {
			$output = $elements->Get();
		}
		return $output;
	}
	private function RenderAction(string $action): string {
		ob_start();
		try {
			include(__DIR__."/../../Admin/views/actions/".$action.".php");
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}
	private function assertEscaped(string $output, string $message = "") {
		$this->assertStringNotContainsString("<script>", $output, $message);
		$this->assertStringContainsString(self::XSS_HTML, $output, $message);
	}

	function testTableEscapesArrayRows() {
		TestsHelper::Print("testing Table() escapes values of array rows...");
		$output = $this->Buffer(fn($e) => $e->Table([["name" => self::XSS, "n" => 42, "nothing" => null, "list" => ["<b>", 1]]]));
		$this->assertEscaped($output);
		$this->assertStringContainsString("<td >42</td>", $output);
		$this->assertStringContainsString("<td ></td>", $output);
		$this->assertStringContainsString("<td >[&quot;&lt;b&gt;&quot;,1]</td>", $output);
	}

	function testTableEscapesModelRows() {
		TestsHelper::Print("testing Table() escapes values of model rows...");
		$output = $this->Buffer(fn($e) => $e->Table([new EscapingTestModel(7, self::XSS)], ["id" => "#", "name" => "Name"]));
		$this->assertEscaped($output);
		$this->assertStringContainsString('<td scope="row">7</td>', $output);
	}

	function testTableCallablesStayRaw() {
		TestsHelper::Print("testing Table() prints callable columns and callable row values as HTML...");
		$output = $this->Buffer(fn($e) => $e->Table(
			[["name" => self::XSS, "link" => fn($r) => "<a href='#'>row</a>"]],
			[
				"name" => "Name",
				"link" => "",
				["title" => "Edit", "key" => fn($r) => "<a href='#'>".htmlspecialchars($r["name"])."</a>"],
			]
		));
		$this->assertStringContainsString("<a href='#'>row</a>", $output);
		$this->assertStringContainsString("<a href='#'>".self::XSS_HTML."</a>", $output);
		$this->assertStringNotContainsString("<script>", $output);
	}

	function testFormElementsEscapeValues() {
		TestsHelper::Print("testing Input, Textarea, Select, date and Checkbox escape their values...");
		$this->assertEscaped($this->Buffer(fn($e) => $e->Input("text", "f", "F", self::XSS)), "input");
		$this->assertEscaped($this->Buffer(fn($e) => $e->Input("disabled", "f", "F", self::XSS)), "disabled");
		$this->assertEscaped($this->Buffer(fn($e) => $e->Input("date", "f", "F", self::XSS)), "date");
		$this->assertEscaped($this->Buffer(fn($e) => $e->Checkbox("f", "F", self::XSS)), "checkbox");
		$textarea = $this->Buffer(fn($e) => $e->Textarea("f", "F", "</textarea>".self::XSS));
		$this->assertStringContainsString("&lt;/textarea&gt;".self::XSS_HTML."</textarea>", $textarea);
		$select = $this->Buffer(fn($e) => $e->Select("f", "F", [self::XSS => self::XSS, "ok" => "fine"], "ok"));
		$this->assertEscaped($select, "select");
		$this->assertStringContainsString('<option value="'.self::XSS_HTML.'" >'.self::XSS_HTML.'</option>', $select);
		$this->assertStringContainsString('<option value="ok" selected>fine</option>', $select);
		$models = $this->Buffer(fn($e) => $e->Select("f", "F", [3 => new EscapingTestModel(3, self::XSS)]));
		$this->assertEscaped($models, "select Ref()");
	}

	function testFormEscapesHiddenAndEmptyValues() {
		TestsHelper::Print("testing AdminForm escapes hidden and empty values, but not callable values...");
		$form = new AdminForm();
		$form->SetName("escaping")->Build([
			["type" => "hidden", "key" => "h"],
			["type" => "empty", "key" => "e"],
			["type" => "empty", "key" => "html"],
			["type" => "text", "key" => "t"],
		], ["h" => self::XSS, "e" => self::XSS, "html" => fn() => "<b>trusted</b>", "t" => self::XSS]);
		ob_start();
		$form->Print();
		$output = ob_get_clean();
		$this->assertEscaped($output);
		$this->assertSame(3, substr_count($output, self::XSS_HTML));
		$this->assertStringContainsString('<div id="html" class=""><b>trusted</b></div>', $output);
	}

	function testQueryRunCardEscapes() {
		TestsHelper::Print("testing the Queries page result card escapes the query...");
		class_exists(\Magrathea\DB\Query::class); // declares the QueryType enum, as the admin always has
		$_POST = ["q" => "UPDATE x SET a = '".self::XSS."'"];
		$output = $this->RenderAction("query-run-card");
		$this->assertEscaped($output);
	}

}
