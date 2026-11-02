<?php

namespace Magrathea\Errors;

use Exception;
use Magrathea\Singleton;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 2.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    ErrorManeger created: 2024-04 by Paulo Martins
####
#######################################################################################

/**
* This class will print errors in beautiful pages.
* In the CLI, it prints them as text on stderr and exits with code 1
*/
class ErrorManager extends Singleton {

	private bool $throwErrors = false;

	/**
	 * Throws the errors instead of displaying them (`Cli\Console::Run()` turns it on while it runs, so its
	 * `--json` output stays one JSON document): `DisplayException()` throws its exception again, and
	 * `DisplayMesage()` throws a `\RuntimeException` with the message
	 * @param 	bool 	$throw
	 * @return 	bool 	the previous setting
	 */
	public function ThrowErrors(bool $throw = true): bool {
		$previous = $this->throwErrors;
		$this->throwErrors = $throw;
		return $previous;
	}

	/**
	 * Displays an exception and stops
	 */
	public function DisplayException(Exception $ex) {
		if($this->throwErrors) throw $ex;
		$errorMessage = $ex->getMessage();
		if(PHP_SAPI === "cli") $this->CliExit($this->CliText($errorMessage, $ex));
		if($ex->getCode() == 424041) {
			$extraMessagePage = "error-magratheaconf.php";
		}
		include(__DIR__."/views/view-exception.php");
		die;
	}

	/**
	 * Displays a message and stops
	 */
	public function DisplayMesage(string $errorMessage) {
		if($this->throwErrors) throw new \RuntimeException($errorMessage);
		if(PHP_SAPI === "cli") $this->CliExit($this->CliText($errorMessage));
		include(__DIR__."/views/view-exception.php");
		die;
	}

	/**
	 * The error as the CLI prints it
	 * @param 	string 			$errorMessage 	the message
	 * @param 	\Throwable|null $ex 			the exception, when there's one: its class, code, place and trace follow
	 * @return 	string
	 */
	public function CliText(string $errorMessage, ?\Throwable $ex = null): string {
		$text = "Magrathea Fatal Error: ".$errorMessage."\n";
		if(!$ex) return $text;
		$text .= get_class($ex).($ex->getCode() ? " (code ".$ex->getCode().")" : "")
			." at ".$ex->getFile().":".$ex->getLine()."\n";
		if($ex->getCode() == 424041) {
			$text .= "Magrathea's config file is missing: a sample is at "
				.realpath(__DIR__."/../Bootstrap/docs/magrathea.conf.sample")."\n";
		}
		return $text.$ex->getTraceAsString()."\n";
	}

	private function CliExit(string $text) {
		fwrite(STDERR, $text);
		exit(1);
	}


}
