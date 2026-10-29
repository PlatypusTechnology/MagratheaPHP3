<?php

use Magrathea\Admin\Features\AppConfig\AppConfig;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\UserLogs\AdminLog;
use Magrathea\MagratheaModel;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\Tests\TestsHelper;

class StrictTypesTestModel extends MagratheaModel {
	protected $dbTable = "strict_types_test";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
		"bio" => "text",
		"price" => "float",
		"amount" => "decimal",
		"active" => "boolean",
		"born_on" => "date",
		"reference" => "uuid",
	];
	public $id, $name, $bio, $price, $amount, $active, $born_on, $reference, $created_at, $updated_at;
	public $extra_joined_column;
	public function Insert() {}
	public function Update() {}
}

/** the 3.0 migration pin: a model that keeps 2.x's raw strings */
class LooseStrictTypesTestModel extends StrictTypesTestModel {
	protected $strictTypes = false;
}

class modelStrictTypesTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		// avoids the Debugger trying to log/display the exceptions this suite intentionally throws
		TestsHelper::Debug();
	}

	private function row(array $overrides = []): array {
		return array_merge([
			"id" => "42",
			"name" => "Widget",
			"bio" => "A widget.",
			"price" => "9.99",
			"amount" => "12345678901234567.89",
			"active" => "1",
			"born_on" => "2020-01-01",
			"reference" => "0190f1a0-0000-7000-8000-000000000000",
			"created_at" => "2020-01-01 00:00:00",
		], $overrides);
	}

	function testLooseModeKeepsRawStrings() {
		TestsHelper::Print("testing a model pinned to strictTypes=false leaves values as the driver returned them");
		$model = new LooseStrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row());
		$this->assertSame("42", $model->id);
		$this->assertSame("9.99", $model->price);
		$this->assertSame("1", $model->active);
	}

	function testStrictModeCastsDeclaredTypes() {
		TestsHelper::Print("testing strictTypes is on by default: int/float/boolean are cast to native PHP types");
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row());
		$this->assertSame(42, $model->id);
		$this->assertIsInt($model->id);
		$this->assertSame(9.99, $model->price);
		$this->assertIsFloat($model->price);
		$this->assertTrue($model->active);
		$this->assertIsBool($model->active);
	}

	function testStrictModeLeavesTextDateUuidUntouched() {
		TestsHelper::Print("testing strictTypes leaves string/text/date/uuid fields as strings");
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row());
		$this->assertSame("Widget", $model->name);
		$this->assertSame("A widget.", $model->bio);
		$this->assertSame("2020-01-01", $model->born_on);
		$this->assertSame("0190f1a0-0000-7000-8000-000000000000", $model->reference);
		$this->assertSame("2020-01-01 00:00:00", $model->created_at);
	}

	function testStrictModeKeepsNullAsNull() {
		TestsHelper::Print("testing strictTypes never casts null, even for int/float/boolean fields");
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row(["id" => "1", "price" => null, "active" => null]));
		$this->assertNull($model->price);
		$this->assertNull($model->active);
	}

	function testStrictModeLeavesUnmappedColumnsUntouched() {
		TestsHelper::Print("testing strictTypes leaves columns with no \$dbValues entry (e.g. join aliases) as-is");
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row(["extra_joined_column" => "raw"]));
		$this->assertSame("raw", $model->extra_joined_column);
	}

	function testStrictModeThrowsOnBadIntCast() {
		TestsHelper::Print("testing strictTypes throws MagratheaModelException on schema drift (non-numeric string in an int column)");
		$this->expectException(MagratheaModelException::class);
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row(["id" => "not-a-number"]));
	}

	function testStrictModeThrowsOnBadFloatCast() {
		TestsHelper::Print("testing strictTypes throws MagratheaModelException on a non-numeric float column");
		$this->expectException(MagratheaModelException::class);
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row(["price" => "free"]));
	}

	function testDecimalKeepsExactStringInLooseMode() {
		TestsHelper::Print("testing a model pinned to strictTypes=false leaves a decimal field as the exact string");
		$model = new LooseStrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row());
		$this->assertSame("12345678901234567.89", $model->amount);
	}

	function testDecimalKeepsExactStringInStrictMode() {
		TestsHelper::Print("testing strictTypes never casts a decimal field (a float would round it)");
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row());
		$this->assertSame("12345678901234567.89", $model->amount);
	}

	function testStrictModeThrowsOnBadDecimal() {
		TestsHelper::Print("testing strictTypes throws MagratheaModelException on a non-numeric decimal column");
		$this->expectException(MagratheaModelException::class);
		$model = new StrictTypesTestModel();
		$model->LoadObjectFromTableRow($this->row(["amount" => "free"]));
	}

	function testFrameworkModelsAreStrict() {
		TestsHelper::Print("testing the framework models load native types (3.0 default)");
		$user = new AdminUser();
		$user->LoadObjectFromTableRow(["id" => "2", "email" => "a@b.c", "role_id" => "1", "active" => "0"]);
		$this->assertSame(2, $user->id);
		$this->assertSame(1, $user->role_id);
		$this->assertFalse($user->active);
		$this->assertTrue($user->IsAdmin());
		$config = new AppConfig();
		$config->LoadObjectFromTableRow(["id" => "3", "name" => "key", "value" => "10", "is_system" => "1"]);
		$this->assertSame(3, $config->id);
		$this->assertSame("10", $config->value);
		$this->assertTrue($config->is_system);
		$log = new AdminLog();
		$log->LoadObjectFromTableRow(["id" => "4", "user_id" => "2", "action" => "login"]);
		$this->assertSame(2, $log->user_id);
	}
}
