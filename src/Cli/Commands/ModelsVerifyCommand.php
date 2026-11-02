<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\Text;
use Magrathea\Cli\UsageException;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    ModelsVerifyCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea models:verify [Class]`: checks models against their tables (`SchemaCheck`). Without a class, every model
* (`CheckAll()`); with one, that model (`CheckModel()`) and its table's timestamps (`CheckTimestamps()`).
* Exits with 1 on any ERROR. Agents run it after writing or changing a model
*/
class ModelsVerifyCommand extends Command {

	private ?SchemaCheck $check;

	/** @param 	SchemaCheck|null 	$check 	the checks (default: `new SchemaCheck()`, created when it runs) */
	public function __construct(?SchemaCheck $check = null) {
		$this->check = $check;
	}

	public function Name(): string { return "models:verify"; }
	public function Summary(): string { return "Checks the models against their tables (exit 1 on any ERROR)"; }
	public function Arguments(): array { return ["class" => false]; }

	public function Execute(array $arguments, array $options): Result {
		$this->check ??= new SchemaCheck();
		$class = isset($arguments["class"]) ? ltrim($arguments["class"], "\\") : null;
		if($class === null) $findings = $this->check->CheckAll();
		else [$class, $findings] = $this->CheckOne($class);
		$data = [
			"class" => $class,
			"level" => Text::WorstLevel($findings),
			"counts" => Text::Counts($findings),
			"findings" => $findings,
		];
		return new Result($data, Text::Findings($findings), Text::ExitCode($findings));
	}

	/**
	* One model: found in the code folders by its full name, or by its short name when only one model has it.
	* A class that isn't found there is still checked (`CheckModel()` says what's wrong with it), without its timestamps
	* @return 	array 	[the class checked (its full name when found), findings]
	* @throws 	UsageException 	a short name more than one model has
	*/
	private function CheckOne(string $class): array {
		$models = $this->check->FindModels()["models"];
		$model = null;
		foreach($models as $row) {
			if(strcasecmp($row["class"], $class) == 0) $model = $row;
		}
		if($model === null && !str_contains($class, "\\")) {
			$matches = array_values(array_filter($models, fn($row) => strcasecmp(self::ShortName($row["class"]), $class) == 0));
			if(count($matches) > 1) {
				throw new UsageException("[".$class."] is ambiguous: ".implode(", ", array_column($matches, "class")));
			}
			$model = $matches[0] ?? null;
		}
		if($model === null) return [$class, $this->check->CheckModel($class)];

		$class = $model["class"];
		$findings = $this->check->CheckModel($class);
		if(!empty($model["table"])) {
			try {
				$findings = array_merge($findings, $this->check->CheckTimestamps((string)$model["table"]));
			} catch(MagratheaDBException $ex) {
				// a missing table: CheckModel() reported it
			}
		}
		return [$class, $findings];
	}

	private static function ShortName(string $class): string {
		$at = strrpos($class, "\\");
		return $at === false ? $class : substr($class, $at + 1);
	}

}
