<?php

use Magrathea\Tests\TestsHelper;

/**
 * 3.0 item 5: deprecated and dead methods are gone.
 */
class removedApisTest extends \PHPUnit\Framework\TestCase {

	function testRemovedMethods() {
		TestsHelper::Print("testing the deprecated and dead methods removed in 3.0 are gone...");
		$removed = [
			[\Magrathea\MagratheaModelControl::class, "ShowAll"],
			[\Magrathea\MagratheaPHP::class, "LoadVendor"],
			[\Magrathea\DB\Query::class, "Clean"],
			[\Magrathea\MagratheaApi::class, "Start"],
			[\Magrathea\MagratheaApiAuth::class, "ResponseUserPayload"],
			[\Magrathea\DB\Database::class, "CloseConnectionThanks"],
			[\Magrathea\DB\DatabaseSimulate::class, "CloseConnectionThanks"],
			[\Magrathea\MagratheaModel::class, "IncludeAllModels"],
			[\Magrathea\MagratheaModel::class, "GetNextID"],
			[\Magrathea\MagratheaModel::class, "GetAutoLoad"],
			[\Magrathea\Admin\Install::class, "Load"],
			[\Magrathea\Admin\Install::class, "getAdminFileEntrance"],
			[\Magrathea\Admin\Install::class, "CreateAdminEntranceFile"],
			[\Magrathea\Admin\Install::class, "CheckIfAdminFileExists"],
			[\Magrathea\Admin\Install::class, "GetAdminCode"],
		];
		foreach($removed as [$class, $method]) {
			$this->assertFalse(method_exists($class, $method), $class."::".$method."()");
		}
		$this->assertFalse(property_exists(\Magrathea\MagratheaModel::class, "autoLoad"));
		$this->assertFalse(property_exists(\Magrathea\Admin\Install::class, "adminFileName"));
		$this->assertFalse(property_exists(\Magrathea\Admin\Install::class, "appPath"));
		$this->assertTrue(method_exists(\Magrathea\MagratheaPHP::class, "StartDB"), "StartDB() stays until 4.0");
	}

	/**
	 * B9: `Authentication` couldn't encode on php-jwt 6/7; controllers' jwtEncode() replaces it.
	 */
	function testAuthenticationClassIsRemoved() {
		TestsHelper::Print("testing the Authentication class is gone...");
		$this->assertFalse(class_exists("\\Magrathea\\Authentication"));
		$this->assertTrue(method_exists(\Magrathea\MagratheaApiControl::class, "jwtEncode"));
	}

}
