<?php

use Magrathea\Admin\QueryRunner;
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;

// Database that records what runs: QueryStream() yields $rows (counting what was read), QueryMulti() answers $multiResult,
// and the SQL in $errors throws
class QueryRunnerRecordingDatabase extends Database {
	public $log = [];
	public $rows = [];
	public $read = 0;
	public $freed = 0;
	public $multiResult = true;
	public $affected = 0;
	public $errors = [];
	public function QueryStream(string $sql): \Generator {
		$this->log[] = "stream:".$sql;
		if(in_array($sql, $this->errors, true)) throw new MagratheaDBException("failed: ".$sql);
		try {
			foreach($this->rows as $row) {
				$this->read++;
				yield $row;
			}
		} finally {
			$this->freed++;
		}
	}
	public function QueryMulti($queries, $killable=true) {
		$this->log[] = "multi:".implode(" | ", (array)$queries);
		if(in_array($queries[0], $this->errors, true)) throw new MagratheaDBException("failed: ".$queries[0]);
		return [["query" => $queries[0], "success" => true, "result" => $this->multiResult]];
	}
	public function LastAffectedRows(): int {
		return $this->affected;
	}
}

class adminQueryRunnerTest extends \PHPUnit\Framework\TestCase {

	private QueryRunnerRecordingDatabase $db;
	private QueryRunner $runner;

	protected function setUp(): void {
		parent::setUp();
		$this->db = QueryRunnerRecordingDatabase::Instance();
		$this->db->log = [];
		$this->db->rows = [];
		$this->db->read = 0;
		$this->db->freed = 0;
		$this->db->multiResult = [];
		$this->db->affected = 0;
		$this->db->errors = [];
		$this->runner = new QueryRunner($this->db);
	}

	private static function Rows(int $count): array {
		return array_map(fn($i) => ["id" => $i], range(1, $count));
	}

	function testReadOnlyRunsWithoutConfirmation() {
		TestsHelper::Print("testing QueryRunner runs read-only SQL right away, streaming its rows...");
		$this->db->rows = self::Rows(3);
		$run = $this->runner->Run("-- list\nSELECT id FROM articles;");
		$this->assertSame(QueryRunner::OK, $run["status"]);
		$this->assertTrue($run["readOnly"]);
		$this->assertSame(["stream:SELECT id FROM articles"], $this->db->log);
		$this->assertSame([[
			"sql" => "SELECT id FROM articles", "type" => "SELECT", "readOnly" => true, "rows" => self::Rows(3),
			"truncated" => false, "affected" => null, "error" => null,
		]], $run["statements"]);
	}

	function testWriteAsksForConfirmation() {
		TestsHelper::Print("testing QueryRunner runs nothing that isn't read-only until it's confirmed...");
		foreach(["DELETE FROM articles", "SELECT 1; SELECT 2", "SELECT 1 INTO OUTFILE '/tmp/x'", "SELECT 1; DROP TABLE a"] as $sql) {
			$run = $this->runner->Run($sql);
			$this->assertSame(QueryRunner::CONFIRM, $run["status"], $sql);
			$this->assertFalse($run["readOnly"], $sql);
			$this->assertNull($run["statements"][0]["rows"], $sql);
		}
		$this->assertSame([], $this->db->log, "nothing ran");
		$run = $this->runner->Run("SELECT 1; DROP TABLE a");
		$this->assertSame(["SELECT 1", "DROP TABLE a"], array_column($run["statements"], "sql"));
		$this->assertSame(["SELECT", "DROP"], array_column($run["statements"], "type"));
		$this->assertSame([true, false], array_column($run["statements"], "readOnly"));
	}

	function testConfirmedRunsEachStatement() {
		TestsHelper::Print("testing confirmed SQL runs one statement at a time: reads stream, writes get their affected rows...");
		$this->db->rows = self::Rows(2);
		$this->db->affected = 4;
		$run = $this->runner->Run("UPDATE a SET b = 1 WHERE c = 'x;y';\nSELECT id FROM a", true);
		$this->assertSame(QueryRunner::OK, $run["status"]);
		$this->assertFalse($run["readOnly"]);
		$this->assertSame(["multi:UPDATE a SET b = 1 WHERE c = 'x;y'", "stream:SELECT id FROM a"], $this->db->log);
		$this->assertSame(4, $run["statements"][0]["affected"]);
		$this->assertNull($run["statements"][0]["rows"]);
		$this->assertSame(self::Rows(2), $run["statements"][1]["rows"]);
		$this->assertNull($run["statements"][1]["affected"]);
	}

	function testRowCap() {
		TestsHelper::Print("testing QueryRunner keeps the first ".QueryRunner::MAX_ROWS." rows, flags the rest and frees the stream...");
		$this->db->rows = self::Rows(QueryRunner::MAX_ROWS);
		$run = $this->runner->Run("SELECT id FROM a");
		$this->assertCount(QueryRunner::MAX_ROWS, $run["statements"][0]["rows"]);
		$this->assertFalse($run["statements"][0]["truncated"], "exactly MAX_ROWS: nothing more");

		$this->db->read = 0;
		$this->db->freed = 0;
		$this->db->rows = self::Rows(QueryRunner::MAX_ROWS + 500);
		$run = $this->runner->Run("SELECT id FROM a");
		$this->assertCount(QueryRunner::MAX_ROWS, $run["statements"][0]["rows"]);
		$this->assertSame(QueryRunner::MAX_ROWS, end($run["statements"][0]["rows"])["id"]);
		$this->assertTrue($run["statements"][0]["truncated"]);
		$this->assertSame(QueryRunner::MAX_ROWS + 1, $this->db->read, "stops reading one row past the cap");
		$this->assertSame(1, $this->db->freed, "the stream is freed");

		// a confirmed statement that isn't read-only but returns rows (QueryMulti() fetched them all) is capped too
		$this->db->multiResult = self::Rows(QueryRunner::MAX_ROWS + 1);
		$run = $this->runner->Run("CHECK TABLE a", true);
		$this->assertCount(QueryRunner::MAX_ROWS, $run["statements"][0]["rows"]);
		$this->assertTrue($run["statements"][0]["truncated"]);
	}

	function testCreateAnswerIsNotRows() {
		TestsHelper::Print("testing QueryMulti()'s [\"success\" => true] for a CREATE isn't shown as rows...");
		$this->db->multiResult = ["success" => true];
		$run = $this->runner->Run("CREATE TABLE a (id int)", true);
		$this->assertNull($run["statements"][0]["rows"]);
		$this->assertSame(0, $run["statements"][0]["affected"]);
	}

	function testStopsAtTheFirstError() {
		TestsHelper::Print("testing QueryRunner stops at the first failing statement and counts the ones left...");
		$this->db->errors = ["DELETE FROM b"];
		$run = $this->runner->Run("DELETE FROM a; DELETE FROM b; DELETE FROM c; DELETE FROM d", true);
		$this->assertSame(QueryRunner::ERROR, $run["status"]);
		$this->assertSame("failed: DELETE FROM b", $run["error"]);
		$this->assertSame(2, $run["notRun"]);
		$this->assertSame(["multi:DELETE FROM a", "multi:DELETE FROM b"], $this->db->log);
		$this->assertCount(2, $run["statements"]);
		$this->assertNull($run["statements"][0]["error"]);
		$this->assertSame("failed: DELETE FROM b", $run["statements"][1]["error"]);

		$this->db->errors = ["SELECT broken"];
		$run = $this->runner->Run("SELECT broken");
		$this->assertSame(QueryRunner::ERROR, $run["status"]);
		$this->assertSame(0, $run["notRun"]);
	}

	function testEmptyAndUnsplittable() {
		TestsHelper::Print("testing QueryRunner answers EMPTY for no statement, and ERROR for SQL it can't split...");
		$this->assertSame(QueryRunner::EMPTY, $this->runner->Run("")["status"]);
		$this->assertSame(QueryRunner::EMPTY, $this->runner->Run("-- nothing\n/* here */ ;")["status"]);
		$run = $this->runner->Run("DELIMITER\nSELECT 1", true);
		$this->assertSame(QueryRunner::ERROR, $run["status"]);
		$this->assertStringContainsString("DELIMITER", $run["error"]);
		$this->assertSame([], $run["statements"]);
		$this->assertSame([], $this->db->log);
	}

}
