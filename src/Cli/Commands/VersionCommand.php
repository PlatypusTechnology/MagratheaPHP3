<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\MagratheaPHP;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    VersionCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea version`: the framework's version (`MagratheaPHP::Version()`), the app's (`AppVersion()`: the `version`
* file in the project's root, `???` without one) and PHP's
*/
class VersionCommand extends Command {

	public function Name(): string { return "version"; }
	public function Summary(): string { return "Shows the framework's, the app's and PHP's versions"; }

	public function Execute(array $arguments, array $options): Result {
		$data = [
			"magrathea" => MagratheaPHP::Version(),
			"app" => MagratheaPHP::Instance()->AppVersion(),
			"php" => PHP_VERSION,
		];
		$text = "MagratheaPHP ".$data["magrathea"]."\nApp ".$data["app"]."\nPHP ".$data["php"];
		return new Result($data, $text);
	}

}
