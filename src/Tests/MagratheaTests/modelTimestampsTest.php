<?php

use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaApiControl;
use Magrathea\MagratheaModel;
use Magrathea\Tests\TestsHelper;

// Database that records PrepareAndExecute() and QueryRow() (in order) instead of running them,
// and answers QueryRow() with $row
class TimestampsRecordingDatabase extends Database {
	public $executed = [];
	public $log = [];
	public $row = [];
	public function PrepareAndExecute($query, $arrTypes, $arrValues) {
		$this->executed[] = ["query" => $query, "types" => $arrTypes, "values" => $arrValues];
		$this->log[] = $query;
		return 7;
	}
	public function QueryRow(string $sql): array|object {
		$this->log[] = $sql;
		return $this->row;
	}
}

// generated shape: lists the timestamps in $dbValues
class TimestampsTestModel extends MagratheaModel {
	protected $dbTable = "timestamps_test";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
		"created_at" => "datetime",
		"updated_at" => "datetime",
	];
	protected $autoload = null;
	public $id, $name, $created_at, $updated_at;
}

// 3.0 shape: timestamps come from MagratheaModel, not listed in $dbValues
class TimestampsUnlistedModel extends MagratheaModel {
	protected $dbTable = "timestamps_unlisted";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
	];
	public $id, $name;
}

class TimestampsUuidModel extends MagratheaModel {
	protected $dbTable = "timestamps_uuid";
	protected $dbPk = "uuid";
	protected $dbValues = [
		"uuid" => "uuid",
		"name" => "string",
	];
	public $uuid, $name;
}

class TimestampsApiControl extends MagratheaApiControl {
	protected $model = TimestampsTestModel::class;
}

/**
 * 3.0 items 10 and 11: the database owns created_at / updated_at.
 * Insert() sends a timestamp only when it's assigned (as assigned), Update() never writes either,
 * and nothing reads the row back, MagratheaApiControl::Create()/Update() included: the object's
 * timestamps are null after a write (never a guessed value). Reload() is the explicit read.
 */
class modelTimestampsTest extends \PHPUnit\Framework\TestCase {

	private $previousDb;
	private $previousMethod;
	private TimestampsRecordingDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		$this->db = TimestampsRecordingDatabase::Instance();
		$this->db->executed = [];
		$this->db->log = [];
		$this->db->row = [];
		Database::MockClass($this->db);
		$this->previousMethod = $_SERVER["REQUEST_METHOD"] ?? null;
		$_SERVER["REQUEST_METHOD"] = "GET";
	}
	protected function tearDown(): void {
		if($this->previousMethod === null) unset($_SERVER["REQUEST_METHOD"]);
		else $_SERVER["REQUEST_METHOD"] = $this->previousMethod;
		Database::MockClass($this->previousDb);
		parent::tearDown();
	}

	private function LastExecuted(): array {
		return end($this->db->executed);
	}
	private function AssertNoSelect() {
		foreach($this->db->log as $sql) {
			$this->assertStringStartsNotWith("SELECT", (string)$sql);
		}
	}
	private function LoadedRow(string $table, string $id = "3"): array {
		return [
			$table."/id" => $id,
			$table."/name" => "loaded",
			$table."/created_at" => "2020-01-01 00:00:00",
			$table."/updated_at" => "2020-01-02 00:00:00",
		];
	}
	private function Loaded(string $class, string $table): MagratheaModel {
		$this->db->row = $this->LoadedRow($table);
		$model = new $class(3);
		$this->db->executed = [];
		$this->db->log = [];
		return $model;
	}

	// item 10: Insert()

	function testInsertLeavesUnsetTimestampsToTheDatabase() {
		TestsHelper::Print("testing Insert() doesn't send unset created_at/updated_at, and leaves them null...");
		foreach([TimestampsTestModel::class => "timestamps_test", TimestampsUnlistedModel::class => "timestamps_unlisted"] as $class => $table) {
			$model = new $class();
			$model->name = "new";
			$this->assertSame(7, $model->Insert());
			$run = $this->LastExecuted();
			$this->assertSame("INSERT INTO ".$table." (`name`) VALUES (?) ", $run["query"]);
			$this->assertSame(["name" => "new"], $run["values"]);
			$this->assertNull($model->created_at);
			$this->assertNull($model->updated_at);
		}
		$this->AssertNoSelect();
	}

	function testInsertSendsAssignedTimestampsAsTheyAre() {
		TestsHelper::Print("testing Insert() sends assigned timestamps as assigned, listed in \$dbValues or not...");
		foreach([TimestampsTestModel::class => "timestamps_test", TimestampsUnlistedModel::class => "timestamps_unlisted"] as $class => $table) {
			$model = new $class();
			$model->name = "imported";
			$model->created_at = "2001-01-01 00:00:00";
			$model->updated_at = "2002-02-02 00:00:00";
			$model->Insert();
			$run = $this->LastExecuted();
			$this->assertSame("INSERT INTO ".$table." (`name`,`created_at`,`updated_at`) VALUES (?, ?, ?) ", $run["query"]);
			$this->assertSame(["text", "date", "date"], $run["types"]);
			$this->assertSame(["name" => "imported", "created_at" => "2001-01-01 00:00:00", "updated_at" => "2002-02-02 00:00:00"], $run["values"]);
			// sent, but the database may store it differently: not kept on the object
			$this->assertNull($model->created_at);
			$this->assertNull($model->updated_at);
		}
	}

	function testInsertSendsOnlyTheAssignedTimestamp() {
		TestsHelper::Print("testing Insert() sends an assigned created_at alone, leaving updated_at to the database...");
		$model = new TimestampsUnlistedModel();
		$model->name = "imported";
		$model->created_at = "2001-01-01 00:00:00";
		$model->Insert();
		$this->assertSame("INSERT INTO timestamps_unlisted (`name`,`created_at`) VALUES (?, ?) ", $this->LastExecuted()["query"]);
		$this->assertNull($model->updated_at);
	}

	function testInsertWithPkSendsAssignedTimestamps() {
		TestsHelper::Print("testing InsertWithPk() keeps an import's dates...");
		$model = new TimestampsTestModel();
		$model->id = 40;
		$model->name = "imported";
		$model->created_at = "2001-01-01 00:00:00";
		$model->InsertWithPk();
		$run = $this->LastExecuted();
		$this->assertSame("INSERT INTO timestamps_test (`id`,`name`,`created_at`) VALUES (?, ?, ?) ", $run["query"]);
		$this->assertSame("2001-01-01 00:00:00", $run["values"]["created_at"]);
		$this->assertNull($model->created_at);
	}

	function testInsertSendsAndKeepsUuidPk() {
		TestsHelper::Print("testing Insert() on a uuid-PK model sends the generated PK and keeps it...");
		$model = new TimestampsUuidModel();
		$model->name = "new";
		$id = $model->Insert();
		$this->assertIsString($id);
		$this->assertSame($id, $model->uuid);
		$run = $this->LastExecuted();
		$this->assertSame("INSERT INTO timestamps_uuid (`uuid`,`name`) VALUES (?, ?) ", $run["query"]);
		$this->assertSame($id, $run["values"]["uuid"]);

		// an assigned uuid is sent as it is (Save() would update it: the PK isn't empty)
		$model = new TimestampsUuidModel();
		$model->uuid = "0190a5b2-6d1e-7c3a-9f00-123456789abc";
		$model->name = "assigned";
		$this->assertSame("0190a5b2-6d1e-7c3a-9f00-123456789abc", $model->Insert());
		$this->assertSame("0190a5b2-6d1e-7c3a-9f00-123456789abc", $this->LastExecuted()["values"]["uuid"]);

		$model = new TimestampsUuidModel();
		$model->name = "saved";
		$id = $model->Save();
		$this->assertStringStartsWith("INSERT INTO timestamps_uuid", $this->LastExecuted()["query"]);
		$this->assertSame($id, $model->uuid);
		$this->assertSame($id, $this->LastExecuted()["values"]["uuid"]);
	}

	function testSetRefusesTimestamps() {
		TestsHelper::Print("testing Set() ignores created_at/updated_at...");
		$model = new TimestampsTestModel();
		$model->Set("created_at", "2001-01-01 00:00:00");
		$this->assertNull($model->created_at);
	}

	function testApiCreateIgnoresPostedTimestampsAndDoesntReload() {
		TestsHelper::Print("testing MagratheaApiControl::Create() drops posted timestamps and reads nothing back...");
		$created = (new TimestampsApiControl())->Create([
			"name" => "posted",
			"created_at" => "2001-01-01 00:00:00",
			"updated_at" => "2001-01-01 00:00:00",
		]);
		$this->assertSame(["INSERT INTO timestamps_test (`name`) VALUES (?) "], $this->db->log);
		$this->assertInstanceOf(TimestampsTestModel::class, $created);
		$this->assertSame(7, $created->id);
		$this->assertNull($created->created_at);
		$this->assertNull($created->updated_at);
	}

	// item 11: Update()

	function testUpdateNeverWritesTimestamps() {
		TestsHelper::Print("testing Update() never writes created_at/updated_at, loaded or not, listed or not...");
		foreach([TimestampsTestModel::class => "timestamps_test", TimestampsUnlistedModel::class => "timestamps_unlisted"] as $class => $table) {
			$model = $this->Loaded($class, $table);
			$model->name = "changed";
			$model->created_at = "2001-01-01 00:00:00";
			$model->updated_at = "2001-01-01 00:00:00";
			$model->Update();
			$this->assertSame("UPDATE ".$table." SET `name`= ?  WHERE `id`= ? ", $this->LastExecuted()["query"]);

			$model = new $class();
			$model->id = 3;
			$model->name = "changed";
			$model->created_at = "2001-01-01 00:00:00";
			$model->updated_at = "2001-01-01 00:00:00";
			$model->Update();
			$this->assertSame("UPDATE ".$table." SET `name`= ?  WHERE `id`= ? ", $this->LastExecuted()["query"]);
		}
	}

	function testUpdateNullsUpdatedAtAndKeepsCreatedAt() {
		TestsHelper::Print("testing after a write updated_at is null, created_at is unchanged, and nothing is read back...");
		$model = $this->Loaded(TimestampsUnlistedModel::class, "timestamps_unlisted");
		$model->name = "changed";
		$this->assertSame(7, $model->Update());
		$this->assertNull($model->updated_at);
		$this->assertSame("2020-01-01 00:00:00", $model->created_at);
		$this->assertCount(1, $this->db->log);
		$this->AssertNoSelect();
	}

	function testUpdateWithNothingToWriteRunsNoQuery() {
		TestsHelper::Print("testing an unchanged loaded object runs no query, returns true and keeps updated_at...");
		$model = $this->Loaded(TimestampsTestModel::class, "timestamps_test");
		$this->assertTrue($model->Update());
		$this->assertTrue($model->Save());
		$this->assertCount(0, $this->db->log);
		$this->assertSame("2020-01-02 00:00:00", $model->updated_at);
	}

	function testUpdateAssigningOnlyATimestampRunsNoQuery() {
		TestsHelper::Print("testing \$obj->updated_at = ...; Save() writes nothing...");
		$model = $this->Loaded(TimestampsTestModel::class, "timestamps_test");
		$model->updated_at = "2030-01-01 00:00:00";
		$this->assertTrue($model->Save());
		$this->assertCount(0, $this->db->log);
	}

	function testApiUpdateRefusesMalformedIdBeforeWriting() {
		TestsHelper::Print("testing MagratheaApiControl::Update() refuses a malformed int id with 400, before writing...");
		try {
			(new TimestampsApiControl())->Update(["id" => "3 OR 1=1", "data" => ["name" => "changed"]]);
			$this->fail("expected a MagratheaModelException");
		} catch(MagratheaModelException $ex) {
			$this->assertSame(400, $ex->getCode());
		}
		$this->assertCount(0, $this->db->log);
	}

	function testApiUpdateDropsPostedTimestampsAndDoesntReload() {
		TestsHelper::Print("testing MagratheaApiControl::Update() ignores posted timestamps and reads nothing back...");
		$updated = (new TimestampsApiControl())->Update(["id" => "3", "data" => ["name" => "changed", "updated_at" => "2001-01-01 00:00:00"]]);
		$this->assertSame(["UPDATE timestamps_test SET `name`= ?  WHERE `id`= ? "], $this->db->log);
		$this->assertSame(3, $this->db->executed[0]["values"]["id"]);
		$this->assertSame("changed", $updated->name);
		$this->assertNull($updated->updated_at);

		// nothing to write: no query, and the posted timestamp still doesn't land on the object
		$this->db->log = [];
		$updated = (new TimestampsApiControl())->Update(["id" => "3", "data" => ["updated_at" => "2001-01-01 00:00:00"]]);
		$this->assertSame([], $this->db->log);
		$this->assertNull($updated->updated_at);
	}

	function testApiUpdateIgnoresThePkInTheBody() {
		TestsHelper::Print("testing MagratheaApiControl::Update() writes the row of the URL id, whatever id the body has...");
		(new TimestampsApiControl())->Update(["id" => "3", "data" => ["id" => 7, "name" => "changed"]]);
		$this->assertSame("UPDATE timestamps_test SET `name`= ?  WHERE `id`= ? ", $this->db->log[0]);
		$this->assertSame(3, $this->db->executed[0]["values"]["id"]);

		// a malformed body id can't get past ValidateId() either
		(new TimestampsApiControl())->Update(["id" => "3", "data" => ["id" => "7 OR 1=1", "name" => "changed"]]);
		$this->assertSame(3, $this->db->executed[1]["values"]["id"]);
	}

	function testApiUpdateTakesTheIdFromTheBodyWhenTheUrlHasNone() {
		TestsHelper::Print("testing MagratheaApiControl::Update() falls back to the body id, validated...");
		(new TimestampsApiControl())->Update(["data" => ["id" => "4", "name" => "changed"]]);
		$this->assertSame(4, $this->db->executed[0]["values"]["id"]);

		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionCode(400);
		(new TimestampsApiControl())->Update(["data" => ["id" => "4abc", "name" => "changed"]]);
	}

	function testApiUpdateRefusesEmptyDataBeforeAnythingElse() {
		TestsHelper::Print("testing MagratheaApiControl::Update() with no data throws 'Empty Data Sent', no query...");
		try {
			(new TimestampsApiControl())->Update(["id" => "3", "data" => []]);
			$this->fail("expected an exception");
		} catch(\Exception $ex) {
			$this->assertSame("Empty Data Sent", $ex->getMessage());
		}
		$this->assertCount(0, $this->db->log);
	}

	// Reload()

	function testReloadReadsTheRowAfterAWrite() {
		TestsHelper::Print("testing Reload() reads the database's timestamps after Insert(), with one SELECT...");
		$this->db->row = $this->LoadedRow("timestamps_unlisted", "7");
		$model = new TimestampsUnlistedModel();
		$model->name = "new";
		$model->Insert();
		$this->assertSame($model, $model->Reload());
		$this->assertCount(2, $this->db->log);
		$this->assertStringStartsWith("SELECT", (string)$this->db->log[1]);
		$this->assertStringContainsString("timestamps_unlisted.id = 7", (string)$this->db->log[1]);
		$this->assertSame("2020-01-01 00:00:00", $model->created_at);
		$this->assertSame("2020-01-02 00:00:00", $model->updated_at);
		$this->assertSame("loaded", $model->name);
	}

	function testReloadTakesANewSnapshot() {
		TestsHelper::Print("testing a reloaded object counts as loaded: no changes, no query...");
		$this->db->row = $this->LoadedRow("timestamps_unlisted");
		$model = new TimestampsUnlistedModel();
		$model->id = 3;
		$model->Reload();
		$this->db->log = [];
		$this->assertTrue($model->Update());
		$this->assertCount(0, $this->db->log);
	}

	function testReloadWithoutPkThrows() {
		TestsHelper::Print("testing Reload() without a PK throws, before any query...");
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionMessage("TimestampsUnlistedModel");
		try {
			(new TimestampsUnlistedModel())->Reload();
		} finally {
			$this->assertCount(0, $this->db->log);
		}
	}

	// item 12: Touch()

	function testTouchSetsUpdatedAtToTheDatabaseClock() {
		TestsHelper::Print("testing Touch() runs one UPDATE with CURRENT_TIMESTAMP and the PK bound by type...");
		foreach([TimestampsTestModel::class => "timestamps_test", TimestampsUnlistedModel::class => "timestamps_unlisted"] as $class => $table) {
			$model = $this->Loaded($class, $table);
			$this->assertTrue($model->Touch());
			$this->assertCount(1, $this->db->executed);
			$q = $this->LastExecuted();
			$this->assertSame("UPDATE ".$table." SET `updated_at` = CURRENT_TIMESTAMP WHERE `id`= ? ", $q["query"]);
			$this->assertSame(["id" => "integer"], $q["types"]);
			$this->assertSame(["id" => 3], $q["values"]);
		}
	}

	function testTouchBindsAUuidPkAsText() {
		TestsHelper::Print("testing Touch() binds a uuid PK as text...");
		$model = new TimestampsUuidModel();
		$model->uuid = "018f0000-0000-7000-8000-000000000000";
		$model->Touch();
		$q = $this->LastExecuted();
		$this->assertSame("UPDATE timestamps_uuid SET `updated_at` = CURRENT_TIMESTAMP WHERE `uuid`= ? ", $q["query"]);
		$this->assertSame(["uuid" => "text"], $q["types"]);
		$this->assertSame(["uuid" => "018f0000-0000-7000-8000-000000000000"], $q["values"]);
	}

	function testTouchNullsUpdatedAtAndKeepsTheRest() {
		TestsHelper::Print("testing after Touch() updated_at is null, created_at stays, pending changes stay pending, no SELECT...");
		$model = $this->Loaded(TimestampsTestModel::class, "timestamps_test");
		$model->name = "changed";
		$model->Touch();
		$this->assertNull($model->updated_at);
		$this->assertSame("2020-01-01 00:00:00", $model->created_at);
		$this->AssertNoSelect();
		// Touch() doesn't write name, so the next Update() still does
		$model->Update();
		$q = $this->LastExecuted();
		$this->assertStringContainsString("`name`= ?", $q["query"]);
		$this->assertSame("changed", $q["values"]["name"]);
	}

	function testTouchWithoutPkThrows() {
		TestsHelper::Print("testing Touch() without a PK throws, before any query...");
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionMessage("TimestampsUnlistedModel");
		try {
			(new TimestampsUnlistedModel())->Touch();
		} finally {
			$this->assertCount(0, $this->db->log);
		}
	}

}
