<?php
// $data: Steps::Database(). Helpers: see index.php
?>
<div class="panel">
	<h2>Database</h2>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else if(!$data["configured"]) { ?>
		<div class="alert level-info">The config has no database (<code>db_host</code>, <code>db_name</code> and <code>db_user</code> are empty): nothing to connect to.</div>
	<?php } else { ?>
		<p>Connecting to <b><?=$h($data["database"])?></b> on <code><?=$h($data["host"])?></code>, with the config's values.</p>
		<?php
		if($data["connected"]) {
			echo '<div class="alert level-ok">Connected.</div>';
		}
		if($data["error"] !== null) {
			echo '<div class="alert level-error">'.$md($data["error"]).'</div>';
		}
		if($data["connected"]) {
			?>
			<h3>Timezone</h3>
			<?php $findings($data["timezone"]); ?>
			<h3>Tables</h3>
			<?php if(empty($data["tables"])) { ?>
				<p class="muted">The database has no tables yet: import a dump, or install the admin's tables.</p>
			<?php } else { ?>
				<table>
					<tr><th>Table</th><th>Engine</th><th>Rows (approx.)</th></tr>
					<?php foreach($data["tables"] as $table) { ?>
						<tr><td><?=$h($table["name"] ?? "")?></td><td><?=$h($table["engine"] ?? "")?></td><td><?=$h($table["rows"] ?? "")?></td></tr>
					<?php } ?>
				</table>
			<?php } ?>
			<?php
		}
		?>
		<p class="muted">Wrong values? Change them in the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a>.</p>
	<?php } ?>
</div>
