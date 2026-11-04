<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\Text;
use Magrathea\ProjectCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    CheckCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea check [--no-db]`: `ProjectCheck::Run()`'s findings. Exits with 1 on any ERROR
*/
class CheckCommand extends Command {

	/** @var string 	a `ProjectCheck` class (its `Run()` is static) */
	private string $check;

	/** @param 	string 	$check 	the `ProjectCheck` class to run (default: `ProjectCheck`) */
	public function __construct(string $check = ProjectCheck::class) {
		$this->check = $check;
	}

	public function Name(): string { return "check"; }
	public function Summary(): string { return "Checks the project: config, folders, database, timezone and schema (exit 1 on any ERROR)"; }
	public function Options(): array { return ["no-db" => false]; }

	public function Execute(array $arguments, array $options): Result {
		$findings = ($this->check)::Run(empty($options["no-db"]));
		$data = [
			"level" => Text::WorstLevel($findings),
			"counts" => Text::Counts($findings),
			"findings" => $findings,
		];
		return new Result($data, Text::Findings($findings), Text::ExitCode($findings));
	}

}
