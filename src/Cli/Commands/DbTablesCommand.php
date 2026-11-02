<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\Text;
use Magrathea\DB\Schema;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DbTablesCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea db:tables`: the database's tables and views (`Schema::Tables()`), the framework's included
*/
class DbTablesCommand extends Command {

	private ?Schema $schema;

	/** @param 	Schema|null 	$schema 	the schema to read (default: `new Schema()`, created when it runs) */
	public function __construct(?Schema $schema = null) {
		$this->schema = $schema;
	}

	public function Name(): string { return "db:tables"; }
	public function Summary(): string { return "Lists the database's tables and views"; }

	public function Execute(array $arguments, array $options): Result {
		$this->schema ??= new Schema();
		$tables = $this->schema->Tables();
		return new Result($tables, Text::Table($tables, ["name", "type", "engine", "rows"]));
	}

}
