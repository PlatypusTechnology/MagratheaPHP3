<?php

use Magrathea\Admin\AdminDatabase;
use Magrathea\DB\SqlSplitter;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Tests\TestsHelper;

class sqlSplitterTest extends \PHPUnit\Framework\TestCase {

	// split whole, and in every chunk size up to 12: a quote, comment or delimiter split between chunks changes nothing
	private function AssertSplits(array $expected, string $sql) {
		$this->assertSame($expected, SqlSplitter::Split($sql));
		for($size = 1; $size <= 12; $size++) {
			$this->assertSame($expected, iterator_to_array(SqlSplitter::Statements(str_split($sql, $size)), false), "chunks of ".$size);
		}
	}

	function testQuotes() {
		TestsHelper::Print("testing SqlSplitter keeps ; inside quotes, with backslash escapes and doubled quotes...");
		$this->AssertSplits([
			"INSERT INTO t VALUES ('a;b', \"c;d\")",
			"SELECT 'it''s; here'",
			"SELECT 'a\\';b', \"x\\\";y\"",
			"SELECT 'ends with \\\\'",
			"SELECT 2",
		], "INSERT INTO t VALUES ('a;b', \"c;d\");\nSELECT 'it''s; here';SELECT 'a\\';b', \"x\\\";y\";SELECT 'ends with \\\\';SELECT 2;");
	}

	function testBackticks() {
		TestsHelper::Print("testing SqlSplitter keeps ; inside backticks, where only a doubled backtick escapes...");
		$this->AssertSplits([
			"SELECT `a;b`, `c``;d` FROM t",
			"SELECT `ends\\`",
			"SELECT 3",
		], "SELECT `a;b`, `c``;d` FROM t; SELECT `ends\\`; SELECT 3");
	}

	function testComments() {
		TestsHelper::Print("testing SqlSplitter removes -- , # and block comments, and keeps --x and comment marks in strings...");
		$this->AssertSplits([
			"SELECT 1",
			// a block comment becomes one space
			"SELECT 2   FROM t",
			"SELECT 1--1",
			"SELECT '-- no', '# no', '/* no */'",
			"SELECT 3",
		], "-- first; comment\nSELECT 1; # hash; comment\nSELECT 2 /* block; comment */ FROM t;\nSELECT 1--1;\n".
			"SELECT '-- no', '# no', '/* no */';\nSELECT 3 --");
	}

	function testConditionalCommentsAndHintsAreKept() {
		TestsHelper::Print("testing SqlSplitter keeps /*! conditional comments and /*+ hints, without splitting inside them...");
		$this->AssertSplits([
			"/*!40101 SET NAMES utf8mb4 */",
			"/*!50003 CREATE*/ /*!50003 TRIGGER x BEFORE INSERT ON t FOR EACH ROW SET a = 1; */",
			"SELECT /*+ BKA(t) */ * FROM t",
		], "/*!40101 SET NAMES utf8mb4 */;\n/*!50003 CREATE*/ /*!50003 TRIGGER x BEFORE INSERT ON t FOR EACH ROW SET a = 1; */;\nSELECT /*+ BKA(t) */ * FROM t;");
	}

	function testDelimiter() {
		TestsHelper::Print("testing SqlSplitter follows DELIMITER lines (case-insensitive), as mysqldump writes triggers...");
		$this->AssertSplits([
			"DROP TRIGGER IF EXISTS x",
			"CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = ';;'; END",
			"CREATE PROCEDURE p() BEGIN SELECT 1; END",
			"SELECT delimiter FROM t",
			"SELECT 4",
		], "DROP TRIGGER IF EXISTS x;\nDELIMITER ;;\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = ';;'; END ;;\n".
			"delimiter //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//\nDELIMITER ;\nSELECT delimiter FROM t;\nSELECT 4;");
	}

	function testDelimiterWithoutValueThrows() {
		TestsHelper::Print("testing a DELIMITER line without a delimiter throws...");
		$this->expectException(MagratheaDBException::class);
		SqlSplitter::Split("SELECT 1;\nDELIMITER   \nSELECT 2;");
	}

	function testCrlf() {
		TestsHelper::Print("testing SqlSplitter handles CRLF line ends...");
		$this->AssertSplits([
			"SELECT 1",
			"CREATE TRIGGER x BEGIN SET a = 1; END",
			"SELECT 2",
		], "-- comment\r\nSELECT 1;\r\nDELIMITER $$\r\nCREATE TRIGGER x BEGIN SET a = 1; END$$\r\nDELIMITER ;\r\nSELECT 2;\r\n");
	}

	function testEmptyStatementsAreSkipped() {
		TestsHelper::Print("testing SqlSplitter skips empty statements and comment-only input...");
		$this->AssertSplits(["SELECT 1"], " ;;\n; SELECT 1 ;\n;");
		$this->AssertSplits([], "-- nothing\n/* here */\n");
	}

	function testFromFile() {
		TestsHelper::Print("testing SqlSplitter::FromFile() streams a file in chunks, and a missing file throws...");
		$file = tempnam(sys_get_temp_dir(), "magrathea-split-");
		file_put_contents($file, "SELECT 'a;b';\nDELIMITER ;;\nSELECT 1; SELECT 2;;\n");
		$this->assertSame(["SELECT 'a;b'", "SELECT 1; SELECT 2"], iterator_to_array(SqlSplitter::FromFile($file, 3), false));
		unlink($file);
		$this->expectException(MagratheaDBException::class);
		iterator_to_array(SqlSplitter::FromFile($file));
	}

	function testAdminDatabaseSqlSplits() {
		TestsHelper::Print("testing the admin's database.sql splits into its statements...");
		$statements = iterator_to_array(SqlSplitter::FromFile(AdminDatabase::Instance()->GetSQLFile()), false);
		$this->assertCount(5, $statements);
		foreach(["CREATE TABLE `_magrathea_config`", "CREATE TABLE `_magrathea_roles`", "INSERT INTO `_magrathea_roles`",
			"CREATE TABLE `_magrathea_users`", "CREATE TABLE `_magrathea_logs`"] as $n => $start) {
			$this->assertStringStartsWith($start, $statements[$n]);
		}
	}

}
