<?php

namespace Magrathea\DB;

use Magrathea\Config;
use Magrathea\Exceptions\MagratheaConfigException;
use Magrathea\Exceptions\MagratheaDBConnectionLostException;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaHelper;
use Magrathea\MagratheaPHP;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Dump created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* Database dumps: exports the database to a `.sql` file in the backups folder (`backups_path`), and imports one.
* Two drivers:
* - `binary`: `mysqldump` and `mysql`, run without a shell, with the credentials in a temporary defaults file
*   (never on the command line). The default when `proc_open()` exists and the program answers `--version`.
* - `php`: the fallback, through the connection. It exports tables, rows, views and triggers (not routines),
*   and imports any file statement by statement (`SqlSplitter`), without holding it in memory.
*
* The binary driver reads the credentials from the config (`db_host`, `db_port`, `db_name`, `db_user`, `db_pass`);
* the php driver uses the `Database` it's given.
*/
class Dump {

	const BINARY = "binary";
	const PHP = "php";

	/** the php driver starts a new INSERT when one passes this size (bytes) */
	const INSERT_BYTES = 1048576;
	/** column base types the php driver writes as `0x…` */
	const BINARY_TYPES = [
		"binary", "varbinary", "tinyblob", "blob", "mediumblob", "longblob", "bit",
		"geometry", "point", "linestring", "polygon", "multipoint", "multilinestring", "multipolygon",
		"geometrycollection", "geomcollection",
	];

	/** @var Database|DatabaseSimulate */
	private $db;
	private Schema $schema;
	/** program => whether it answered `--version` */
	private array $available = [];

	/**
	* @param 	Database|null 	$db 	database to dump (default: `Database::Instance()`)
	*/
	public function __construct(?Database $db = null) {
		$this->db = $db ?? Database::Instance();
		$this->schema = new Schema($this->db);
	}

	/**
	* The backups folder: `backups_path`, or `<magRoot>/backups` when it's not set.
	* @return 	string 		folder path
	* @throws 	MagratheaConfigException 	`backups_path` isn't set and the Magrathea root is unknown
	*/
	public static function Folder(): string {
		try {
			$folder = Config::Instance()->Get("backups_path");
		} catch(MagratheaConfigException $ex) {
			$folder = null;
		}
		if(empty($folder)) {
			$magRoot = MagratheaPHP::Instance()->magRoot;
			if(!$magRoot) throw new MagratheaConfigException("backups_path is invalid and magrathea root is null");
			$folder = $magRoot."/backups";
		}
		return (string)$folder;
	}

	/**
	* Gets the default file name ("backup_[date].sql")
	*/
	public static function GetDefaultFileName(): string {
		return "backup_".date("Ymd").".sql";
	}

	/**
	* Validates a backup file name: `.sql` is appended when it's missing, then it must be a plain name
	* (`^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$`, no `/`, `\` or `..`), so it can't leave the backups folder.
	* @param 	mixed 	$name		file name
	* @return 	string					file name, ending with `.sql`
	* @throws 	MagratheaException	invalid file name
	*/
	public static function ValidateBackupFileName($name): string {
		if(!is_string($name)) throw new MagratheaException("invalid file name");
		if(!str_ends_with($name, ".sql")) $name .= ".sql";
		if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/', $name) || str_contains($name, "..")) {
			throw new MagratheaException("invalid file name");
		}
		return $name;
	}

	/**
	* Exports the database to `<backups folder>/<file>`. An existing file is never overwritten; a failed export
	* removes its partial file.
	* @param 	mixed 	$file 		file name (see `ValidateBackupFileName()`)
	* @param 	array 	$options 	"tables" (names: only those), "driver" (`binary` or `php`; default: binary when available)
	* @return 	array 	["file" => file name, "size" => bytes, "driver"], plus "skipped" (what the php driver couldn't export:
	* 					"PROCEDURE name", "FUNCTION name") when there's any
	* @throws 	MagratheaException 	invalid name or options, existing file, unknown table, or the export failed
	*/
	public function Export($file, array $options = []): array {
		$file = self::ValidateBackupFileName($file);
		$destination = self::Path($file);
		if(file_exists($destination)) throw new MagratheaException("file already exists");
		$tables = array_key_exists("tables", $options) ? $this->RequireTables($options["tables"]) : null;
		$driver = $this->Driver($options["driver"] ?? null, "mysqldump");
		$skipped = [];
		if($driver === self::BINARY) $this->ExportBinary($destination, $tables ?? []);
		else $skipped = $this->ExportPhp($destination, $tables);
		clearstatcache(true, $destination);
		$rs = ["file" => $file, "size" => filesize($destination), "driver" => $driver];
		if($skipped) $rs["skipped"] = $skipped;
		return $rs;
	}

	/**
	* Imports `<backups folder>/<file>`. It stops at the first error, unless "continue" is set.
	* @param 	mixed 	$file 		file name (see `ValidateBackupFileName()`)
	* @param 	array 	$options 	"driver" (`binary` or `php`; default: binary when available), "continue" (bool: don't stop at errors)
	* @return 	array 	["driver", "statements" (how many ran: php driver only, null with binary)],
	* 					plus "errors" with "continue" (php: ["statement" => number, "error"]; binary: mysql's error lines)
	* @throws 	MagratheaException 	invalid name or options, no such file, or (without "continue") a failing statement:
	* 					with php, a MagratheaDBException naming the statement's number, with MySQL's errno as its code
	*/
	public function Import($file, array $options = []): array {
		$file = self::ValidateBackupFileName($file);
		$path = self::Path($file);
		if(!is_file($path)) throw new MagratheaException("file not found: ".$file);
		$driver = $this->Driver($options["driver"] ?? null, "mysql");
		$continue = !empty($options["continue"]);
		$rs = $driver === self::BINARY ? $this->ImportBinary($path, $continue) : $this->ImportPhp($path, $continue);
		return ["driver" => $driver] + $rs;
	}

	private static function Path(string $file): string {
		return MagratheaHelper::EnsureTrailingSlash(self::Folder()).$file;
	}

	/**
	* @param 	string|null 	$driver 	the "driver" option
	* @param 	string 		$program 	what the binary driver runs
	* @return 	string 		the driver to use
	* @throws 	MagratheaException 	unknown driver, or binary asked for and not available
	*/
	private function Driver($driver, string $program): string {
		if($driver === null) return $this->BinaryAvailable($program) ? self::BINARY : self::PHP;
		if($driver === self::PHP) return self::PHP;
		if($driver !== self::BINARY) throw new MagratheaException("invalid driver: use \"binary\" or \"php\"");
		if(!$this->BinaryAvailable($program)) {
			throw new MagratheaException($program." is not available (no proc_open(), or it doesn't answer --version): use the php driver");
		}
		return self::BINARY;
	}

	private function BinaryAvailable(string $program): bool {
		if(!array_key_exists($program, $this->available)) {
			try {
				$this->available[$program] = function_exists("proc_open") && $this->RunProcess([$program, "--version"])["code"] === 0;
			} catch(\Throwable $ex) {
				$this->available[$program] = false;
			}
		}
		return $this->available[$program];
	}

	/**
	* @param 	mixed 	$tables 	the "tables" option
	* @return 	string[] 	the names as the database has them
	* @throws 	MagratheaException 	not a list of names, or an unknown table
	*/
	private function RequireTables($tables): array {
		if(!is_array($tables) || !$tables) throw new MagratheaException("\"tables\" must be a list of table names");
		$known = array_column($this->schema->Tables(), "name");
		$names = [];
		foreach($tables as $table) {
			$name = null;
			if(is_string($table)) {
				$name = in_array($table, $known, true) ? $table : null;
				foreach($known as $k) {
					if($name === null && strcasecmp($k, $table) === 0) $name = $k;
				}
			}
			if($name === null) throw new MagratheaDBException("Unknown table [".(is_string($table) ? $table : gettype($table))."]");
			$names[] = $name;
		}
		return array_values(array_unique($names));
	}


	// BINARY DRIVER

	private function ExportBinary(string $destination, array $tables): void {
		$this->WithDefaultsFile(function($defaultsFile) use($destination, $tables) {
			// --defaults-extra-file must be the first option
			$rs = $this->RunProcess(array_merge([
				"mysqldump",
				"--defaults-extra-file=".$defaultsFile,
				"--single-transaction",
				"--routines",
				"--triggers",
				"--default-character-set=utf8mb4",
				"--result-file=".$destination,
				(string)Config::Instance()->Get("db_name"),
			], $tables));
			if($rs["code"] !== 0) {
				if(file_exists($destination)) unlink($destination);
				throw new MagratheaException("mysqldump failed (exit code ".$rs["code"]."): ".trim($rs["stderr"]));
			}
		});
	}

	private function ImportBinary(string $path, bool $continue): array {
		return $this->WithDefaultsFile(function($defaultsFile) use($path, $continue) {
			// --defaults-extra-file must be the first option
			$command = ["mysql", "--defaults-extra-file=".$defaultsFile];
			if($continue) $command[] = "--force";
			$command[] = (string)Config::Instance()->Get("db_name");
			$rs = $this->RunProcess($command, $path);
			if(!$continue) {
				if($rs["code"] !== 0) throw new MagratheaException("mysql failed (exit code ".$rs["code"]."): ".trim($rs["stderr"]));
				return ["statements" => null];
			}
			$errors = array_values(array_filter(array_map("trim", explode("\n", $rs["stderr"])), "strlen"));
			if($rs["code"] !== 0 && !$errors) $errors[] = "mysql failed (exit code ".$rs["code"].")";
			return ["statements" => null, "errors" => $errors];
		});
	}

	/**
	* Runs $fn with the path of a temporary `[client]` defaults file holding the config's credentials (0600, deleted afterwards).
	* @return 	mixed 	what $fn returns
	*/
	private function WithDefaultsFile(callable $fn) {
		$config = Config::Instance();
		$defaultsFile = tempnam(sys_get_temp_dir(), "magrathea-dump-");
		if($defaultsFile === false) throw new MagratheaException("could not create the mysql credentials file");
		try {
			chmod($defaultsFile, 0600);
			file_put_contents($defaultsFile, self::GetDefaultsFileContent([
				"user" => $config->Get("db_user"),
				"password" => $config->Get("db_pass"),
				"host" => $config->Get("db_host"),
				"port" => $config->Get("db_port"),
			]));
			return $fn($defaultsFile);
		} finally {
			if(file_exists($defaultsFile)) unlink($defaultsFile);
		}
	}

	/**
	* Builds a MySQL option file's `[client]` section. Empty values are left out.
	* Backslashes are doubled and values are quoted with `"`, or with `'` when they contain a `"`.
	* @param 	array 	$values		[ option => value ]
	* @return 	string		file content
	* @throws 	MagratheaException	a value with both quote characters or a line break
	*/
	private static function GetDefaultsFileContent(array $values): string {
		$content = "[client]\n";
		foreach($values as $option => $value) {
			if($value === null || $value === "") continue;
			$value = (string)$value;
			if(str_contains($value, "\n") || str_contains($value, "\r")) {
				throw new MagratheaException("the database ".$option." can't contain a line break for mysqldump");
			}
			$quote = str_contains($value, '"') ? "'" : '"';
			if($quote === "'" && str_contains($value, "'")) {
				throw new MagratheaException("the database ".$option." can't contain both quote characters for mysqldump");
			}
			$content .= $option."=".$quote.str_replace("\\", "\\\\", $value).$quote."\n";
		}
		return $content;
	}

	/**
	* Runs a command without a shell (`proc_open()` with an argument array).
	* stdout and stderr are read together, so a process that fills one of them never hangs.
	* @param 	array 	$command		program and arguments
	* @param 	string|null 	$stdinFile 	file given to the process as its stdin
	* @return 	array		["code" => exit code, "stdout" => string, "stderr" => string]
	* @throws 	MagratheaException	the process couldn't start
	*/
	protected function RunProcess(array $command, ?string $stdinFile = null): array {
		$descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
		if($stdinFile !== null) $descriptors[0] = ["file", $stdinFile, "r"];
		$pipes = [];
		$process = @proc_open($command, $descriptors, $pipes);
		if(!is_resource($process)) throw new MagratheaException("could not run ".$command[0]);
		$output = [1 => "", 2 => ""];
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		foreach($open as $pipe) stream_set_blocking($pipe, false);
		while($open) {
			$read = array_values($open);
			$write = null;
			$except = null;
			if(@stream_select($read, $write, $except, null) === false) break;
			foreach($open as $n => $pipe) {
				if(!in_array($pipe, $read, true)) continue;
				$chunk = fread($pipe, 65536);
				if($chunk !== false) $output[$n] .= $chunk;
				if(feof($pipe)) {
					fclose($pipe);
					unset($open[$n]);
				}
			}
		}
		foreach($open as $pipe) fclose($pipe);
		return [ "code" => proc_close($process), "stdout" => $output[1], "stderr" => $output[2] ];
	}


	// PHP DRIVER

	/**
	* @param 	string 		$destination 	file to create
	* @param 	array|null 	$tables 	only these (null: everything, and the routines are reported)
	* @return 	string[] 	what was skipped
	*/
	private function ExportPhp(string $destination, ?array $tables): array {
		$session = $this->SessionState();
		$handle = @fopen($destination, "xb");
		if(!$handle) throw new MagratheaException("could not create the dump file [".$destination."]");
		$done = false;
		try {
			// TIMESTAMP values are read (and written back by the file's header) in UTC, so they round-trip exactly
			$this->db->Query("SET time_zone = '+00:00'");
			$selected = array_filter($this->schema->Tables(), fn($t) => $tables === null || in_array($t["name"], $tables, true));
			$baseTables = array_column(array_filter($selected, fn($t) => $t["type"] !== "VIEW"), "name");
			$views = array_column(array_filter($selected, fn($t) => $t["type"] === "VIEW"), "name");

			$this->Write($handle,
				"-- Magrathea dump (php driver)\n".
				"-- database: ".self::QuoteName((string)$this->db->getDatabaseName()).", ".date("Y-m-d H:i:s")."\n\n".
				"SET NAMES utf8mb4;\n".
				"SET time_zone = '+00:00';\n".
				"SET FOREIGN_KEY_CHECKS = 0;\n");
			foreach($baseTables as $table) $this->ExportTable($handle, $table);
			foreach($views as $view) {
				$this->Write($handle, "\n--\n-- View ".self::QuoteName($view)."\n--\n\n".
					"DROP VIEW IF EXISTS ".self::QuoteName($view).";\n".
					$this->schema->CreateStatement($view).";\n");
			}
			$this->ExportTriggers($handle, $baseTables);
			$skipped = $tables === null ? $this->Routines() : [];
			if($skipped) {
				$this->Write($handle, "\n-- not exported (the php driver can't export routines):\n-- ".implode("\n-- ", $skipped)."\n");
			}
			$this->Write($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
			$done = true;
		} finally {
			fclose($handle);
			if(!$done && file_exists($destination)) unlink($destination);
			$this->RestoreSession($session);
		}
		return $skipped;
	}

	/**
	* `DROP TABLE`, `CREATE TABLE` and the rows (streamed) as multi-row INSERTs with their column names.
	* Generated columns are left out: they can't be inserted.
	*/
	private function ExportTable($handle, string $table): void {
		$quoted = self::QuoteName($table);
		$this->Write($handle, "\n--\n-- Table ".$quoted."\n--\n\n".
			"DROP TABLE IF EXISTS ".$quoted.";\n".
			$this->schema->CreateStatement($table).";\n");
		$columns = array_values(array_filter($this->schema->Columns($table),
			fn($c) => !preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string)$c["extra"])));
		if(!$columns) return;
		$names = implode(", ", array_map(fn($c) => self::QuoteName($c["name"]), $columns));
		$binary = array_map(fn($c) => self::IsBinary($c["type"]), $columns);
		$insert = "INSERT INTO ".$quoted." (".$names.") VALUES\n";
		$values = "";
		foreach($this->db->QueryStream("SELECT ".$names." FROM ".$quoted) as $row) {
			$parts = [];
			foreach(array_values($row) as $n => $value) $parts[] = $this->Value($value, $binary[$n]);
			$tuple = "(".implode(", ", $parts).")";
			if($values !== "" && strlen($values) + strlen($tuple) > self::INSERT_BYTES) {
				$this->Write($handle, $insert.$values.";\n");
				$values = "";
			}
			$values .= ($values === "" ? "" : ",\n").$tuple;
		}
		if($values !== "") $this->Write($handle, $insert.$values.";\n");
	}

	/** the triggers of $tables, between `DELIMITER ;;` lines (their bodies have `;`) */
	private function ExportTriggers($handle, array $tables): void {
		$triggers = array_filter(
			$this->db->QueryAll("SELECT TRIGGER_NAME AS `name`, EVENT_OBJECT_TABLE AS `table` FROM information_schema.TRIGGERS ".
				"WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER"),
			fn($t) => in_array($t["table"], $tables, true));
		if(!$triggers) return;
		$sql = "\n--\n-- Triggers\n--\n\nDELIMITER ;;\n";
		foreach($triggers as $trigger) {
			$quoted = self::QuoteName($trigger["name"]);
			$row = $this->db->QueryRow("SHOW CREATE TRIGGER ".$quoted);
			$sql .= "DROP TRIGGER IF EXISTS ".$quoted.";;\n".($row["sql original statement"] ?? "").";;\n";
		}
		$this->Write($handle, $sql."DELIMITER ;\n");
	}

	/** @return string[] the database's routines, as "PROCEDURE name" / "FUNCTION name" */
	private function Routines(): array {
		$rows = $this->db->QueryAll("SELECT ROUTINE_TYPE AS `type`, ROUTINE_NAME AS `name` FROM information_schema.ROUTINES ".
			"WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME");
		return array_map(fn($r) => $r["type"]." ".$r["name"], $rows);
	}

	private function ImportPhp(string $path, bool $continue): array {
		$session = $this->SessionState();
		$count = 0;
		$errors = [];
		try {
			foreach(SqlSplitter::FromFile($path) as $statement) {
				$count++;
				try {
					// one statement at a time, through a path that doesn't split it again
					$this->db->QueryMulti([$statement]);
				} catch(MagratheaDBConnectionLostException $ex) {
					throw $ex;
				} catch(MagratheaDBException $ex) {
					if(!$continue) {
						throw new MagratheaDBException("import stopped at statement ".$count.": ".$ex->getMessage(), $statement, $ex->getCode(), $ex);
					}
					$errors[] = ["statement" => $count, "error" => $ex->getMessage()];
				}
			}
		} finally {
			$this->RestoreSession($session);
		}
		$rs = ["statements" => $count];
		if($continue) $rs["errors"] = $errors;
		return $rs;
	}

	/** what a dump changes in the (shared) session: restored by RestoreSession() */
	private function SessionState(): array {
		return $this->db->QueryRow("SELECT @@session.time_zone AS `time_zone`, @@session.foreign_key_checks AS `foreign_key_checks`");
	}
	/** if it can't be restored, the connection is dropped: the next query opens a clean one */
	private function RestoreSession(array $session): void {
		try {
			$this->db->Query("SET time_zone = '".$this->db->Escape($session["time_zone"] ?? "SYSTEM")."', ".
				"foreign_key_checks = ".(int)($session["foreign_key_checks"] ?? 1));
		} catch(\Throwable $ex) {
			$this->db->Disconnect();
		}
	}

	private function Value($value, bool $binary): string {
		if($value === null) return "NULL";
		if($binary) return $value === "" ? "''" : "0x".bin2hex($value);
		return "'".$this->db->Escape($value)."'";
	}

	private static function IsBinary(string $columnType): bool {
		return preg_match('/^([a-z]+)/', strtolower(trim($columnType)), $match) && in_array($match[1], self::BINARY_TYPES, true);
	}

	private static function QuoteName(string $name): string {
		return "`".str_replace("`", "``", $name)."`";
	}

	/** @throws MagratheaException 	the write failed (disk full…) */
	private function Write($handle, string $sql): void {
		if(fwrite($handle, $sql) !== strlen($sql)) throw new MagratheaException("could not write the dump file");
	}

}
