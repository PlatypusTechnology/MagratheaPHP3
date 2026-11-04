<?php

namespace Magrathea\Cli;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Command created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* A `magrathea` CLI command. `Console::Run()` checks its arguments and options against what it declares,
* loads the project's bootstrap when it needs it, and prints the `Result` its `Execute()` returns
*/
abstract class Command {

	/** @return 	string 	the name it's called by (`check`, `db:tables`, …) */
	abstract public function Name(): string;

	/** @return 	string 	one line, for `magrathea help` */
	abstract public function Summary(): string;

	/**
	* Its positional arguments, in order (required ones first)
	* @return 	array 	name => required?
	*/
	public function Arguments(): array {
		return [];
	}

	/**
	* Its options, besides the global ones (`--json`, `--bootstrap`, `--help`)
	* @return 	array 	name => takes a value? (`--name=value`; otherwise a flag, `--name`)
	*/
	public function Options(): array {
		return [];
	}

	/** @return 	bool 	does it need the project's bootstrap? */
	public function NeedsBootstrap(): bool {
		return true;
	}

	/**
	* The flag this run needs because it writes (`--write`, `--yes`), or null when it doesn't need one.
	* `Console::Run()` refuses to run it without the flag (exit code 2), before loading the bootstrap.
	* There are no prompts, since agents can't answer them: the flag is the consent. It must be one of its `Options()`
	* @param 	array 	$arguments 	name => value, as `Execute()` gets them
	* @return 	string|null 	the flag's name, without `--`
	*/
	public function ConsentFlag(array $arguments): ?string {
		return null;
	}

	/** @return 	string 	its usage line: `name <required> [optional] [--flag] [--option=…]` */
	public function Usage(): string {
		$parts = [$this->Name()];
		foreach($this->Arguments() as $name => $required) {
			$parts[] = $required ? "<".$name.">" : "[".$name."]";
		}
		foreach($this->Options() as $name => $takesValue) {
			$parts[] = "[--".$name.($takesValue ? "=…" : "")."]";
		}
		return implode(" ", $parts);
	}

	/**
	* Runs the command. A `UsageException` exits with 2; any other exception with 1
	* @param 	array 	$arguments 	name => value, for the arguments given (a missing optional one isn't set)
	* @param 	array 	$options 	name => `true` (flags) or the value, for its options given (no global ones)
	* @return 	Result
	*/
	abstract public function Execute(array $arguments, array $options): Result;

}
