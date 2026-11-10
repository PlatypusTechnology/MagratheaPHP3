<?php

use Magrathea\Tests\TestsHelper;

class adminInstallTest extends \PHPUnit\Framework\TestCase {

	function testInstallSchemaFollowsTheTimestampContract() {
		TestsHelper::Print("testing database.sql gives every table DATETIME created_at/updated_at with the database's defaults...");
		$sql = file_get_contents(__DIR__."/../../Admin/database.sql");
		preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\n\);/s', $sql, $tables, PREG_SET_ORDER);
		$this->assertCount(4, $tables);
		foreach($tables as [, $table, $body]) {
			$this->assertStringContainsString("`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", $body, $table);
			$this->assertStringContainsString("`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", $body, $table);
			// a bare TIMESTAMP column can pick up ON UPDATE CURRENT_TIMESTAMP (explicit_defaults_for_timestamp=OFF)
			$this->assertDoesNotMatchRegularExpression('/\bTIMESTAMP\b/i', str_replace("CURRENT_TIMESTAMP", "", $body), $table);
		}
		$this->assertStringContainsString("`last_login` DATETIME NULL,", $sql);
	}

}
