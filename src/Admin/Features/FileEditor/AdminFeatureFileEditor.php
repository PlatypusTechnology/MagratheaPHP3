<?php

namespace Magrathea\Admin\Features\FileEditor;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\iAdminFeature;

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
class AdminFeatureFileEditor extends AdminFeature implements iAdminFeature { 

	public string $featureName = "File Editor";
	public string $featureId = "AdminFeatureFileEditor";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function HasEditPermission($user): bool {
		$loggedUser = AdminManager::Instance()->GetLoggedUser();
		return !empty($loggedUser->id);
	}

	public function View() {
		include(__DIR__."/editor.php");
	}

}
