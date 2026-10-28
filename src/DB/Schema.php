<?php

namespace Magrathea\DB;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Schema created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* Database schema: what the database's columns mean for Magrathea models.
*/
class Schema {

	/** column base type => Magrathea type (tinyint(1), bit(1) and char(36) are handled apart) */
	const TYPE_MAP = [
		"tinyint" => "int",
		"smallint" => "int",
		"mediumint" => "int",
		"int" => "int",
		"integer" => "int",
		"bigint" => "int",
		"year" => "int",
		"decimal" => "decimal",
		"numeric" => "decimal",
		"float" => "float",
		"double" => "float",
		"real" => "float",
		"char" => "string",
		"varchar" => "string",
		"enum" => "string",
		"set" => "string",
		"time" => "string",
		"tinytext" => "text",
		"text" => "text",
		"mediumtext" => "text",
		"longtext" => "text",
		"json" => "text",
		"date" => "date",
		"datetime" => "datetime",
		"timestamp" => "datetime",
	];

	/**
	* Magrathea type suggested for a SQL column type (as `SHOW COLUMNS` gives it: `int(11) unsigned`,
	* `varchar(255)`, `decimal(10,2)`, …). Case-insensitive; `unsigned`/`zerofill` and display widths are
	* ignored, except `tinyint(1)` and `bit(1)` (`boolean`) and `char(36)` (`uuid`: a suggestion,
	* the column may hold other strings).
	* @param 	string 	$columnType		SQL column type
	* @return 	string|null 			Magrathea type, or null when there's no suggestion (blobs, binary, spatial types)
	*/
	public static function MagratheaType(string $columnType): ?string {
		$type = strtolower(trim($columnType));
		if(!preg_match('/^([a-z]+)\s*(?:\(([^)]*)\))?/', $type, $match)) return null;
		$base = $match[1];
		$size = trim($match[2] ?? "");
		if(($base == "tinyint" || $base == "bit") && $size === "1") return "boolean";
		if($base == "char" && $size === "36") return "uuid";
		return self::TYPE_MAP[$base] ?? null;
	}

}
