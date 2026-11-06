<?php
// $data: Steps::Admin(); $result: Steps::InstallAdmin() or Steps::CreateAdmin(). Helpers: see index.php
use Magrathea\Bootstrap\Steps;

$errors = $result["errors"] ?? [];
?>
<div class="panel">
	<h2>Admin</h2>
	<?php
	if(isset($result["queries"])) {
		if(empty($result["errors"])) {
			echo '<div class="alert level-ok">Installed the admin\'s tables: '.(int)$result["queries"].' statements ran.</div>';
		} else {
			echo '<div class="alert level-error">'.count($result["errors"]).' of '.(int)$result["queries"].' statements failed:</div>';
			foreach($result["errors"] as $error) echo '<div class="alert level-error">'.$h($error["error"]).'<pre>'.$h($error["query"]).'</pre></div>';
		}
	}
	if(!empty($result["created"])) {
		echo '<div class="alert level-ok">Created the super admin <b>'.$h($result["email"]).'</b>.</div>';
	}
	?>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else if(!$data["configured"]) { ?>
		<div class="alert level-info">The config has no database: the admin needs one.</div>
	<?php } else if($data["error"] !== null) { ?>
		<div class="alert level-error"><?=$md($data["error"])?></div>
	<?php } else { ?>
		<h3>The admin's tables</h3>
		<?php if($data["installed"]) { ?>
			<div class="alert level-ok">Installed (<code>_magrathea_config</code> exists).</div>
		<?php } else { ?>
			<p>Creates the <code>_magrathea_*</code> tables (<code>Admin/database.sql</code>).</p>
			<form method="post" action="<?=$h($wizard->GetStepLink("admin"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="install-admin">
				<button type="submit">Install the admin's tables</button>
			</form>
		<?php } ?>

		<h3>The first super admin</h3>
		<?php if(!$data["installed"]) { ?>
			<p class="muted">Install the tables first.</p>
		<?php } else if(!$data["canCreateFirstUser"]) { ?>
			<div class="alert level-ok">The admin has users already. A forgotten password: <code>vendor/bin/magrathea admin:reset-password &lt;email&gt;</code>, on the server.</div>
		<?php } else { ?>
			<form method="post" action="<?=$h($wizard->GetStepLink("admin"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="create-admin">
				<div class="field">
					<label for="email">E-mail</label>
					<input type="email" id="email" name="email" value="<?=$h($result["email"] ?? "")?>" autocomplete="off">
					<?php $fieldError($errors, "email"); ?>
				</div>
				<div class="grid">
					<div class="field">
						<label for="password">Password</label>
						<input type="password" id="password" name="password" autocomplete="new-password">
						<div class="hint">At least <?=(int)Steps::MIN_PASSWORD?> characters.</div>
						<?php $fieldError($errors, "password"); ?>
					</div>
					<div class="field">
						<label for="password_confirm">Password, again</label>
						<input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password">
						<?php $fieldError($errors, "password_confirm"); ?>
					</div>
				</div>
				<button type="submit">Create the super admin</button>
			</form>
		<?php } ?>
		<p class="muted">The admin's entry file, <code>public/magrathea.php</code>, comes with the project's skeleton: the wizard doesn't write it.</p>
	<?php } ?>
</div>
