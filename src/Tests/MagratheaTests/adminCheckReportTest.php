<?php

use Magrathea\Admin\CheckReport;
use Magrathea\Tests\TestsHelper;
use Magrathea\TimezoneCheck;

class adminCheckReportTest extends \PHPUnit\Framework\TestCase {

	private static function Finding(string $group, string $level, string $message, array $extra = []): array {
		return ["level" => $level, "message" => $message, "group" => $group] + $extra;
	}
	private static function Section(array $report, string $key): ?array {
		foreach($report["sections"] as $section) {
			if($section["key"] === $key) return $section;
		}
		return null;
	}

	function testSectionsInOrder() {
		TestsHelper::Print("testing CheckReport::Group() lists every section, in order, even without findings...");
		$report = CheckReport::Group([]);
		$this->assertSame(["config", "folders", "database", "timezone", "timestamps", "models"], array_column($report["sections"], "key"));
		$this->assertSame(["Configuration", "Folders", "Database", "Timezone", "Timestamps", "Models"], array_column($report["sections"], "title"));
		foreach($report["sections"] as $section) {
			$this->assertSame([], $section["findings"], $section["key"]);
			$this->assertNull($section["status"], $section["key"]);
			$this->assertSame("", $section["fixes"], $section["key"]);
		}
		$this->assertSame(TimezoneCheck::OK, $report["status"]);
		$this->assertSame([TimezoneCheck::OK => 0, TimezoneCheck::INFO => 0, TimezoneCheck::WARNING => 0, TimezoneCheck::ERROR => 0], $report["counts"]);
	}

	function testFindingsGoToTheirGroup() {
		TestsHelper::Print("testing CheckReport::Group() puts each finding in its group's section, keeping their order...");
		$findings = [
			self::Finding("config", TimezoneCheck::OK, "PHP 8.3."),
			self::Finding("folders", TimezoneCheck::WARNING, "Cache: `/tmp/c` isn't writable."),
			self::Finding("config", TimezoneCheck::INFO, "`proc_open()` is disabled."),
			self::Finding("database", TimezoneCheck::OK, "Connected to the database."),
			self::Finding("timezone", TimezoneCheck::ERROR, "The clocks disagree."),
		];
		$report = CheckReport::Group($findings);
		$this->assertSame([$findings[0], $findings[2]], self::Section($report, "config")["findings"]);
		$this->assertSame(TimezoneCheck::INFO, self::Section($report, "config")["status"]);
		$this->assertSame([$findings[1]], self::Section($report, "folders")["findings"]);
		$this->assertSame(TimezoneCheck::WARNING, self::Section($report, "folders")["status"]);
		$this->assertSame(TimezoneCheck::OK, self::Section($report, "database")["status"]);
		$this->assertSame(TimezoneCheck::ERROR, self::Section($report, "timezone")["status"]);
		$this->assertNull(self::Section($report, "models")["status"]);
		$this->assertNull(self::Section($report, CheckReport::OTHER));

		$this->assertSame(TimezoneCheck::ERROR, $report["status"]);
		$this->assertSame([TimezoneCheck::OK => 2, TimezoneCheck::INFO => 1, TimezoneCheck::WARNING => 1, TimezoneCheck::ERROR => 1], $report["counts"]);
	}

	function testSchemaSplitsTimestampsFromModels() {
		TestsHelper::Print("testing CheckReport::Group() splits schema findings: with a fix (timestamps), without (models)...");
		$fix1 = "ALTER TABLE `a` MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;";
		$fix2 = "ALTER TABLE `b` MODIFY `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;";
		$findings = [
			self::Finding("schema", TimezoneCheck::ERROR, "`App\\A::\$dbValues[x]` has no column in `a`: writes fail.", ["model" => "App\\A", "table" => "a", "field" => "x"]),
			self::Finding("schema", TimezoneCheck::WARNING, "`a`.`created_at` has no `DEFAULT CURRENT_TIMESTAMP`.", ["table" => "a", "field" => "created_at", "fix" => $fix1."\n"]),
			self::Finding("schema", TimezoneCheck::INFO, "No model maps the table `c`.", ["table" => "c"]),
			self::Finding("schema", TimezoneCheck::ERROR, "`b`.`updated_at` has no `ON UPDATE CURRENT_TIMESTAMP`.", ["table" => "b", "field" => "updated_at", "fix" => $fix2]),
			self::Finding("schema", TimezoneCheck::ERROR, "`b` has no `created_at` column.", ["table" => "b", "field" => "created_at", "fix" => ""]),
		];
		$report = CheckReport::Group($findings);
		$timestamps = self::Section($report, "timestamps");
		$this->assertSame([$findings[1], $findings[3]], $timestamps["findings"]);
		$this->assertSame(TimezoneCheck::ERROR, $timestamps["status"]);
		$this->assertSame($fix1."\n".$fix2, $timestamps["fixes"]);

		$models = self::Section($report, "models");
		$this->assertSame([$findings[0], $findings[2], $findings[4]], $models["findings"]);
		$this->assertSame(TimezoneCheck::ERROR, $models["status"]);
		$this->assertSame("", $models["fixes"]);
	}

	function testUnknownGroupsGoToOther() {
		TestsHelper::Print("testing CheckReport::Group() adds an Other section, last, for findings without a known group...");
		$findings = [
			["level" => TimezoneCheck::WARNING, "message" => "no group"],
			self::Finding("bogus", "bogus-level", "unknown group and level"),
		];
		$report = CheckReport::Group($findings);
		$other = end($report["sections"]);
		$this->assertSame(CheckReport::OTHER, $other["key"]);
		$this->assertSame("Other", $other["title"]);
		$this->assertSame($findings, $other["findings"]);
		$this->assertSame(TimezoneCheck::WARNING, $other["status"]);
		// an unknown level isn't counted
		$this->assertSame(1, array_sum($report["counts"]));
	}

	function testChecksReplacesStructure() {
		TestsHelper::Print("testing the admin has a checks page and no structure page...");
		$pages = __DIR__."/../../Admin/views/pages/";
		$this->assertFileExists($pages."checks.php");
		$this->assertFileDoesNotExist($pages."structure.php");
	}

}
