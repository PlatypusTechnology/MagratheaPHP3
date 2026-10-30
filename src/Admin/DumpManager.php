<?php

namespace Magrathea\Admin;

use Magrathea\Config;
use Magrathea\DB\Dump;
use Magrathea\Exceptions\MagratheaException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    DumpManager created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* The admin's Database → Dumps page, as arrays: the `.sql` files in the backups folder (`Dump::Folder()`), and their
* export, upload, download, import and delete, through `Dump`. Every name goes through `Dump::ValidateBackupFileName()`,
* and a file that must exist through `AdminFiles::ResolveInFolder()`. An upload is only saved: nothing runs it. An import
* runs only when it's confirmed with the database's name. The view only renders.
*/
class DumpManager {

	const CONFIRM = "confirm";
	const OK = "ok";

	private Dump $dump;
	private ?string $database;

	/**
	* @param 	Dump|null 		$dump 		exports and imports (default: `new Dump()`)
	* @param 	string|null 	$database 	the database's name, typed to confirm an import (default: the config's `db_name`)
	*/
	public function __construct(?Dump $dump = null, ?string $database = null) {
		$this->dump = $dump ?? new Dump();
		$this->database = $database;
	}

	/**
	* The name an import must be confirmed with
	*/
	public function DatabaseName(): string {
		return $this->database ?? (string)Config::Instance()->Get("db_name");
	}

	/**
	* The `.sql` files in the backups folder, the most recent first
	* @return 	array 	rows with ["name", "size" (bytes), "date" (modification time, a timestamp),
	* 					"usable" (the page can download, import and delete it: a valid name, inside the folder)]
	* @throws 	MagratheaException 	the backups folder doesn't exist
	*/
	public function Files(): array {
		$folder = Dump::Folder();
		$base = realpath($folder);
		if(!$base || !is_dir($base)) throw new MagratheaException("the backups folder doesn't exist: ".$folder);
		$files = [];
		foreach(scandir($base) ?: [] as $name) {
			$path = $base.DIRECTORY_SEPARATOR.$name;
			if(!str_ends_with(strtolower($name), ".sql") || !is_file($path)) continue;
			$files[] = [
				"name" => $name,
				"size" => filesize($path),
				"date" => filemtime($path),
				"usable" => $this->IsUsable($name),
			];
		}
		usort($files, fn($a, $b) => ($b["date"] <=> $a["date"]) ?: strcmp($a["name"], $b["name"]));
		return $files;
	}

	/**
	* The path of a file in the backups folder
	* @param 	mixed 	$file 	file name (see `Dump::ValidateBackupFileName()`)
	* @return 	string 	its full path
	* @throws 	MagratheaException 	invalid name, or not a file inside the backups folder
	*/
	public function Resolve($file): string {
		$name = Dump::ValidateBackupFileName($file);
		$path = AdminFiles::ResolveInFolder(Dump::Folder(), $name);
		if($path === null) throw new MagratheaException("file not found: ".$name);
		return $path;
	}

	/**
	* Exports the database to a new file (`Dump::Export()`)
	* @param 	mixed 	$file 		file name
	* @param 	mixed 	$tables 	table names (empty: every table)
	* @param 	mixed 	$driver 	`Dump::BINARY` or `Dump::PHP` (empty: binary when available)
	* @return 	array 	`Dump::Export()`'s result: ["file", "size", "driver"], plus "skipped"
	* @throws 	MagratheaException 	see `Dump::Export()`
	*/
	public function Export($file, $tables = [], $driver = null): array {
		$options = [];
		if(!empty($tables)) $options["tables"] = $tables;
		if($driver !== null && $driver !== "") $options["driver"] = $driver;
		return $this->dump->Export($file, $options);
	}

	/**
	* Saves an uploaded `.sql` file in the backups folder. It's never run: importing it is a separate, confirmed step.
	* @param 	mixed 	$upload 	one `$_FILES` entry
	* @param 	mixed 	$name 		name to save it under (empty: the uploaded file's name)
	* @return 	array 	["file" => saved name, "size" => bytes]
	* @throws 	MagratheaException 	no file, an upload error, not a `.sql` file, invalid name, existing file, or it couldn't be saved
	*/
	public function Upload($upload, $name = null): array {
		if(!is_array($upload) || !isset($upload["error"], $upload["tmp_name"]) || !is_int($upload["error"])) {
			throw new MagratheaException("no file uploaded");
		}
		if($upload["error"] !== UPLOAD_ERR_OK) throw new MagratheaException(self::UploadError($upload["error"]));
		if(!is_string($upload["name"] ?? null) || !str_ends_with(strtolower($upload["name"]), ".sql")) {
			throw new MagratheaException("only .sql files can be uploaded");
		}
		if($name === null || $name === "") $name = $upload["name"];
		$name = Dump::ValidateBackupFileName($name);
		$folder = Dump::Folder();
		$base = realpath($folder);
		if(!$base || !is_dir($base)) throw new MagratheaException("the backups folder doesn't exist: ".$folder);
		$destination = $base.DIRECTORY_SEPARATOR.$name;
		if(file_exists($destination) || is_link($destination)) throw new MagratheaException("file already exists");
		if(!$this->MoveUploadedFile((string)$upload["tmp_name"], $destination)) {
			throw new MagratheaException("could not save the uploaded file");
		}
		return ["file" => $name, "size" => filesize($destination)];
	}

	/**
	* What a download sends: the file and its headers (`Content-Disposition: attachment`)
	* @param 	mixed 	$file 	file name
	* @return 	array 	["path", "file" => name, "size" => bytes, "headers" => header lines]
	* @throws 	MagratheaException 	see `Resolve()`
	*/
	public function Download($file): array {
		$path = $this->Resolve($file);
		// a valid name is [A-Za-z0-9._-]: nothing to escape in the header
		$name = Dump::ValidateBackupFileName($file);
		$size = filesize($path);
		return [
			"path" => $path,
			"file" => $name,
			"size" => $size,
			"headers" => [
				"Content-Type: application/sql",
				"Content-Disposition: attachment; filename=\"".$name."\"",
				"Content-Length: ".$size,
				"X-Content-Type-Options: nosniff",
				"Cache-Control: no-store",
			],
		];
	}

	/**
	* Imports a file (`Dump::Import()`), only when it's confirmed with the database's name
	* @param 	mixed 	$file 		file name
	* @param 	mixed 	$confirm 	the database's name, typed to confirm (empty: not confirmed yet)
	* @param 	mixed 	$driver 	`Dump::BINARY` or `Dump::PHP` (empty: binary when available)
	* @param 	bool 	$continue 	don't stop at the first error
	* @return 	array 	["status" => CONFIRM (nothing ran) or OK, "file", "database",
	* 					"error" => why a confirmation was refused (the name didn't match), or null,
	* 					"result" => `Dump::Import()`'s result, or null]
	* @throws 	MagratheaException 	see `Resolve()` and `Dump::Import()`
	*/
	public function Import($file, $confirm = null, $driver = null, bool $continue = false): array {
		$this->Resolve($file);
		$name = Dump::ValidateBackupFileName($file);
		$database = $this->DatabaseName();
		$rs = ["status" => self::CONFIRM, "file" => $name, "database" => $database, "error" => null, "result" => null];
		if($confirm === null || $confirm === "") return $rs;
		if($database === "" || $confirm !== $database) {
			return ["error" => "the database name doesn't match: nothing ran"] + $rs;
		}
		$options = ["continue" => $continue];
		if($driver !== null && $driver !== "") $options["driver"] = $driver;
		return ["status" => self::OK, "result" => $this->dump->Import($name, $options)] + $rs;
	}

	/**
	* Deletes a file from the backups folder
	* @param 	mixed 	$file 	file name
	* @return 	array 	["file" => name]
	* @throws 	MagratheaException 	see `Resolve()`, or it couldn't be deleted
	*/
	public function Delete($file): array {
		$path = $this->Resolve($file);
		$name = Dump::ValidateBackupFileName($file);
		if(!@unlink($path)) throw new MagratheaException("could not delete ".$name);
		return ["file" => $name];
	}

	/**
	* `move_uploaded_file()`, only for a file PHP received in this request (tests override it)
	*/
	protected function MoveUploadedFile(string $from, string $to): bool {
		return is_uploaded_file($from) && @move_uploaded_file($from, $to);
	}

	private function IsUsable(string $name): bool {
		try {
			return Dump::ValidateBackupFileName($name) === $name && AdminFiles::ResolveInFolder(Dump::Folder(), $name) !== null;
		} catch(MagratheaException $ex) {
			return false;
		}
	}

	private static function UploadError(int $code): string {
		return match($code) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "the file is bigger than the upload limit (upload_max_filesize: ".ini_get("upload_max_filesize").")",
			UPLOAD_ERR_PARTIAL => "the file was only partially uploaded",
			UPLOAD_ERR_NO_FILE => "no file uploaded",
			default => "the upload failed (error ".$code.")",
		};
	}

}
