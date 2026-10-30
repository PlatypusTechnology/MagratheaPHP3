<?php

namespace Magrathea\Admin;

use Magrathea\DB\Database;
use Magrathea\DB\SqlSplitter;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    QueryRunner created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The admin's Database → Queries page, as arrays: runs the SQL typed there, one statement at a time.
* SQL that isn't read-only (`SqlSplitter::IsReadOnly()`) runs only when confirmed. Read-only statements stream their
* rows and stop at MAX_ROWS; the view only renders.
*/
class QueryRunner {

	/** rows shown for a statement */
	const MAX_ROWS = 1000;

	const EMPTY = "empty";
	const CONFIRM = "confirm";
	const OK = "ok";
	const ERROR = "error";

	/** @var Database|\Magrathea\DB\DatabaseSimulate */
	private $db;

	/**
	* @param 	Database|null 	$db 	database the SQL runs on (default: `Database::Instance()`)
	*/
	public function __construct(?Database $db = null) {
		$this->db = $db ?? Database::Instance();
	}

	/**
	* Runs SQL, one statement at a time, and stops at the first error (the statements before it stay run: no transaction).
	* A read-only statement streams its rows (`Database::QueryStream()`: column names in lower case) and keeps the first
	* MAX_ROWS; any other runs through `Database::QueryMulti()`, so it isn't split again, and gets its affected rows.
	* @param 	string 	$sql 		SQL, with any number of statements
	* @param 	bool 	$confirmed 	run it even when it isn't read-only
	* @return 	array 	["status" => EMPTY (no statement), CONFIRM (not read-only and not confirmed: nothing ran), OK or ERROR,
	* 					"readOnly" => `SqlSplitter::IsReadOnly()`, "error" => the error, or null,
	* 					"statements" => rows with ["sql", "type" (its first keyword, upper case), "readOnly",
	* 						"rows" (array, or null when it returned none), "truncated" (it had more than MAX_ROWS rows),
	* 						"affected" (int for a statement that isn't read-only, or null), "error" (string, or null)]:
	* 						every statement for CONFIRM (none of them ran), the ones that ran for ERROR (the last one failed),
	* 					"notRun" => statements left after the failing one]
	*/
	public function Run(string $sql, bool $confirmed = false): array {
		$response = ["status" => self::OK, "readOnly" => false, "error" => null, "statements" => [], "notRun" => 0];
		try {
			$statements = SqlSplitter::Split($sql);
		} catch(\Throwable $ex) {
			return ["status" => self::ERROR, "error" => $ex->getMessage()] + $response;
		}
		if(empty($statements)) return ["status" => self::EMPTY] + $response;

		$response["readOnly"] = SqlSplitter::IsReadOnly($sql);
		if(!$response["readOnly"] && !$confirmed) {
			$response["status"] = self::CONFIRM;
			$response["statements"] = array_map(fn($statement) => self::Statement($statement), $statements);
			return $response;
		}

		foreach($statements as $i => $statement) {
			$result = $this->RunStatement($statement);
			$response["statements"][] = $result;
			if($result["error"] !== null) {
				$response["status"] = self::ERROR;
				$response["error"] = $result["error"];
				$response["notRun"] = count($statements) - $i - 1;
				break;
			}
		}
		return $response;
	}

	/**
	* A statement's row, before it runs
	*/
	private static function Statement(string $statement): array {
		preg_match('/^[A-Za-z]+/', $statement, $keyword);
		return [
			"sql" => $statement,
			"type" => strtoupper($keyword[0] ?? ""),
			"readOnly" => SqlSplitter::IsReadOnlyStatement($statement),
			"rows" => null,
			"truncated" => false,
			"affected" => null,
			"error" => null,
		];
	}

	/**
	* Runs one statement. A failure is returned, not thrown
	*/
	private function RunStatement(string $statement): array {
		$result = self::Statement($statement);
		try {
			if($result["readOnly"]) {
				$result["rows"] = [];
				$stream = $this->db->QueryStream($statement);
				foreach($stream as $row) {
					if(count($result["rows"]) === self::MAX_ROWS) {
						$result["truncated"] = true;
						break;
					}
					$result["rows"][] = $row;
				}
				// frees the unbuffered result (the generator's finally) before the next statement
				$stream = null;
			} else {
				$run = $this->db->QueryMulti([$statement]);
				$result["affected"] = $this->db->LastAffectedRows();
				// QueryMulti() answers a CREATE with ["success" => true], not rows
				$rows = $run[0]["result"] ?? null;
				if(is_array($rows) && !empty($rows) && array_is_list($rows)) {
					$result["truncated"] = count($rows) > self::MAX_ROWS;
					$result["rows"] = array_slice($rows, 0, self::MAX_ROWS);
				}
			}
		} catch(\Throwable $ex) {
			$result["error"] = $ex->getMessage();
		}
		return $result;
	}

}
