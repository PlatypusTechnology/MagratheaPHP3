<?php

namespace Magrathea\Admin;

use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\TimezoneCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DatabaseExplorer created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The admin's Database → Explorer page, as arrays: the tables with the models that map them and their drift
* status, and one table's structure, findings and first rows. Read-only; the view only renders.
*/
class DatabaseExplorer {

	/** rows shown in a table's preview */
	const PREVIEW_ROWS = 20;
	/** finding levels, from the least to the most severe */
	const LEVELS = [TimezoneCheck::OK, TimezoneCheck::INFO, TimezoneCheck::WARNING, TimezoneCheck::ERROR];

	/** @var Database|\Magrathea\DB\DatabaseSimulate */
	private $db;
	private Schema $schema;
	private SchemaCheck $check;
	private ?array $models = null;

	/**
	* @param 	Database|null 		$db 		database for the rows' preview (default: `Database::Instance()`)
	* @param 	Schema|null 		$schema 	schema (default: `new Schema($db)`)
	* @param 	SchemaCheck|null 	$check 		model checks (default: `new SchemaCheck($schema)`)
	*/
	public function __construct(?Database $db = null, ?Schema $schema = null, ?SchemaCheck $check = null) {
		$this->db = $db ?? Database::Instance();
		$this->schema = $schema ?? new Schema($db);
		$this->check = $check ?? new SchemaCheck($this->schema);
	}

	/**
	* The tables and views of the database, with the models that map them (`SchemaCheck::FindModels()`) and their drift status
	* @return 	array 	["tables" => rows with ["name", "type", "engine", "rows" (approximate, int|null), "models" (class names),
	* 					"status" (the most severe level of the models' findings, `TimezoneCheck::OK` without any; null when no model maps it)],
	* 					"findings" => FindModels()'s findings (models that couldn't be loaded)]
	* @throws 	MagratheaDBException 	when the database can't be read
	*/
	public function Tables(): array {
		$found = $this->FindModels();
		$tables = [];
		foreach($this->schema->Tables() as $table) {
			$models = $this->ModelsOf($table["name"]);
			$tables[] = [
				"name" => $table["name"],
				"type" => $table["type"],
				"engine" => $table["engine"],
				"rows" => $table["rows"],
				"models" => $models,
				"status" => empty($models) ? null : self::WorstLevel($this->ModelFindings($table["name"], $models)),
			];
		}
		return ["tables" => $tables, "findings" => $found["findings"]];
	}

	/**
	* One table or view: its structure, the findings of the models that map it, and its first rows
	* @param 	string 	$table 		table name, exactly as `Tables()` lists it
	* @return 	array 	["name", "type", "columns" (`Schema::Columns()` rows, plus "magrathea_type": `Schema::MagratheaType()`, or null),
	* 					"indexes" (`Schema::Indexes()`), "create" (`SHOW CREATE TABLE`), "models" (class names),
	* 					"findings" (`SchemaCheck::CheckModel()` for each model and `CheckTimestamps()`; none when no model maps it),
	* 					"preview" => ["rows" => the first PREVIEW_ROWS rows, "error" => the query's error message, or null]]
	* @throws 	MagratheaDBException 	unknown table, or when the database can't be read
	*/
	public function Table(string $table): array {
		$info = null;
		foreach($this->schema->Tables() as $row) {
			if($row["name"] === $table) {
				$info = $row;
				break;
			}
		}
		if($info === null) throw new MagratheaDBException("Unknown table [".$table."]");
		$name = $info["name"];

		$columns = array_map(
			fn($column) => $column + ["magrathea_type" => Schema::MagratheaType((string)$column["type"])],
			$this->schema->Columns($name));
		$this->FindModels();
		$models = $this->ModelsOf($name);
		return [
			"name" => $name,
			"type" => $info["type"],
			"columns" => $columns,
			"indexes" => $this->schema->Indexes($name),
			"create" => $this->schema->CreateStatement($name),
			"models" => $models,
			"findings" => empty($models) ? [] : $this->ModelFindings($name, $models),
			"preview" => $this->Preview($name),
		];
	}

	/**
	* The most severe level in a list of findings
	* @param 	array 	$findings 	findings
	* @return 	string 	a `TimezoneCheck` level (`OK` for an empty list)
	*/
	public static function WorstLevel(array $findings): string {
		$worst = 0;
		foreach($findings as $finding) {
			$level = array_search($finding["level"] ?? null, self::LEVELS, true);
			if($level !== false && $level > $worst) $worst = $level;
		}
		return self::LEVELS[$worst];
	}

	/**
	* `SchemaCheck::FindModels()`, once per instance
	*/
	private function FindModels(): array {
		if($this->models === null) $this->models = $this->check->FindModels();
		return $this->models;
	}

	/**
	* Classes of the found models that map a table (table names compared case-insensitively, as SchemaCheck does)
	*/
	private function ModelsOf(string $table): array {
		$classes = [];
		foreach($this->FindModels()["models"] as $model) {
			if(strcasecmp((string)$model["table"], $table) == 0) $classes[] = $model["class"];
		}
		return $classes;
	}

	/**
	* Findings of a table's models, and of its timestamps
	*/
	private function ModelFindings(string $table, array $models): array {
		$findings = [];
		foreach($models as $class) $findings = array_merge($findings, $this->check->CheckModel($class));
		return array_merge($findings, $this->check->CheckTimestamps($table));
	}

	/**
	* The first rows of a table. A failure (a broken view) is returned, not thrown: the rest of the page still shows
	* @param 	string 	$table 		a name Schema listed
	*/
	private function Preview(string $table): array {
		$sql = "SELECT * FROM `".str_replace("`", "``", $table)."` LIMIT ".self::PREVIEW_ROWS;
		try {
			return ["rows" => (array)$this->db->QueryAll($sql), "error" => null];
		} catch(\Throwable $ex) {
			return ["rows" => [], "error" => $ex->getMessage()];
		}
	}

}
