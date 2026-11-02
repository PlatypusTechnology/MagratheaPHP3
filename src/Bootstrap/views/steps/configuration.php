<?php
// $data: Steps::ConfigurationForm(); $result: Steps::SaveConfiguration(), after a save. Helpers: see index.php
$errors = $result["errors"] ?? [];
// after a refused save, the form shows what was typed (never the password)
$values = $data["values"];
$environment = $data["environment"];
if($result && !$result["saved"]) {
	$values = array_merge($values, $result["values"]);
	if($data["environmentEditable"]) $environment = $result["environment"];
}
$input = function(string $key, string $type = "text", string $hint = "") use ($data, $values, $errors, $h, $md, $fieldError) {
	$wide = in_array($key, ["app_url", "logs_path", "cache_path", "backups_path", "jwt_key"], true) ? " wide" : "";
	echo '<div class="field'.$wide.'"><label for="'.$h($key).'">'.$h($data["fields"][$key]).'</label>';
	echo '<input type="'.$h($type).'" id="'.$h($key).'" name="'.$h($key).'" value="'.$h($values[$key] ?? "").'" autocomplete="off">';
	if($hint) echo '<div class="hint">'.$md($hint).'</div>';
	$fieldError($errors, $key);
	echo '</div>';
};
?>
<div class="panel">
	<h2>Configuration</h2>
	<?php
	if($result && $result["saved"]) {
		echo '<div class="alert level-ok">Saved <code>'.$h($result["file"]).'</code>, environment <b>'.$h($result["environment"]).'</b>.</div>';
	} else if($result) {
		echo '<div class="alert level-error">Nothing was saved: fix the fields below.</div>';
	}
	?>
	<p>
		<?php if($data["exists"]) { ?>
			Editing the <b><?=$h($data["environment"])?></b> section of <code><?=$h($data["file"])?></code>: the other sections stay as they are.
		<?php } else { ?>
			There's no config yet: this writes <code><?=$h($data["file"])?></code> with <code>[general]</code> and the environment's section.
		<?php } ?>
		The section gets <code>bootstrap_unlocked = true</code>, so the next steps can open; <b>Finish</b> removes it (outside <code>dev</code>, where you may keep it).
	</p>
	<p class="muted">Values can't contain <code>"</code> or line breaks. Saving rewrites the file: comments in it are lost.</p>

	<form method="post" action="<?=$h($wizard->GetStepLink("configuration"))?>">
		<?=$csrfField()?>
		<input type="hidden" name="action" value="save-config">
		<div class="grid">
			<div class="field wide">
				<label for="environment">Environment</label>
				<?php if($data["environmentEditable"]) { ?>
					<input type="text" id="environment" name="environment" value="<?=$h($environment)?>" autocomplete="off">
					<div class="hint">The section's name, and <code>[general] use_environment</code>: <code>dev</code>, <code>production</code>, …</div>
				<?php } else { ?>
					<input type="text" id="environment" value="<?=$h($data["environment"])?>" disabled>
					<div class="hint">The active environment. To switch, change <code>use_environment</code> in the file.</div>
				<?php } ?>
				<?php $fieldError($errors, "environment"); ?>
			</div>
			<?php
			$input("db_host", "text", "Host, name and user all empty: a project without a database.");
			$input("db_port", "text", "Empty: the default (3306).");
			$input("db_name");
			$input("db_user");
			$input("db_pass", "password", $data["hasPassword"] ? "Empty: keeps the current password." : "");
			?>
			<div class="field">
				<label for="timezone">Timezone</label>
				<select id="timezone" name="timezone">
					<?php
					foreach($data["timezones"] as $zone) {
						echo '<option value="'.$h($zone).'"'.($zone === ($values["timezone"] ?? "") ? " selected" : "").'>'.$h($zone).'</option>';
					}
					?>
				</select>
				<?php $fieldError($errors, "timezone"); ?>
			</div>
			<?php
			$input("app_url");
			$input("logs_path");
			$input("cache_path");
			$input("backups_path", "text", "The folders aren't created: the Checks step tells which are missing.");
			$input("jwt_key", "text", "A random key, for `MagratheaApiAuth`'s tokens.");
			?>
		</div>
		<button type="submit">Save the configuration</button>
	</form>
</div>
