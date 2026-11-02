<?php
// $data: Steps::Dumps(); $result: Steps::Upload() or Steps::Import(). Helpers: see index.php
use Magrathea\Admin\DumpManager;
use Magrathea\DB\Dump;
use Magrathea\MagratheaHelper;
?>
<div class="panel">
	<h2>Import <span class="muted">(optional)</span></h2>
	<?php
	if(isset($result["uploaded"])) {
		echo '<div class="alert level-ok">Uploaded <b>'.$h($result["uploaded"]["file"]).'</b> ('.$h(MagratheaHelper::FormatSize((int)$result["uploaded"]["size"])).'). Nothing ran: import it below.</div>';
	}
	if(isset($result["import"])) {
		$import = $result["import"];
		if($import["status"] === DumpManager::CONFIRM) {
			echo '<div class="alert level-warning">'.$md($import["error"] ?? "Type the database's name to confirm the import.").' Nothing ran.</div>';
		} else {
			$rs = $import["result"];
			$count = $rs["statements"] === null ? "the binary driver doesn't count statements" : (int)$rs["statements"]." statements ran";
			$errors = $rs["errors"] ?? [];
			echo '<div class="alert level-'.($errors ? "WARNING" : "OK").'">Imported <b>'.$h($import["file"]).'</b> into <b>'.$h($import["database"]).'</b> with the <b>'.$h($rs["driver"]).'</b> driver: '.$h($count).'.</div>';
			if($errors) {
				echo '<div class="alert level-error">'.count($errors).' error(s), continued past them. The first one: '.$md($result["firstError"]).'</div>';
			}
		}
	}
	?>
	<?php if($data["needsConfig"]) { ?>
		<div class="alert level-warning">There's no config yet: save the <a href="<?=$h($wizard->GetStepLink("configuration"))?>">configuration</a> first.</div>
	<?php } else { ?>
		<p>Runs a dump of the backups folder (<code><?=$h($data["folder"] ?? "")?></code>) on the database. Skip this step for an empty project.</p>
		<?php if($data["error"] !== null) echo '<div class="alert level-error">'.$md($data["error"]).'</div>'; ?>

		<?php if(!$data["configured"]) { ?>
			<div class="alert level-info">The config has no database: there's nothing to import into.</div>
		<?php } else if(empty($data["files"])) { ?>
			<p class="muted">No <code>.sql</code> file in the backups folder yet: upload one below.</p>
		<?php } else { ?>
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
				<div class="alert level-warning">
					A dump usually drops and recreates its tables: what they hold now is lost.
					To confirm, type the database's name, <b><?=$h($data["database"])?></b>.
				</div>
				<div class="grid">
					<div class="field">
						<label for="confirm">Database's name</label>
						<input type="text" id="confirm" name="confirm" autocomplete="off">
					</div>
					<div class="field">
						<label for="driver">Driver</label>
						<select id="driver" name="driver">
							<option value="">auto (binary when available)</option>
							<option value="<?=$h(Dump::BINARY)?>"><?=$h(Dump::BINARY)?> (mysql)</option>
							<option value="<?=$h(Dump::PHP)?>"><?=$h(Dump::PHP)?></option>
						</select>
					</div>
					<div class="field checkbox wide">
						<input type="checkbox" id="continue" name="continue" value="1"> <label for="continue">Continue past errors</label>
					</div>
				</div>
				<button type="submit" class="danger">Import</button>
			</form>
		<?php } ?>

		<h3>Upload a dump</h3>
		<form method="post" enctype="multipart/form-data" action="<?=$h($wizard->GetStepLink("import"))?>">
			<?=$csrfField()?>
			<input type="hidden" name="action" value="upload-dump">
			<div class="grid">
				<div class="field">
					<label for="upload">.sql file</label>
					<input type="file" id="upload" name="upload" accept=".sql">
				</div>
				<div class="field">
					<label for="name">Save as <span class="muted">(optional)</span></label>
					<input type="text" id="name" name="name" placeholder="the file's name" autocomplete="off">
				</div>
			</div>
			<button type="submit" class="secondary">Upload</button>
			<p class="muted">It's only saved in the backups folder: importing it is the form above.</p>
		</form>
	<?php } ?>
</div>
