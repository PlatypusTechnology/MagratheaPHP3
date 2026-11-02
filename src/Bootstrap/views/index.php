<?php
/**
 * The Setup Wizard's page. Variables: $page (Start::Handle()'s result) and $wizard (Start).
 * Everything that comes from the config, the filesystem, the database or the request is escaped ($h).
 * No external assets and no JavaScript: every form posts to the server, which validates it.
 */

use Magrathea\Admin\AdminCsrf;
use Magrathea\Bootstrap\Start;
use Magrathea\MagratheaPHP;

$h = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES);
// escapes a message, then shows its `code` spans
$md = fn($message) => preg_replace('/`([^`]+)`/', '<code>$1</code>', $h($message));
$csrfField = function() use ($h) {
	return '<input type="hidden" name="csrf" value="'.$h(AdminCsrf::Instance()->GetToken()).'">';
};
$findings = function(array $list) use ($h, $md) {
	foreach($list as $finding) {
		$level = (string)($finding["level"] ?? \Magrathea\TimezoneCheck::INFO);
		echo '<div class="alert level-'.$h($level).'"><span class="badge">'.$h($level).'</span>'.$md($finding["message"] ?? "");
		if(!empty($finding["fix"])) echo '<pre>'.$h($finding["fix"]).'</pre>';
		echo '</div>';
	}
};
$fieldError = function(array $errors, string $field) use ($md) {
	if(!empty($errors[$field])) echo '<div class="field-error">'.$md($errors[$field]).'</div>';
};

$view = $page["view"];
$step = $page["step"] ?? null;
$data = $page["data"] ?? null;
$result = $page["result"] ?? null;
$version = MagratheaPHP::Instance()->Version();
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>Magrathea Setup Wizard</title>
		<style>
			<?php include(__DIR__."/css/_variables.css"); ?>
			<?php include(__DIR__."/css/wizard.css"); ?>
		</style>
	</head>
	<body>
		<header class="wizard-header">
			<?php include(__DIR__."/logo.svg"); ?>
			<span class="title">Magrathea Setup Wizard</span>
			<span class="meta">
				MagratheaPHP <?=$h($version)?><br/>
				<?=$h($page["mode"])?> mode<?=!empty($page["environment"]) ? " &middot; environment <b>".$h($page["environment"])."</b>" : ""?>
			</span>
		</header>

		<?php if($view === "step") { ?>
		<div class="wizard">
			<nav class="wizard-nav">
				<ol>
					<?php
					$past = true;
					foreach(Start::STEPS as $key => $title) {
						$class = ($key === $step) ? "current" : ($past ? "done" : "");
						if($key === $step) $past = false;
						echo '<li><a class="'.$class.'" href="'.$h($wizard->GetStepLink($key)).'">'.$h($title).'</a></li>';
					}
					?>
				</ol>
			</nav>
			<main class="wizard-main">
				<?php
				if(!empty($page["error"])) echo '<div class="alert level-error">'.$md($page["error"]).'</div>';
				// after "finish" the session isn't verified: only its result is left to show
				if(($step !== null && $data !== null) || ($step === "finish" && $result !== null)) {
					include(__DIR__."/steps/".$step.".php");
				}
				if($step !== null && $step !== "finish") {
					$keys = array_keys(Start::STEPS);
					$at = array_search($step, $keys, true);
					?>
					<div class="actions">
						<span><?php if($at > 0) { ?><a class="button secondary" href="<?=$h($wizard->GetStepLink($keys[$at - 1]))?>">Back</a><?php } ?></span>
						<span><?php if($at < count($keys) - 1) { ?><a class="button" href="<?=$h($wizard->GetStepLink($keys[$at + 1]))?>">Next: <?=$h(Start::STEPS[$keys[$at + 1]])?></a><?php } ?></span>
					</div>
					<?php
				}
				?>
			</main>
		</div>
		<?php } else { ?>
		<main class="narrow">
			<?php include(__DIR__."/".$view.".php"); ?>
		</main>
		<?php } ?>
	</body>
</html>
