<?php

namespace Magrathea\Admin;

use Magrathea\Admin\Features\AppConfig\AppConfigControl;
use Magrathea\Admin\Features\UserLogs\AdminLog;
use Magrathea\Admin\Features\UserLogs\AdminLogControl;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\DB\Database;
use Magrathea\MagratheaPHP;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin created: 2022-12 by Paulo Martins
####
#######################################################################################

/**
 * Class for installing Magrathea's Admin
 */
class Install {

	public function InstallDatabase() {
		$file = realpath(dirname(__FILE__)."/database.sql");
		$magdb = Database::Instance();
		return $magdb->ImportFile($file, false);
	}

	public function CreateFirstUser($username, $password): AdminUser {
		$user = new AdminUser();
		$user->email = $username;
		$user->SetPassword($password);
		$user->role_id = 1;
		$user->Insert();
		$this->LogUserCreated($user);
		$this->SetBasicConfigs();
		return $user;
	}

	public function LogUserCreated($user): AdminLog {
		$logControl = new AdminLogControl();
		return $logControl->Log(
			$user->id, 
			"first user created",
			null,
			json_encode(["email" => $user->email])
		);
	}

	public function SetBasicConfigs(): void {
		$configControl = new AppConfigControl();
		$configControl->SaveSystem("admin_install_date", \Magrathea\now(), false);
		$configControl->Save("app_name", "", false);
	}

	/**
	 * Checks if AdminConfig exists - therefore Admin is installed
	 * @return 		bool 		true if is installed
	 */
	public function CheckIfDBInstalled(): bool {
		MagratheaPHP::Instance()->Connect();
		$query = "SHOW TABLES LIKE '_magrathea_config'";
		$rs = Database::Instance()->QueryRow($query);
		return (count($rs) > 0);
	}

}
