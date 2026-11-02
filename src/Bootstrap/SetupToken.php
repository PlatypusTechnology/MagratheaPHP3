<?php

namespace Magrathea\Bootstrap;

use Magrathea\Exceptions\MagratheaException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    SetupToken created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* The Setup Wizard's token: `configs/.setup-token`, which only someone with access to the server's files can read.
* Line 1 is the token (`xxxx-xxxx`, 8 digits), line 2 the failed attempts. At `MAX_FAILURES` the file is renamed
* to `.setup-locked`, and no token is accepted until the owner deletes that file.
*/
class SetupToken {

	const FILE = ".setup-token";
	const LOCKED_FILE = ".setup-locked";
	const MAX_FAILURES = 10;

	private string $folder;

	/**
	* @param 	string 	$folder 	the configs folder
	*/
	public function __construct(string $folder) {
		$this->folder = rtrim($folder, "/");
	}

	/** @return 	string 	the configs folder */
	public function Folder(): string {
		return $this->folder;
	}
	/** @return 	string 	the token file */
	public function Path(): string {
		return $this->folder."/".self::FILE;
	}
	/** @return 	string 	the file that locks the wizard out */
	public function LockedPath(): string {
		return $this->folder."/".self::LOCKED_FILE;
	}

	/** @return 	bool 	does the token file exist? */
	public function Exists(): bool {
		return is_file($this->Path());
	}

	/** @return 	bool 	too many failures: `.setup-locked` exists, and no token is accepted */
	public function IsLockedOut(): bool {
		return file_exists($this->LockedPath());
	}

	/**
	* Creates the token file when it's missing (mode `0600`), and the configs folder (`0750`) when that's missing.
	* Locked out, it creates nothing.
	* @return 	bool 	true when a new token was written; false when one already existed
	* @throws 	MagratheaException 	locked out, or the folder or the file can't be created
	*/
	public function Issue(): bool {
		if($this->IsLockedOut()) throw new MagratheaException("the wizard is locked out: delete ".$this->LockedPath());
		if($this->Exists()) return false;
		if(!is_dir($this->folder) && !@mkdir($this->folder, 0750, true) && !is_dir($this->folder)) {
			throw new MagratheaException("can't create the folder ".$this->folder);
		}
		$handle = @fopen($this->Path(), "x");
		if(!$handle) {
			if($this->Exists()) return false;
			throw new MagratheaException("can't write ".$this->Path());
		}
		// before it holds the token
		@chmod($this->Path(), 0600);
		fwrite($handle, self::Format(self::Generate())."\n0\n");
		fclose($handle);
		return true;
	}

	/**
	* @return 	string|null 	the token (`xxxx-xxxx`), or null when there's none
	*/
	public function Token(): ?string {
		if(!$this->Exists()) return null;
		$lines = @file($this->Path(), FILE_IGNORE_NEW_LINES);
		$token = is_array($lines) ? self::Normalize($lines[0] ?? null) : null;
		return $token === null ? null : self::Format($token);
	}

	/**
	* @return 	string|null 	what the session keeps: `hash("sha256", $token)`; null when there's no token
	*/
	public function Hash(): ?string {
		$token = $this->Token();
		return $token === null ? null : hash("sha256", $token);
	}

	/**
	* @return 	int 	failed attempts on the current token
	*/
	public function Failures(): int {
		if(!$this->Exists()) return 0;
		$lines = @file($this->Path(), FILE_IGNORE_NEW_LINES);
		return is_array($lines) ? (int)($lines[1] ?? 0) : 0;
	}

	/**
	* Checks a token typed by the user. A wrong one counts as a failure (under `flock()`); the `MAX_FAILURES`th
	* renames the file to `.setup-locked`. Locked out, or without a token, nothing is accepted.
	* @param 	mixed 	$input 	`xxxx-xxxx` or `xxxxxxxx` (spaces and dashes are ignored)
	* @return 	bool 	is it the token?
	*/
	public function Check($input): bool {
		if($this->IsLockedOut() || !$this->Exists()) return false;
		$handle = @fopen($this->Path(), "r+");
		if(!$handle) return false;
		try {
			if(!flock($handle, LOCK_EX)) return false;
			$lines = explode("\n", (string)stream_get_contents($handle));
			$token = self::Normalize($lines[0] ?? null);
			$failures = (int)($lines[1] ?? 0);
			// a request that waited for the lock while another one used up the last try
			if($token === null || $failures >= self::MAX_FAILURES) return false;
			$typed = self::Normalize($input);
			if($typed !== null && hash_equals($token, $typed)) return true;

			$failures++;
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, self::Format($token)."\n".$failures."\n");
			fflush($handle);
			if($failures >= self::MAX_FAILURES) @rename($this->Path(), $this->LockedPath());
			return false;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	* Deletes the token: the sessions it verified stop working
	* @return 	bool 	true when there's no token file anymore
	*/
	public function Delete(): bool {
		if(!$this->Exists()) return true;
		return @unlink($this->Path());
	}

	/**
	* @param 	mixed 	$input 	a typed token
	* @return 	string|null 	its 8 digits, without spaces and dashes; null when it isn't 8 digits
	*/
	public static function Normalize($input): ?string {
		if(!is_string($input)) return null;
		$digits = str_replace([" ", "-", "\t"], "", trim($input));
		return preg_match('/^\d{8}$/D', $digits) ? $digits : null;
	}

	/**
	* @param 	string 	$digits 	8 digits
	* @return 	string 	`xxxx-xxxx`
	*/
	public static function Format(string $digits): string {
		return substr($digits, 0, 4)."-".substr($digits, 4);
	}

	/** @return 	string 	8 random digits */
	protected static function Generate(): string {
		return str_pad((string)random_int(0, 99999999), 8, "0", STR_PAD_LEFT);
	}

}
