<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Console;
use Magrathea\Cli\Result;
use Magrathea\DB\Dump;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DbImportCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea db:import <file> --yes [--driver=binary|php] [--continue]`: imports a file in the backups folder
* (`Dump::Import()`). It runs only with `--yes`. With `--continue` it doesn't stop at errors, and exits with 1 when
* there were any
*/
class DbImportCommand extends Command {

	private ?Dump $dump;

	/** @param 	Dump|null 	$dump 	what imports (default: `new Dump()`, created when it runs) */
	public function __construct(?Dump $dump = null) {
		$this->dump = $dump;
	}

	public function Name(): string { return "db:import"; }
	public function Summary(): string { return "Imports a .sql file from the backups folder into the database (needs --yes)"; }
	public function Arguments(): array { return ["file" => true]; }
	public function Options(): array { return ["yes" => false, "driver" => true, "continue" => false]; }

	public function ConsentFlag(array $arguments): ?string {
		return "yes";
	}

	public function Execute(array $arguments, array $options): Result {
		$file = DbDumpCommand::FileName($arguments["file"]);
		$importOptions = DbDumpCommand::DriverOption($options);
		if(!empty($options["continue"])) $importOptions["continue"] = true;
		$this->dump ??= new Dump();
		$rs = ["file" => $file] + $this->dump->Import($file, $importOptions);
		$text = "Imported ".$file." (".$rs["driver"]." driver".(isset($rs["statements"]) ? ", ".$rs["statements"]." statements" : "").")";
		if(!empty($rs["errors"])) {
			$text .= "\nErrors (".count($rs["errors"])."):";
			foreach($rs["errors"] as $error) {
				$text .= "\n  ".(is_array($error) ? "statement ".$error["statement"].": ".$error["error"] : $error);
			}
		}
		return new Result($rs, $text, empty($rs["errors"]) ? Console::OK : Console::FAILED);
	}

}
