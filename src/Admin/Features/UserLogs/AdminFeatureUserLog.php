<?php

namespace Magrathea\Admin\Features\UserLogs;

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\Features\User\AdminUser;
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
class AdminFeatureUserLog extends AdminFeature implements iAdminFeature { 

	public string $featureName = "UserLog";
	public string $featureId = "AdminFeatureUserLog";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function HasPermission($action=null): bool {
		return true;
	}

	public function Detail() {
		$id = @$_POST["id"];
		$log = new AdminLog($id);
		if(!$log->id) {
			AdminElements::Instance()->Alert("Log [".$id."] not found!", "danger");
		}
		$user = new AdminUser($log->user_id);
		include(__DIR__."/detail.php");
	}


}
