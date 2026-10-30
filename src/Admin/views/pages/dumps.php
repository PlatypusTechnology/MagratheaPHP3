<?php

use Magrathea\Admin\AdminElements;
use Magrathea\DB\Dump;
use Magrathea\DB\Schema;

$adminElements = AdminElements::Instance();
$adminElements->Header("Database Dumps");

$folder = null;
$tables = [];
$error = null;
try {
	$folder = Dump::Folder();
	$tables = array_column((new Schema())->Tables(), "name");
} catch(\Exception $ex) {
	$error = $ex->getMessage();
}
$drivers = ["" => "auto (binary when available)", Dump::BINARY => Dump::BINARY, Dump::PHP => Dump::PHP];

// names come from the config and the database: escaped
$h = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES);

?>

<div class="container">
	<?php
	if($error !== null) $adminElements->ErrorCard($h($error), "Couldn't read the folder or the database");
	?>
	<div class="card">
		<div class="card-header">
			Export
		</div>
		<div class="card-body">
			<p><b>Folder</b> <code><?=$h($folder)?></code></p>
			<div class="row">
				<div class="col-6">
					<?php $adminElements->Input("text", "dump-file", "File", Dump::GetDefaultFileName()); ?>
				</div>
				<div class="col-6">
					<?php $adminElements->Select("dump-export-driver", "Driver", $drivers, ""); ?>
				</div>
			</div>
			<div class="mt-3">
				<label>Tables <small class="text-muted">(none checked: every table)</small></label>
				<div>
					<?php foreach($tables as $table) { ?>
					<label class="form-check form-check-inline">
						<input type="checkbox" class="form-check-input dump-table" data-table="<?=$h($table)?>">
						<?=$h($table)?>
					</label>
					<?php } ?>
				</div>
			</div>
			<div class="right mt-3">
				<?php $adminElements->Button("Export", "dumpExport();", ["btn-primary"]); ?>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Upload
		</div>
		<div class="card-body">
			<p class="text-muted">The file is only saved in the folder: importing it is a separate step, from the list below.</p>
			<div class="row">
				<div class="col-6">
					<label for="dump-upload-file">File <small class="text-muted">(.sql)</small></label>
					<input type="file" id="dump-upload-file" class="form-control" accept=".sql">
				</div>
				<div class="col-6">
					<?php $adminElements->Input("text", "dump-upload-name", "Save as", "", "", "", "(the uploaded file's name)"); ?>
				</div>
			</div>
			<div class="right mt-3">
				<?php $adminElements->Button("Upload", "dumpUpload();", ["btn-primary"]); ?>
			</div>
		</div>
	</div>

	<div id="dumps-rs"></div>
	<div id="dumps-list"></div>

</div>

<script type="text/javascript">
afterLoad().then(dumpsLoadList);
function dumpsLoadList() {
	callAction("dumps-list").then(rs => showOn($("#dumps-list"), rs));
}
function dumpsShow(rs) {
	$("#dumps-rs").prepend(rs);
	dumpsLoadList();
}
function dumpExport() {
	let tables = $(".dump-table:checked").map((i, el) => $(el).attr("data-table")).get();
	callAction("dump-export", "POST", { file: $("#dump-file").val(), tables, driver: $("#dump-export-driver").val() })
		.then(dumpsShow);
}
// callAction() can't send a file: FormData, with the CSRF token in the body
function dumpUpload() {
	let input = $("#dump-upload-file")[0];
	if(!input.files.length) {
		showToast("choose a .sql file", "Upload", true);
		return;
	}
	let data = new FormData();
	data.append("upload", input.files[0]);
	data.append("name", $("#dump-upload-name").val());
	data.append("magrathea_csrf_token", magrathea_csrf_token);
	showLoading();
	$.ajax({
		url: getCurrentPage() + "?magrathea_subpage=dump-upload",
		method: "POST",
		data,
		processData: false,
		contentType: false,
	})
		.done(rs => {
			$(input).val("");
			dumpsShow(rs);
		})
		.fail(err => showToast($("<span>").text(err.statusText).html(), "Upload failed", true))
		.always(hideLoading);
}
function dumpImport(file) {
	callAction("dump-import", "POST", { file }).then(rs => $("#dumps-rs").prepend(rs));
}
// the confirmation card: the button is enabled only when the typed name matches (the server checks it again)
function dumpConfirmInput(el) {
	let card = $(el).closest(".card");
	card.find(".dump-confirm-btn").prop("disabled", $(el).val() !== card.find(".dump-confirm-database").val());
}
function dumpConfirmImport(el) {
	let card = $(el).closest(".card");
	callAction("dump-import", "POST", {
		file: card.find(".dump-confirm-file").val(),
		confirm: card.find(".dump-confirm-name").val(),
		driver: card.find(".dump-confirm-driver").val(),
		continue: card.find(".dump-confirm-continue").is(":checked") ? "1" : "",
	}).then(rs => {
		card.replaceWith(rs);
		dumpsLoadList();
	});
}
function dumpDelete(file) {
	if(!confirm("Delete " + file + "?")) return;
	callAction("dump-delete", "POST", { file }).then(dumpsShow);
}
</script>
