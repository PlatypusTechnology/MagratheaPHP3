<?php

namespace Magrathea\Admin\Features\User;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\iAdminFeature;

use function Magrathea\p_r;

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
class AdminFeatureUser extends AdminFeature implements iAdminFeature { 

	public string $featureName = "Users";
	public string $featureId = "AdminFeatureUser";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function HasEditPermission($user): bool {
		$loggedUser = AdminManager::Instance()->GetLoggedUser();
		if($loggedUser->id == $user->id) return true;
		return $loggedUser->PermissionCanEdit();
	}

	private function GetUser(): AdminUser {
		$id = @$_GET["id"];
		if(!$id && !empty(@$_POST["id"])) {
			$id = $_POST["id"];
		} else if(!$id) {
			return AdminManager::Instance()->GetLoggedUser();
		}
		return new AdminUser($id);
	}

	public function ChangePassword() {
		$user = $this->GetUser();
		if(!$this->HasEditPermission($user)) {
			AdminManager::Instance()->PermissionDenied();
			die;
		}
		if(@$_POST["new_password"]) {
			$control = new AdminUserControl();
			$saved = $control->SetNewPassword($user, $_POST["new_password"]);
		}
		include(__DIR__."/password-edit.php");
	}

}
