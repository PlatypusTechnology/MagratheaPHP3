<?php

namespace Magrathea;

use Magrathea\DB\Database;
use Magrathea\DB\Dump;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    ProjectCheck created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The project's health, as findings: PHP and the config, the folders, the database, the timezone and the schema.
* Shown by the admin's Checks page, `magrathea check` and the Setup Wizard; never called at runtime.
* Read-only, and never throws: whatever fails is a finding.
*/
class ProjectCheck {

	const CONFIG = "config";
	const FOLDERS = "folders";
	const DATABASE = "database";
	const TIMEZONE = "timezone";
	const SCHEMA = SchemaCheck::GROUP;

	const MIN_PHP = "8.1.0";
	/** all of them: the project uses a database; none: it doesn't; some: a mistake */
	const DB_KEYS = ["db_host", "db_name", "db_user"];
	/** created by the admin's install */
	const ADMIN_TABLE = "_magrathea_config";

	/**
	* Runs every check. Each finding has a `group`: `config`, `folders`, `database`, `timezone` or `schema`.
	* When the config can't be read, only `config` runs. The database groups (`database`, `timezone` and `schema`)
	* run only when the config has `db_host`, `db_name` and `db_user`, and stop at a failed connection.
	* @param 	bool 	$withDatabase 	false skips everything that connects: the `database`, `timezone` and `schema` groups
	* @return 	array 	list of findings: ["level" => TimezoneCheck::OK|INFO|WARNING|ERROR, "message" => string, "group" => string, …]
	*/
	public static function Run(bool $withDatabase = true): array {
		$findings = static::PhpFindings();
		$config = static::ConfigFileFindings();
		$findings = array_merge($findings, $config);
		if(static::Failed($config)) return $findings;

		$database = static::DatabaseKeyFindings();
		$findings = array_merge(
			$findings,
			$database,
			self::InGroup(TimezoneCheck::ConfigFindings(), self::CONFIG),
			static::BootstrapFindings(),
			static::FolderFindings(),
		);
		if(!$withDatabase || !static::UsesDatabase() || static::Failed($database)) return $findings;

		$connection = static::ConnectionFindings();
		$findings = array_merge($findings, $connection);
		if(static::Failed($connection)) return $findings;
		return array_merge(
			$findings,
			static::AdminTableFindings(),
			self::InGroup(TimezoneCheck::DatabaseFindings(), self::TIMEZONE),
			static::SchemaFindings(),
		);
	}

	/**
	* PHP's version and `proc_open()` (used by dumps)
	* @return 	array 	findings
	*/
	protected static function PhpFindings(): array {
		$version = static::PhpVersion();
		$findings = [version_compare($version, self::MIN_PHP, ">=")
			? self::Finding(self::CONFIG, TimezoneCheck::OK, "PHP ".$version.".")
			: self::Finding(self::CONFIG, TimezoneCheck::ERROR, "PHP ".$version.": Magrathea needs PHP ".self::MIN_PHP." or later.")];
		$findings[] = static::HasFunction("proc_open")
			? self::Finding(self::CONFIG, TimezoneCheck::OK, "`proc_open()` is available: dumps can use `mysqldump` and `mysql`.")
			: self::Finding(self::CONFIG, TimezoneCheck::INFO, "`proc_open()` is disabled: dumps can't use `mysqldump` and `mysql`.");
		return $findings;
	}

	/**
	* The config file is readable, `use_environment` names one of its sections, and the active environment exists.
	* An ERROR here stops Run(): every other check reads the config.
	* @return 	array 	findings
	*/
	protected static function ConfigFileFindings(): array {
		$config = Config::Instance();
		try {
			$file = $config->GetFilePath();
		} catch(\Throwable $ex) {
			return [self::Finding(self::CONFIG, TimezoneCheck::ERROR, "No config file: ".$ex->getMessage())];
		}
		if(!is_readable($file)) {
			return [self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The config file `".$file."` isn't readable.")];
		}
		try {
			$all = $config->GetConfig();
		} catch(\Throwable $ex) {
			return [self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The config file `".$file."` can't be parsed: ".$ex->getMessage())];
		}
		$findings = [self::Finding(self::CONFIG, TimezoneCheck::OK, "Config file: `".$file."`.")];

		$useEnvironment = $all["general"]["use_environment"] ?? "";
		if(!is_string($useEnvironment) || $useEnvironment === "") {
			$findings[] = self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The config has no `use_environment` in `[general]`.");
			return $findings;
		}
		if(!is_array($all[$useEnvironment] ?? null)) {
			$findings[] = self::Finding(self::CONFIG, TimezoneCheck::ERROR, "`use_environment = \"".$useEnvironment."\"`, but the config has no `[".$useEnvironment."]` section.");
			return $findings;
		}
		// SetEnvironment() can override use_environment
		$active = $config->GetEnvironment();
		if(!is_array($all[$active] ?? null)) {
			$findings[] = self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The active environment is `".$active."`, but the config has no `[".$active."]` section.");
			return $findings;
		}
		$findings[] = self::Finding(self::CONFIG, TimezoneCheck::OK, "Environment: `".$active."`.");
		return $findings;
	}

	/**
	* `db_host`, `db_name` and `db_user`: all set, none set (no database), or some (ERROR for each missing one);
	* and the `mysqli` extension, when any of them is set
	* @return 	array 	findings
	*/
	protected static function DatabaseKeyFindings(): array {
		$missing = static::MissingDatabaseKeys();
		if(count($missing) == count(self::DB_KEYS)) {
			return [self::Finding(self::CONFIG, TimezoneCheck::INFO, "No database configured (`".implode("`, `", self::DB_KEYS)."` are empty): the database checks are skipped.")];
		}
		$findings = [];
		foreach($missing as $key) {
			$findings[] = self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The database is configured, but `".$key."` is empty.");
		}
		if(empty($missing)) {
			$findings[] = self::Finding(self::CONFIG, TimezoneCheck::OK, "Database configured: `".Config::Instance()->Get("db_name")."` on `".Config::Instance()->Get("db_host")."`.");
		}
		$findings[] = static::HasExtension("mysqli")
			? self::Finding(self::CONFIG, TimezoneCheck::OK, "The `mysqli` extension is loaded.")
			: self::Finding(self::CONFIG, TimezoneCheck::ERROR, "The `mysqli` extension isn't loaded: the database can't be reached.");
		return $findings;
	}

	/**
	* `bootstrap_unlocked` outside a `dev` environment: WARNING
	* @return 	array 	findings
	*/
	protected static function BootstrapFindings(): array {
		$config = Config::Instance();
		if(!filter_var($config->Get("bootstrap_unlocked"), FILTER_VALIDATE_BOOLEAN)) {
			return [self::Finding(self::CONFIG, TimezoneCheck::OK, "The Setup Wizard is locked.")];
		}
		$environment = $config->GetEnvironment();
		if($environment === "dev") {
			return [self::Finding(self::CONFIG, TimezoneCheck::INFO, "The Setup Wizard is unlocked (`bootstrap_unlocked`, in `[dev]`).")];
		}
		return [self::Finding(self::CONFIG, TimezoneCheck::WARNING, "The Setup Wizard is unlocked outside `dev`: remove `bootstrap_unlocked` from `[".$environment."]`.")];
	}

	/**
	* The logs, cache and backups folders exist and are writable (logs: ERROR; cache and backups: WARNING, only
	* their feature breaks). An empty `cache_path` is a WARNING; `logs_path` and `backups_path` have defaults.
	* On the web, `configs/` under the document root is a WARNING.
	* @return 	array 	findings
	*/
	protected static function FolderFindings(): array {
		$config = Config::Instance();
		$findings = [];
		try {
			$findings[] = self::FolderFinding("Logs", Logger::Instance()->GetLogPath(), TimezoneCheck::ERROR,
				empty($config->Get("logs_path")) ? "`logs_path` isn't set" : null);
		} catch(\Throwable $ex) {
			$findings[] = self::Finding(self::FOLDERS, TimezoneCheck::ERROR, "Logs: the folder can't be found: ".$ex->getMessage());
		}

		$cache = $config->Get("cache_path");
		$findings[] = empty($cache)
			? self::Finding(self::FOLDERS, TimezoneCheck::WARNING, "Cache: `cache_path` isn't set, so the cache can't be used.")
			: self::FolderFinding("Cache", (string)$cache, TimezoneCheck::WARNING);

		try {
			$findings[] = self::FolderFinding("Backups", Dump::Folder(), TimezoneCheck::WARNING,
				empty($config->Get("backups_path")) ? "`backups_path` isn't set" : null);
		} catch(\Throwable $ex) {
			$findings[] = self::Finding(self::FOLDERS, TimezoneCheck::WARNING, "Backups: the folder can't be found: ".$ex->getMessage());
		}

		// the CLI can't know the web root
		$documentRoot = static::DocumentRoot();
		$documentRoot = $documentRoot ? realpath($documentRoot) : false;
		$configs = realpath($config->GetPath());
		if($documentRoot && $configs) {
			$findings[] = ($configs === $documentRoot || str_starts_with($configs, rtrim($documentRoot, "/")."/"))
				? self::Finding(self::FOLDERS, TimezoneCheck::WARNING, "The configs folder `".$configs."` is under the document root (`".$documentRoot."`): unless the web server blocks it, the config, with the database password, can be downloaded.")
				: self::Finding(self::FOLDERS, TimezoneCheck::OK, "The configs folder is outside the document root.");
		}
		return $findings;
	}

	/**
	* @param 	string 		$name 		folder's name, for the message
	* @param 	string|null $path 		folder
	* @param 	string 		$level 		level when it's missing or not writable
	* @param 	string|null $note 		added to the message (why this path)
	* @return 	array 	finding
	*/
	private static function FolderFinding(string $name, ?string $path, string $level, ?string $note = null): array {
		$at = $name.": `".$path."`".($note ? " (the default: ".$note.")" : "");
		if(empty($path) || !is_dir($path)) {
			return self::Finding(self::FOLDERS, $level, $at." doesn't exist.");
		}
		if(!is_writable($path)) {
			return self::Finding(self::FOLDERS, $level, $at." isn't writable.");
		}
		return self::Finding(self::FOLDERS, TimezoneCheck::OK, $at." exists and is writable.");
	}

	/**
	* Opens the database connection (connecting with the config's credentials first, when nothing set them)
	* @return 	array 	findings
	*/
	protected static function ConnectionFindings(): array {
		try {
			$db = Database::Instance();
			if(method_exists($db, "getDatabaseName") && empty($db->getDatabaseName())) {
				MagratheaPHP::Instance()->Connect();
			}
			$db->OpenConnectionPlease();
		} catch(\Throwable $ex) {
			return [self::Finding(self::DATABASE, TimezoneCheck::ERROR, "Can't connect to the database: ".$ex->getMessage())];
		}
		return [self::Finding(self::DATABASE, TimezoneCheck::OK, "Connected to the database.")];
	}

	/**
	* The admin's tables (`_magrathea_config`): WARNING when they're missing
	* @return 	array 	findings
	*/
	protected static function AdminTableFindings(): array {
		try {
			$installed = (new Schema())->HasTable(self::ADMIN_TABLE);
		} catch(\Throwable $ex) {
			return [self::Finding(self::DATABASE, TimezoneCheck::ERROR, "Can't read the database's tables: ".$ex->getMessage())];
		}
		return [$installed
			? self::Finding(self::DATABASE, TimezoneCheck::OK, "The admin's tables are installed.")
			: self::Finding(self::DATABASE, TimezoneCheck::WARNING, "There's no `".self::ADMIN_TABLE."` table: the admin isn't installed.", ["table" => self::ADMIN_TABLE])];
	}

	/**
	* SchemaCheck::CheckAll(); one ERROR if it throws
	* @return 	array 	findings
	*/
	protected static function SchemaFindings(): array {
		try {
			return (new SchemaCheck())->CheckAll();
		} catch(\Throwable $ex) {
			return [self::Finding(self::SCHEMA, TimezoneCheck::ERROR, "The models can't be checked against the database: ".$ex->getMessage())];
		}
	}

	/**
	* Does the config set every one of `db_host`, `db_name` and `db_user`?
	* @return 	bool
	*/
	protected static function UsesDatabase(): bool {
		return empty(static::MissingDatabaseKeys());
	}

	/**
	* @return 	array 	the DB_KEYS that are empty in the active environment
	*/
	private static function MissingDatabaseKeys(): array {
		$config = Config::Instance();
		return array_values(array_filter(self::DB_KEYS, fn($key) => empty($config->Get($key))));
	}

	/** @return 	string 	PHP's version (overridable in tests) */
	protected static function PhpVersion(): string {
		return PHP_VERSION;
	}
	/** @return 	bool 	is the extension loaded? (overridable in tests) */
	protected static function HasExtension(string $name): bool {
		return extension_loaded($name);
	}
	/** @return 	bool 	does the function exist and isn't disabled? (overridable in tests) */
	protected static function HasFunction(string $name): bool {
		return function_exists($name);
	}
	/** @return 	string|null 	the web root; null in the CLI (overridable in tests) */
	protected static function DocumentRoot(): ?string {
		if(PHP_SAPI === "cli") return null;
		return $_SERVER["DOCUMENT_ROOT"] ?? null;
	}

	/**
	* @param 	array 	$findings 	findings
	* @return 	bool 	is any of them an ERROR?
	*/
	private static function Failed(array $findings): bool {
		foreach($findings as $finding) {
			if($finding["level"] === TimezoneCheck::ERROR) return true;
		}
		return false;
	}

	/**
	* @param 	array 	$findings 	findings without a group
	* @param 	string 	$group 		group
	* @return 	array 	the findings, in the group
	*/
	private static function InGroup(array $findings, string $group): array {
		return array_map(fn($f) => $f + ["group" => $group], $findings);
	}

	/**
	* @param 	string 	$group 		CONFIG, FOLDERS, DATABASE, TIMEZONE or SCHEMA
	* @param 	string 	$level 		TimezoneCheck::OK, INFO, WARNING or ERROR
	* @param 	string 	$message 	message
	* @param 	array 	$extra 		optional keys (`table`, `model`, `field`, `fix`)
	* @return 	array
	*/
	private static function Finding(string $group, string $level, string $message, array $extra = []): array {
		return ["level" => $level, "message" => $message, "group" => $group] + $extra;
	}

}
