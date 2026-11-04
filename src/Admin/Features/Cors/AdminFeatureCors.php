<?php

namespace Magrathea\Admin\Features\Cors;

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\iAdminFeature;
use Magrathea\MagratheaApi;

#######################################################################################
####
####    MAGRATHEA Admin CORS Origins PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Admin created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
 * Admin feature that edits the API's allowed-origins file (`MagratheaApi::GetCorsOriginsFile()`,
 * read by `MagratheaApi::AllowFromFile()`).
 * Opt-in: load it with `$this->LoadCors()` in `Admin::SetFeatures()`.
 */
class AdminFeatureCors extends AdminFeature implements iAdminFeature {

	public string $featureName = "CORS Origins";
	public string $featureId = "AdminFeatureCors";

	public function __construct() {
		parent::__construct();
		$this->AddJs(__DIR__."/scripts.js");
		$this->SetClassPath(__DIR__);
	}

	/**
	 * Is this exactly what a browser sends in `Origin`? `scheme://host[:port]`, lowercase, no path,
	 * no trailing slash. `Allow()` compares origins exactly, so anything else could never match.
	 * Passes `https://app.com`, `http://localhost:3000`, `capacitor://localhost`, `http://[::1]:3000`;
	 * fails `*`, `null`, `https://app.com/`, `HTTPS://app.com`.
	 * @param 	string 	$origin		origin to check
	 * @return 	bool
	 */
	public static function IsValidOrigin(string $origin): bool {
		return preg_match('/^[a-z][a-z0-9+.-]*:\/\/([a-z0-9-]+(\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])(:[0-9]{1,5})?$/D', $origin) === 1;
	}

	/**
	 * Shows the file's path and content, in a textarea to edit it.
	 */
	public function Index() {
		$featureId = $this->featureId;
		$filePath = MagratheaApi::GetCorsOriginsFile();
		$fileExists = is_file($filePath);
		$content = $fileExists ? @file_get_contents($filePath) : "";
		$readError = ($content === false);
		if($readError) $content = "";
		include(__DIR__."/index.php");
	}

	/**
	 * Saves the posted `origins` (POST only): one origin per line, trimmed; empty lines dropped, `#` comments kept.
	 * If a line isn't a valid origin (`IsValidOrigin()`), nothing is written.
	 * Errors answer 200 with an alert (the admin's `ajax()` drops non-JSON error bodies), except the 405.
	 */
	public function Save() {
		$elements = AdminElements::Instance();
		if(@$_SERVER["REQUEST_METHOD"] !== "POST") {
			http_response_code(405);
			$elements->Alert("Saving the CORS origins requires a POST request", "danger");
			return;
		}
		$posted = $_POST["origins"] ?? "";
		if(!is_string($posted)) $posted = "";
		$lines = [];
		$invalid = [];
		foreach(preg_split('/\r\n|\r|\n/', $posted) as $line) {
			$line = trim($line);
			if($line === "") continue;
			if(!str_starts_with($line, "#") && !self::IsValidOrigin($line)) {
				$invalid[] = $line;
			}
			$lines[] = $line;
		}
		if(count($invalid) > 0) {
			$list = implode("", array_map(fn($l) => "<li><code>".htmlspecialchars($l, ENT_QUOTES)."</code></li>", $invalid));
			$elements->Alert(
				"Nothing saved. These lines aren't origins (<code>scheme://host[:port]</code>, lowercase, no path or trailing slash):"
				."<ul class='mb-0'>".$list."</ul>",
				"danger"
			);
			return;
		}
		$filePath = MagratheaApi::GetCorsOriginsFile();
		$content = count($lines) > 0 ? implode("\n", $lines)."\n" : "";
		if(@file_put_contents($filePath, $content, LOCK_EX) === false) {
			$elements->Alert("Could not write <code>".htmlspecialchars($filePath, ENT_QUOTES)."</code>", "danger");
			return;
		}
		$origins = count(array_filter($lines, fn($l) => !str_starts_with($l, "#")));
		$elements->Alert(
			"Saved ".$origins." origin".($origins == 1 ? "" : "s")." to <code>".htmlspecialchars($filePath, ENT_QUOTES)."</code>",
			"success"
		);
	}

}
