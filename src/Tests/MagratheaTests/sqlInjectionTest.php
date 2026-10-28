<?php

use Magrathea\DB\Database;
use Magrathea\DB\Query;
use Magrathea\Debugger;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\Tests\TestsHelper;

class SqlInjectionTestModel extends MagratheaModel {
	protected $dbTable = "sql_injection_test";
	protected $dbPk = "reference";
	protected $dbValues = [
		"reference" => "uuid",
		"name" => "string",
	];
	protected $autoload = null;
	public $reference, $name, $created_at, $updated_at;
}

class SqlInjectionIntPkTestModel extends MagratheaModel {
	protected $dbTable = "sql_injection_int_test";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
	];
	protected $autoload = null;
	public $id, $name, $created_at, $updated_at;
}

// Database that records QueryRow() instead of running it (and finds no row)
class SqlInjectionRecordingDatabase extends Database {
	public $queries = [];
	public function Escape($value): string {
		return addcslashes((string)$value, "\x00\n\r\\'\"\x1a");
	}
	public function QueryRow(string $sql): array|object {
		$this->queries[] = $sql;
		return [];
	}
}

/**
 * Regression tests for the SQL injection fix in Query::BuildWhere(), QueryUpdate::SQL(),
 * QueryInsert::SQL() and MagratheaModel::GetById() (both PK branches).
 * All of them used to interpolate raw values straight into the SQL string.
 */
class sqlInjectionTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Database::Escape() needs a connection; the mock provides a connection-free equivalent.
		// Mock() replaces the singleton instance itself, so only call it once - a second call
		// would land on the DatabaseSimulate instance, which has no Mock() method of its own.
		if (!(Database::Instance() instanceof \Magrathea\DB\DatabaseSimulate)) {
			Database::Instance()->Mock();
		}
	}

	function testWhereArrayEscapesSingleQuotes() {
		TestsHelper::Print("testing Query::Where(array) escapes a single quote instead of letting it break out of the SQL string");
		$malicious = "x' OR '1'='1";
		$sql = Query::Select()->Table("users")->Where(["name" => $malicious])->SQL();
		$this->assertStringContainsString("`name` = 'x\\' OR \\'1\\'=\\'1'", $sql);
		$this->assertStringNotContainsString("`name` = 'x' OR '1'='1'", $sql);
	}

	function testWhereArrayEscapesInjectedStatements() {
		TestsHelper::Print("testing a stacked-query payload is escaped, not left able to close the string early");
		$malicious = "x'; DROP TABLE users; -- ";
		$sql = Query::Select()->Table("users")->Where(["name" => $malicious])->SQL();
		$this->assertStringContainsString("\\'; DROP TABLE users", $sql);
		$this->assertStringNotContainsString("`name` = 'x'; DROP TABLE users", $sql);
	}

	function testWhereArrayIsNoopForBenignValues() {
		TestsHelper::Print("testing escaping is a no-op for values with no special characters (behavior-preserving)");
		$sql = Query::Select()->Table("users")->Where(["name" => "regular value"])->SQL();
		$this->assertStringContainsString("`name` = 'regular value'", $sql);
	}

	function testUpdateSetEscapesSingleQuotes() {
		TestsHelper::Print("testing QueryUpdate::Set() escapes a single quote in the value being SET");
		$malicious = "x', admin = 1 -- ";
		$sql = Query::Update()->Table("users")->Set("name", $malicious)->SQL();
		$this->assertStringContainsString("name = 'x\\', admin = 1", $sql);
		$this->assertStringNotContainsString("name = 'x', admin = 1", $sql);
	}

	function testInsertValuesEscapesSingleQuotes() {
		TestsHelper::Print("testing QueryInsert::Values() escapes a single quote in an inserted value");
		$malicious = "x', (SELECT password FROM users LIMIT 1))-- ";
		$sql = Query::Insert()->Table("users")->Values(["name" => $malicious])->SQL();
		$this->assertStringContainsString("'x\\', (SELECT password FROM users LIMIT 1))-- '", $sql);
	}

	function testGetByIdEscapesNonIntPrimaryKey() {
		TestsHelper::Print("testing MagratheaModel::GetById() escapes a non-int PK value before it reaches SQL");
		Debugger::Instance()->SetDebug()->LogQueries(true);

		$model = new SqlInjectionTestModel();
		$malicious = "x' OR '1'='1";
		try {
			$model->GetById($malicious);
			$this->fail("Expected MagratheaModelException (the mocked DB always returns no rows)");
		} catch (MagratheaModelException $ex) {
			// expected: DatabaseSimulate::QueryRow() always returns an empty result
		}

		$debugItems = new \ReflectionProperty(Debugger::class, "debugItems");
		$debugItems->setAccessible(true);
		$queryLog = array_values(array_filter($debugItems->getValue(Debugger::Instance()), fn($item) => is_string($item) && str_contains($item, "SELECT")));
		$this->assertNotEmpty($queryLog, "expected the SELECT built by GetById() to have been logged");
		$loggedQuery = end($queryLog);

		$this->assertStringContainsString("x\\' OR \\'1\\'=\\'1", $loggedQuery);
		$this->assertStringNotContainsString("= 'x' OR '1'='1'", $loggedQuery);

		Debugger::Instance()->SetType(Debugger::NONE);
	}

	/**
	 * Runs GetById() on a recording database
	 * @return array [exception thrown (or null), queries run]
	 */
	private function GetByIdRecorded(MagratheaModel $model, $id): array {
		$previousDb = Database::Instance();
		$db = SqlInjectionRecordingDatabase::Instance();
		$db->queries = [];
		Database::MockClass($db);
		$thrown = null;
		try {
			$model->GetById($id);
		} catch (MagratheaModelException $ex) {
			$thrown = $ex;
		} finally {
			Database::MockClass($previousDb);
		}
		return [$thrown, $db->queries];
	}

	function testGetByIdRejectsInjectionOnIntPrimaryKey() {
		TestsHelper::Print("testing GetById() on an int PK throws 400 for an injection payload and runs no query");
		[$ex, $queries] = $this->GetByIdRecorded(new SqlInjectionIntPkTestModel(), "0 UNION SELECT password FROM _magrathea_users");
		$this->assertNotNull($ex);
		$this->assertSame(400, $ex->getCode());
		$this->assertSame([], $queries);
	}

	function testGetByIdRejectsDigitsWithTrailingText() {
		TestsHelper::Print("testing GetById() on an int PK throws 400 for '5abc' and '5\\n' instead of loading row 5");
		foreach (["5abc", "5\n", " 5", "5.0"] as $id) {
			[$ex, $queries] = $this->GetByIdRecorded(new SqlInjectionIntPkTestModel(), $id);
			$this->assertNotNull($ex, "expected [".$id."] to be rejected");
			$this->assertSame(400, $ex->getCode());
			$this->assertSame([], $queries);
		}
	}

	function testGetByIdCastsValidIntIds() {
		TestsHelper::Print("testing GetById() on an int PK builds '= 5' for 5 and '5', and accepts negative ids");
		foreach ([[5, "= 5"], ["5", "= 5"], ["-3", "= -3"]] as [$id, $expected]) {
			[$ex, $queries] = $this->GetByIdRecorded(new SqlInjectionIntPkTestModel(), $id);
			$this->assertCount(1, $queries);
			$this->assertStringContainsString("sql_injection_int_test.id ".$expected, $queries[0]);
			// the recording database finds no row
			$this->assertNotSame(400, $ex->getCode());
		}
	}

	function testGetByIdStillEscapesStringPrimaryKey() {
		TestsHelper::Print("testing GetById() on a string PK still escapes the id, with no int check");
		[$ex, $queries] = $this->GetByIdRecorded(new SqlInjectionTestModel(), "x' OR '1'='1");
		$this->assertCount(1, $queries);
		$this->assertStringContainsString("sql_injection_test.reference = 'x\\' OR \\'1\\'=\\'1'", $queries[0]);
		$this->assertNotSame(400, $ex->getCode());
	}

	function testValidateIdLeavesStringPrimaryKeysAlone() {
		TestsHelper::Print("testing ValidateId() returns an int for int PKs and the raw id for string PKs");
		$this->assertSame(5, (new SqlInjectionIntPkTestModel())->ValidateId("5"));
		$this->assertSame("5abc", (new SqlInjectionTestModel())->ValidateId("5abc"));
		$this->expectException(MagratheaModelException::class);
		(new SqlInjectionIntPkTestModel())->ValidateId(["5"]);
	}
}
