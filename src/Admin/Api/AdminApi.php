<?php

namespace Magrathea\Admin\Api;

use Magrathea\Debugger;
use Magrathea\Exceptions\MagratheaApiException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 2.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin Api created: 2023-04 by Paulo Martins
####
#######################################################################################
class AdminApi extends \Magrathea\MagratheaApi {

	public function __construct(){
		parent::__construct();
		Debugger::Instance()->SetType(Debugger::LOG);
	}

	/**
	 * Removes "Api" from the end of string
	 * as provided by chatGPT
	 * @param 	string  $name		api Name
	 * @return	string	new api Name
	 * 
	 */
	private function removeApiSuffix($name) {
		$suffix = 'Api';
		if (substr($name, -strlen($suffix)) === $suffix) {
			return substr($name, 0, -strlen($suffix));
		}
		return $name;
	}

	public function Call($api, $method="Default") {
		try {
			$controlName = '\Magrathea\Admin\Api\\'.$api."Api";
			$control = new $controlName();
	
			$params = array_merge($_GET, array_diff_key($_POST, $_GET));
			if(!method_exists($control, $method)) {
				return $this->ReturnError(500, "Function (".$method.") does not exists in class ".get_class($control));
			}
			$data = $control->$method($params);
			return $this->ReturnSuccess($data);

		} catch(MagratheaApiException $ex) {
			if($ex->killerError) return $this->ReturnApiException($ex);
			else return $this->ReturnFail($ex);
		} catch (\Exception $ex) {
			if($ex->getCode() == 0) {
				return $this->ReturnFail($ex);
			} else {
				return $this->ReturnError($ex->getCode(), $ex->getMessage(), $ex);
			}
		} catch (\Error $err) {
			return $this->ReturnInternalError($err);
		}
	}

}

