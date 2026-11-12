<?php

namespace Magrathea\Admin;

use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    ObjectsExplorer created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* The admin's Objects area, as arrays: the project's models (`SchemaCheck::FindModels()`, without the framework's own),
* one model's structure, a page of its rows, and saving and deleting its objects through the model.
* Only a class `Models()` lists can be read or written: anything else throws, with code 404.
*/
class ObjectsExplorer {

	/** rows on a page of `Page()` */
	const PAGE_SIZE = 25;
	/** the most rows a page can ask for */
	const MAX_PAGE_SIZE = 100;
	/** longer values are cut on the list (`Cell()`) */
	const CELL_LENGTH = 80;

	/** @var Database|\Magrathea\DB\DatabaseSimulate|null */
	private $db;
	private ?SchemaCheck $check;
	private ?Schema $schema;
	private ModelFields $fields;
	private ?array $found = null;

	/**
	* @param 	Database|null 		$db 		database for the rows (default: `Database::Instance()`)
	* @param 	SchemaCheck|null 	$check 		finds the models and checks them (default: `new SchemaCheck($schema)`)
	* @param 	Schema|null 		$schema 	the tables' approximate row counts (default: `new Schema($db)`)
	* @param 	ModelFields|null 	$fields 	the form fields (default: `new ModelFields($db)`)
	*/
	public function __construct(?Database $db = null, ?SchemaCheck $check = null, ?Schema $schema = null, ?ModelFields $fields = null) {
		$this->db = $db;
		$this->schema = $schema;
		$this->check = $check;
		$this->fields = $fields ?? new ModelFields($db);
	}

	private function Db() {
		return $this->db ?? Database::Instance();
	}
	private function GetSchema(): Schema {
		return $this->schema ??= new Schema($this->db);
	}
	private function GetCheck(): SchemaCheck {
		return $this->check ??= new SchemaCheck($this->GetSchema());
	}

	/**
	* The form fields this manager uses
	* @return 	ModelFields
	*/
	public function GetModelFields(): ModelFields {
		return $this->fields;
	}

	/**
	* Is it one of the framework's own models (namespace `Magrathea\`)? They're never listed.
	* @param 	string 	$class 		class name
	* @return 	bool
	*/
	public static function IsFramework(string $class): bool {
		return stripos(ltrim($class, "\\"), "Magrathea\\") === 0;
	}

	/**
	* FindModels(), once per instance, without the framework's models
	*/
	private function Found(): array {
		if($this->found !== null) return $this->found;
		$found = $this->GetCheck()->FindModels();
		$models = [];
		foreach($found["models"] as $model) {
			if(self::IsFramework($model["class"])) continue;
			$model["name"] = \Magrathea\getClassNameOfClass($model["class"]);
			$models[] = $model;
		}
		usort($models, fn($a, $b) => strcasecmp($a["name"], $b["name"]) ?: strcmp($a["class"], $b["class"]));
		return $this->found = ["models" => $models, "findings" => $found["findings"]];
	}

	/**
	* The project's models, sorted by name, with their tables' approximate row counts
	* @return 	array 	["models" => rows with ["class", "name" (without the namespace), "file", "table", "pk", "values" ($dbValues),
	* 					"rows" (approximate, int|null), "hasTable" (bool; null when the tables couldn't be read)],
	* 					"findings" => FindModels()'s findings, "error" => the tables' read error, or null]
	*/
	public function Models(): array {
		$found = $this->Found();
		$tables = null;
		$error = null;
		try {
			$tables = [];
			foreach($this->GetSchema()->Tables() as $table) $tables[strtolower($table["name"])] = $table;
		} catch(\Throwable $ex) {
			$tables = null;
			$error = $ex->getMessage();
		}
		$models = [];
		foreach($found["models"] as $model) {
			$table = $tables === null ? null : ($tables[strtolower((string)$model["table"])] ?? null);
			$model["rows"] = $table["rows"] ?? null;
			$model["hasTable"] = $tables === null ? null : $table !== null;
			$models[] = $model;
		}
		return ["models" => $models, "findings" => $found["findings"], "error" => $error];
	}

	/**
	* One of the project's models
	* @param 	mixed 	$class 		model class (full name, case-insensitive, with or without the leading backslash), from the query string
	* @return 	array 	["class", "name", "file", "table", "pk", "values"] (as `Models()` lists it, without the row count)
	* @throws 	MagratheaModelException 	(code 404) when `Models()` doesn't list it
	*/
	public function Model($class): array {
		if(is_string($class) && $class !== "") {
			$class = ltrim($class, "\\");
			foreach($this->Found()["models"] as $model) {
				if(strcasecmp($model["class"], $class) === 0) return $model;
			}
		}
		throw new MagratheaModelException("Object [".(is_scalar($class) ? $class : gettype($class))."] not found: it isn't one of the project's models", 404);
	}

	/**
	* A model's structure: its fields, with the PK and the relations, and its findings against its table
	* @param 	mixed 	$class 		model class (see `Model()`)
	* @return 	array 	["model" => Model(), "fields" => rows with ["field", "type", "pk" (bool), "relation" (class|null)],
	* 					"findings" => `SchemaCheck::CheckModel()`]
	* @throws 	MagratheaModelException 	(code 404) see `Model()`
	*/
	public function Structure($class): array {
		$model = $this->Model($class);
		$relations = ModelFields::Relations(new $model["class"]());
		$fields = [];
		foreach($model["values"] as $field => $type) {
			$fields[] = [
				"field" => $field,
				"type" => is_scalar($type) ? (string)$type : gettype($type),
				"pk" => $field === $model["pk"],
				"relation" => $relations[$field] ?? null,
			];
		}
		return ["model" => $model, "fields" => $fields, "findings" => $this->GetCheck()->CheckModel($model["class"])];
	}

	/**
	* A page of a model's rows, newest PK first. Long values are cut (`Cell()`).
	* @param 	mixed 	$class 		model class (see `Model()`)
	* @param 	int 	$page 		page, from 0
	* @param 	int 	$limit 		rows per page (1 to MAX_PAGE_SIZE)
	* @return 	array 	["model" => Model(), "columns" (the PK, the `$dbValues` fields, and created_at/updated_at when the rows have them),
	* 					"rows" => rows with ["id" => the PK's value, "cells" => [column => value]],
	* 					"page", "limit", "total", "pages"]
	* @throws 	MagratheaModelException 	(code 404) see `Model()`
	* @throws 	\Magrathea\Exceptions\MagratheaDBException 	when the table can't be read
	*/
	public function Page($class, int $page = 0, int $limit = self::PAGE_SIZE): array {
		$model = $this->Model($class);
		$page = max(0, $page);
		$limit = min(self::MAX_PAGE_SIZE, max(1, $limit));
		$table = self::Quote((string)$model["table"]);
		$total = (int)$this->Db()->QueryOne("SELECT COUNT(*) FROM ".$table);
		$rows = $this->Db()->QueryAll("SELECT * FROM ".$table.
			" ORDER BY ".self::Quote((string)$model["pk"])." DESC LIMIT ".$limit." OFFSET ".($page * $limit));
		$rows = is_array($rows) ? array_map(fn($row) => array_change_key_case((array)$row, CASE_LOWER), $rows) : [];

		$columns = array_values(array_unique(array_merge([$model["pk"]], array_keys($model["values"]))));
		foreach(ModelFields::TIMESTAMPS as $timestamp) {
			if(in_array($timestamp, $columns, true)) continue;
			foreach($rows as $row) {
				if(array_key_exists($timestamp, $row)) {
					$columns[] = $timestamp;
					break;
				}
			}
		}
		$result = [];
		foreach($rows as $row) {
			$cells = [];
			foreach($columns as $column) $cells[$column] = self::Cell($row[strtolower($column)] ?? null);
			$result[] = ["id" => $row[strtolower($model["pk"])] ?? null, "cells" => $cells];
		}
		return [
			"model" => $model,
			"columns" => $columns,
			"rows" => $result,
			"page" => $page,
			"limit" => $limit,
			"total" => $total,
			"pages" => (int)ceil($total / $limit),
		];
	}

	/**
	* A value for the list: long strings are cut to CELL_LENGTH characters, with "…"
	* @param 	mixed 	$value
	* @return 	mixed 	the value, or the cut string
	*/
	public static function Cell($value) {
		if(!is_string($value) || mb_strlen($value) <= self::CELL_LENGTH) return $value;
		return mb_substr($value, 0, self::CELL_LENGTH)."…";
	}

	/**
	* Loads an object, or creates an empty one
	* @param 	mixed 	$class 		model class (see `Model()`)
	* @param 	mixed 	$id 		its PK (empty: a new object)
	* @return 	MagratheaModel
	* @throws 	MagratheaModelException 	(code 404) see `Model()`; the object doesn't exist; (code 400) the id is invalid
	*/
	public function Load($class, $id = null): MagratheaModel {
		$model = $this->Model($class);
		if($id !== null && !is_scalar($id)) throw new MagratheaModelException("Invalid id for ".$model["class"], 400);
		$modelClass = $model["class"];
		return ($id === null || $id === "") ? new $modelClass() : new $modelClass($id);
	}

	/**
	* Saves posted form data: inserts when `$id` is empty, or updates that object. Only the form's fields are assigned
	* (`ModelFields::Values()`). A new object whose PK isn't generated (not int, not uuid) takes it from the post,
	* and is inserted with it (`InsertWithPk()`).
	* @param 	mixed 	$class 		model class (see `Model()`)
	* @param 	mixed 	$id 		the object's PK, as it was loaded (empty: a new object)
	* @param 	array 	$post 		posted data
	* @return 	array 	["type" => "insert"|"update", "id" => the object's PK, "object" => the object]
	* @throws 	MagratheaModelException 	see `Load()`; (code 400) a new object without its PK
	* @throws 	\Magrathea\Exceptions\MagratheaDBException 	the write fails
	*/
	public function Save($class, $id, array $post): array {
		$isNew = ($id === null || $id === "");
		$object = $this->Load($class, $isNew ? null : $id);
		$object->Assign($this->fields->Values($object, $post));
		if(!$isNew) {
			$object->Update();
			return ["type" => "update", "id" => $object->GetID(), "object" => $object];
		}
		$pk = $object->GetPkName();
		$pkType = $object->GetDbValues()[$pk] ?? "int";
		if(in_array($pkType, ModelFields::GENERATED_PK_TYPES, true)) {
			$object->Insert();
		} else {
			$value = $post[$pk] ?? null;
			if(!is_scalar($value) || (string)$value === "") {
				throw new MagratheaModelException("A new ".$object->ModelName()." needs its primary key [".$pk."]", 400);
			}
			$object->Set($pk, (string)$value);
			$object->InsertWithPk();
		}
		return ["type" => "insert", "id" => $object->GetID(), "object" => $object];
	}

	/**
	* Deletes an object
	* @param 	mixed 	$class 		model class (see `Model()`)
	* @param 	mixed 	$id 		its PK
	* @return 	bool 	`MagratheaModel::Delete()`'s result
	* @throws 	MagratheaModelException 	see `Load()`; (code 400) an empty id
	* @throws 	\Magrathea\Exceptions\MagratheaDBException 	the delete fails
	*/
	public function Delete($class, $id): bool {
		if($id === null || $id === "") throw new MagratheaModelException("Which object? The id is empty", 400);
		return (bool)$this->Load($class, $id)->Delete();
	}

	private static function Quote(string $name): string {
		return "`".str_replace("`", "``", $name)."`";
	}

}
