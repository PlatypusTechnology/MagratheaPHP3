<?php

use Magrathea\DB\Schema;
use Magrathea\Tests\TestsHelper;

class schemaTest extends \PHPUnit\Framework\TestCase {

	function testMagratheaType() {
		TestsHelper::Print("testing Schema::MagratheaType() maps SQL column types...");
		$expected = [
			"tinyint(1)" => "boolean",
			"TINYINT(1) UNSIGNED" => "boolean",
			"bit(1)" => "boolean",
			"tinyint(4)" => "int",
			"tinyint" => "int",
			"smallint(6)" => "int",
			"mediumint" => "int",
			"int(11)" => "int",
			"int(10) unsigned" => "int",
			"INT" => "int",
			"bigint(20) unsigned zerofill" => "int",
			"year(4)" => "int",
			"decimal(10,2)" => "decimal",
			"numeric(5,0)" => "decimal",
			"float" => "float",
			"double" => "float",
			"double precision" => "float",
			"real" => "float",
			"char(36)" => "uuid",
			"char(2)" => "string",
			"char" => "string",
			"varchar(255)" => "string",
			"enum('a','b')" => "string",
			"set('x','y')" => "string",
			"time" => "string",
			"time(3)" => "string",
			"tinytext" => "text",
			"text" => "text",
			"mediumtext" => "text",
			"longtext" => "text",
			"json" => "text",
			"date" => "date",
			"datetime" => "datetime",
			"datetime(6)" => "datetime",
			"timestamp" => "datetime",
			" Timestamp " => "datetime",
		];
		foreach($expected as $columnType => $type) {
			$this->assertSame($type, Schema::MagratheaType($columnType), $columnType);
		}
	}

	function testMagratheaTypeWithoutSuggestion() {
		TestsHelper::Print("testing Schema::MagratheaType() gives null for blobs, binary and spatial types...");
		foreach(["blob", "longblob", "binary(16)", "varbinary(255)", "bit(8)", "geometry", "point", "", "(11)"] as $columnType) {
			$this->assertNull(Schema::MagratheaType($columnType), $columnType);
		}
	}

}
