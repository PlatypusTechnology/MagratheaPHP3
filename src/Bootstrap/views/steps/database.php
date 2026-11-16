<?php
// $data: Steps::Database(); $result: Steps::ConnectDatabase(), after "Connect". Helpers: see index.php
?>
<div class="panel">
	<h2>Database</h2>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else { ?>
		<?php if($data["error"] !== null) echo '<div class="alert level-error">'.$md($data["error"]).'</div>'; ?>
		<h3>Credentials</h3>
		<p class="muted">As the active environment's section has them. A <code>$=NAME</code> value reads the environment variable <code>NAME</code>: the value shown is what PHP reads from it.</p>
		<table>
			<tr><th>Key</th><th>Value</th><th>In the file</th></tr>
			<?php foreach($data["credentials"] as $row) { ?>
				<tr>
					<td><?=$h($row["label"])?> <span class="muted"><code><?=$h($row["key"])?></code></span></td>
					<td><?php
						if($row["value"] === null || $row["value"] === "") {
							echo '<span class="muted">'.($row["env"] !== null ? "(the variable isn't set)" : "(empty)").'</span>';
						} else {
							echo '<code>'.$h($row["value"]).'</code>';
						}
					?></td>
					<td><?php
						if($row["raw"] === null) echo '<span class="muted">(missing)</span>';
						else if($row["env"] !== null) echo 'environment variable <code>'.$h($row["env"]).'</code>';
						else echo '<span class="muted">value</span>';
					?></td>
				</tr>
			<?php } ?>
		</table>

		<?php if($data["unsetMessage"] !== null) { ?>
			<div class="alert level-error"><?=$md($data["unsetMessage"])?> Connect needs every one of <code>db_host</code>, <code>db_name</code> and <code>db_user</code>.</div>
		<?php } else if(!$data["configured"]) { ?>
			<div class="alert level-info">The config has no database (<code>db_host</code>, <code>db_name</code> and <code>db_user</code> are empty): nothing to connect to.</div>
		<?php } else { ?>
			<h3>Connection</h3>
			<?php if($result === null) { ?>
				<p>Opening this step doesn't connect: connect to <b><?=$h($data["database"])?></b> on <code><?=$h($data["host"])?></code> when the values above look right.</p>
			<?php } else {
				if($result["connected"]) echo '<div class="alert level-ok">Connected to <b>'.$h($data["database"]).'</b> on <code>'.$h($data["host"]).'</code>.</div>';
				if($result["error"] !== null) echo '<div class="alert level-error">'.$md($result["error"]).'</div>';
			} ?>
			<form method="post" action="<?=$h($wizard->GetStepLink("database"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="connect-database">
				<button type="submit"><?=$result === null ? "Connect" : "Connect again"?></button>
			</form>
			<?php if($result !== null && $result["connected"]) { ?>
				<h3>Timezone</h3>
				<?php $findings($result["timezone"]); ?>
				<h3>Tables</h3>
				<?php $count = count($result["tables"]); ?>
				<?php if($count === 0) { ?>
					<p class="muted">The database has no tables yet: import a dump, or install the admin's tables.</p>
				<?php } else { ?>
					<p>The database has <b><?=$count?></b> table<?=$count === 1 ? "" : "s"?>: see them, with their columns, in the <a href="<?=$h($wizard->GetStepLink("tables"))?>">Tables</a> step.</p>
				<?php } ?>
			<?php } ?>
		<?php } ?>
		<p class="muted">Wrong values? Change them in the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a>.</p>
	<?php } ?>
</div>
