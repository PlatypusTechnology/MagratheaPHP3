<?php

namespace Magrathea\Admin;

use Magrathea\DB\Database;
use Magrathea\MagratheaModel;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    ModelFields created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* A model's fields as admin form elements (`AdminForm::Build()`), and posted form data back as the values to assign.
* Shared by the Objects area (`ObjectsExplorer`) and `AdminCrudObject`.
*/
class ModelFields {

	/** a relation is a select when the related table has this many rows or fewer; otherwise, a text input for the id */
	const RELATION_OPTIONS_LIMIT = 100;
	/** owned by the database: never in a form, never assigned */
	const TIMESTAMPS = ["created_at", "updated_at"];
	/** PK types the database or the model generates (an auto-increment int, `Uuid::V7()`) */
	const GENERATED_PK_TYPES = ["int", "uuid"];

	/** @var Database|\Magrathea\DB\DatabaseSimulate|null */
	private $db;

	/**
	* @param 	Database|null 	$db 	database for the relations' options (default: `Database::Instance()`, when one is needed)
	*/
	public function __construct(?Database $db = null) {
		$this->db = $db;
	}

	private function Db() {
		return $this->db ?? Database::Instance();
	}

	/**
	* A model's relations (`$relations["external"]`), as [field => related model class]
	* @param 	MagratheaModel 	$object 	model
	* @return 	array 	[field => class name, without the leading backslash]
	*/
	public static function Relations(MagratheaModel $object): array {
		$relations = [];
		foreach($object->GetFields() as $field => $type) {
			if(is_string($type) && str_starts_with($type, "\\")) $relations[$field] = ltrim($type, "\\");
		}
		return $relations;
	}

	/**
	* The form elements for a model: the primary key first, then each `$dbValues` field in order, without the
	* timestamps. A generated PK (int, uuid) is always disabled; any other PK is a text input on a new object, disabled after.
	* @param 	MagratheaModel 	$object 	model
	* @param 	bool 			$isNew 		is it a new object (no PK yet)?
	* @return 	array 	AdminForm elements (see `Field()`)
	*/
	public function Fields(MagratheaModel $object, bool $isNew): array {
		$pk = $object->GetPkName();
		$values = (array)$object->GetDbValues();
		$relations = self::Relations($object);
		$pkType = $values[$pk] ?? "int";
		$pkField = $this->Field($pk, (string)$pkType);
		$pkField["name"] = self::Label($pk, $pkType.", primary key");
		$pkField["type"] = ($isNew && !in_array($pkType, self::GENERATED_PK_TYPES, true)) ? "text" : "disabled";
		unset($pkField["attributes"]);
		if($pkField["type"] === "disabled" && $isNew) $pkField["placeholder"] = "generated";
		$fields = [$pkField];
		foreach($values as $key => $type) {
			if($key === $pk || in_array($key, self::TIMESTAMPS, true)) continue;
			$fields[] = $this->Field($key, (string)$type, $relations[$key] ?? null);
		}
		return $fields;
	}

	/**
	* The form element for a field, from its `$dbValues` type: boolean is a switch, text a textarea, int/float/decimal
	* a number input, date a date input, datetime a text input (`YYYY-MM-DD HH:MM:SS`), anything else a text input.
	* A relation is a select of the related objects (`RelationOptions()`), or a text input for the id when there are too many.
	* @param 	string 		$key 		field name
	* @param 	string 		$type 		its `$dbValues` type
	* @param 	string|null $relation 	related model class, for a relation field
	* @return 	array 	["name" (escaped label), "key", "type", "size", and "placeholder"/"attributes" when needed]
	*/
	public function Field(string $key, string $type, ?string $relation = null): array {
		$field = [
			"name" => self::Label($key, $type),
			"key" => $key,
			"type" => "text",
			"size" => "col-sm-12 col-md-6",
		];
		if($relation !== null) {
			$field["name"] = self::Label($key, \Magrathea\getClassNameOfClass($relation));
			$options = $this->RelationOptions($relation);
			if($options === null) {
				$field["placeholder"] = "id of a ".\Magrathea\getClassNameOfClass($relation);
			} else {
				$field["type"] = ["" => "(none)"] + $options;
			}
			return $field;
		}
		switch($type) {
			case "boolean":
				$field["type"] = "switch";
				break;
			case "text":
				$field["type"] = "textarea";
				$field["size"] = "col-12";
				break;
			case "int":
				$field["type"] = "number";
				$field["attributes"] = ["step" => "1"];
				break;
			case "float":
			case "decimal":
				$field["type"] = "number";
				$field["attributes"] = ["step" => "any"];
				break;
			case "date":
				$field["type"] = "date";
				break;
			case "datetime":
				$field["placeholder"] = "YYYY-MM-DD HH:MM:SS";
				break;
		}
		return $field;
	}

	/**
	* The objects of a related model, for a select: up to RELATION_OPTIONS_LIMIT rows, ordered by PK.
	* @param 	string 		$class 		related model class
	* @return 	array|null 	[id => `Ref()`], or null when the table has more rows than that, or can't be read
	*/
	public function RelationOptions(string $class): ?array {
		try {
			if(!class_exists($class) || !is_subclass_of($class, MagratheaModel::class)) return null;
			$model = new $class();
			$sql = "SELECT * FROM ".self::Quote($model->GetDbTable()).
				" ORDER BY ".self::Quote($model->GetPkName())." LIMIT ".(self::RELATION_OPTIONS_LIMIT + 1);
			$rows = $this->Db()->QueryAll($sql);
			if(!is_array($rows) || count($rows) > self::RELATION_OPTIONS_LIMIT) return null;
			$options = [];
			foreach($rows as $row) {
				$item = new $class();
				$item->LoadObjectFromTableRow($row);
				$options[(string)$item->GetID()] = $item->Ref();
			}
			return $options;
		} catch(\Throwable $ex) {
			return null;
		}
	}

	/**
	* The values to assign from posted form data: only the model's `$dbValues` fields that are in the form and editable
	* (never the PK, the timestamps or a disabled element). A switch or checkbox missing from the post (unchecked) is
	* `false` on a boolean field; `""` is `null` on any field that isn't `string` or `text`. Non-scalar values are ignored.
	* @param 	MagratheaModel 	$object 	model
	* @param 	array 			$post 		posted data
	* @param 	array|null 		$fields 	the form's elements (default: `Fields()`'s keys and types, without reading relations)
	* @return 	array 	[field => value], for `Assign()`
	*/
	public function Values(MagratheaModel $object, array $post, ?array $fields = null): array {
		$types = (array)$object->GetDbValues();
		$pk = $object->GetPkName();
		if($fields === null) {
			$fields = [];
			foreach($types as $key => $type) {
				$fields[] = ["key" => $key, "type" => $type === "boolean" ? "switch" : "text"];
			}
		}
		$data = [];
		foreach($fields as $field) {
			$key = $field["key"] ?? null;
			if(!is_string($key) || !array_key_exists($key, $types)) continue;
			if($key === $pk || in_array($key, self::TIMESTAMPS, true)) continue;
			$element = $field["type"] ?? null;
			if($element === "disabled") continue;
			$type = $types[$key];
			$value = $post[$key] ?? null;
			if($value !== null && !is_scalar($value)) continue;
			if($element === "switch" || $element === "checkbox") {
				$checked = $value !== null && $value !== "" && $value !== "0";
				$data[$key] = ($type === "boolean") ? $checked : ($checked ? $value : null);
				continue;
			}
			if(!array_key_exists($key, $post)) continue;
			if($value === "" && !in_array($type, ["string", "text"], true)) $value = null;
			$data[$key] = $value;
		}
		return $data;
	}

	/**
	* A field label: "name (type)", escaped (the form elements print labels raw)
	*/
	private static function Label(string $key, string $type): string {
		return htmlspecialchars($key, ENT_QUOTES).' <small class="text-muted">('.htmlspecialchars($type, ENT_QUOTES).')</small>';
	}

	private static function Quote(string $name): string {
		return "`".str_replace("`", "``", $name)."`";
	}

}
