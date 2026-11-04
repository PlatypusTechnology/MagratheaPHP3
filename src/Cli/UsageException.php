<?php

namespace Magrathea\Cli;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    UsageException created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* A CLI usage error (unknown command or option, a missing or extra argument, a refused write):
* `Console::Run()` prints it and exits with `Console::USAGE` (2)
*/
class UsageException extends \InvalidArgumentException {}
