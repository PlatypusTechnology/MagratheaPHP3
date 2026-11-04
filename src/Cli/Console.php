<?php

namespace Magrathea\Cli;

use Magrathea\Cli\Commands\HelpCommand;
use Magrathea\Errors\ErrorManager;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Console created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The `magrathea` CLI's dispatcher: parses the arguments, loads the project's bootstrap and runs a command.
* `bin/magrathea` only wires `Run()` to STDOUT and STDERR and exits with its code, so it runs in-process in tests.
*/
class Console {

	/** exit code: ok */
	const OK = 0;
	/** exit code: an ERROR finding or a failed command */
	const FAILED = 1;
	/** exit code: a usage error */
	const USAGE = 2;

	/** the bootstrap, relative to the nearest composer.json, when it doesn't declare `extra.magrathea.bootstrap` */
	const DEFAULT_BOOTSTRAP = "app/_inc.php";

	/** options every command takes: name => takes a value? */
	const GLOBAL_OPTIONS = ["json" => false, "bootstrap" => true, "help" => false];

	/** for `magrathea help` */
	const GLOBAL_OPTION_HELP = [
		"--json" => "print one JSON document on stdout: {\"command\", \"exit\", \"data\"}, or {\"command\", \"exit\", \"error\"}",
		"--bootstrap=<file>" => "the project's bootstrap (default: composer.json's extra.magrathea.bootstrap, else ".self::DEFAULT_BOOTSTRAP.")",
		"--help" => "show the command's usage",
	];
	/** for `magrathea help` */
	const EXIT_CODE_HELP = [
		self::OK => "ok",
		self::FAILED => "an ERROR finding or a failed command",
		self::USAGE => "a usage error",
	];

	/**
	* The commands, besides `help`
	* @return 	array 	Command classes (constructed without arguments)
	*/
	protected static function CommandClasses(): array {
		return [
			Commands\CheckCommand::class,
			Commands\DbTablesCommand::class,
			Commands\DbDescribeCommand::class,
			Commands\DbQueryCommand::class,
			Commands\DbDumpCommand::class,
			Commands\DbImportCommand::class,
			Commands\ModelsVerifyCommand::class,
			Commands\AdminCreateUserCommand::class,
			Commands\AdminResetPasswordCommand::class,
			Commands\VersionCommand::class,
		];
	}

	/**
	* Runs the CLI
	* @param 	array 		$argv 	the script's arguments (`$argv[0]` is the script)
	* @param 	resource 	$out 	where results go (STDOUT)
	* @param 	resource 	$err 	where errors and warnings go (STDERR)
	* @return 	int 		the exit code: OK, FAILED or USAGE
	*/
	public static function Run(array $argv, $out, $err): int {
		$json = static::WantsJson(array_slice($argv, 1));
		$name = null;
		$command = null;
		// framework error pages become exceptions, answered below
		$throwErrors = ErrorManager::Instance()->ThrowErrors(true);
		try {
			if(static::Sapi() !== "cli") {
				throw new UsageException("magrathea runs only from the command line (this is [".static::Sapi()."])");
			}
			[$name, $positional, $options] = static::Parse(array_slice($argv, 1));
			$commands = static::Commands();
			$name ??= "help";
			$command = $commands[$name] ?? null;
			if(!$command) throw new UsageException("Unknown command [".$name."]");
			$commandOptions = static::CheckOptions($command, $options);
			if(!empty($options["help"])) {
				$positional = [$name];
				$command = $commands["help"];
				$commandOptions = [];
			}
			$arguments = static::BindArguments($command, $positional);
			$flag = $command->ConsentFlag($arguments);
			if($flag !== null && empty($commandOptions[$flag])) {
				throw new UsageException("[".$command->Name()."] writes: it runs only with --".$flag);
			}
			if($command->NeedsBootstrap()) {
				static::LoadBootstrap(static::FindBootstrap($options["bootstrap"] ?? null), $err);
			}
			$result = $command->Execute($arguments, $commandOptions);
		} catch(UsageException $ex) {
			$usage = $command ? "Usage: magrathea ".$command->Usage() : "Run `magrathea help` for the commands.";
			return static::Fail($out, $err, $json, $name, self::USAGE, $ex->getMessage(), $usage);
		} catch(\Throwable $ex) {
			return static::Fail($out, $err, $json, $name, self::FAILED, $ex->getMessage());
		} finally {
			ErrorManager::Instance()->ThrowErrors($throwErrors);
		}
		return static::Write($out, $err, $json, $name, $result);
	}

	/**
	* Every command, `help` included
	* @return 	Command[] 	name => command
	*/
	public static function Commands(): array {
		$commands = [];
		foreach(static::CommandClasses() as $class) {
			$command = new $class();
			$commands[$command->Name()] = $command;
		}
		$help = new HelpCommand($commands);
		return ["help" => $help] + $commands;
	}

	/**
	* Splits the arguments into the command's name, its positional arguments and the options.
	* Options are `--flag` or `--name=value`, anywhere; `--` ends them
	* @param 	array 	$args 	the arguments, without the script
	* @return 	array 	[name (null when there's none), positional arguments, options: name => true|string]
	*/
	public static function Parse(array $args): array {
		$name = null;
		$positional = [];
		$options = [];
		$onlyPositional = false;
		foreach($args as $arg) {
			$arg = (string)$arg;
			if(!$onlyPositional && $arg === "--") {
				$onlyPositional = true;
				continue;
			}
			if(!$onlyPositional && str_starts_with($arg, "--")) {
				$eq = strpos($arg, "=");
				$option = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
				if(!preg_match('/^[a-z][a-z0-9-]*$/', $option)) {
					throw new UsageException("Invalid option [".$arg."]: an argument that starts with -- goes after a `--`");
				}
				$options[$option] = $eq === false ? true : substr($arg, $eq + 1);
				continue;
			}
			if(!$onlyPositional && strlen($arg) > 1 && $arg[0] === "-") {
				throw new UsageException("Unknown option [".$arg."]: options are --name or --name=value");
			}
			if($name === null) $name = $arg;
			else $positional[] = $arg;
		}
		return [$name, $positional, $options];
	}

	/**
	* Checks the options against the global ones and the command's
	* @return 	array 	the command's own options (the global ones left out)
	* @throws 	UsageException 	an unknown option, a flag given a value, an option without one
	*/
	protected static function CheckOptions(Command $command, array $options): array {
		$own = $command->Options();
		$known = self::GLOBAL_OPTIONS + $own;
		$commandOptions = [];
		foreach($options as $option => $value) {
			if(!array_key_exists($option, $known)) {
				throw new UsageException("Unknown option [--".$option."] for [".$command->Name()."]");
			}
			if($known[$option] && ($value === true || $value === "")) {
				throw new UsageException("Option [--".$option."] needs a value: --".$option."=…");
			}
			if(!$known[$option] && $value !== true) {
				throw new UsageException("Option [--".$option."] doesn't take a value");
			}
			if(array_key_exists($option, $own)) $commandOptions[$option] = $value;
		}
		return $commandOptions;
	}

	/**
	* @return 	array 	the positional arguments by the command's argument names
	* @throws 	UsageException 	a missing required argument, or too many
	*/
	protected static function BindArguments(Command $command, array $positional): array {
		$arguments = [];
		foreach($command->Arguments() as $argument => $required) {
			if(empty($positional)) {
				if($required) throw new UsageException("Missing argument <".$argument.">");
				continue;
			}
			$arguments[$argument] = array_shift($positional);
		}
		if(!empty($positional)) {
			throw new UsageException("Too many arguments for [".$command->Name()."]: [".implode("] [", $positional)."]");
		}
		return $arguments;
	}

	/**
	* Finds the project's bootstrap: `--bootstrap=<file>` (relative to the current folder), else
	* `extra.magrathea.bootstrap` in the nearest composer.json above the current folder (relative to that file),
	* else `app/_inc.php` next to that composer.json
	* @param 	string|null 	$option 	`--bootstrap`'s value
	* @return 	string 		the bootstrap's path
	* @throws 	UsageException 	`--bootstrap` names a file that doesn't exist
	* @throws 	\RuntimeException 	nothing found
	*/
	public static function FindBootstrap(?string $option): string {
		$cwd = static::WorkingDirectory();
		if($option !== null) {
			$file = self::Resolve($option, $cwd);
			if(!is_file($file)) throw new UsageException("Bootstrap [".$file."] not found (--bootstrap)");
			return $file;
		}
		$composer = self::FindComposerJson($cwd);
		$fix = "pass --bootstrap=<file>, or declare it in composer.json: \"extra\": {\"magrathea\": {\"bootstrap\": \"<file>\"}}";
		if($composer === null) {
			throw new \RuntimeException("No composer.json in [".$cwd."] or above it to find the bootstrap: ".$fix);
		}
		$data = json_decode((string)@file_get_contents($composer), true);
		if(!is_array($data)) throw new \RuntimeException("[".$composer."] isn't valid JSON");
		$folder = dirname($composer);
		$declared = $data["extra"]["magrathea"]["bootstrap"] ?? null;
		if($declared !== null) {
			if(!is_string($declared) || $declared === "") {
				throw new \RuntimeException("extra.magrathea.bootstrap in [".$composer."] must be a file path");
			}
			$file = self::Resolve($declared, $folder);
			if(!is_file($file)) {
				throw new \RuntimeException("Bootstrap [".$file."] not found (extra.magrathea.bootstrap in [".$composer."])");
			}
			return $file;
		}
		$file = $folder."/".self::DEFAULT_BOOTSTRAP;
		if(!is_file($file)) throw new \RuntimeException("Bootstrap [".$file."] not found: ".$fix);
		return $file;
	}

	/**
	* Requires the bootstrap. It must not print: anything it prints is discarded, with a warning on `$err`
	* @throws 	\RuntimeException 	the bootstrap threw
	*/
	protected static function LoadBootstrap(string $file, $err): void {
		ob_start();
		try {
			(static function() use ($file) { require $file; })();
		} catch(\Throwable $ex) {
			throw new \RuntimeException("The bootstrap [".$file."] failed: ".$ex->getMessage(), 0, $ex);
		} finally {
			$printed = ob_get_clean();
		}
		if($printed !== false && $printed !== "") {
			fwrite($err, "Warning: the bootstrap [".$file."] printed ".strlen($printed)." bytes, discarded: it must not print\n");
		}
	}

	/** @return 	string|null 	the nearest composer.json in `$folder` or above it */
	private static function FindComposerJson(string $folder): ?string {
		while(true) {
			if(is_file($folder."/composer.json")) return $folder."/composer.json";
			$parent = dirname($folder);
			if($parent === $folder) return null;
			$folder = $parent;
		}
	}

	/** @return 	string 	`$path` when it's absolute; otherwise `$folder/$path` */
	private static function Resolve(string $path, string $folder): string {
		if(preg_match('~^([a-zA-Z]:)?[/\\\\]~', $path)) return $path;
		return rtrim($folder, "/\\")."/".$path;
	}

	/** `--json` given before any `--`: errors in parsing are answered in JSON too */
	private static function WantsJson(array $args): bool {
		foreach($args as $arg) {
			if($arg === "--") return false;
			if($arg === "--json") return true;
		}
		return false;
	}

	private static function Write($out, $err, bool $json, ?string $name, Result $result): int {
		if(!$json) {
			if($result->text !== "") fwrite($out, rtrim($result->text, "\n")."\n");
			return $result->code;
		}
		try {
			$document = self::Json(["command" => $name, "exit" => $result->code, "data" => $result->data]);
		} catch(\JsonException $ex) {
			return self::Fail($out, $err, true, $name, self::FAILED, "The result can't be encoded as JSON: ".$ex->getMessage());
		}
		fwrite($out, $document."\n");
		return $result->code;
	}

	private static function Fail($out, $err, bool $json, ?string $name, int $code, string $message, ?string $hint = null): int {
		if($json) {
			fwrite($out, self::Json(["command" => $name, "exit" => $code, "error" => $message])."\n");
		} else {
			fwrite($err, "magrathea: ".$message."\n".($hint ? $hint."\n" : ""));
		}
		return $code;
	}

	private static function Json(array $document): string {
		return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}

	/** @return 	string 	PHP's SAPI (overridable in tests) */
	protected static function Sapi(): string {
		return PHP_SAPI;
	}

	/** @return 	string 	the folder the bootstrap is looked for from (overridable in tests) */
	protected static function WorkingDirectory(): string {
		return (string)getcwd();
	}

}
