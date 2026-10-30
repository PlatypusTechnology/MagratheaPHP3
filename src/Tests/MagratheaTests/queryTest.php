<?php
use Magrathea\DB\Query;
use Magrathea\DB\QueryHelper;
use Magrathea\DB\QueryType;
use Magrathea\DB\Database;

// Database that escapes without a connection
class QueryTestEscapingDatabase extends Database {
	public function Escape($value): string {
		return addslashes((string)$value);
	}
}

class queryTest extends \PHPUnit\Framework\TestCase {

	function testQueryTypes() {
		$selQuery = Query::Select();
		$this->assertEquals(QueryType::Select, $selQuery->GetType());
		$upQuery = Query::Update();
		$this->assertEquals(QueryType::Update, $upQuery->GetType());
		$inQuery = Query::Insert();
		$this->assertEquals(QueryType::Insert, $inQuery->GetType());
		$delQuery = Query::Delete();
		$this->assertEquals(QueryType::Delete, $delQuery->GetType());
	}

	function testNullValuesWriteNull() {
		$previousDb = Database::Instance();
		Database::MockClass(QueryTestEscapingDatabase::Instance());
		try {
			$sql = Query::Insert()->Table("users")->Values(["name" => "Jo", "phone" => null, "age" => 0])->SQL();
			$this->assertEquals("INSERT INTO `users` (`name`, `phone`, `age`)  VALUES  ('Jo', NULL, '0') ", $sql);
			$sql = Query::Update()->Table("users")->Set("phone", null)->Set("name", "")->Where(["id" => 1])->SQL();
			$this->assertEquals("UPDATE users SET phone = NULL, name = '' WHERE  `id` = '1' ", $sql);
		} finally {
			Database::MockClass($previousDb);
		}
	}

	function testBooleanValuesWriteOneAndZero() {
		$previousDb = Database::Instance();
		Database::MockClass(QueryTestEscapingDatabase::Instance());
		try {
			$sql = Query::Insert()->Table("posts")->Values(["published" => false, "featured" => true, "title" => "0"])->SQL();
			$this->assertEquals("INSERT INTO `posts` (`published`, `featured`, `title`)  VALUES  ('0', '1', '0') ", $sql);
			$sql = Query::Update()->Table("posts")->SetArray(["published" => false, "featured" => true])->Where(["id" => 1])->SQL();
			$this->assertEquals("UPDATE posts SET published = '0', featured = '1' WHERE  `id` = '1' ", $sql);
		} finally {
			Database::MockClass($previousDb);
		}
	}

	function testGuessQueryType() {
		$type = QueryHelper::GetQueryType("SELECT * FROM _magrathea_logs LIMIT 50");
		$this->assertEquals(QueryType::Select, $type);
		$type = QueryHelper::GetQueryType("UPDATE users SET name = 'Golias' LIMIT 50");
		$this->assertEquals(QueryType::Update, $type);
	}

}

