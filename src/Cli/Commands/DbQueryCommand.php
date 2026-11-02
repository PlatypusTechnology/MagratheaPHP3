<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Admin\QueryRunner;
use Magrathea\Cli\Command;
use Magrathea\Cli\Console;
use Magrathea\Cli\Result;
use Magrathea\Cli\Text;
use Magrathea\Cli\UsageException;
use Magrathea\DB\SqlSplitter;
use Magrathea\Exceptions\MagratheaDBException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DbQueryCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea db:query "<sql>" [--write]`: runs SQL through the admin's `QueryRunner` (one statement at a time, the first
* error stops it, at most `QueryRunner::MAX_ROWS` rows per statement). SQL that isn't read-only
* (`SqlSplitter::IsReadOnly()`) runs only with `--write`. Exits with 1 when a statement fails
*/
class DbQueryCommand extends Command {

	private ?QueryRunner $runner;

	/** @param 	QueryRunner|null 	$runner 	what runs the SQL (default: `new QueryRunner()`, created when it runs) */
	public function __construct(?QueryRunner $runner = null) {
		$this->runner = $runner;
	}

	public function Name(): string { return "db:query"; }
	public function Summary(): string { return "Runs SQL: read-only SQL prints its rows; anything else needs --write"; }
	public function Arguments(): array { return ["sql" => true]; }
	public function Options(): array { return ["write" => false]; }

	public function ConsentFlag(array $arguments): ?string {
		$sql = $arguments["sql"];
		try {
			// nothing to run: Execute() says so
			if(empty(SqlSplitter::Split($sql))) return null;
		} catch(MagratheaDBException $ex) {
			return "write";
		}
		return SqlSplitter::IsReadOnly($sql) ? null : "write";
	}

	public function Execute(array $arguments, array $options): Result {
		$this->runner ??= new QueryRunner();
		$run = $this->runner->Run($arguments["sql"], !empty($options["write"]));
		if($run["status"] === QueryRunner::EMPTY) throw new UsageException("No SQL to run");
		if($run["status"] === QueryRunner::CONFIRM) throw new UsageException("[".$this->Name()."] writes: it runs only with --write");
		$code = $run["status"] === QueryRunner::ERROR ? Console::FAILED : Console::OK;
		return new Result($run, $this->Text($run), $code);
	}

	private function Text(array $run): string {
		$blocks = [];
		$many = count($run["statements"]) > 1;
		foreach($run["statements"] as $statement) {
			$lines = $many ? ["> ".$statement["sql"]] : [];
			if($statement["error"] !== null) {
				$lines[] = "Error: ".$statement["error"];
			} else {
				if($statement["rows"] !== null) {
					$lines[] = Text::Table($statement["rows"]);
					$lines[] = "(".count($statement["rows"])." row".(count($statement["rows"]) == 1 ? "" : "s")
						.($statement["truncated"] ? ": stopped at ".QueryRunner::MAX_ROWS.", there are more" : "").")";
				}
				if($statement["affected"] !== null) $lines[] = "Affected rows: ".$statement["affected"];
			}
			$blocks[] = implode("\n", $lines);
		}
		if($run["status"] === QueryRunner::ERROR) {
			if(empty($run["statements"])) $blocks[] = "Error: ".$run["error"];
			if($run["notRun"] > 0) $blocks[] = $run["notRun"]." statement".($run["notRun"] == 1 ? "" : "s")." after it didn't run.";
		}
		return implode("\n\n", $blocks);
	}

}
