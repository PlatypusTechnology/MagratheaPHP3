<?php
// $data: Steps::Dumps(); $result: Steps::Import(), Steps::Upload() or Steps::Paste(). Helpers: see index.php
use Magrathea\Admin\DumpManager;
use Magrathea\DB\Dump;
use Magrathea\MagratheaHelper;

$source = $result["source"] ?? null;
// the confirmation, driver and "continue" fields, once per form ($id: unique suffix for the ids)
$importFields = function(string $id) use ($h, $data) {
	?>
	<div class="alert level-warning">
		A dump usually drops and recreates its tables: what they hold now is lost.
		To confirm, type the database's name, <b><?=$h($data["database"])?></b>.
	</div>
	<div class="grid">
		<div class="field">
			<label for="confirm-<?=$id?>">Database's name</label>
			<input type="text" id="confirm-<?=$id?>" name="confirm" autocomplete="off">
		</div>
		<div class="field">
			<label for="driver-<?=$id?>">Driver</label>
			<select id="driver-<?=$id?>" name="driver">
				<option value="">auto (binary when available)</option>
				<option value="<?=$h(Dump::BINARY)?>"><?=$h(Dump::BINARY)?> (mysql)</option>
				<option value="<?=$h(Dump::PHP)?>"><?=$h(Dump::PHP)?></option>
			</select>
		</div>
		<div class="field checkbox wide">
			<input type="checkbox" id="continue-<?=$id?>" name="continue" value="1"> <label for="continue-<?=$id?>">Continue past errors</label>
		</div>
	</div>
	<?php
};
?>
<div class="panel">
	<h2>Import <span class="muted">(optional)</span></h2>
	<?php
	if(isset($result["import"])) {
		$import = $result["import"];
		if($import["status"] === DumpManager::CONFIRM) {
			echo '<div class="alert level-warning">'.$md($import["error"] ?? "Type the database's name to confirm the import.").' Nothing ran'.($source === "upload" ? ': choose the file again.' : '.').'</div>';
		} else {
			$rs = $import["result"];
			$count = $rs["statements"] === null ? "the binary driver doesn't count statements" : (int)$rs["statements"]." statements ran";
			$errors = $rs["errors"] ?? [];
			$kept = $source === null ? "" : (!empty($import["temporary"]) ? " (from a temporary file, deleted: there's no backups folder)" : " (saved in the backups folder)");
			echo '<div class="alert level-'.($errors ? "WARNING" : "OK").'">Imported <b>'.$h($import["file"]).'</b>'.$h($kept).' into <b>'.$h($import["database"]).'</b> with the <b>'.$h($rs["driver"]).'</b> driver: '.$h($count).'.</div>';
			if($errors) {
				echo '<div class="alert level-error">'.count($errors).' error(s), continued past them. The first one: '.$md($result["firstError"]).'</div>';
			}
		}
	}
	?>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else if($data["unsetMessage"] !== null) { ?>
		<div class="alert level-error"><?=$md($data["unsetMessage"])?></div>
	<?php } else if(!$data["configured"]) { ?>
		<div class="alert level-info">The config has no database: there's nothing to import into.</div>
	<?php } else { ?>
		<p>Runs a dump on the database: one of the backups folder (<code><?=$h($data["folder"] ?? "")?></code>), an uploaded file or pasted SQL. Skip this step for an empty project.</p>
		<?php if($data["error"] !== null) echo '<div class="alert level-error">'.$md($data["error"]).'</div>'; ?>

		<?php if(!empty($data["files"])) { ?>
			<h3>From the backups folder</h3>
			<form method="post" action="<?=$h($wizard->GetStepLink("import"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="import-dump">
				<table>
					<tr><th></th><th>File</th><th>Size</th><th>Date</th></tr>
					<?php foreach($data["files"] as $i => $file) { ?>
						<tr>
							<td><input type="radio" name="file" value="<?=$h($file["name"])?>" id="dump-<?=$i?>" <?=$file["usable"] ? "" : "disabled"?> <?=$i === 0 && $file["usable"] ? "checked" : ""?>></td>
							<td><label for="dump-<?=$i?>"><?=$h($file["name"])?></label></td>
							<td><?=$h(MagratheaHelper::FormatSize((int)$file["size"]))?></td>
							<td><?=$h(date("Y-m-d H:i", (int)$file["date"]))?></td>
						</tr>
					<?php } ?>
				</table>
				<?php $importFields("file"); ?>
				<button type="submit" class="danger">Import</button>
			</form>
		<?php } ?>

		<h3>New import</h3>
		<p class="muted">
			<?php if($data["temporary"]) { ?>
				There's no writable backups folder: the SQL runs from a temporary file, deleted afterwards.
			<?php } else { ?>
				The SQL is saved in the backups folder as <code>import-YYYYmmdd-His.sql</code>, then it runs.
			<?php } ?>
		</p>
		<details class="accordion" name="import-source" <?=$source !== "paste" ? "open" : ""?>>
			<summary>Upload a dump</summary>
			<form method="post" enctype="multipart/form-data" action="<?=$h($wizard->GetStepLink("import"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="upload-dump">
				<div class="field">
					<label for="upload">.sql file</label>
					<input type="file" id="upload" name="upload" accept=".sql">
				</div>
				<?php $importFields("upload"); ?>
				<button type="submit" class="danger">Upload and import</button>
			</form>
		</details>
		<details class="accordion" name="import-source" <?=$source === "paste" ? "open" : ""?>>
			<summary>Paste SQL</summary>
			<form method="post" action="<?=$h($wizard->GetStepLink("import"))?>">
				<?=$csrfField()?>
				<input type="hidden" name="action" value="paste-sql">
				<div class="field">
					<label for="sql">Statements</label>
					<textarea id="sql" name="sql" rows="12" spellcheck="false"><?=$h($result["sql"] ?? "")?></textarea>
				</div>
				<?php $importFields("paste"); ?>
				<button type="submit" class="danger">Import</button>
			</form>
		</details>
	<?php } ?>
</div>
