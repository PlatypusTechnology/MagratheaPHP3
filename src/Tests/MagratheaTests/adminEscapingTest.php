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

// Database for Schema with one table, x (column a): answers its information_schema queries, records anything else
class EscapingSchemaDatabase extends Database {
	public $ran = [];
	public function Escape($value): string {
		return addslashes((string)$value);
	}
	public function QueryAll(string $sql) {
		if(str_contains($sql, "information_schema.TABLES")) return [["name" => "x", "type" => "BASE TABLE", "engine" => "InnoDB", "rows" => 0, "collation" => "", "comment" => ""]];
		if(str_contains($sql, "information_schema.COLUMNS")) return [["name" => "a", "type" => "varchar(9)", "nullable" => "YES", "default" => null, "extra" => "", "key" => ""]];
		$this->ran[] = $sql;
		return [];
	}
	public function QueryRow(string $sql): array|object {
		if(str_contains($sql, "information_schema.TABLES") && str_contains($sql, "TABLE_NAME = 'x'")) return ["name" => "x"];
		if(str_contains($sql, "information_schema")) return [];
		$this->ran[] = $sql;
		return [];
	}
	public function QueryStream(string $sql): \Generator {
		$this->ran[] = $sql;
		yield from [];
	}
	public function QueryMulti($queries, $killable = true) {
		$this->ran[] = $queries;
		return [];
	}
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

	function testTableEscapesTitlesFromKeys() {
		TestsHelper::Print("testing Table() escapes titles taken from the rows' keys, not the ones passed in \$cols...");
		$output = $this->Buffer(fn($e) => $e->Table([[self::XSS => "x", 3 => "y"]]));
		$this->assertStringContainsString('<th scope="col">'.str_replace("&quot;", "&quot;", self::XSS_HTML).'</th>', $output);
		$this->assertStringContainsString('<th scope="col">3</th>', $output);
		$this->assertStringNotContainsString("<script>", $output);
		$passed = $this->Buffer(fn($e) => $e->Table([["a" => "x"]], [["title" => "&nbsp;", "key" => "a"]]));
		$this->assertStringContainsString('<th scope="col">&nbsp;</th>', $passed);
	}

	function testFindingsEscape() {
		TestsHelper::Print("testing Findings() escapes messages and fixes, with `code` spans as <code>...");
		$output = $this->Buffer(fn($e) => $e->Findings([
			["level" => \Magrathea\TimezoneCheck::ERROR, "message" => "`t`.`c` ".self::XSS, "fix" => "ALTER TABLE `t` -- ".self::XSS],
			["level" => \Magrathea\TimezoneCheck::OK, "message" => "fine"],
			["level" => "bogus", "message" => "unknown level"],
		]));
		$this->assertEscaped($output);
		$this->assertStringContainsString('<div class="alert alert-danger py-1 mb-1"><code>t</code>.<code>c</code> '.self::XSS_HTML, $output);
		$this->assertStringContainsString('<pre class="code-light mb-0 mt-1">ALTER TABLE `t` -- '.self::XSS_HTML.'</pre>', $output);
		$this->assertStringContainsString('<div class="alert alert-success py-1 mb-1">fine</div>', $output);
		$this->assertStringContainsString('<div class="alert alert-secondary py-1 mb-1">unknown level</div>', $output);
	}

	function testCheckReportEscapes() {
		TestsHelper::Print("testing the Checks page's report escapes messages, fixes and unknown groups' findings...");
		$report = \Magrathea\Admin\CheckReport::Group([
			["level" => \Magrathea\TimezoneCheck::WARNING, "message" => "Cache: `".self::XSS."` doesn't exist.", "group" => "folders"],
			["level" => \Magrathea\TimezoneCheck::ERROR, "message" => "`t`.`updated_at` ".self::XSS, "group" => "schema", "table" => "t",
				"fix" => "ALTER TABLE `t` -- ".self::XSS],
			["level" => \Magrathea\TimezoneCheck::INFO, "message" => self::XSS, "group" => self::XSS],
		]);
		$output = $this->Buffer(fn($e) => $e->CheckReport($report));
		$this->assertStringNotContainsString("<script>", $output);
		// the folder message, the fix twice (the finding and the section's block), the unknown group's message
		$this->assertSame(5, substr_count($output, self::XSS_HTML));
		$this->assertStringContainsString('<pre class="code-light" id="checks-fixes-timestamps">ALTER TABLE `t` -- '.self::XSS_HTML.'</pre>', $output);
		$this->assertStringContainsString('id="checks-other"', $output);
		$this->assertStringContainsString('<span class="badge bg-warning">warning</span>', $output);
		$this->assertStringContainsString('<span class="badge bg-light text-muted">no findings</span>', $output);
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

	function testBooleansRenderAsOneAndZero() {
		TestsHelper::Print("testing Table() and AdminForm render strict-typed booleans as 1/0, not \"\"...");
		$table = $this->Buffer(fn($e) => $e->Table([["on" => true, "off" => false]]));
		$this->assertStringContainsString('<td scope="row">1</td><td >0</td>', $table);
		$form = new AdminForm();
		$form->SetName("booleans")->Build([
			["type" => "text", "key" => "off"],
			["type" => "hidden", "key" => "on"],
			["type" => "checkbox", "key" => "flag"],
		], ["off" => false, "on" => true, "flag" => false]);
		ob_start();
		$form->Print();
		$output = ob_get_clean();
		$this->assertStringContainsString('name="off" id="off" value="0"', $output);
		$this->assertStringContainsString('name="on" id="on" value="1"', $output);
		$this->assertStringNotContainsString("checked", $output);
	}

	function testQueryRunCardEscapes() {
		TestsHelper::Print("testing the Queries page result card escapes the query...");
		class_exists(\Magrathea\DB\Query::class); // declares the QueryType enum, as the admin always has
		$_POST = ["q" => "UPDATE x SET a = '".self::XSS."'"];
		$output = $this->RenderAction("query-run-card");
		$this->assertEscaped($output);
	}

	function testQueryEditorCardEscapes() {
		TestsHelper::Print("testing the Query editor card escapes the PHP, the SQL and the spec it carries...");
		class_exists(\Magrathea\DB\Query::class);
		Database::MockClass(EscapingSchemaDatabase::Instance());
		// a write, run but not confirmed: the card shows the PHP, the SQL and the spec to post again; nothing runs
		$spec = ["type" => "delete", "table" => "x", "where" => [["column" => "a", "operator" => "LIKE", "value" => self::XSS]]];
		$_POST = ["spec" => json_encode($spec), "run" => "1"];
		$output = $this->RenderAction("query-editor-card");
		$this->assertStringContainsString('class="code-light qe-php"', $output, "the card rendered");
		$this->assertStringContainsString("confirm-spec", $output, "a write asks first");
		$this->assertStringNotContainsString("<script>", $output);
		$this->assertStringContainsString("&lt;script&gt;", $output);
		$this->assertSame([], EscapingSchemaDatabase::Instance()->ran, "nothing ran");

		$_POST = ["spec" => json_encode(["type" => "select", "table" => self::XSS])];
		$output = $this->RenderAction("query-editor-card");
		$this->assertEscaped($output, "the error names the table, escaped");
	}

}
