<?php

use Magrathea\Admin\Features\AppConfig\AppConfig;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\UserLogs\AdminLog;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\Tests\TestsHelper;

// Database that records PrepareAndExecute() instead of running it
class FieldTypesRecordingDatabase extends Database {
	public $executed = [];
	public function PrepareAndExecute($query, $arrTypes, $arrValues) {
		$this->executed[] = ["query" => $query, "types" => $arrTypes, "values" => $arrValues];
		return 7;
	}
}

class FieldTypesUnknownModel extends MagratheaModel {
	protected $dbTable = "field_types_unknown";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
		"flag" => "bool",
	];
	public $id, $name, $flag;
	public function SetStrictTypes(bool $strict) { $this->strictTypes = $strict; }
}

class FieldTypesUnknownPkModel extends MagratheaModel {
	protected $dbTable = "field_types_unknown_pk";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "integer",
		"name" => "string",
	];
	public $id, $name;
}

class FieldTypesValidModel extends MagratheaModel {
	protected $dbTable = "field_types_valid";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"active" => "boolean",
		"name" => "string",
		"bio" => "text",
		"price" => "float",
		"amount" => "decimal",
		"seen_at" => "datetime",
		"born_on" => "date",
		"reference" => "uuid",
	];
	public $id, $active, $name, $bio, $price, $amount, $seen_at, $born_on, $reference;
}

/**
 * 3.0 item 7: MagratheaModel::TYPES is the one list of field types, with no aliases (2.x's
 * `integer` and `double` are gone). An unknown type throws where it's used: a write binding
 * that field, or a $strictTypes load of it. No extra check runs at runtime; ValidateTypes()
 * checks every type, for the admin/CLI checks.
 */
class modelFieldTypesTest extends \PHPUnit\Framework\TestCase {

	private $previousDb;
	private FieldTypesRecordingDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		// avoids the Debugger trying to log/display the exceptions this suite intentionally throws
		TestsHelper::Debug();
		$this->previousDb = Database::Instance();
		$this->db = FieldTypesRecordingDatabase::Instance();
		$this->db->executed = [];
		Database::MockClass($this->db);
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		parent::tearDown();
	}

	private function assertUnknownTypeThrows(string $type, callable $call) {
		try {
			$call();
			$this->fail("expected a MagratheaModelException for the unknown type [".$type."]");
		} catch(MagratheaModelException $ex) {
			$this->assertStringContainsString("Unknown field type [".$type."]", $ex->getMessage());
			$this->assertStringContainsString("use one of ".implode(", ", MagratheaModel::TYPES), $ex->getMessage());
		}
		$this->assertSame([], $this->db->executed, "no query runs when a type is unknown");
	}

	function testEveryTypeHasABindCategory() {
		TestsHelper::Print("testing every TYPES entry maps to a bind category...");
		foreach(MagratheaModel::TYPES as $type) {
			$this->assertContains(MagratheaModel::GetDataTypeFromField($type), ["integer", "decimal", "text", "date"], $type);
		}
	}

	public static function unknownTypes(): array {
		return [
			"bool" => ["bool"],
			"varchar" => ["varchar"],
			"integer (2.x alias)" => ["integer"],
			"double (2.x alias)" => ["double"],
		];
	}

	/**
	 * @dataProvider unknownTypes
	 */
	function testGetDataTypeFromFieldThrowsOnUnknownType(string $type) {
		TestsHelper::Print("testing GetDataTypeFromField() throws on [".$type."]...");
		$this->assertUnknownTypeThrows($type, fn() => MagratheaModel::GetDataTypeFromField($type));
	}

	function testInsertThrowsWhenItBindsTheField() {
		TestsHelper::Print("testing Insert() and InsertWithPk() throw on an unknown type they bind...");
		$this->assertUnknownTypeThrows("bool", function() {
			$model = new FieldTypesUnknownModel();
			$model->flag = true;
			$model->Insert();
		});
		$this->assertUnknownTypeThrows("bool", function() {
			$model = new FieldTypesUnknownModel();
			$model->id = 3;
			$model->flag = true;
			$model->InsertWithPk();
		});
	}

	function testUpdateThrowsWhenItWritesTheField() {
		TestsHelper::Print("testing Update() throws on an unknown type it writes...");
		$this->assertUnknownTypeThrows("bool", function() {
			$model = new FieldTypesUnknownModel();
			$model->id = 3;
			$model->Set("flag", true);
			$model->Update();
		});
	}

	function testDeleteThrowsOnAnUnknownPkType() {
		TestsHelper::Print("testing Delete() throws when the PK's type is unknown...");
		$this->assertUnknownTypeThrows("integer", function() {
			$model = new FieldTypesUnknownPkModel();
			$model->id = 3;
			$model->Delete();
		});
	}

	function testStrictLoadThrows() {
		TestsHelper::Print("testing a strictTypes load (the default) throws on an unknown type, naming the field...");
		$model = new FieldTypesUnknownModel();
		$this->assertUnknownTypeThrows("bool", fn() => $model->LoadObjectFromTableRow(["id" => "3", "flag" => "1"]));
		try {
			$model->LoadObjectFromTableRow(["id" => "3", "flag" => "1"]);
		} catch(MagratheaModelException $ex) {
			$this->assertStringContainsString("for field [flag]", $ex->getMessage());
		}
	}

	function testUnusedUnknownTypeDoesNotThrow() {
		TestsHelper::Print("testing an unknown type that no write or load uses doesn't throw at runtime...");
		// the trade-off: no runtime check of the whole $dbValues; ValidateTypes() (admin/CLI) finds these
		$model = new FieldTypesUnknownModel();
		$model->name = "no flag";
		$model->Insert();
		$model = new FieldTypesUnknownModel();
		$model->id = 3;
		$model->Set("name", "new");
		$model->Update();
		$model->Delete();
		// a loose load copies values without reading their types (a strict one reads "flag": see below)
		$model = new FieldTypesUnknownModel();
		$model->SetStrictTypes(false);
		$model->LoadObjectFromTableRow(["id" => "3", "flag" => "1"]);
		$this->assertSame("1", $model->flag);
		$this->assertCount(3, $this->db->executed);
	}

	function testValidateTypesNamesModelAndField() {
		TestsHelper::Print("testing ValidateTypes() checks every type, naming the model and the field...");
		$this->assertUnknownTypeThrows("bool", fn() => (new FieldTypesUnknownModel())->ValidateTypes());
		try {
			(new FieldTypesUnknownModel())->ValidateTypes();
		} catch(MagratheaModelException $ex) {
			$this->assertStringContainsString("FieldTypesUnknownModel::\$dbValues[flag]", $ex->getMessage());
		}
	}

	function testEveryTypeWritesAndLoads() {
		TestsHelper::Print("testing a model using every TYPES entry passes ValidateTypes(), writes and loads strictly...");
		$model = new FieldTypesValidModel();
		$model->ValidateTypes();
		$model->name = "Widget";
		$model->Insert();
		// never loaded: Update() writes every field (an inserted object writes only what changed since)
		$model = new FieldTypesValidModel();
		$model->id = 3;
		$model->name = "Gadget";
		$model->Update();
		$model->Delete();
		$this->assertCount(3, $this->db->executed);
		$model = new FieldTypesValidModel();
		$model->LoadObjectFromTableRow([
			"id" => "3", "active" => "1", "name" => "Widget", "bio" => "A widget.", "price" => "9.5",
			"amount" => "1.00", "seen_at" => "2020-01-01 00:00:00", "born_on" => "2020-01-01",
			"reference" => "0190f1a0-0000-7000-8000-000000000000", "created_at" => "2020-01-01 00:00:00",
		]);
		$this->assertSame(3, $model->id);
		$this->assertSame("2020-01-01", $model->born_on);
	}

	public static function frameworkModels(): array {
		return [
			"AdminUser" => [AdminUser::class],
			"AppConfig" => [AppConfig::class],
			"AdminLog" => [AdminLog::class],
		];
	}

	/**
	 * @dataProvider frameworkModels
	 */
	function testFrameworkModelsUseOnlyTypes(string $class) {
		TestsHelper::Print("testing ".$class."'s \$dbValues use only MagratheaModel::TYPES...");
		$model = new $class();
		foreach($model->GetDbValues() as $field => $type) {
			$this->assertContains($type, MagratheaModel::TYPES, $class."::\$dbValues[".$field."]");
		}
	}

}
