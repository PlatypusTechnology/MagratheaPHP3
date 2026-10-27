<?php

namespace Magrathea\Admin\Api;
use Magrathea\Admin\Features\AppConfig\AppConfigControl;
use Magrathea\Admin\CodeManager;
use Magrathea\Exceptions\MagratheaApiException;

use function Magrathea\p_r;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 2.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin Api created: 2023-04 by Paulo Martins
####
#######################################################################################
class AdminConfigApi extends \Magrathea\MagratheaApiControl {

	public function SaveConfig($params) {
		unset($params["magrathea_api"]);
		unset($params["magrathea_api_method"]);
		$configs = [];
		$control = new AppConfigControl();
		foreach($params as $key => $value) {
			$k = str_replace('-', '_', $key);
			$configs[$k] = $value;
			$control->Save($k, $value);
		}
		return CodeManager::Instance()->GetCodeCreationData();
	}

}
