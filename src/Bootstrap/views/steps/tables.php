<?php
// $data: Steps::Tables(). Helpers: see index.php
?>
<div class="panel">
	<h2>Tables</h2>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else if($data["unsetMessage"] !== null) { ?>
		<div class="alert level-error"><?=$md($data["unsetMessage"])?></div>
	<?php } else if(!$data["configured"]) { ?>
		<div class="alert level-info">The config has no database (<code>db_host</code>, <code>db_name</code> and <code>db_user</code> are empty): no tables to show.</div>
	<?php } else if(!$data["connected"]) { ?>
		<div class="alert level-error">Can't connect to <b><?=$h($data["database"])?></b> on <code><?=$h($data["host"])?></code>: <?=$md((string)$data["error"])?></div>
		<p class="muted">Check the <a href="<?=$h($wizard->GetStepLink("database"))?>">Database</a> step.</p>
	<?php } else { ?>
		<?php
		$framework = count(array_filter($data["tables"], fn($t) => $t["framework"]));
		$app = count($data["tables"]) - $framework;
		?>
		<p>
			What the config's credentials read in <b><?=$h($data["database"])?></b> on <code><?=$h($data["host"])?></code>:
			<b><?=$app?></b> table<?=$app === 1 ? "" : "s"?> of the project and <b><?=$framework?></b> of the framework (<code>_magrathea_*</code>).
			Open a table to see its columns.
		</p>
		<?php if(empty($data["tables"])) { ?>
			<div class="alert level-warning">The database has no tables: <a href="<?=$h($wizard->GetStepLink("import"))?>">import a dump</a>, or install the <a href="<?=$h($wizard->GetStepLink("admin"))?>">admin's tables</a>.</div>
		<?php } else if($framework === 0) { ?>
			<div class="alert level-warning">The admin's tables aren't installed: see the <a href="<?=$h($wizard->GetStepLink("admin"))?>">Admin</a> step.</div>
		<?php } ?>
		<?php foreach($data["tables"] as $table) {
			$columns = $table["columns"];
			$rows = $table["rows"];
		?>
			<details class="accordion">
				<summary>
					<?=$h($table["name"])?>
					<?php if($table["framework"]) echo '<span class="badge">framework</span>'; ?>
					<?php if(($table["type"] ?? "") === "VIEW") echo '<span class="badge">view</span>'; ?>
					<span class="muted">&middot; <?=count($columns)?> column<?=count($columns) === 1 ? "" : "s"?><?=$rows !== null ? " &middot; ~".$h($rows)." row".($rows === 1 ? "" : "s") : ""?><?=!empty($table["engine"]) ? " &middot; ".$h($table["engine"]) : ""?></span>
				</summary>
				<div class="accordion-body">
					<?php if($table["columnsError"] !== null) { ?>
						<div class="alert level-error">Can't read the columns: <?=$md($table["columnsError"])?></div>
					<?php } else { ?>
						<?php if(!empty($table["comment"])) echo '<p class="muted">'.$h($table["comment"]).'</p>'; ?>
						<table>
							<tr><th>Column</th><th>Type</th><th>Magrathea type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>
							<?php foreach($columns as $column) { ?>
								<tr>
									<td><code><?=$h($column["name"] ?? "")?></code></td>
									<td><?=$h($column["type"] ?? "")?></td>
									<td><?=$column["magrathea_type"] === null ? '<span class="muted">-</span>' : $h($column["magrathea_type"])?></td>
									<td><?=!empty($column["nullable"]) ? "yes" : '<span class="muted">no</span>'?></td>
									<td><?=$h($column["key"] ?? "")?></td>
									<td><?=($column["default"] ?? null) === null ? '<span class="muted">NULL</span>' : '<code>'.$h($column["default"]).'</code>'?></td>
									<td><?=$h($column["extra"] ?? "")?></td>
								</tr>
							<?php } ?>
						</table>
					<?php } ?>
				</div>
			</details>
		<?php } ?>
	<?php } ?>
</div>
