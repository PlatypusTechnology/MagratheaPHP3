<?php

namespace Magrathea\Admin;

use Magrathea\Singleton;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 2.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin created: 2022-12 by Paulo Martins
####
#######################################################################################

/**
 * The admin's own database tables (`database.sql`).
 * Backups moved to `Magrathea\DB\Dump`.
 */
class AdminDatabase extends Singleton {

	/**
	 * Get sql file location
	 * @return 	string 			String with full path for admin's sql file
	 */
	public function GetSQLFile() {
		return realpath(__DIR__."/database.sql");
	}

	/**
	 * Get sql file content
	 * @return	string			SQL for create admin's database
	 */
	public function GetSQLFileContents() {
		$file = $this->GetSQLFile();
		return file_get_contents($file);
	}

}
