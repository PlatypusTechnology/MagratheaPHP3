<?php

namespace Magrathea\Cli;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Result created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* What a CLI command returns: its data (the `--json` output), its text (the default output) and its exit code
*/
class Result {

	/**
	* @param 	mixed 	$data 	what `--json` prints, under `data` (anything `json_encode()` takes)
	* @param 	string 	$text 	what's printed without `--json`
	* @param 	int 	$code 	the exit code: `Console::OK`, `Console::FAILED` or `Console::USAGE`
	*/
	public function __construct(
		public mixed $data = null,
		public string $text = "",
		public int $code = Console::OK
	) {}

}
