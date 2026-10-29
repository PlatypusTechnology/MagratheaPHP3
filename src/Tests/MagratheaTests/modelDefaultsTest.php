<?php

namespace ModelDefaultsTest;

use Magrathea\Admin\Features\AppConfig\AppConfig;
use Magrathea\Admin\Features\AppConfig\AppConfigControl;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Admin\Features\UserLogs\AdminLog;
use Magrathea\Admin\Features\UserLogs\AdminLogControl;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\MagratheaModelControl;
use Magrathea\Tests\TestsHelper;

// Database that records QueryRow() instead of running it, and answers with $row
class DefaultsRecordingDatabase extends Database {
	public $queries = [];
	public $row = [];
	public function QueryRow(string $sql): array|object {
		$this->queries[] = $sql;
		return $this->row;
	}
}

// only properties and MagratheaStart(): no constructor, $autoload, timestamps or GetControl()
class Gizmo extends MagratheaModel {
	public $id, $name;
	public function MagratheaStart() {
		$this->dbTable = "gizmos";
		$this->dbPk = "id";
		$this->dbValues = [ "id" => "int", "name" => "string" ];
	}
}
class GizmoControl extends MagratheaModelControl {
	protected static $modelNamespace = "ModelDefaultsTest";
	protected static $modelName = "Gizmo";
	protected static $dbTable = "gizmos";
}

// properties as defaults, no MagratheaStart()
class Sprocket extends MagratheaModel {
	protected $dbTable = "sprockets";
	protected $dbPk = "id";
	protected $dbValues = [ "id" => "int", "name" => "string" ];
	public $id, $name;
}

// Base split: the control follows the concrete class
class ThingBase extends MagratheaModel {
	public $id, $name;
	public function MagratheaStart() {
		$this->dbTable = "things";
		$this->dbPk = "id";
		$this->dbValues = [ "id" => "int", "name" => "string" ];
	}
}
class Thing extends ThingBase {}
class ThingControl extends MagratheaModelControl {
	protected static $modelNamespace = "ModelDefaultsTest";
	protected static $modelName = "Thing";
	protected static $dbTable = "things";
}

// the shape the generator wrote (2.x)
class LegacyWidget extends MagratheaModel {
	public $id, $name;
	public $created_at, $updated_at;
	protected $autoload = null;
	public $constructed = false;
	public function __construct($id = 0) {
		$this->constructed = true;
		$this->MagratheaStart();
		if( !empty($id) ){
			$pk = $this->dbPk;
			$this->$pk = $id;
			$this->GetById($id);
		}
	}
	public function MagratheaStart() {
		$this->dbTable = "widgets";
		$this->dbPk = "id";
		$this->dbValues["id"] = "int";
		$this->dbValues["name"] = "string";
		$this->dbValues["created_at"] = "datetime";
		$this->dbValues["updated_at"] = "datetime";
	}
	public function GetControl() {
		return new GizmoControl();
	}
}

/**
 * MagratheaModel's defaults (3.0 item 6): constructor, MagratheaStart(), $autoload,
 * $created_at/$updated_at and GetControl(), so a hand-written model needs none of them.
 */
class modelDefaultsTest extends \PHPUnit\Framework\TestCase {

	private $previousDb;
	private DefaultsRecordingDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		$this->db = DefaultsRecordingDatabase::Instance();
		$this->db->queries = [];
		$this->db->row = [];
		Database::MockClass($this->db);
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		parent::tearDown();
	}

	private function Row(string $table, array $fields): array {
		$row = [];
		foreach($fields as $field => $value) $row[$table."/".$field] = $value;
		return $row;
	}

	function testMinimalModelLoadsById() {
		TestsHelper::Print("testing a model with only properties and MagratheaStart() loads by id...");
		$this->db->row = $this->Row("gizmos", [
			"id" => "5", "name" => "spinner",
			"created_at" => "2026-01-01 10:00:00", "updated_at" => "2026-01-02 10:00:00",
		]);
		$gizmo = new Gizmo(5);
		$this->assertCount(1, $this->db->queries);
		$this->assertStringContainsString("FROM `gizmos`", $this->db->queries[0]);
		$this->assertStringContainsString("gizmos.id = 5", $this->db->queries[0]);
		$this->assertSame("spinner", $gizmo->name);
		$this->assertSame("2026-01-01 10:00:00", $gizmo->created_at);
		$this->assertSame("2026-01-02 10:00:00", $gizmo->updated_at);
	}

	function testMinimalModelToJson() {
		TestsHelper::Print("testing ToJson() works on a model that doesn't declare the timestamps...");
		$this->db->row = $this->Row("gizmos", [
			"id" => "5", "name" => "spinner",
			"created_at" => "2026-01-01 10:00:00", "updated_at" => "2026-01-02 10:00:00",
		]);
		$json = (new Gizmo(5))->ToJson();
		$this->assertSame(Gizmo::class, $json["object"]);
		$this->assertSame(5, $json["id"]);
		$this->assertSame("2026-01-01 10:00:00", $json["created_at"]);
		$this->assertSame("2026-01-02 10:00:00", $json["updated_at"]);
		$this->assertSame(["id" => 5, "name" => "spinner"], $json["fields"]);
		$this->assertNull((new Gizmo())->ToJson()["created_at"]);
	}

	function testTimestampsArePublic() {
		TestsHelper::Print("testing json_encode() of a model carries created_at/updated_at...");
		$gizmo = new Gizmo();
		$gizmo->created_at = "2026-01-01 10:00:00";
		$encoded = json_decode(json_encode($gizmo), true);
		$this->assertSame("2026-01-01 10:00:00", $encoded["created_at"]);
		$this->assertArrayHasKey("updated_at", $encoded);
	}

	function testNoIdRunsMagratheaStartAndNoQuery() {
		TestsHelper::Print("testing new Model() runs MagratheaStart() and no query...");
		$gizmo = new Gizmo();
		$this->assertSame("gizmos", $gizmo->GetDbTable());
		$this->assertSame("id", $gizmo->GetPkName());
		$this->assertNull($gizmo->id);
		new Gizmo(0);
		new Gizmo("");
		new Gizmo(null);
		$this->assertCount(0, $this->db->queries);
	}

	function testPropertyDefaultsWithoutMagratheaStart() {
		TestsHelper::Print("testing a model declaring its table as property defaults loads by id...");
		$this->db->row = $this->Row("sprockets", [ "id" => "3", "name" => "cog" ]);
		$sprocket = new Sprocket(3);
		$this->assertStringContainsString("FROM `sprockets`", $this->db->queries[0]);
		$this->assertSame("cog", $sprocket->name);
	}

	function testMissingRowThrows() {
		TestsHelper::Print("testing new Model(\$id) throws when the row doesn't exist...");
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionMessage("Could not find ".Gizmo::class." with id 9");
		new Gizmo(9);
	}

	function testInvalidIdThrows() {
		TestsHelper::Print("testing new Model(\$id) validates an int PK before querying...");
		try {
			new Gizmo("5 OR 1=1");
			$this->fail("no exception");
		} catch(MagratheaModelException $ex) {
			$this->assertSame(400, $ex->getCode());
		}
		$this->assertCount(0, $this->db->queries);
	}

	function testAutoloadDefaultsToNull() {
		TestsHelper::Print("testing \$autoload is declared and null by default...");
		$prop = new \ReflectionProperty(MagratheaModel::class, "autoload");
		$this->assertTrue($prop->isProtected());
		$this->assertNull($prop->getValue(new Gizmo()));
	}

	function testGetControlByConvention() {
		TestsHelper::Print("testing GetControl() returns a new <model class>Control...");
		$this->assertInstanceOf(GizmoControl::class, (new Gizmo())->GetControl());
		$this->assertNotSame((new Gizmo())->GetControl(), (new Gizmo())->GetControl());
	}

	function testGetControlFollowsConcreteClass() {
		TestsHelper::Print("testing GetControl() on a Base-split model uses the concrete class's control...");
		$this->assertInstanceOf(ThingControl::class, (new Thing())->GetControl());
	}

	function testMissingControlThrows() {
		TestsHelper::Print("testing GetControl() throws naming the control it looked for...");
		try {
			(new Sprocket())->GetControl();
			$this->fail("no exception");
		} catch(MagratheaModelException $ex) {
			$this->assertStringContainsString("ModelDefaultsTest\\SprocketControl", $ex->getMessage());
			$this->assertStringContainsString("GetControl()", $ex->getMessage());
		}
	}

	function testLegacyShapeBehavesAsBefore() {
		TestsHelper::Print("testing a model with its own constructor, \$autoload, timestamps and GetControl()...");
		$this->db->row = $this->Row("widgets", [
			"id" => "4", "name" => "legacy",
			"created_at" => "2026-01-01 10:00:00", "updated_at" => "2026-01-02 10:00:00",
		]);
		$widget = new LegacyWidget(4);
		$this->assertTrue($widget->constructed);
		$this->assertCount(1, $this->db->queries);
		$this->assertSame("legacy", $widget->name);
		$this->assertSame("2026-01-01 10:00:00", $widget->ToJson()["created_at"]);
		$this->assertInstanceOf(GizmoControl::class, $widget->GetControl());
	}

	public static function frameworkModels(): array {
		return [
			"AdminUser" => [AdminUser::class, AdminUserControl::class, "_magrathea_users", ["email" => "a@b.c"]],
			"AppConfig" => [AppConfig::class, AppConfigControl::class, "_magrathea_config", ["name" => "key"]],
			"AdminLog" => [AdminLog::class, AdminLogControl::class, "_magrathea_logs", ["action" => "login"]],
		];
	}

	/**
	 * @dataProvider frameworkModels
	 */
	function testFrameworkModelsStillLoad(string $class, string $control, string $table, array $fields) {
		TestsHelper::Print("testing ".$class." still loads by id...");
		$empty = new $class();
		$this->assertSame($table, $empty->GetDbTable());
		$this->assertCount(0, $this->db->queries);

		$this->db->row = $this->Row($table, ["id" => "2"] + $fields + ["created_at" => "2026-01-01 10:00:00"]);
		$model = new $class(2);
		$this->assertStringContainsString("FROM `".$table."`", $this->db->queries[0]);
		$this->assertSame(2, $model->id);
		foreach($fields as $field => $value) $this->assertSame($value, $model->$field);
		$this->assertSame("2026-01-01 10:00:00", $model->ToJson()["created_at"]);
		$this->assertInstanceOf($control, $model->GetControl());
	}

	/**
	 * @dataProvider frameworkModels
	 */
	function testFrameworkModelsUseTheDefaults(string $class) {
		TestsHelper::Print("testing ".$class." relies on MagratheaModel's defaults...");
		foreach(["created_at", "updated_at", "autoload"] as $prop) {
			$this->assertSame(MagratheaModel::class, (new \ReflectionProperty($class, $prop))->getDeclaringClass()->getName());
		}
		$this->assertSame(MagratheaModel::class, (new \ReflectionMethod($class, "__construct"))->getDeclaringClass()->getName());
		$this->assertNotContains(\Magrathea\iMagratheaModel::class, class_implements($class));
	}
}
