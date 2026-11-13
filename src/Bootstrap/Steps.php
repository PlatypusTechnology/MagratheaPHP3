<?php

namespace Magrathea\Bootstrap;

use Magrathea\Admin\CheckReport;
use Magrathea\Admin\DumpManager;
use Magrathea\Admin\Install;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Config;
use Magrathea\ConfigFile;
use Magrathea\DB\Database;
use Magrathea\DB\Dump;
use Magrathea\DB\Schema;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaPHP;
use Magrathea\ProjectCheck;
use Magrathea\TimezoneCheck;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Bootstrap Steps created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* The Setup Wizard's steps, as arrays: what each step shows (`View()`) and what its forms do (`Run()`). The server
* validates everything, and every method refuses a session that `Start::IsVerified()` doesn't accept (`MagratheaException`, code 403). The views only
* render.
*/
class Steps {

	/** the configuration form's fields: key => label */
	const FIELDS = [
		"db_host" => "Database host",
		"db_port" => "Database port",
		"db_name" => "Database name",
		"db_user" => "Database user",
		"db_pass" => "Database password",
		"timezone" => "Timezone",
		"app_url" => "App URL",
		"logs_path" => "Logs folder",
		"cache_path" => "Cache folder",
		"backups_path" => "Backups folder",
		"jwt_key" => "JWT key",
	];
	/** the configuration's database keys, as the Database step shows them */
	const DB_FIELDS = ["db_host", "db_port", "db_name", "db_user", "db_pass"];
	/** a config value that starts with this reads the environment variable named after it (`Config::Get()`) */
	const ENV_PREFIX = "$=";
	/** the first super admin's password, at least */
	const MIN_PASSWORD = 12;
	/** "Generate random password": random bytes, in hex (16 characters), like `admin:create-user` */
	const GENERATED_BYTES = 8;
	/** the environment that may keep `bootstrap_unlocked` after Finish */
	const DEV = "dev";
	/** the super admin's `role_id` (`AdminUser::GetRoles()`): the Admin step lists them and resets their passwords */
	const SUPER_ADMIN_ROLE = 1;

	private Start $start;
	private ?DumpManager $dumps;
	private ?Install $install;
	private ?\Magrathea\Admin\Start $admin;
	private ?AdminUserControl $users;

	/**
	* @param 	Start 						$start 		the wizard
	* @param 	DumpManager|null 			$dumps 		dumps (default: a new one, when it's used)
	* @param 	Install|null 				$install 	the admin's install (default: a new one)
	* @param 	\Magrathea\Admin\Start|null $admin 		the admin (default: its instance), for `CanCreateFirstUser()`
	* @param 	AdminUserControl|null 		$users 		the admin's users (default: a new one), for `SuperAdmins()` and `ResetPassword()`
	*/
	public function __construct(Start $start, ?DumpManager $dumps = null, ?Install $install = null, ?\Magrathea\Admin\Start $admin = null, ?AdminUserControl $users = null) {
		$this->start = $start;
		$this->dumps = $dumps;
		$this->install = $install;
		$this->admin = $admin;
		$this->users = $users;
	}

	/** @return 	Start 	the wizard */
	public function Start(): Start {
		return $this->start;
	}

	/**
	* @throws 	MagratheaException 	the session isn't verified (code 403)
	*/
	protected function RequireVerified(): void {
		if(!$this->start->IsVerified()) throw new MagratheaException("Enter the setup token first.", 403);
	}

	/**
	* What a step shows
	* @param 	string 	$step 	one of `Start::STEPS`
	* @return 	array 	the step's data
	* @throws 	MagratheaException 		not verified (code 403)
	* @throws 	MagratheaException 		unknown step
	*/
	public function View(string $step): array {
		$this->RequireVerified();
		return match($step) {
			"requirements" => $this->Requirements(),
			"configuration" => $this->ConfigurationForm(),
			"structure" => $this->Structure(),
			"database" => $this->Database(),
			"import" => $this->Dumps(),
			"admin" => $this->Admin(),
			"checks" => $this->Checks(),
			"finish" => $this->FinishForm(),
			default => throw new MagratheaException("unknown step: ".$step),
		};
	}

	/**
	* Runs a form's action
	* @param 	string 	$action 	one of `Start::ACTIONS`
	* @param 	array 	$post 		the form
	* @param 	array 	$files 		uploaded files
	* @return 	array 	the action's result
	* @throws 	MagratheaException 		not verified (code 403)
	* @throws 	MagratheaException 		unknown action, or what the action throws
	*/
	public function Run(string $action, array $post, array $files = []): array {
		$this->RequireVerified();
		return match($action) {
			"save-config" => $this->SaveConfiguration($post),
			"connect-database" => $this->ConnectDatabase(),
			"upload-dump" => $this->Upload($files["upload"] ?? null, $post["confirm"] ?? null, $post["driver"] ?? null, ($post["continue"] ?? null) === "1"),
			"paste-sql" => $this->Paste($post["sql"] ?? null, $post["confirm"] ?? null, $post["driver"] ?? null, ($post["continue"] ?? null) === "1"),
			"import-dump" => $this->Import($post["file"] ?? null, $post["confirm"] ?? null, $post["driver"] ?? null, ($post["continue"] ?? null) === "1"),
			"install-admin" => $this->InstallAdmin(),
			"generate-password" => $this->GeneratePassword($post["email"] ?? null),
			"create-admin" => $this->CreateAdmin($post["email"] ?? null, $post["password"] ?? null),
			"reset-password" => $this->ResetPassword($post["id"] ?? null, $post["password"] ?? null),
			"finish" => $this->Finish(($post["keep_unlocked"] ?? null) === "1"),
			default => throw new MagratheaException("unknown action: ".$action),
		};
	}

	// 1. REQUIREMENTS

	/**
	* @return 	array 	["findings" => `ProjectCheck::Requirements()`]
	*/
	public function Requirements(): array {
		$this->RequireVerified();
		return ["findings" => ProjectCheck::Requirements($this->start->ConfigFolder())];
	}

	// 2. CONFIGURATION

	/**
	* The configuration form: the active section's values (maintenance), or the defaults (setup)
	* @return 	array 	["file", "exists", "environment", "environmentEditable" (setup only), "values" (field => value, as
	* 					written in the file: `db_pass` included, and `$=NAME` references unresolved), "timezones", "fields" (FIELDS)]
	*/
	public function ConfigurationForm(): array {
		$this->RequireVerified();
		$exists = $this->start->HasConfig();
		$values = $this->Defaults();
		$environment = self::DEV;
		if($exists) {
			$environment = (string)$this->start->GetEnvironmentName();
			$section = $this->ConfigFile()->GetConfig()[$environment] ?? [];
			foreach(self::FIELDS as $key => $label) {
				if(isset($section[$key]) && $section[$key] !== "") $values[$key] = (string)$section[$key];
			}
		}
		return [
			"file" => $this->start->ConfigFilePath(),
			"exists" => $exists,
			"environment" => $environment,
			"environmentEditable" => !$exists,
			"values" => $values,
			"timezones" => timezone_identifiers_list(),
			"fields" => self::FIELDS,
		];
	}

	/**
	* @return 	array 	the fields' defaults: the folders under the project's root, a random `jwt_key`, PHP's timezone
	*/
	public function Defaults(): array {
		$root = $this->start->GetMagRoot();
		$values = array_fill_keys(array_keys(self::FIELDS), "");
		$values["timezone"] = date_default_timezone_get();
		$values["logs_path"] = $root."/logs";
		$values["cache_path"] = $root."/cache";
		$values["backups_path"] = $root."/backups";
		$values["jwt_key"] = bin2hex(random_bytes(32));
		return $values;
	}

	/**
	* Validates the form and writes `configs/magrathea.conf` (`ConfigFile::Save()`). Setup writes `[general]`
	* (`use_environment`) and the environment's section; maintenance edits only the active section. The section always
	* gets `bootstrap_unlocked = true`, so the next steps can open: Finish removes it outside `dev`.
	* @param 	array 	$input 	the form: "environment" (setup only) and the FIELDS, stored as typed (`db_pass` isn't trimmed;
	* 					empty is no password)
	* @return 	array 	["saved" => bool, "errors" => [field => message], "file", "environment",
	* 					"values" => what was typed, trimmed (but `db_pass`)]
	*/
	public function SaveConfiguration(array $input): array {
		$this->RequireVerified();
		$exists = $this->start->HasConfig();
		$errors = [];
		$environment = $exists ? (string)$this->start->GetEnvironmentName() : trim((string)($input["environment"] ?? ""));
		if(!$exists && !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $environment)) {
			$errors["environment"] = "Letters, digits, `_` and `-`, starting with a letter.";
		} else if(strtolower($environment) === "general") {
			$errors["environment"] = "`general` is the config's own section.";
		}

		$all = $exists ? $this->ConfigFile()->GetConfig() : [];
		$current = $all[$environment] ?? [];
		$values = [];
		foreach(self::FIELDS as $key => $label) {
			$value = $input[$key] ?? "";
			if(!is_string($value)) {
				$errors[$key] = "Invalid value.";
				continue;
			}
			$value = $key === "db_pass" ? $value : trim($value);
			if(!ConfigFile::IsStorable($value)) {
				$errors[$key] = "Can't be stored as written: no `\"`, line breaks or `\${…}`; `yes`, `no`, `true`, `false` and `0` read back as booleans.";
				continue;
			}
			$values[$key] = $value;
		}
		if(!isset($errors["db_port"]) && ($values["db_port"] ?? "") !== ""
			&& (!ctype_digit($values["db_port"]) || (int)$values["db_port"] < 1 || (int)$values["db_port"] > 65535)) {
			$errors["db_port"] = "A port number (1-65535), or empty for the default.";
		}
		if(!isset($errors["timezone"]) && !in_array($values["timezone"] ?? "", timezone_identifiers_list(), true)) {
			$errors["timezone"] = "Choose a timezone from the list.";
		}
		if(!isset($errors["app_url"]) && ($values["app_url"] ?? "") !== "" && !preg_match('#^https?://#i', $values["app_url"])) {
			$errors["app_url"] = "A URL starting with http:// or https://.";
		}
		$dbKeys = array_filter(ProjectCheck::DB_KEYS, fn($key) => ($values[$key] ?? "") !== "");
		if($dbKeys && count($dbKeys) < count(ProjectCheck::DB_KEYS)) {
			foreach(array_diff(ProjectCheck::DB_KEYS, $dbKeys) as $key) {
				$errors[$key] = $errors[$key] ?? "Fill in the host, the name and the user, or none (a project without a database).";
			}
		}
		// what the form shows again after a refused save
		$typed = [];
		foreach(self::FIELDS as $key => $label) {
			if(is_string($input[$key] ?? null)) $typed[$key] = $key === "db_pass" ? $input[$key] : trim($input[$key]);
		}
		$result = ["saved" => false, "errors" => $errors, "file" => $this->start->ConfigFilePath(), "environment" => $environment, "values" => $typed];
		if($errors) return $result;

		$general = $all["general"] ?? [];
		if(!$exists) $general["use_environment"] = $environment;
		$section = array_merge($current, $values);
		$section["bootstrap_unlocked"] = "true";
		$all = ["general" => $general] + $all;
		$all[$environment] = $section;
		$this->ConfigFile()->SetConfig($all)->Save();
		$this->start->ReloadConfig();
		$result["saved"] = true;
		return $result;
	}

	// 3. STRUCTURE

	/**
	* The project's folders, without connecting: `ProjectCheck::Folders()` (logs, cache and backups exist and are
	* writable; the configs folder outside the document root)
	* @return 	array 	["needsConfig" => bool, "findings" => findings]
	*/
	public function Structure(): array {
		$this->RequireVerified();
		$rs = ["needsConfig" => !$this->start->HasConfig(), "findings" => []];
		if($rs["needsConfig"]) return $rs;
		$rs["findings"] = ProjectCheck::Folders();
		return $rs;
	}

	// 4. DATABASE

	/**
	* The database's credentials, as the config has them, without connecting (`ConnectDatabase()` does). The
	* password is shown too: only a verified session sees the wizard.
	* @return 	array 	["needsConfig" => bool, "configured" => bool (db_host, db_name and db_user are set), "database", "host",
	* 					"credentials" => [["key", "label", "raw" => as written in the file (null: missing), "env" => the
	* 					environment variable's name when `raw` is `$=NAME`, else null, "value" => what `Config::Get()` reads
	* 					(null: missing, or an unset variable)]], "error"]
	*/
	public function Database(): array {
		$this->RequireVerified();
		$rs = ["needsConfig" => !$this->start->HasConfig(), "configured" => false,
			"database" => null, "host" => null, "credentials" => [], "error" => null];
		if($rs["needsConfig"]) return $rs;
		try {
			$section = $this->ConfigFile()->GetConfig()[(string)$this->start->GetEnvironmentName()] ?? [];
			foreach(self::DB_FIELDS as $key) {
				$raw = array_key_exists($key, $section) ? (string)$section[$key] : null;
				$value = Config::Instance()->Get($key);
				$rs["credentials"][] = [
					"key" => $key,
					"label" => self::FIELDS[$key],
					"raw" => $raw,
					"env" => ($raw !== null && str_starts_with($raw, self::ENV_PREFIX)) ? substr($raw, strlen(self::ENV_PREFIX)) : null,
					"value" => $value === null ? null : (string)$value,
				];
			}
		} catch(\Throwable $ex) {
			$rs["error"] = $ex->getMessage();
			return $rs;
		}
		$rs["configured"] = $this->UsesDatabase();
		$rs["database"] = (string)Config::Instance()->Get("db_name");
		$rs["host"] = (string)Config::Instance()->Get("db_host");
		return $rs;
	}

	/**
	* Connects with the config's values, runs `TimezoneCheck::Run()` and lists the tables: what the Database step
	* shows after its "Connect" button (opening the step doesn't connect, so a slow server doesn't hold the page)
	* @return 	array 	["connected" => bool, "error", "timezone" => findings, "tables" => `Schema::Tables()`]
	* @throws 	MagratheaException 	no config, or it has no database
	*/
	public function ConnectDatabase(): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$rs = ["connected" => false, "error" => null, "timezone" => [], "tables" => []];
		try {
			$db = $this->Connect();
		} catch(\Throwable $ex) {
			$rs["error"] = $ex->getMessage();
			return $rs;
		}
		$rs["connected"] = true;
		$rs["timezone"] = TimezoneCheck::Run();
		try {
			$rs["tables"] = (new Schema($db))->Tables();
		} catch(\Throwable $ex) {
			$rs["error"] = "Can't list the tables: ".$ex->getMessage();
		}
		return $rs;
	}

	// 5. IMPORT

	/**
	* @return 	array 	["needsConfig", "configured", "folder", "database", "files" => `DumpManager::Files()`,
	* 					"temporary" (no writable backups folder: an upload or a paste runs from a temporary file), "error"]
	*/
	public function Dumps(): array {
		$this->RequireVerified();
		$rs = ["needsConfig" => !$this->start->HasConfig(), "configured" => false, "folder" => null, "database" => null, "files" => [], "temporary" => true, "error" => null];
		if($rs["needsConfig"]) return $rs;
		$rs["configured"] = $this->UsesDatabase();
		$rs["database"] = (string)Config::Instance()->Get("db_name");
		$rs["temporary"] = $this->Dumper()->WritableFolder() === null;
		try {
			$rs["folder"] = Dump::Folder();
			if(is_dir($rs["folder"])) $rs["files"] = $this->Dumper()->Files();
		} catch(\Throwable $ex) {
			$rs["error"] = $ex->getMessage();
		}
		return $rs;
	}

	/**
	* Saves an uploaded `.sql` and imports it (`DumpManager::ImportUpload()`): only when it's confirmed with the database's name
	* @param 	mixed 	$upload 	one `$_FILES` entry
	* @param 	mixed 	$confirm 	the database's name, typed (empty: asks for it)
	* @param 	mixed 	$driver 	`binary`, `php`, or empty (binary when available)
	* @param 	bool 	$continue 	don't stop at the first error
	* @return 	array 	["import" => `DumpManager::ImportUpload()`'s result, "firstError", "source" => "upload"]
	* @throws 	MagratheaException 	no database, see `DumpManager::ImportUpload()`
	*/
	public function Upload($upload, $confirm = null, $driver = null, bool $continue = false): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$this->Connect();
		return $this->ImportResult($this->Dumper()->ImportUpload($upload, $confirm, ($driver === "" ? null : $driver), $continue)) + ["source" => "upload"];
	}

	/**
	* Saves pasted SQL and imports it (`DumpManager::ImportSql()`): only when it's confirmed with the database's name
	* @param 	mixed 	$sql 		the statements
	* @param 	mixed 	$confirm 	the database's name, typed (empty: asks for it)
	* @param 	mixed 	$driver 	`binary`, `php`, or empty (binary when available)
	* @param 	bool 	$continue 	don't stop at the first error
	* @return 	array 	["import" => `DumpManager::ImportSql()`'s result, "firstError", "source" => "paste",
	* 					"sql" => the statements, to fill the form again, when it wasn't confirmed]
	* @throws 	MagratheaException 	no database, see `DumpManager::ImportSql()`
	*/
	public function Paste($sql, $confirm = null, $driver = null, bool $continue = false): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$this->Connect();
		$rs = $this->ImportResult($this->Dumper()->ImportSql($sql, $confirm, ($driver === "" ? null : $driver), $continue)) + ["source" => "paste"];
		if($rs["import"]["status"] === DumpManager::CONFIRM) $rs["sql"] = (string)$sql;
		return $rs;
	}

	/**
	* Imports a dump of the backups folder (`DumpManager::Import()`): only when it's confirmed with the database's name
	* @param 	mixed 	$file 		file name
	* @param 	mixed 	$confirm 	the database's name, typed (empty: asks for it)
	* @param 	mixed 	$driver 	`binary`, `php`, or empty (binary when available)
	* @param 	bool 	$continue 	don't stop at the first error
	* @return 	array 	["import" => `DumpManager::Import()`'s result, "firstError" => the first error with `continue`, or null]
	* @throws 	MagratheaException 	no database, see `DumpManager::Import()` (without `continue`, the first error)
	*/
	public function Import($file, $confirm = null, $driver = null, bool $continue = false): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$this->Connect();
		return $this->ImportResult($this->Dumper()->Import($file, $confirm, ($driver === "" ? null : $driver), $continue));
	}

	/** an import's result, and its first error (with `continue`) as one line */
	private function ImportResult(array $import): array {
		$errors = $import["result"]["errors"] ?? [];
		$first = $errors ? reset($errors) : null;
		if(is_array($first)) $first = (isset($first["statement"]) ? "statement ".$first["statement"].": " : "").($first["error"] ?? "");
		return ["import" => $import, "firstError" => $first];
	}

	// 6. ADMIN

	/**
	* @return 	array 	["needsConfig", "configured", "connected", "installed" (`_magrathea_config` exists),
	* 					"canCreateFirstUser" (`Admin\Start::CanCreateFirstUser()`), "superAdmins" (`SuperAdmins()`, once
	* 					there are users), "error"]
	*/
	public function Admin(): array {
		$this->RequireVerified();
		$rs = ["needsConfig" => !$this->start->HasConfig(), "configured" => false, "connected" => false,
			"installed" => false, "canCreateFirstUser" => false, "superAdmins" => [], "error" => null];
		if($rs["needsConfig"] || !$this->UsesDatabase()) return $rs;
		$rs["configured"] = true;
		try {
			$db = $this->Connect();
			$rs["connected"] = true;
			$rs["installed"] = (new Schema($db))->HasTable(ProjectCheck::ADMIN_TABLE);
		} catch(\Throwable $ex) {
			$rs["error"] = $ex->getMessage();
			return $rs;
		}
		$rs["canCreateFirstUser"] = $rs["installed"] && $this->AdminStart()->CanCreateFirstUser();
		if($rs["installed"] && !$rs["canCreateFirstUser"]) {
			try {
				$rs["superAdmins"] = $this->SuperAdmins();
			} catch(\Throwable $ex) {
				$rs["error"] = $ex->getMessage();
			}
		}
		return $rs;
	}

	/**
	* The admin's super admins (`role_id` = `SUPER_ADMIN_ROLE`), inactive ones included, by id
	* @return 	array 	[["id" => int, "email", "active" => bool, "last_login" => string|null]]
	* @throws 	\Magrathea\Exceptions\MagratheaDBException
	*/
	public function SuperAdmins(): array {
		$this->RequireVerified();
		$users = $this->Users()->GetWhere(["role_id" => self::SUPER_ADMIN_ROLE]);
		$list = array_map(fn(AdminUser $user) => [
			"id" => (int)$user->id,
			"email" => (string)$user->email,
			"active" => !empty($user->active),
			"last_login" => $user->last_login ? (string)$user->last_login : null,
		], $users);
		usort($list, fn($a, $b) => $a["id"] <=> $b["id"]);
		return $list;
	}

	/**
	* Sets a super admin's password (`AdminUserControl::SetNewPassword()`, which logs `change-password`), like
	* `admin:reset-password`. An empty password generates one (`GENERATED_BYTES` random bytes, in hex), given back
	* once. It refuses users that aren't super admins, and doesn't activate an inactive one
	* @param 	mixed 	$id 		the user's id
	* @param 	mixed 	$password 	at least MIN_PASSWORD characters; empty (or missing) generates one
	* @return 	array 	["reset" => bool, "errors" => [field => message], "id", "email", "password" (the new one, or the
	* 					typed one given back on errors), "generated" => bool, "active" => bool]
	* @throws 	MagratheaException 	no database, the user isn't a super admin, or the password wasn't saved
	*/
	public function ResetPassword($id, $password): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$this->Connect();
		$id = filter_var($id, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
		$user = null;
		if($id !== false) {
			foreach($this->Users()->GetWhere(["id" => $id, "role_id" => self::SUPER_ADMIN_ROLE]) as $found) $user = $found;
		}
		if(!$user) throw new MagratheaException("There's no super admin with that id.");
		$typed = is_string($password) ? $password : "";
		$generated = $typed === "";
		$password = $generated ? bin2hex(random_bytes(self::GENERATED_BYTES)) : $typed;
		$rs = ["reset" => false, "errors" => [], "id" => (int)$user->id, "email" => (string)$user->email,
			"password" => $password, "generated" => $generated, "active" => !empty($user->active)];
		if(mb_strlen($password) < self::MIN_PASSWORD) {
			$rs["errors"]["password"] = "At least ".self::MIN_PASSWORD." characters, or leave it empty to generate one.";
			return $rs;
		}
		$saved = $this->Users()->SetNewPassword($user, $password);
		if(!empty($saved["error"])) throw new MagratheaException($saved["error"]);
		if(empty($saved["success"])) throw new MagratheaException("The password for ".$user->email." couldn't be saved.");
		$rs["reset"] = true;
		return $rs;
	}

	/**
	* Creates the admin's tables (`Install::InstallDatabase()`), only when `_magrathea_config` is missing
	* @return 	array 	["queries" => how many ran, "errors" => [["query", "error"]]]
	* @throws 	MagratheaException 	no database, or the tables already exist
	*/
	public function InstallAdmin(): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$db = $this->Connect();
		if((new Schema($db))->HasTable(ProjectCheck::ADMIN_TABLE)) {
			throw new MagratheaException("The admin's tables are already installed.");
		}
		$rows = $this->Installer()->InstallDatabase() ?: [];
		$errors = [];
		foreach($rows as $row) {
			if(empty($row["success"])) $errors[] = ["query" => (string)($row["query"] ?? ""), "error" => (string)($row["result"] ?? "")];
		}
		return ["queries" => count($rows), "errors" => $errors];
	}

	/**
	* "Generate random password" on the first super admin's form: a password of `GENERATED_BYTES` random bytes,
	* in hex, to fill the form with (nothing is created). The pages have no JavaScript: the form posts here
	* @param 	mixed 	$email 		the e-mail typed so far, given back
	* @return 	array 	["created" => false, "errors" => [], "email", "password"]
	*/
	public function GeneratePassword($email): array {
		$this->RequireVerified();
		$email = is_string($email) ? trim($email) : "";
		return ["created" => false, "errors" => [], "email" => $email, "password" => bin2hex(random_bytes(self::GENERATED_BYTES))];
	}

	/**
	* Creates the first super admin (`Install::CreateFirstUser()`), only when `Admin\Start::CanCreateFirstUser()`
	* allows it (there are no users yet)
	* @param 	mixed 	$email 		the e-mail
	* @param 	mixed 	$password 	at least MIN_PASSWORD characters
	* @return 	array 	["created" => bool, "errors" => [field => message], "email", "password" (given back on errors)]
	* @throws 	MagratheaException 	no database, or there are users already
	*/
	public function CreateAdmin($email, $password): array {
		$this->RequireVerified();
		$this->RequireDatabase();
		$this->Connect();
		$email = is_string($email) ? trim($email) : "";
		$errors = [];
		if(filter_var($email, FILTER_VALIDATE_EMAIL) === false) $errors["email"] = "A valid e-mail.";
		if(!is_string($password) || mb_strlen($password) < self::MIN_PASSWORD) {
			$errors["password"] = "At least ".self::MIN_PASSWORD." characters.";
		}
		if($errors) return ["created" => false, "errors" => $errors, "email" => $email, "password" => is_string($password) ? $password : ""];
		if(!$this->AdminStart()->CanCreateFirstUser()) {
			throw new MagratheaException("The admin already has users: the first one is created only once. Reset a super admin's password below, or with `vendor/bin/magrathea admin:reset-password <email>`.");
		}
		$this->Installer()->CreateFirstUser($email, $password);
		return ["created" => true, "errors" => [], "email" => $email];
	}

	// 7. CHECKS

	/**
	* @return 	array 	`CheckReport::Group(ProjectCheck::Run())`: every check, the schema's timestamps and drift with their fix SQL
	*/
	public function Checks(): array {
		$this->RequireVerified();
		return CheckReport::Group(ProjectCheck::Run());
	}

	// 8. FINISH

	/**
	* @return 	array 	["environment", "unlocked" => the sections with `bootstrap_unlocked`,
	* 					"canKeepDev" (`[dev]` is one of them: it may keep the flag), "tokenFile"]
	*/
	public function FinishForm(): array {
		$this->RequireVerified();
		$unlocked = $this->UnlockedSections();
		return [
			"environment" => $this->start->GetEnvironmentName(),
			"unlocked" => $unlocked,
			"canKeepDev" => in_array(self::DEV, $unlocked, true),
			"tokenFile" => $this->start->Token()->Path(),
		];
	}

	/**
	* Ends the setup: removes `bootstrap_unlocked` from every section but `dev` (and from `dev` too, unless it's kept),
	* deletes the token and forgets the session
	* @param 	bool 	$keepDevUnlocked 	keep the flag in `[dev]`
	* @return 	array 	["removed" => sections the flag was removed from, "kept" => sections that still have it,
	* 					"tokenDeleted" => bool, "tokenFile"]
	*/
	public function Finish(bool $keepDevUnlocked = false): array {
		$this->RequireVerified();
		$removed = [];
		$kept = [];
		if($this->start->HasConfig()) {
			$file = $this->ConfigFile();
			$all = $file->GetConfig();
			foreach($all as $section => $values) {
				if($section === "general" || !is_array($values) || !array_key_exists("bootstrap_unlocked", $values)) continue;
				if($section === self::DEV && $keepDevUnlocked && filter_var($values["bootstrap_unlocked"], FILTER_VALIDATE_BOOLEAN)) {
					$kept[] = $section;
					continue;
				}
				unset($all[$section]["bootstrap_unlocked"]);
				$removed[] = $section;
			}
			if($removed) {
				$file->SetConfig($all)->Save();
				$this->start->ReloadConfig();
			}
		}
		$token = $this->start->Token();
		$deleted = $token->Delete();
		$this->start->EndSession();
		return ["removed" => $removed, "kept" => $kept, "tokenDeleted" => $deleted, "tokenFile" => $token->Path()];
	}

	// HELPERS

	/**
	* @return 	string[] 	the config's sections with `bootstrap_unlocked` set to a true value
	*/
	private function UnlockedSections(): array {
		if(!$this->start->HasConfig()) return [];
		$sections = [];
		foreach($this->ConfigFile()->GetConfig() as $section => $values) {
			if($section === "general" || !is_array($values)) continue;
			if(filter_var($values["bootstrap_unlocked"] ?? null, FILTER_VALIDATE_BOOLEAN)) $sections[] = $section;
		}
		return $sections;
	}

	/** @return 	ConfigFile 	the wizard's `magrathea.conf` */
	protected function ConfigFile(): ConfigFile {
		return (new ConfigFile())->SetPath($this->start->ConfigFolder())->SetFile("magrathea.conf");
	}

	/** @return 	bool 	the active environment sets `db_host`, `db_name` and `db_user` */
	protected function UsesDatabase(): bool {
		try {
			foreach(ProjectCheck::DB_KEYS as $key) {
				if(empty(Config::Instance()->Get($key))) return false;
			}
		} catch(\Throwable $ex) {
			return false;
		}
		return true;
	}

	/** @throws 	MagratheaException 	there's no config yet */
	private function RequireConfig(): void {
		if(!$this->start->HasConfig()) throw new MagratheaException("Save the configuration first.");
	}
	/** @throws 	MagratheaException 	there's no config, or it has no database */
	private function RequireDatabase(): void {
		$this->RequireConfig();
		if(!$this->UsesDatabase()) throw new MagratheaException("The configuration has no database (`db_host`, `db_name` and `db_user`).");
	}

	/**
	* Connects with the config's credentials (`MagratheaPHP::Connect()`) and opens the connection
	* @return 	Database
	* @throws 	\Throwable 	the connection failed
	*/
	protected function Connect(): Database {
		MagratheaPHP::Instance()->Connect();
		$db = Database::Instance();
		$db->OpenConnectionPlease();
		return $db;
	}

	/** @return 	DumpManager */
	protected function Dumper(): DumpManager {
		return $this->dumps ?? new DumpManager();
	}
	/** @return 	Install */
	protected function Installer(): Install {
		return $this->install ?? new Install();
	}
	/** @return 	\Magrathea\Admin\Start */
	protected function AdminStart(): \Magrathea\Admin\Start {
		return $this->admin ?? \Magrathea\Admin\Start::Instance();
	}
	/** @return 	AdminUserControl */
	protected function Users(): AdminUserControl {
		return $this->users ??= new AdminUserControl();
	}

}
