<?php

namespace Magrathea\Admin\Features\UserLogs;

use Admin;
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
class AdminLog extends MagratheaModel { 

	public $id;
	public $user_id, $action, $victim, $info;

	public function MagratheaStart() {
		$this->dbTable = "_magrathea_logs";
		$this->dbPk = "id";
		$this->dbValues = [
			"id" => "int",
			"user_id" => "int",
			"action" => "string",
			"victim" => "string",
			"info" => "text",
		];
		$this->dbAlias["data"] = "info";
	}

	public function Simple() {
		unset($this->info);
		return $this;
	}

}

