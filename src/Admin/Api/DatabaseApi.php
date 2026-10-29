<?php

namespace Magrathea\Admin\Api;

use Magrathea\DB\Dump;

class DatabaseApi extends \Magrathea\MagratheaApiControl {

	/**
	 * The Dump the actions use (tests override it)
	 */
	protected function GetDump(): Dump {
		return new Dump();
	}

	/**
	 * Creates a backup file in the backups folder (see `Dump::Export()`)
	 * @param 	array 	$params		["filename"]
	 * @return 	array		["success" => true, "file" => file name, "size" => bytes, "driver"]
	 */
	public function CreateBackupFile($params) {
		$rs = $this->GetDump()->Export(@$params["filename"]);
		return [
			"success" => true,
			"file" => $rs["file"],
			"size" => $rs["size"],
			"driver" => $rs["driver"],
		];
	}
}

