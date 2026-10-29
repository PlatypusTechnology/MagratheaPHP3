<?php

namespace Magrathea\DB;

use Magrathea\Exceptions\MagratheaDBException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Schema created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* Database schema, read-only: tables, columns, indexes and `CREATE` statements of the connection's
* current database (`DATABASE()`), as arrays; and what the database's columns mean for Magrathea models.
*/
class Schema {

	/** @var Database|DatabaseSimulate */
	private $db;

	/**
	* @param 	Database|null 	$db 	database to read (default: `Database::Instance()`)
	*/
	public function __construct(?Database $db = null) {
		$this->db = $db ?? Database::Instance();
	}

	/**
	* Tables and views of the current database, sorted by name.
	* @param 	bool 	$withFramework 		false leaves out the framework's `_magrathea_*` tables
	* @return 	array 	rows with ["name", "type" ("BASE TABLE" or "VIEW"), "engine", "rows" (approximate, int|null), "collation", "comment"]
	*/
	public function Tables(bool $withFramework = true): array {
		$sql = "SELECT TABLE_NAME AS `name`, TABLE_TYPE AS `type`, ENGINE AS `engine`, TABLE_ROWS AS `rows`, ".
			"TABLE_COLLATION AS `collation`, TABLE_COMMENT AS `comment` ".
			"FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()";
		if(!$withFramework) $sql .= " AND TABLE_NAME NOT LIKE '\\_magrathea\\_%'";
		$sql .= " ORDER BY TABLE_NAME";
		return array_map(function($row) {
			$row["rows"] = $row["rows"] === null ? null : (int)$row["rows"];
			return $row;
		}, $this->db->QueryAll($sql));
	}

	/**
	* Whether the current database has this table (or view).
	* @param 	string 	$table 		table name
	* @return 	bool
	*/
	public function HasTable(string $table): bool {
		return $this->FindTable($table) !== null;
	}

	/**
	* Columns of a table, in their order.
	* @param 	string 	$table 		table name
	* @return 	array 	rows with ["name", "type" (column type: `int(10) unsigned`, `varchar(255)`, …), "nullable" (bool), "default", "extra", "key" (`PRI`, `UNI`, `MUL` or "")]
	* @throws 	MagratheaDBException 	unknown table
	*/
	public function Columns(string $table): array {
		$table = $this->RequireTable($table);
		$sql = "SELECT COLUMN_NAME AS `name`, COLUMN_TYPE AS `type`, IS_NULLABLE AS `nullable`, ".
			"COLUMN_DEFAULT AS `default`, EXTRA AS `extra`, COLUMN_KEY AS `key` ".
			"FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->Escape($table)."' ".
			"ORDER BY ORDINAL_POSITION";
		return array_map(function($row) {
			$row["nullable"] = ($row["nullable"] === "YES");
			return $row;
		}, $this->db->QueryAll($sql));
	}

	/**
	* Indexes of a table, `PRIMARY` first, one row per index (`information_schema.STATISTICS` has one per column).
	* @param 	string 	$table 		table name
	* @return 	array 	rows with ["name", "unique" (bool), "type" (`BTREE`, `FULLTEXT`, …), "columns" (names, in the index's order)]
	* @throws 	MagratheaDBException 	unknown table
	*/
	public function Indexes(string $table): array {
		$table = $this->RequireTable($table);
		$sql = "SELECT INDEX_NAME AS `name`, NON_UNIQUE AS `non_unique`, INDEX_TYPE AS `type`, COLUMN_NAME AS `column` ".
			"FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->Escape($table)."' ".
			"ORDER BY INDEX_NAME = 'PRIMARY' DESC, INDEX_NAME, SEQ_IN_INDEX";
		$indexes = [];
		foreach($this->db->QueryAll($sql) as $row) {
			$name = $row["name"];
			if(!isset($indexes[$name])) {
				$indexes[$name] = [
					"name" => $name,
					"unique" => ((int)$row["non_unique"] === 0),
					"type" => $row["type"],
					"columns" => [],
				];
			}
			$indexes[$name]["columns"][] = $row["column"];
		}
		return array_values($indexes);
	}

	/**
	* The table's `CREATE TABLE` statement (`CREATE VIEW` for a view), from `SHOW CREATE TABLE`.
	* @param 	string 	$table 		table name
	* @return 	string
	* @throws 	MagratheaDBException 	unknown table
	*/
	public function CreateStatement(string $table): string {
		$table = $this->RequireTable($table);
		$row = $this->db->QueryRow("SHOW CREATE TABLE `".str_replace("`", "``", $table)."`");
		return (string)($row["create table"] ?? $row["create view"] ?? "");
	}

	/**
	* The table's name as the database has it, or null when there's no such table.
	*/
	private function FindTable(string $table): ?string {
		$sql = "SELECT TABLE_NAME AS `name` FROM information_schema.TABLES ".
			"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$this->db->Escape($table)."'";
		$row = $this->db->QueryRow($sql);
		return empty($row["name"]) ? null : $row["name"];
	}

	/**
	* @throws 	MagratheaDBException 	unknown table
	*/
	private function RequireTable(string $table): string {
		$name = $this->FindTable($table);
		if($name === null) throw new MagratheaDBException("Unknown table [".$table."]");
		return $name;
	}

	/** column base type => Magrathea type (tinyint(1), bit(1) and char(36) are handled apart) */
	const TYPE_MAP = [
		"tinyint" => "int",
		"smallint" => "int",
		"mediumint" => "int",
		"int" => "int",
		"integer" => "int",
		"bigint" => "int",
		"year" => "int",
		"decimal" => "decimal",
		"numeric" => "decimal",
		"float" => "float",
		"double" => "float",
		"real" => "float",
		"char" => "string",
		"varchar" => "string",
		"enum" => "string",
		"set" => "string",
		"time" => "string",
		"tinytext" => "text",
		"text" => "text",
		"mediumtext" => "text",
		"longtext" => "text",
		"json" => "text",
		"date" => "date",
		"datetime" => "datetime",
		"timestamp" => "datetime",
	];

	/**
	* Magrathea type suggested for a SQL column type (as `SHOW COLUMNS` gives it: `int(11) unsigned`,
	* `varchar(255)`, `decimal(10,2)`, …). Case-insensitive; `unsigned`/`zerofill` and display widths are
	* ignored, except `tinyint(1)` and `bit(1)` (`boolean`) and `char(36)` (`uuid`: a suggestion,
	* the column may hold other strings).
	* @param 	string 	$columnType		SQL column type
	* @return 	string|null 			Magrathea type, or null when there's no suggestion (blobs, binary, spatial types)
	*/
	public static function MagratheaType(string $columnType): ?string {
		$type = strtolower(trim($columnType));
		if(!preg_match('/^([a-z]+)\s*(?:\(([^)]*)\))?/', $type, $match)) return null;
		$base = $match[1];
		$size = trim($match[2] ?? "");
		if(($base == "tinyint" || $base == "bit") && $size === "1") return "boolean";
		if($base == "char" && $size === "36") return "uuid";
		return self::TYPE_MAP[$base] ?? null;
	}

}
