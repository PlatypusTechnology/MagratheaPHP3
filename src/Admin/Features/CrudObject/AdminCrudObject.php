<?php

namespace Magrathea\Admin\Features\CrudObject;

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\iAdminFeature;
use Magrathea\Admin\ModelFields;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\MagratheaModelControl;

#######################################################################################
####
####    MAGRATHEA PHP Admin Object
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin created: 2023-12 by Paulo Martins
####
#######################################################################################

/**
 * Class for Admin Object
 */
class AdminCrudObject extends AdminFeature implements iAdminFeature {

	public string $featureName;
	public string $featureId;
	public MagratheaModel $object;
	public MagratheaModelControl $control;
	public string $objectName;
	public string $fullObjectName; 

	public function __construct() {
		parent::__construct(); // runs Initialize() once
		$this->SetClassPath(__DIR__);
		$this->AddJs(__DIR__."/scripts.js");
	}


	public function Initialize() {}

	/**
	 * Sets the object for crud
	 * @param MagratheaModel 							$object		Magrathea Model for admin
	 * @param MagratheaModelControl|null 	$control	its control; if null, "<model full class name>Control"
	 * @return AdminCrudObject					itself
	 */
	public function SetObject(MagratheaModel $object, ?MagratheaModelControl $control = null): AdminCrudObject {
		$this->object = $object;
		$this->fullObjectName = get_class($object);
		$objName = $object->ModelName();
		$this->objectName = $objName;
		if(empty($this->featureName)) $this->featureName = $this->objectName." CRUD";
		if(empty($this->featureId)) $this->featureId = "CRUD".$this->objectName;
		if($control === null) {
			$controlName = $this->fullObjectName."Control";
			if(!class_exists($controlName)) {
				throw new MagratheaModelException(
					"Control [".$controlName."] not found for ".$this->fullObjectName.": pass the control to SetObject()"
				);
			}
			$control = new $controlName();
		}
		$this->SetControl($control);
		return $this;
	}

	/**
	 * Sets the control for crud
	 * @param MagratheaModelControl $c		Magrathea Model Control for admin
	 * @return AdminCrudObject						itself
	 */
	public function SetControl(MagratheaModelControl $c): AdminCrudObject {
		$this->control = $c;
		return $this;
	}

	public function ReturnError($err) {
		echo json_encode(["success" => false, "error" => $err]);
		die;
	}
	public function ReturnException($ex, $data=null) {
		if(empty($data)) $data = $ex;
		echo json_encode([
			"success" => false,
			"data" => $data,
			"error" => $ex->getMessage(),
		]);
		die;
	}
	/**
	 * Answers 405 (JSON error) and stops, unless it's a POST: admin CSRF tokens are checked on POST only,
	 * so a GET link (an `<img src>`) can't save or delete
	 */
	protected function RefuseIfNotPost(): void {
		if(@$_SERVER["REQUEST_METHOD"] === "POST") return;
		http_response_code(405);
		$this->ReturnError("this action requires a POST request");
	}
	public function ShowObjectNotFound($id) {
		// the id comes from the query string
		$errorMsg = "Can't find a ".$this->objectName." with ID [".htmlspecialchars(is_scalar($id) ? (string)$id : "")."]";
		return AdminElements::Instance()->ErrorCard($errorMsg);
	}

	/**
	 * Gets the columns for display the list
	 * @return array
	 */
	public function Columns(): array {
		$properties = $this->object->GetProperties();
		unset($properties["created_at"]);
		unset($properties["updated_at"]);
		return array_keys($properties);
	}
	/**
	 * Gets the column value for editing an object row. The id is a JSON string in the onclick, so string
	 * and uuid PKs work and can't break out of it.
	 * @return array
	 */
	public function GetEditColumn(): array {
		return [
			"title" => "...",
			"key" => function ($item) {
				$id = json_encode((string)$item->GetID(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
				$action = "event.preventDefault(); editCrudObject(".$id.")";
				return '<a href="#" onclick="'.htmlspecialchars($action, ENT_QUOTES).'">Edit</a>';
			}
		];
	}

	/**
	 * Gets the array of fields for a object form
	 * @param MagratheaModel $object		object for the form
	 * @return array
	 */
	public function Fields(MagratheaModel $object): array {
		$properties = $object->GetFields();
		unset($properties["created_at"]);
		unset($properties["updated_at"]);
		$fields = [];
		foreach($properties as $field => $type) {
			array_push($fields, $this->GetField($field, $type));
		}
		if(count($fields) % 2 != 0) {
			array_push($fields, ["type" => "empty"]);
		}
		if(!empty($object->GetID())) {
			array_push($fields,
				["type" => "empty", "size" => "col-6"],
				$this->GetDeleteButton(),
				$this->GetSaveButton()
			);
		} else {
			array_push($fields,
				["type" => "empty", "size" => "col-9"],
				$this->GetSaveButton()
			);
		}
		return $fields;
	}
	/**
	 * Get a field related to type (`ModelFields::Field()`): "pk" is disabled; a relation ("\\Class") is a select of
	 * the related objects, or a text input for the id when they're more than `ModelFields::RELATION_OPTIONS_LIMIT`
	 * @param string $key 			Object property
	 * @param string $type			Object type (`GetFields()`: a `$dbValues` type, "pk", or a related model class)
	 * @return array
	 */
	public function GetField(string $key, string $type): array {
		$modelFields = new ModelFields();
		if(str_starts_with($type, "\\")) {
			return $modelFields->Field($key, "int", ltrim($type, "\\"));
		}
		if($type === "pk") {
			$field = $modelFields->Field($key, "pk");
			$field["type"] = "disabled";
			return $field;
		}
		return $modelFields->Field($key, $type);
	}
	/**
	 * Gets the button for saving an object
	 * @return array
	 */
	public function GetSaveButton(): array {
		return 	[
			"name" => "Save",
			"type" => "button",
			"class" => ["w-100", "btn-success"],
			"size" => "col-3",
			"action" => "saveCrudObject(this)",
		];	
	}
	/**
	 * Gets the button for deleting an object
	 * @return array
	 */
	public function GetDeleteButton(): array {
		return 	[
			"name" => "Delete",
			"type" => "button",
			"class" => ["w-100", "btn-danger"],
			"size" => "col-3",
			"action" => "deleteCrudObject(this)",
		];	
	}

	/**
	 * Gets the title of the page
	 * @return string 
	 */
	public function GetHeaderTitle(): string {
		return $this->objectName;
	}
	/**
	 * Prints a button for adding new object
	 */
	public function PrintButtonNew() {
		AdminElements::Instance()->Button(
			"New ".$this->objectName, "newCrudObject()", ["btn-success"]);
	}

	/**
	 * Display the list of objects
	 */
	public function List() {
		$columns = $this->Columns();
		array_push($columns, $this->GetEditColumn());
		$list = $this->control->GetListPage();
		include(__DIR__."/list.php");
	}

	/**
	 * Display a form for the object
	 */
	public function Form() {
		$id = @$_GET["id"];
		try {
			$object = new $this->fullObjectName($id);
			if(!$object) {
				return $this->ShowObjectNotFound($id);
			}
			$formData = $this->Fields($object);
		} catch(MagratheaModelException $ex) {
			return $this->ShowObjectNotFound($id);
		} catch(\Exception $ex) {
			return $this->ReturnException($ex, $_POST);
		}
		include(__DIR__."/form.php");
	}

	/**
	 * Saves the object
	 */
	/**
	 * Saves the object (POST): only the fields of `Fields()` are assigned (`ModelFields::Values()`), the object is
	 * identified by its PK (posted under the PK's name)
	 */
	public function Save() {
		$this->RefuseIfNotPost();
		$id = @$_POST[$this->object->GetPkName()];
		try {
			$o = new $this->fullObjectName(is_scalar($id) ? $id : null);
		} catch(MagratheaModelException $ex) {
			return $this->ReturnException($ex, $_POST);
		}
		$o = $o->Assign((new ModelFields())->Values($o, $_POST, $this->Fields($o)));
		try {
			$success = $o->Save();
		} catch(\Magrathea\Exceptions\MagratheaDBException $ex) {
			return $this->ReturnException($ex, $_POST);
		}
		echo json_encode([
			"success" => true,
			"data" => $o,
			"type" => ($id ? "update" : "insert"),
		]);
	}

	/**
	 * Deletes the object
	 */
	public function Delete() {
		$this->RefuseIfNotPost();
		$id = @$_POST[$this->object->GetPkName()];
		if(!is_scalar($id) || (string)$id === "") return $this->ReturnError("Which object? The id is empty");
		try {
			$o = new $this->fullObjectName($id);
			$success = $o->Delete();
		} catch(MagratheaModelException | \Magrathea\Exceptions\MagratheaDBException $ex) {
			return $this->ReturnException($ex);
		}
		echo json_encode([
			"success" => $success,
			"data" => $o,
			"type" => "delete",
		]);
	}

}

