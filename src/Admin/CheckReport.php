<?php

namespace Magrathea\Admin;

use Magrathea\ProjectCheck;
use Magrathea\TimezoneCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    CheckReport created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The admin's Database → Checks page, as arrays: `ProjectCheck::Run()`'s findings in sections, one per group,
* with the `schema` group split in two: the timestamps (the findings with a `fix`) and the models' drift.
* Read-only; the view only renders (`AdminElements::CheckReport()`).
*/
class CheckReport {

	/** sections, in the page's order: key => [group, title] */
	const SECTIONS = [
		"config" => [ProjectCheck::CONFIG, "Configuration"],
		"folders" => [ProjectCheck::FOLDERS, "Folders"],
		"database" => [ProjectCheck::DATABASE, "Database"],
		"timezone" => [ProjectCheck::TIMEZONE, "Timezone"],
		"timestamps" => [ProjectCheck::SCHEMA, "Timestamps"],
		"models" => [ProjectCheck::SCHEMA, "Models"],
	];
	/** findings without a known group */
	const OTHER = "other";

	/**
	* Runs `ProjectCheck::Run()` and groups its findings
	* @return 	array 	Group()'s result
	*/
	public static function Build(): array {
		return static::Group(ProjectCheck::Run());
	}

	/**
	* Groups findings into the page's sections, keeping their order inside each one
	* @param 	array 	$findings 	findings with a `group` (`ProjectCheck::Run()`'s)
	* @return 	array 	["status" => the most severe level (`TimezoneCheck::OK` without findings),
	* 					"counts" => [level => number of findings, for OK, INFO, WARNING and ERROR],
	* 					"sections" => rows with ["key" (SECTIONS' key, or OTHER), "title", "findings",
	* 					"status" (the most severe level; null when the section has no findings: it wasn't checked),
	* 					"fixes" (every `fix` of the section, one per line)]]. An OTHER section ("Other") is
	* 					added only when a finding has no known group
	*/
	public static function Group(array $findings): array {
		$sections = [];
		foreach(self::SECTIONS as $key => [$group, $title]) {
			$sections[$key] = ["key" => $key, "title" => $title, "findings" => []];
		}
		$other = [];
		$counts = array_fill_keys(DatabaseExplorer::LEVELS, 0);
		foreach($findings as $finding) {
			if(isset($counts[$finding["level"] ?? null])) $counts[$finding["level"]]++;
			$key = self::SectionOf($finding);
			if($key === null) $other[] = $finding;
			else $sections[$key]["findings"][] = $finding;
		}
		if(!empty($other)) $sections[self::OTHER] = ["key" => self::OTHER, "title" => "Other", "findings" => $other];

		foreach($sections as &$section) {
			$section["status"] = empty($section["findings"]) ? null : DatabaseExplorer::WorstLevel($section["findings"]);
			$fixes = array_filter(array_map(fn($f) => trim((string)($f["fix"] ?? "")), $section["findings"]), fn($fix) => $fix !== "");
			$section["fixes"] = implode("\n", $fixes);
		}
		unset($section);
		return [
			"status" => DatabaseExplorer::WorstLevel($findings),
			"counts" => $counts,
			"sections" => array_values($sections),
		];
	}

	/**
	* @param 	array 	$finding 	finding
	* @return 	string|null 	its section's key; null when its group isn't one of SECTIONS'
	*/
	private static function SectionOf(array $finding): ?string {
		$group = $finding["group"] ?? null;
		if($group === ProjectCheck::SCHEMA) {
			return empty($finding["fix"]) ? "models" : "timestamps";
		}
		foreach(self::SECTIONS as $key => [$sectionGroup, $title]) {
			if($sectionGroup === $group) return $key;
		}
		return null;
	}

}
