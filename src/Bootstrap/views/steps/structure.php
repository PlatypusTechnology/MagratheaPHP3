<?php
// $data: Steps::Structure(). Helpers: see index.php
?>
<div class="panel">
	<h2>Structure</h2>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first: the folders are its <code>logs_path</code>, <code>cache_path</code> and <code>backups_path</code>.</div>
	<?php } else { ?>
		<p>
			The folders the config points at (<code>logs_path</code>, <code>cache_path</code>, <code>backups_path</code>):
			each must exist and be writable by the web server's user. Nothing here creates them: create them on the server
			(<code>mkdir -p</code>, then <code>chown</code>/<code>chmod</code> for that user) and reload this step.
		</p>
		<?php $findings($data["findings"]); ?>
		<p class="muted">Wrong paths? Change them in the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a>.</p>
	<?php } ?>
</div>
