<?php

namespace Magrathea\Cli;

use Magrathea\TimezoneCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Text created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* The commands' text output (without `--json`): tables of rows and lists of findings
*/
class Text {

	/** levels, least severe first */
	const LEVELS = [TimezoneCheck::OK, TimezoneCheck::INFO, TimezoneCheck::WARNING, TimezoneCheck::ERROR];

	/**
	* Rows as aligned columns, under a header. `null` prints as `NULL`, booleans as `yes`/`no`, lists joined
	* with `, `; line breaks and tabs print as `\n`, `\r` and `\t`, so a row stays on one line
	* @param 	array 		$rows 		rows (arrays)
	* @param 	array|null 	$columns 	the keys to print, in order (default: the first row's keys)
	* @return 	string 		the table, or "(no rows)"
	*/
	public static function Table(array $rows, ?array $columns = null): string {
		if(empty($rows)) return "(no rows)";
		$columns ??= array_keys((array)reset($rows));
		$lines = [array_map("strval", $columns)];
		foreach($rows as $row) {
			$row = (array)$row;
			$lines[] = array_map(fn($column) => self::Cell($row[$column] ?? null), $columns);
		}
		$widths = [];
		foreach($lines as $line) {
			foreach($line as $i => $cell) $widths[$i] = max($widths[$i] ?? 0, self::Width($cell));
		}
		$last = count($columns) - 1;
		$text = [];
		foreach($lines as $n => $line) {
			$cells = [];
			foreach($line as $i => $cell) {
				$cells[] = $i === $last ? $cell : $cell.str_repeat(" ", $widths[$i] - self::Width($cell));
			}
			$text[] = rtrim(implode("  ", $cells));
			if($n === 0) $text[] = implode("  ", array_map(fn($width) => str_repeat("-", $width), $widths));
		}
		return implode("\n", $text);
	}

	/**
	* Findings, one per line (`LEVEL  [group] message`), each `fix` indented under its finding, then the count per level
	* @param 	array 	$findings 	findings (`TimezoneCheck`'s shape)
	* @return 	string
	*/
	public static function Findings(array $findings): string {
		if(empty($findings)) return "No findings.";
		$lines = [];
		foreach($findings as $finding) {
			$level = strtoupper((string)($finding["level"] ?? "?"));
			$group = isset($finding["group"]) ? "[".$finding["group"]."] " : "";
			$lines[] = str_pad($level, 8).$group.($finding["message"] ?? "");
			if(!empty($finding["fix"])) {
				foreach(explode("\n", (string)$finding["fix"]) as $fix) $lines[] = "        ".$fix;
			}
		}
		$counts = [];
		foreach(array_reverse(self::Counts($findings)) as $level => $count) {
			$plural = $count > 1 && in_array($level, [TimezoneCheck::WARNING, TimezoneCheck::ERROR], true);
			if($count > 0) $counts[] = $count." ".$level.($plural ? "s" : "");
		}
		$lines[] = "";
		$lines[] = implode(", ", $counts);
		return implode("\n", $lines);
	}

	/**
	* @param 	array 	$findings 	findings
	* @return 	array 	level => how many, every level (least severe first)
	*/
	public static function Counts(array $findings): array {
		$counts = array_fill_keys(self::LEVELS, 0);
		foreach($findings as $finding) {
			$level = $finding["level"] ?? null;
			if(isset($counts[$level])) $counts[$level]++;
		}
		return $counts;
	}

	/**
	* @param 	array 	$findings 	findings
	* @return 	string 	the most severe level (`OK` for no findings)
	*/
	public static function WorstLevel(array $findings): string {
		$worst = TimezoneCheck::OK;
		foreach(self::Counts($findings) as $level => $count) {
			if($count > 0) $worst = $level;
		}
		return $worst;
	}

	/**
	* @param 	array 	$findings 	findings
	* @return 	int 	`Console::FAILED` when there's an ERROR finding, else `Console::OK`
	*/
	public static function ExitCode(array $findings): int {
		return self::WorstLevel($findings) === TimezoneCheck::ERROR ? Console::FAILED : Console::OK;
	}

	private static function Cell($value): string {
		if($value === null) return "NULL";
		if(is_bool($value)) return $value ? "yes" : "no";
		if(is_array($value)) return implode(", ", array_map(fn($v) => self::Cell($v), $value));
		return strtr((string)$value, ["\n" => "\\n", "\r" => "\\r", "\t" => "\\t"]);
	}

	private static function Width(string $text): int {
		return function_exists("mb_strwidth") ? mb_strwidth($text, "UTF-8") : strlen($text);
	}

}
