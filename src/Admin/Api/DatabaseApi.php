<?php

namespace Magrathea\Admin\Api;

use Magrathea\Admin\AdminDatabase;

class DatabaseApi extends \Magrathea\MagratheaApiControl {

	/**
	 * Creates a backup file in the backups folder (see `AdminDatabase::DoBackup()`)
	 * @param 	array 	$params		["filename"]
	 * @return 	array		["success" => true, "file" => file name, "size" => bytes]
	 */
	public function CreateBackupFile($params) {
		$rs = AdminDatabase::Instance()->DoBackup(@$params["filename"]);
		return [
			"success" => true,
			"file" => $rs["file"],
			"size" => $rs["size"],
		];
	}
}

