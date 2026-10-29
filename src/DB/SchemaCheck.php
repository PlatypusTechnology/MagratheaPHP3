<?php

namespace Magrathea\DB;

use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\MagratheaPHP;
use Magrathea\TimezoneCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    SchemaCheck created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* Models against their tables: finds the models in the code folders and reports, as findings, where a model
* and its table disagree, and timestamp columns that break the table contract
* (`created_at DEFAULT CURRENT_TIMESTAMP`, `updated_at DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).
* Read-only: fixes are SQL in the findings' `fix`, never run.
*/
class SchemaCheck {

	const GROUP = "schema";
	/** owned by the database: never in `$dbValues`, checked by CheckTimestamps() */
	const TIMESTAMPS = ["created_at", "updated_at"];
	/** types that bind and load the same (as text, unchanged): compared as one type */
	const TEXT_TYPES = ["string", "text", "uuid"];

	private Schema $schema;

	/**
	* @param 	Schema|null 	$schema 	schema to check against (default: `new Schema()`, on `Database::Instance()`)
	*/
	public function __construct(?Schema $schema = null) {
		$this->schema = $schema ?? new Schema();
	}

	/**
	* Finds the models in the code folders (`MagratheaPHP::Instance()->codeFolder`): the non-abstract
	* `MagratheaModel` subclasses declared in their `*.php` files (not recursively), loaded through the autoloader.
	* When a model and its subclass are both found (a generated `ArticleBase` and its `Article`), only the subclass is kept.
	* Never throws: a class that fails to load or instantiate is a finding.
	* @return 	array 	["models" => rows with ["class", "file", "table", "pk", "values" ($dbValues)], "findings" => findings]
	*/
	public function FindModels(): array {
		$folders = $this->CodeFolders();
		$candidates = [];
		foreach($folders as $folder) {
			foreach(glob($folder."/*.php") ?: [] as $file) {
				foreach(self::DeclaredClasses($file) as $class) $candidates[$class] ??= $file;
			}
		}

		// a class can fail here and still be declared by a file loaded later: checked again below
		$failed = [];
		$loaded = [];
		foreach($candidates as $class => $file) {
			try {
				if(class_exists($class)) $loaded[$class] = $file;
				else $failed[$class] = "the autoloader can't find it: is its file named after the class?";
			} catch(\Throwable $ex) {
				$failed[$class] = get_class($ex).": ".$ex->getMessage();
			}
		}
		$findings = [];
		foreach($failed as $class => $error) {
			if(class_exists($class, false)) {
				$loaded[$class] = $candidates[$class];
				continue;
			}
			$findings[] = self::Finding(TimezoneCheck::WARNING,
				"`".$class."` (".$candidates[$class].") couldn't be loaded, so it wasn't checked: ".$error,
				["model" => $class]);
		}

		$models = [];
		foreach($loaded as $class => $file) {
			$reflection = new \ReflectionClass($class);
			if($reflection->isAbstract() || !$reflection->isSubclassOf(MagratheaModel::class)) continue;
			$models[$reflection->getName()] = $file;
		}
		// Base classes: a subclass maps the same table
		foreach(array_keys($models) as $class) {
			foreach(array_keys($models) as $other) {
				if(is_subclass_of($other, $class)) {
					unset($models[$class]);
					break;
				}
			}
		}

		$found = [];
		foreach($models as $class => $file) {
			try {
				$found[] = ["file" => $file] + $this->Describe($class);
			} catch(\Throwable $ex) {
				$findings[] = self::Finding(TimezoneCheck::ERROR,
					"`".$class."` can't be instantiated: ".$ex->getMessage(),
					["model" => $class]);
			}
		}
		if(empty($found)) {
			$findings[] = self::Finding(TimezoneCheck::WARNING, empty($folders)
				? "No model found: there are no code folders (`MagratheaPHP::AddCodeFolder()`)."
				: "No model found in the code folders [".implode(", ", $folders)."].");
		}
		return ["models" => $found, "findings" => $findings];
	}

	/**
	* Checks a model against its table: the table, the PK, each `$dbValues` field and its type, the columns the
	* model doesn't list, and the timestamp columns' presence (their definitions: CheckTimestamps()).
	* Never throws: a class that can't be checked is a finding.
	* @param 	string 	$class 		model class (full name)
	* @return 	array 	findings
	*/
	public function CheckModel(string $class): array {
		try {
			$model = $this->Describe($class);
		} catch(\Throwable $ex) {
			return [self::Finding(TimezoneCheck::ERROR, "`".$class."` can't be checked: ".$ex->getMessage(), ["model" => $class])];
		}
		$class = $model["class"];
		$table = $model["table"];
		if(empty($table)) {
			return [self::Finding(TimezoneCheck::ERROR, "`".$class."` has no `\$dbTable`.", ["model" => $class])];
		}
		$at = ["model" => $class, "table" => $table];
		if(!$this->schema->HasTable($table)) {
			return [self::Finding(TimezoneCheck::ERROR, "`".$class."` maps the table `".$table."`, which doesn't exist.", $at)];
		}

		$columns = [];
		foreach($this->schema->Columns($table) as $column) $columns[strtolower($column["name"])] = $column;
		$findings = [];

		$pk = (string)$model["pk"];
		$tablePk = array_values(array_map(fn($c) => $c["name"], array_filter($columns, fn($c) => $c["key"] === "PRI")));
		if(count($tablePk) != 1 || strcasecmp($tablePk[0], $pk) != 0) {
			$actual = empty($tablePk) ? "it has none" : "it's (".implode(", ", $tablePk).")";
			$findings[] = self::Finding(TimezoneCheck::WARNING,
				"`".$class."::\$dbPk` is `".$pk."`, but that's not `".$table."`'s primary key: ".$actual.".",
				$at + ["field" => $pk]);
		}

		$listed = [];
		foreach($model["values"] as $field => $type) {
			$listed[strtolower($field)] = true;
			if(in_array(strtolower($field), self::TIMESTAMPS, true)) continue;
			$where = $at + ["field" => $field];
			$column = $columns[strtolower($field)] ?? null;
			if($column === null) {
				$findings[] = self::Finding(TimezoneCheck::ERROR,
					"`".$class."::\$dbValues[".$field."]` has no column in `".$table."`: writes fail.", $where);
				continue;
			}
			$finding = self::CheckType($class, $field, $type, $column, strcasecmp($field, $pk) == 0);
			if($finding !== null) $findings[] = self::Finding($finding[0], $finding[1], $where);
		}

		foreach($columns as $key => $column) {
			if(isset($listed[$key]) || in_array($key, self::TIMESTAMPS, true)) continue;
			$findings[] = self::Finding(TimezoneCheck::INFO,
				"`".$table."`.`".$column["name"]."` isn't in `".$class."::\$dbValues`: the model doesn't read or write it.",
				$at + ["field" => $column["name"]]);
		}

		foreach(self::TIMESTAMPS as $timestamp) {
			if(isset($columns[$timestamp])) continue;
			$findings[] = self::Finding(TimezoneCheck::ERROR,
				"`".$table."` has no `".$timestamp."` column: every SELECT built from `".$class."` fails.",
				$at + ["field" => $timestamp]);
		}
		return $findings;
	}

	/**
	* Checks a table's `created_at` and `updated_at` against the contract: both `DEFAULT CURRENT_TIMESTAMP`,
	* and only `updated_at` with `ON UPDATE CURRENT_TIMESTAMP`. One finding per column that breaks it, whose `fix`
	* is an `ALTER TABLE … MODIFY …` keeping the column's type and nullability. A missing column isn't reported
	* here (CheckModel() does).
	* @param 	string 	$table 		table name
	* @return 	array 	findings
	* @throws 	MagratheaDBException 	unknown table
	*/
	public function CheckTimestamps(string $table): array {
		$findings = [];
		foreach($this->schema->Columns($table) as $column) {
			$name = strtolower($column["name"]);
			if(!in_array($name, self::TIMESTAMPS, true)) continue;
			$level = null;
			$problems = [];
			if(!self::IsCurrentTimestamp($column["default"])) {
				if($column["nullable"]) {
					$level = TimezoneCheck::WARNING;
					$problems[] = "no `DEFAULT CURRENT_TIMESTAMP` (it's `NULL` after an insert)";
				} else {
					$level = TimezoneCheck::ERROR;
					$problems[] = "no `DEFAULT CURRENT_TIMESTAMP` (".($column["default"] === null
						? "inserts fail"
						: "inserts store `".$column["default"]."`").")";
				}
			}
			$onUpdate = (bool)preg_match('/on update current_timestamp/i', (string)$column["extra"]);
			if($name == "created_at" && $onUpdate) {
				$level = TimezoneCheck::ERROR;
				$problems[] = "`ON UPDATE CURRENT_TIMESTAMP` (it changes on every update)";
			}
			if($name == "updated_at" && !$onUpdate) {
				$level = TimezoneCheck::ERROR;
				$problems[] = "no `ON UPDATE CURRENT_TIMESTAMP` (updates don't change it)";
			}
			if(empty($problems)) continue;
			$findings[] = self::Finding($level,
				"`".$table."`.`".$column["name"]."` has ".implode(" and ", $problems).".",
				["table" => $table, "field" => $column["name"], "fix" => self::TimestampFix($table, $column)]);
		}
		return $findings;
	}

	/**
	* Every model found (FindModels()): CheckModel(), and CheckTimestamps() on its table;
	* plus an INFO for each table no model maps (the framework's `_magrathea_*` tables aside).
	* @return 	array 	findings
	* @throws 	MagratheaDBException 	when the database can't be read
	*/
	public function CheckAll(): array {
		$found = $this->FindModels();
		$findings = $found["findings"];
		$mapped = [];
		foreach($found["models"] as $model) {
			$findings = array_merge($findings, $this->CheckModel($model["class"]));
			$table = (string)$model["table"];
			if($table === "" || isset($mapped[strtolower($table)])) continue;
			// a missing table: CheckModel() reported it
			if(!$this->schema->HasTable($table)) continue;
			$mapped[strtolower($table)] = true;
			$findings = array_merge($findings, $this->CheckTimestamps($table));
		}
		foreach($this->schema->Tables(false) as $table) {
			if(isset($mapped[strtolower($table["name"])])) continue;
			$findings[] = self::Finding(TimezoneCheck::INFO,
				"No model maps the ".($table["type"] == "VIEW" ? "view" : "table")." `".$table["name"]."`.",
				["table" => $table["name"]]);
		}
		return $findings;
	}

	/**
	* A model's table settings, from a new instance (no id: nothing is loaded)
	* @return 	array 	["class", "table", "pk", "values"]
	* @throws 	\Throwable 	the class doesn't load, isn't a concrete model, or its constructor throws
	*/
	private function Describe(string $class): array {
		if(!class_exists($class)) throw new MagratheaModelException("class not found");
		$reflection = new \ReflectionClass($class);
		if(!$reflection->isSubclassOf(MagratheaModel::class)) throw new MagratheaModelException("it isn't a MagratheaModel");
		if($reflection->isAbstract()) throw new MagratheaModelException("it's abstract");
		$model = new $class();
		return [
			"class" => $reflection->getName(),
			"table" => $model->GetDbTable(),
			"pk" => $model->GetPkName(),
			"values" => (array)$model->GetDbValues(),
		];
	}

	/**
	* A field's type against its column (`Schema::MagratheaType()`)
	* @return 	array|null 		[level, message], or null when they agree
	*/
	private static function CheckType(string $class, string $field, $type, array $column, bool $isPk): ?array {
		$name = "`".$class."::\$dbValues[".$field."]`";
		if(!in_array($type, MagratheaModel::TYPES, true)) {
			return [TimezoneCheck::ERROR, $name." has the unknown type [".(is_scalar($type) ? $type : gettype($type))."]: use one of ".implode(", ", MagratheaModel::TYPES).". Writes and strict loads of it throw."];
		}
		$suggested = Schema::MagratheaType($column["type"]);
		if($suggested === null || $suggested === $type) return null;
		if(in_array($type, self::TEXT_TYPES, true) && in_array($suggested, self::TEXT_TYPES, true)) {
			// they bind and load the same: only a uuid PK behaves differently (Insert() generates and sends it)
			if(!($isPk && $suggested == "uuid")) return null;
			return [TimezoneCheck::WARNING, $name." is `".$type."`, but it's the PK and its column is `".$column["type"]."`: declare it `uuid`, or `Insert()` sends no PK."];
		}
		if($type == "float" && $suggested == "decimal") {
			return [TimezoneCheck::WARNING, $name." is `float` on a `".$column["type"]."` column: it rounds under strict types. Use `decimal`."];
		}
		return [TimezoneCheck::WARNING, $name." is `".$type."`, but its column is `".$column["type"]."`: use `".$suggested."`."];
	}

	/**
	* Is a column default MySQL's or MariaDB's current timestamp (`CURRENT_TIMESTAMP`, `current_timestamp()`, `CURRENT_TIMESTAMP(3)`)?
	*/
	private static function IsCurrentTimestamp($default): bool {
		return $default !== null && (bool)preg_match('/^(current_timestamp|now)(\(\d*\))?$/i', trim((string)$default));
	}

	/**
	* `ALTER TABLE … MODIFY …` bringing a timestamp column to the contract, with its type and nullability
	*/
	private static function TimestampFix(string $table, array $column): string {
		// a datetime(3) needs CURRENT_TIMESTAMP(3)
		$now = "CURRENT_TIMESTAMP".(preg_match('/\((\d+)\)/', $column["type"], $match) ? "(".$match[1].")" : "");
		$sql = "ALTER TABLE ".self::Quote($table)." MODIFY ".self::Quote($column["name"])." ".$column["type"].
			($column["nullable"] ? " NULL" : " NOT NULL")." DEFAULT ".$now;
		if(strtolower($column["name"]) == "updated_at") $sql .= " ON UPDATE ".$now;
		return $sql.";";
	}

	private static function Quote(string $name): string {
		return "`".str_replace("`", "``", $name)."`";
	}

	/**
	* The code folders that exist, once each
	*/
	private function CodeFolders(): array {
		$folders = [];
		foreach(MagratheaPHP::Instance()->codeFolder as $folder) {
			$real = realpath($folder);
			if($real === false || !is_dir($real)) continue;
			$folders[$real] ??= $folder;
		}
		return array_values($folders);
	}

	/**
	* Full names of the classes a file declares that could be models: not abstract, and with `extends`
	* @param 	string 	$file 	PHP file
	* @return 	array 	class names
	*/
	private static function DeclaredClasses(string $file): array {
		$code = @file_get_contents($file);
		if($code === false) return [];
		$tokens = array_values(array_filter(token_get_all($code),
			fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$namespace = "";
		$classes = [];
		$count = count($tokens);
		for($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if(!is_array($token)) continue;
			if($token[0] === T_NAMESPACE) {
				$namespace = "";
				for($i++; $i < $count && is_array($tokens[$i]); $i++) {
					if(in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED], true)) $namespace = $tokens[$i][1];
				}
				continue;
			}
			if($token[0] !== T_CLASS) continue;
			// Foo::class, new class
			$previous = $tokens[$i - 1] ?? null;
			if(is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) continue;
			$abstract = false;
			for($j = $i - 1; $j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_ABSTRACT, T_FINAL, T_READONLY], true); $j--) {
				if($tokens[$j][0] === T_ABSTRACT) $abstract = true;
			}
			$name = $tokens[$i + 1] ?? null;
			if(!is_array($name) || $name[0] !== T_STRING) continue;
			$extends = false;
			for($j = $i + 2; $j < $count && $tokens[$j] !== "{"; $j++) {
				if(is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) $extends = true;
			}
			if($abstract || !$extends) continue;
			$classes[] = ($namespace === "" ? "" : $namespace."\\").$name[1];
		}
		return $classes;
	}

	/**
	* A finding of this check's group
	*/
	private static function Finding(string $level, string $message, array $extra = []): array {
		return ["level" => $level, "message" => $message, "group" => self::GROUP] + $extra;
	}

}
