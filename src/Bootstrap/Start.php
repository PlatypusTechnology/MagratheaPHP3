<?php

namespace Magrathea\Bootstrap;

use Magrathea\Admin\AdminCsrf;
use Magrathea\Config;
use Magrathea\Errors\ErrorManager;
use Magrathea\MagratheaPHP;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Bootstrap Start created: 2022-12 by Paulo Martins
####    Rebuilt (token lock, modes, steps): 2026-11
####
#######################################################################################

/**
* The Setup Wizard. Its entry file (`public/setup.php`) only sets the app's path and calls `Load()`:
* ```php
* Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
* ```
* It reads `<magRoot>/configs/magrathea.conf` itself and never calls `MagratheaPHP::Load()`, so it works with no config.
* - setup mode: there's no config file. It needs the token;
* - maintenance mode: the config exists and the active environment has `bootstrap_unlocked = true`. It needs the token too;
* - anything else is locked (403).
* The token is in `configs/.setup-token` (`SetupToken`); a correct one verifies the session. Every POST needs the
* admin's CSRF token, and every step and action a verified session.
*/
class Start extends \Magrathea\Singleton {

	const SETUP = "setup";
	const MAINTENANCE = "maintenance";
	const LOCKED = "locked";

	/** where a verified session keeps `hash("sha256", $token)` */
	const SESSION_KEY = "magrathea_setup";

	/** the steps, in order: key => title */
	const STEPS = [
		"requirements" => "Requirements",
		"configuration" => "Configuration",
		"database" => "Database",
		"import" => "Import",
		"admin" => "Admin",
		"checks" => "Checks",
		"finish" => "Finish",
	];

	/** the POST actions (`action`), and the step each one belongs to; "verify" (the token form) is the only one without a verified session */
	const ACTIONS = [
		"save-config" => "configuration",
		"upload-dump" => "import",
		"import-dump" => "import",
		"install-admin" => "admin",
		"create-admin" => "admin",
		"finish" => "finish",
	];
	const VERIFY = "verify";

	protected ?string $magRoot = null;
	private ?SetupToken $token = null;
	private ?Steps $steps = null;

	/**
	* Sets the app's folder: `MagratheaPHP::AppPath()`, so `magRoot` is its parent. Points `Config` at
	* `<magRoot>/configs`.
	* @param 	string 	$path 	the app's folder
	* @return 	Start
	*/
	public function AppPath(string $path): Start {
		MagratheaPHP::Instance()->AppPath($path);
		$root = MagratheaPHP::Instance()->magRoot;
		return $this->SetMagRoot($root ?: rtrim($path, "/")."/..");
	}

	/**
	* Sets the project's root (the folder with `configs/`) without touching `MagratheaPHP`
	* @param 	string 	$root 	the root folder
	* @return 	Start
	*/
	public function SetMagRoot(string $root): Start {
		$this->magRoot = rtrim($root, "/");
		$this->token = null;
		$this->ReloadConfig();
		return $this;
	}

	/** @return 	string|null 	the project's root */
	public function GetMagRoot(): ?string {
		if($this->magRoot === null && !empty(MagratheaPHP::Instance()->magRoot)) {
			$this->SetMagRoot(MagratheaPHP::Instance()->magRoot);
		}
		return $this->magRoot;
	}

	/** @return 	string 	`<magRoot>/configs` */
	public function ConfigFolder(): string {
		return $this->GetMagRoot()."/configs";
	}
	/** @return 	string 	`<magRoot>/configs/magrathea.conf` */
	public function ConfigFilePath(): string {
		return $this->ConfigFolder()."/magrathea.conf";
	}
	/** @return 	bool 	does the config file exist? */
	public function HasConfig(): bool {
		return $this->GetMagRoot() !== null && is_file($this->ConfigFilePath());
	}

	/**
	* Points `Config` at the configs folder, dropping what it cached (after the wizard writes the file, too), and
	* sets PHP's timezone from the config, as `MagratheaPHP::Load()` does
	*/
	public function ReloadConfig(): void {
		if($this->magRoot === null) return;
		Config::Instance()->SetConfig(null)->SetEnvironment(null)->SetPath($this->ConfigFolder())->SetConfigFile("magrathea.conf");
		if(!$this->HasConfig()) return;
		try {
			$timezone = Config::Instance()->Get("timezone");
			if($timezone && in_array($timezone, timezone_identifiers_list(), true)) date_default_timezone_set($timezone);
		} catch(\Throwable $ex) { }
	}

	/**
	* Checks if the config unlocks the wizard: the active environment's section must have
	* `bootstrap_unlocked = true`. A missing key, a false value or an unreadable config means locked.
	* @return bool		is it unlocked?
	*/
	public function IsUnlocked(): bool {
		try {
			$unlocked = Config::Instance()->Get("bootstrap_unlocked");
		} catch(\Throwable $ex) {
			return false;
		}
		return filter_var($unlocked, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	* @return 	string 	SETUP (no config), MAINTENANCE (a config with `bootstrap_unlocked`) or LOCKED
	*/
	public function Mode(): string {
		if(!$this->HasConfig()) return self::SETUP;
		return $this->IsUnlocked() ? self::MAINTENANCE : self::LOCKED;
	}

	/**
	* @return 	string|null 	the active environment's name, or null when the config can't be read
	*/
	public function GetEnvironmentName(): ?string {
		if(!$this->HasConfig()) return null;
		try {
			return Config::Instance()->GetEnvironment();
		} catch(\Throwable $ex) {
			return null;
		}
	}

	/** @return 	SetupToken 	the token in the configs folder */
	public function Token(): SetupToken {
		if($this->token === null) $this->token = new SetupToken($this->ConfigFolder());
		return $this->token;
	}

	/** @return 	Steps 	the steps' handlers */
	public function Steps(): Steps {
		if($this->steps === null || $this->steps->Start() !== $this) $this->steps = new Steps($this);
		return $this->steps;
	}
	/**
	* Replaces the steps' handlers (tests)
	* @param 	Steps|null 	$steps 		null: the default
	* @return 	Start
	*/
	public function SetSteps(?Steps $steps): Start {
		$this->steps = $steps;
		return $this;
	}

	/**
	* Is this session verified? Its hash must match the token file's (deleting or replacing the file revokes it),
	* and the wizard must not be locked or locked out.
	* @return 	bool
	*/
	public function IsVerified(): bool {
		$session = $_SESSION[self::SESSION_KEY] ?? null;
		if(!is_string($session) || $session === "") return false;
		if($this->GetMagRoot() === null || $this->Mode() === self::LOCKED) return false;
		$token = $this->Token();
		if($token->IsLockedOut()) return false;
		$hash = $token->Hash();
		return $hash !== null && hash_equals($hash, $session);
	}

	/**
	* Checks a typed token (`SetupToken::Check()`, which counts the failures). A correct one regenerates the
	* session's id and keeps the token's hash in it.
	* @param 	mixed 	$input 	the typed token
	* @return 	bool 	verified?
	*/
	public function Verify($input): bool {
		if($this->Mode() === self::LOCKED) return false;
		$token = $this->Token();
		if(!$token->Check($input)) return false;
		if(session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
		$_SESSION[self::SESSION_KEY] = $token->Hash();
		return true;
	}

	/** Forgets the session's verification */
	public function EndSession(): void {
		unset($_SESSION[self::SESSION_KEY]);
	}

	/**
	* Handles a request, without output: what `Load()` renders
	* @param 	string 	$method 	the HTTP method
	* @param 	array 	$get 		the query string (`step`)
	* @param 	array 	$post 		the form (`action`, `csrf`, the action's fields)
	* @param 	array 	$files 		uploaded files
	* @return 	array 	["status" => HTTP status, "view" => "locked", "locked-out", "no-folder", "token", "redirect" or
	* 					"step", plus the view's keys: "mode", "environment", "error", "step", "data", "result", "location"]
	*/
	public function Handle(string $method, array $get = [], array $post = [], array $files = []): array {
		// a config exception (no config yet, in LOG mode) shows the error page and dies from its constructor: it throws instead
		$previous = ErrorManager::Instance()->ThrowErrors(true);
		try {
			return $this->HandleRequest($method, $get, $post, $files);
		} finally {
			ErrorManager::Instance()->ThrowErrors($previous);
		}
	}

	private function HandleRequest(string $method, array $get, array $post, array $files): array {
		$mode = $this->Mode();
		$page = ["mode" => $mode, "environment" => $this->GetEnvironmentName(), "error" => null];
		if($mode === self::LOCKED) return ["status" => 403, "view" => "locked"] + $page;

		$token = $this->Token();
		if($token->IsLockedOut()) return ["status" => 403, "view" => "locked-out", "file" => $token->LockedPath()] + $page;
		try {
			$token->Issue();
		} catch(\Throwable $ex) {
			return ["status" => 503, "view" => "no-folder", "folder" => $token->Folder(), "error" => $ex->getMessage()] + $page;
		}
		$page["tokenFile"] = $token->Path();

		$isPost = strtoupper($method) === "POST";
		$action = $isPost ? ($post["action"] ?? null) : null;
		$requested = $isPost || array_key_exists("step", $get);
		if($isPost && !AdminCsrf::Instance()->Validate(is_string($post["csrf"] ?? null) ? $post["csrf"] : null)) {
			return ["status" => 403, "view" => $this->IsVerified() ? "step" : "token",
				"error" => "The form expired (or didn't come from this page): try again.",
				"step" => $this->StepOf($get), "data" => null, "result" => null] + $page;
		}

		if($action === self::VERIFY) {
			if($this->Verify($post["token"] ?? null)) {
				return ["status" => 303, "view" => "redirect", "location" => $this->GetStepLink(array_key_first(self::STEPS))] + $page;
			}
			if($token->IsLockedOut()) return ["status" => 403, "view" => "locked-out", "file" => $token->LockedPath()] + $page;
			$left = SetupToken::MAX_FAILURES - $token->Failures();
			return ["status" => 403, "view" => "token", "error" => "Wrong token: ".$left." tries left before the wizard locks itself."] + $page;
		}

		if(!$this->IsVerified()) return ["status" => $requested ? 403 : 200, "view" => "token"] + $page;

		$steps = $this->Steps();
		$result = null;
		if($isPost) {
			if(!is_string($action) || !array_key_exists($action, self::ACTIONS)) {
				return ["status" => 404, "view" => "step", "error" => "Unknown action.", "step" => $this->StepOf($get), "data" => null, "result" => null] + $page;
			}
			$step = self::ACTIONS[$action];
			try {
				$result = $steps->Run($action, $post, $files);
			} catch(\Throwable $ex) {
				$page["error"] = $ex->getMessage();
			}
		} else {
			$step = $this->StepOf($get);
			if($step === null) return ["status" => 404, "view" => "step", "error" => "Unknown step.", "step" => null, "data" => null, "result" => null] + $page;
		}

		// after "finish", the session isn't verified anymore: the step shows its result alone
		$data = null;
		if($this->IsVerified()) {
			try {
				$data = $steps->View($step);
			} catch(\Throwable $ex) {
				$page["error"] = $page["error"] ?? $ex->getMessage();
			}
		}
		// the mode may have changed (save-config writes the file)
		$page["mode"] = $this->Mode();
		$page["environment"] = $this->GetEnvironmentName();
		return ["status" => 200, "view" => "step", "step" => $step, "data" => $data, "result" => $result] + $page;
	}

	/**
	* @param 	array 	$get 	the query string
	* @return 	string|null 	its `step` (the first one when it's missing), or null when it isn't a step
	*/
	private function StepOf(array $get): ?string {
		$step = $get["step"] ?? null;
		if($step === null || $step === "") return array_key_first(self::STEPS);
		return (is_string($step) && array_key_exists($step, self::STEPS)) ? $step : null;
	}

	/**
	* Shows the wizard: handles the request (`Handle()`) and renders it
	*/
	public function Load(): void {
		if($this->GetMagRoot() === null) {
			http_response_code(500);
			echo "Magrathea Setup Wizard: call AppPath() first: Magrathea\\Bootstrap\\Start::Instance()->AppPath(__DIR__.\"/../app\")->Load();";
			return;
		}
		if(session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
		$page = $this->Handle($_SERVER["REQUEST_METHOD"] ?? "GET", $_GET, $_POST, $_FILES);
		http_response_code($page["status"]);
		if($page["view"] === "redirect") {
			header("Location: ".$page["location"]);
			return;
		}
		$this->Render($page);
	}

	/**
	* Renders what `Handle()` returned
	* @param 	array 	$page 	Handle()'s result
	*/
	public function Render(array $page): void {
		$wizard = $this;
		$previous = ErrorManager::Instance()->ThrowErrors(true);
		try {
			include(__DIR__."/views/index.php");
		} finally {
			ErrorManager::Instance()->ThrowErrors($previous);
		}
	}

	/**
	* Gets link to step
	* @param string $step		step to go
	* @return string			step url
	*/
	public function GetStepLink($step) {
		return self::GetBootstrapURL()."?step=".urlencode((string)$step);
	}

	/** @return 	string 	the wizard's URL, without the query string */
	public static function GetBootstrapURL() {
		return strtok($_SERVER["REQUEST_URI"] ?? "", '?') ?: "";
	}

}
