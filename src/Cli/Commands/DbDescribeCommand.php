<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Admin\DatabaseExplorer;
use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\Text;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DbDescribeCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea db:describe <table>`: a table's columns (with the suggested Magrathea type), indexes, `SHOW CREATE TABLE`,
* the models that map it and their findings: the admin Explorer's table page (`DatabaseExplorer::Table()`), without
* its rows. Its findings don't change the exit code (`models:verify` does that)
*/
class DbDescribeCommand extends Command {

	private ?DatabaseExplorer $explorer;

	/** @param 	DatabaseExplorer|null 	$explorer 	what reads the table (default: `new DatabaseExplorer()`, created when it runs) */
	public function __construct(?DatabaseExplorer $explorer = null) {
		$this->explorer = $explorer;
	}

	public function Name(): string { return "db:describe"; }
	public function Summary(): string { return "Describes a table: columns, indexes, CREATE TABLE, the models that map it and their findings"; }
	public function Arguments(): array { return ["table" => true]; }

	public function Execute(array $arguments, array $options): Result {
		$this->explorer ??= new DatabaseExplorer();
		$table = $this->explorer->Table($arguments["table"], false);
		return new Result($table, $this->Text($table));
	}

	private function Text(array $table): string {
		$lines = [
			$table["name"]." (".$table["type"].")",
			"",
			"Columns:",
			Text::Table($table["columns"], ["name", "type", "nullable", "default", "extra", "key", "magrathea_type"]),
			"",
			"Indexes:",
			Text::Table($table["indexes"], ["name", "unique", "type", "columns"]),
			"",
			"Models: ".(empty($table["models"]) ? "none" : implode(", ", $table["models"])),
		];
		if(!empty($table["models"])) {
			$lines[] = "";
			$lines[] = "Findings:";
			$lines[] = Text::Findings($table["findings"]);
		}
		$lines[] = "";
		$lines[] = $table["create"];
		return implode("\n", $lines);
	}

}
