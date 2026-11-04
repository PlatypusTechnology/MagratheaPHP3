<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Console;
use Magrathea\Cli\Result;
use Magrathea\Cli\UsageException;
use Magrathea\MagratheaPHP;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    HelpCommand created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* `magrathea help [command]`: the commands, or one command's usage. Runs without the bootstrap,
* so it works in a project that doesn't load
*/
class HelpCommand extends Command {

	/** @var Command[] name => command */
	private array $commands;

	/** @param 	Command[] 	$commands 	name => command, as `Console::Run()` dispatches them (help adds itself) */
	public function __construct(array $commands) {
		$this->commands = ["help" => $this] + $commands;
	}

	public function Name(): string { return "help"; }
	public function Summary(): string { return "Lists the commands, or shows one command's usage"; }
	public function Arguments(): array { return ["command" => false]; }
	public function NeedsBootstrap(): bool { return false; }

	public function Execute(array $arguments, array $options): Result {
		$name = $arguments["command"] ?? null;
		if($name !== null) {
			$command = $this->commands[$name] ?? null;
			if(!$command) throw new UsageException("Unknown command [".$name."]");
			return new Result($this->Describe($command), "Usage: magrathea ".$command->Usage()."\n\n".$command->Summary());
		}
		$data = [
			"version" => MagratheaPHP::Version(),
			"commands" => array_values(array_map(fn($c) => $this->Describe($c), $this->commands)),
			"options" => Console::GLOBAL_OPTION_HELP,
			"exit_codes" => Console::EXIT_CODE_HELP,
		];
		return new Result($data, $this->Text($data));
	}

	private function Describe(Command $command): array {
		return ["name" => $command->Name(), "summary" => $command->Summary(), "usage" => "magrathea ".$command->Usage()];
	}

	private function Text(array $data): string {
		$lines = [
			"MagratheaPHP ".$data["version"],
			"",
			"Usage: magrathea <command> [arguments] [options]",
			"",
			"Commands:",
		];
		$width = max(array_map(fn($c) => strlen($c["name"]), $data["commands"]));
		foreach($data["commands"] as $c) $lines[] = "  ".str_pad($c["name"], $width + 2).$c["summary"];
		$lines[] = "";
		$lines[] = "Options for every command:";
		$width = max(array_map("strlen", array_keys($data["options"])));
		foreach($data["options"] as $option => $help) $lines[] = "  ".str_pad($option, $width + 2).$help;
		$lines[] = "";
		$lines[] = "Exit codes:";
		foreach($data["exit_codes"] as $code => $help) $lines[] = "  ".$code."  ".$help;
		$lines[] = "";
		$lines[] = "Run `magrathea help <command>` for a command's usage.";
		return implode("\n", $lines);
	}

}
