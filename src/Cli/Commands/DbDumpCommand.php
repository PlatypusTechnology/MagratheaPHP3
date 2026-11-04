<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\UsageException;
use Magrathea\DB\Dump;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DbDumpCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea db:dump [file] [--tables=a,b] [--driver=binary|php]`: exports the database to a file in the backups
* folder (`Dump::Export()`); the default name is `Dump::GetDefaultFileName()`. An existing file is never overwritten
*/
class DbDumpCommand extends Command {

	private ?Dump $dump;

	/** @param 	Dump|null 	$dump 	what exports (default: `new Dump()`, created when it runs) */
	public function __construct(?Dump $dump = null) {
		$this->dump = $dump;
	}

	public function Name(): string { return "db:dump"; }
	public function Summary(): string { return "Exports the database to a .sql file in the backups folder"; }
	public function Arguments(): array { return ["file" => false]; }
	public function Options(): array { return ["tables" => true, "driver" => true]; }

	public function Execute(array $arguments, array $options): Result {
		$file = self::FileName($arguments["file"] ?? Dump::GetDefaultFileName());
		$dumpOptions = self::DriverOption($options);
		if(isset($options["tables"])) {
			$tables = array_values(array_filter(array_map("trim", explode(",", $options["tables"])), fn($t) => $t !== ""));
			if(empty($tables)) throw new UsageException("--tables needs table names: --tables=a,b");
			$dumpOptions["tables"] = $tables;
		}
		$this->dump ??= new Dump();
		$rs = $this->dump->Export($file, $dumpOptions);
		$rs["folder"] = Dump::Folder();
		$text = "Exported ".$rs["file"]." (".$rs["size"]." bytes, ".$rs["driver"]." driver) to ".$rs["folder"];
		if(!empty($rs["skipped"])) $text .= "\nNot exported (the php driver doesn't dump routines): ".implode(", ", $rs["skipped"]);
		return new Result($rs, $text);
	}

	/**
	* A file in the backups folder is given by its name: a path is a usage error (`Dump` checks the name itself)
	* @throws 	UsageException 	a path
	*/
	public static function FileName(string $file): string {
		if(str_contains($file, "/") || str_contains($file, "\\")) {
			throw new UsageException("Give the file's name in the backups folder (".Dump::Folder()."), not a path: [".$file."]");
		}
		return $file;
	}

	/**
	* @return 	array 	`Dump`'s "driver" option, when `--driver` is given
	* @throws 	UsageException 	a driver that isn't `binary` or `php`
	*/
	public static function DriverOption(array $options): array {
		if(!isset($options["driver"])) return [];
		if(!in_array($options["driver"], [Dump::BINARY, Dump::PHP], true)) {
			throw new UsageException("--driver must be ".Dump::BINARY." or ".Dump::PHP);
		}
		return ["driver" => $options["driver"]];
	}

}
