<?php

namespace Magrathea\Admin\Features\AppConfig;

use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;

#######################################################################################
####
####    MAGRATHEA Admin Config PHP
####    v. 2.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin created: 2023-02 by Paulo Martins
####
#######################################################################################

/**
 * Class for installing Magrathea's Admin
 */
class AppConfig extends MagratheaModel { 

	public $id;
	public $name, $value, $is_system;

	public function Validate() {
		if($this->key == null || $this->key == "") {
			return ["valid" => false, "error" => "key should not be empty"];
		}
		return ["valid" => true];
	}

	public function Insert() {
		$valid = $this->Validate();
		if($valid["valid"] == false) throw new MagratheaModelException($valid["error"]);
		return parent::Insert();
	}
	public function Update() {
		$valid = $this->Validate();
		if($valid["valid"] == false) throw new MagratheaModelException($valid["error"]);
		return parent::Update();
	}

	public function MagratheaStart() {
		$this->dbTable = "_magrathea_config";
		$this->dbPk = "id";
		$this->dbValues = [
			"id" => "int",
			"name" => "string",
			"value" => "string",
			"is_system" => "boolean",
		];
		$this->dbAlias["key"] = "name";
	}

	public function GetKey() {
		return $this->name;
	}
	public function GetValue() {
		return $this->value;
	}

	public function __toString() {
		$str_n = $this->is_system ? "MAGRATHEACONFIG-system" : "MAGRATHEACONFIG";
		return "[".$str_n." (id: ".$this->id.")(key: ".$this->key.") = {".$this->value."}]";
	}

}

