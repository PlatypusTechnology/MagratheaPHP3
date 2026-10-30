<?php

namespace Magrathea\Admin;

use Magrathea\DB\Database;
use Magrathea\DB\Query;
use Magrathea\DB\Schema;
use Magrathea\Exceptions\MagratheaDBException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    QueryEditor created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The admin's Database → Query editor page, as arrays: builds a `Query` from the editor's form (a spec) and returns its
* PHP chain and its SQL. Table and column names must be ones `Schema` lists; values go through `Escape()`.
* It only builds: the page runs the SQL through `QueryRunner`.
*/
class QueryEditor {

	const TYPES = ["select", "insert", "update", "delete"];
	const OPERATORS = ["=", "!=", "<", ">", "<=", ">=", "LIKE", "IS NULL", "IS NOT NULL", "IN"];
	const DIRECTIONS = ["ASC", "DESC"];
	/** spec keys each type takes, besides "type" and "table" */
	const KEYS = [
		"select" => ["fields", "where", "order", "limit", "page"],
		"insert" => ["values"],
		"update" => ["values", "where"],
		"delete" => ["where"],
	];

	/**
	* Builds a real `Query` through method calls (never `eval`), and the PHP chain of those same calls.
	* Where rows are joined with AND. A key the type doesn't take is rejected when it isn't empty (null, "" or []):
	* `QueryUpdate` and `QueryDelete` ignore `Order()` and `Limit()`, so a write never gets them.
	* @param 	array 		$spec 		["type" => one of TYPES, "table" => a name `Schema::Tables()` lists exactly,
	* 								"fields" => columns to select (select; none: `*`),
	* 								"values" => [column => string|int|float|null] (insert and update, at least one; null writes NULL),
	* 								"where" => rows with ["column", "operator" (one of OPERATORS, default "="), "value"
	* 									(ignored by IS NULL and IS NOT NULL; IN takes a comma-separated list)] (update and delete: at least one),
	* 								"order" => ["column", "direction" (ASC or DESC, default ASC)], "limit" (>= 1) and "page" (from 0;
	* 									needs "limit") (select)]
	* @param 	Schema|null $schema 	schema the names are checked against (default: `new Schema()`)
	* @return 	array 	["php" => "Query::Select()->Table(\"articles\")->…", "sql" => the query's `SQL()`]
	* @throws 	MagratheaDBException 		unknown table or column
	* @throws 	\InvalidArgumentException 	anything else wrong in the spec
	*/
	public static function Build(array $spec, ?Schema $schema = null): array {
		$schema = $schema ?? new Schema();
		$type = $spec["type"] ?? null;
		if(!is_string($type) || !in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException("Unknown query type [".self::Show($type)."]: use one of ".implode(", ", self::TYPES));
		}
		$known = array_merge(["type", "table"], ...array_values(self::KEYS));
		foreach($spec as $key => $value) {
			if(!in_array($key, $known, true)) throw new \InvalidArgumentException("Unknown key [".$key."]");
			if($key === "type" || $key === "table" || self::IsEmpty($value)) continue;
			if(!in_array($key, self::KEYS[$type], true)) throw new \InvalidArgumentException("A ".$type." doesn't take [".$key."]");
		}

		$table = self::RequireTable($schema, $spec["table"] ?? null);
		$columns = array_column($schema->Columns($table), "name");
		$where = self::WhereCalls($spec["where"] ?? null, $columns, $table);

		$calls = [];
		switch($type) {
			case "select":
				$fields = array_map(
					fn($field) => self::Quote(self::RequireColumn($field, $columns, $table)),
					self::ListOf($spec["fields"] ?? null, "fields"));
				$calls[] = ["Select", empty($fields) ? [] : [implode(", ", $fields)]];
				$calls[] = ["Table", [$table]];
				$calls = array_merge($calls, $where);
				$calls = array_merge($calls, self::SelectTailCalls($spec, $columns, $table));
				break;
			case "insert":
				$calls[] = ["Insert", []];
				$calls[] = ["Table", [$table]];
				$calls[] = ["Values", [self::Values($spec["values"] ?? null, $columns, $table, $type)]];
				break;
			case "update":
				$calls[] = ["Update", []];
				$calls[] = ["Table", [$table]];
				$calls[] = ["SetArray", [self::Values($spec["values"] ?? null, $columns, $table, $type)]];
				if(empty($where)) throw new \InvalidArgumentException("An update needs at least one where row");
				$calls = array_merge($calls, $where);
				break;
			case "delete":
				$calls[] = ["Delete", []];
				$calls[] = ["Table", [$table]];
				if(empty($where)) throw new \InvalidArgumentException("A delete needs at least one where row");
				$calls = array_merge($calls, $where);
				break;
		}

		return ["php" => self::Php($calls), "sql" => self::Apply($calls)->SQL()];
	}

	/**
	* The where rows, as `Where()` calls: `=` as `Where([column => value])`, the others as a string condition
	* built from the quoted column and the `Escape()`d values
	*/
	private static function WhereCalls($rows, array $columns, string $table): array {
		$calls = [];
		foreach(self::ListOf($rows, "where") as $row) {
			if(!is_array($row)) throw new \InvalidArgumentException("A where row must be [column, operator, value]");
			$column = self::RequireColumn($row["column"] ?? null, $columns, $table);
			$operator = strtoupper(trim((string)($row["operator"] ?? "=")));
			if(!in_array($operator, self::OPERATORS, true)) {
				throw new \InvalidArgumentException("Unknown operator [".self::Show($row["operator"])."]: use one of ".implode(", ", self::OPERATORS));
			}
			$value = self::Scalar($row["value"] ?? "", "where [".$column."]");
			$value = (string)$value;
			switch($operator) {
				case "=":
					$calls[] = ["Where", [[$column => $value]]];
					break;
				case "IS NULL":
				case "IS NOT NULL":
					$calls[] = ["Where", [self::Quote($column)." ".$operator]];
					break;
				case "IN":
					$items = array_values(array_filter(array_map("trim", explode(",", $value)), fn($item) => $item !== ""));
					if(empty($items)) throw new \InvalidArgumentException("IN needs a value for [".$column."]");
					$calls[] = ["Where", [self::Quote($column)." IN (".implode(", ", array_map([self::class, "Literal"], $items)).")"]];
					break;
				default:
					$calls[] = ["Where", [self::Quote($column)." ".$operator." ".self::Literal($value)]];
			}
		}
		return $calls;
	}

	/**
	* A select's order, limit and page, as calls
	*/
	private static function SelectTailCalls(array $spec, array $columns, string $table): array {
		$calls = [];
		$order = $spec["order"] ?? null;
		if(!self::IsEmpty($order)) {
			if(!is_array($order)) throw new \InvalidArgumentException("order must be [column, direction]");
			$column = self::RequireColumn($order["column"] ?? null, $columns, $table);
			$direction = strtoupper(trim((string)self::Scalar($order["direction"] ?? "ASC", "order direction")));
			if($direction === "") $direction = "ASC";
			if(!in_array($direction, self::DIRECTIONS, true)) {
				throw new \InvalidArgumentException("Unknown order direction [".self::Show($order["direction"])."]: use ASC or DESC");
			}
			$calls[] = ["Order", [self::Quote($column)." ".$direction]];
		}
		$limit = self::IsEmpty($spec["limit"] ?? null) ? null : self::Int($spec["limit"], "limit", 1);
		$page = self::IsEmpty($spec["page"] ?? null) ? null : self::Int($spec["page"], "page", 0);
		if($page !== null && $limit === null) throw new \InvalidArgumentException("page needs a limit");
		if($limit !== null) $calls[] = ["Limit", [$limit]];
		if($page !== null) $calls[] = ["Page", [$page]];
		return $calls;
	}

	/**
	* An insert's or update's values: [column => value], at least one
	*/
	private static function Values($values, array $columns, string $table, string $type): array {
		if(self::IsEmpty($values)) throw new \InvalidArgumentException("An ".$type." needs at least one value");
		if(!is_array($values) || array_is_list($values)) throw new \InvalidArgumentException("values must be [column => value]");
		$checked = [];
		foreach($values as $column => $value) {
			$column = self::RequireColumn((string)$column, $columns, $table);
			$checked[$column] = ($value === null) ? null : self::Scalar($value, "value of [".$column."]");
		}
		return $checked;
	}

	/**
	* Runs the calls on a new Query: the first one is the static constructor
	*/
	private static function Apply(array $calls): Query {
		[$method, $args] = array_shift($calls);
		$query = call_user_func([Query::class, $method], ...$args);
		foreach($calls as [$method, $args]) $query = $query->{$method}(...$args);
		return $query;
	}

	/**
	* The calls as a PHP chain, starting with `Query::`
	*/
	private static function Php(array $calls): string {
		$php = "Query";
		foreach($calls as $i => [$method, $args]) {
			$php .= ($i === 0 ? "::" : "->").$method."(".implode(", ", array_map([self::class, "PhpValue"], $args)).")";
		}
		return $php;
	}

	/**
	* A PHP literal: strings double-quoted, with `\`, `"` and `$` escaped and control characters as `\xNN`
	*/
	private static function PhpValue($value): string {
		if($value === null) return "null";
		if(is_int($value) || is_float($value)) return var_export($value, true);
		if(is_array($value)) {
			$items = [];
			foreach($value as $key => $item) $items[] = self::PhpValue($key)." => ".self::PhpValue($item);
			return "[".implode(", ", $items)."]";
		}
		$escaped = addcslashes((string)$value, "\\\"\$");
		$escaped = preg_replace_callback('/[\x00-\x1f\x7f]/', fn($char) => sprintf("\\x%02X", ord($char[0])), $escaped);
		return '"'.$escaped.'"';
	}

	/**
	* @throws 	MagratheaDBException 	unknown table
	*/
	private static function RequireTable(Schema $schema, $table): string {
		if(!is_string($table) || !in_array($table, array_column($schema->Tables(), "name"), true)) {
			throw new MagratheaDBException("Unknown table [".self::Show($table)."]");
		}
		return $table;
	}

	/**
	* @throws 	MagratheaDBException 	unknown column
	*/
	private static function RequireColumn($column, array $columns, string $table): string {
		if(!is_string($column) || !in_array($column, $columns, true)) {
			throw new MagratheaDBException("Unknown column [".self::Show($column)."] in [".$table."]");
		}
		return $column;
	}

	/**
	* A list, or [] when it's empty
	*/
	private static function ListOf($value, string $key): array {
		if(self::IsEmpty($value)) return [];
		if(!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException($key." must be a list");
		return $value;
	}

	/**
	* A string, int or float
	*/
	private static function Scalar($value, string $what) {
		if(!is_string($value) && !is_int($value) && !is_float($value)) {
			throw new \InvalidArgumentException("The ".$what." must be a string or a number");
		}
		return $value;
	}

	/**
	* An int (or a string of digits) of at least $min
	*/
	private static function Int($value, string $key, int $min): int {
		if(is_int($value)) $int = $value;
		elseif(is_string($value) && preg_match('/^\d+$/', $value)) $int = (int)$value;
		else throw new \InvalidArgumentException($key." must be a whole number");
		if($int < $min) throw new \InvalidArgumentException($key." must be at least ".$min);
		return $int;
	}

	private static function IsEmpty($value): bool {
		return $value === null || $value === "" || $value === [];
	}

	private static function Quote(string $name): string {
		return "`".str_replace("`", "``", $name)."`";
	}

	private static function Literal(string $value): string {
		return "'".Database::Instance()->Escape($value)."'";
	}

	/**
	* A value for an error message
	*/
	private static function Show($value): string {
		return is_scalar($value) ? (string)$value : gettype($value);
	}

}
