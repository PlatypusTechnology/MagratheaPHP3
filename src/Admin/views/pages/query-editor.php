<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\QueryEditor;
use Magrathea\DB\Schema;

$adminElements = AdminElements::Instance();
$adminElements->Header("Query Editor");

$schema = new Schema();
$tableName = @$_GET["table"];
if(!is_string($tableName)) $tableName = "";
$type = in_array(@$_GET["type"], QueryEditor::TYPES, true) ? $_GET["type"] : "select";
$tables = [];
$columns = null;
$error = null;
try {
	$tables = array_column($schema->Tables(), "name");
	if($tableName !== "") {
		if(!in_array($tableName, $tables, true)) throw new \Exception("Unknown table [".$tableName."]");
		$columns = $schema->Columns($tableName);
	}
} catch(\Exception $ex) {
	$error = $ex->getMessage();
}

// names come from the database: escaped
$h = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES);
$columnOptions = implode("", array_map(fn($c) => '<option value="'.$h($c["name"]).'">'.$h($c["name"]).'</option>', $columns ?? []));
$operatorOptions = implode("", array_map(fn($o) => '<option value="'.$h($o).'">'.$h($o).'</option>', QueryEditor::OPERATORS));

?>

<div class="container">
	<?php
	if($error !== null) $adminElements->ErrorCard($h($error), "Couldn't read the database");
	?>
	<div class="card">
		<div class="card-header">
			Query
		</div>
		<div class="card-body">
			<div class="row">
				<div class="col-6">
					<?php
					$adminElements->Select("qe-type", "Type", array_combine(QueryEditor::TYPES, QueryEditor::TYPES), $type, "", "",
						["onchange" => "qeShowType();"]);
					?>
				</div>
				<div class="col-6">
					<?php
					$adminElements->Select("qe-table", "Table", array_combine($tables, $tables), $tableName, "", "Choose a table",
						["onchange" => "qeChangeTable();"]);
					?>
				</div>
			</div>

			<?php if($columns !== null) { ?>
			<div class="mt-3" data-types="select">
				<label>Fields <small class="text-muted">(none checked: all)</small></label>
				<div>
					<?php foreach($columns as $column) { ?>
					<label class="form-check form-check-inline">
						<input type="checkbox" class="form-check-input qe-field" data-column="<?=$h($column["name"])?>">
						<?=$h($column["name"])?>
					</label>
					<?php } ?>
				</div>
			</div>

			<div class="mt-3" data-types="insert update">
				<label>Values <small class="text-muted">(only the checked ones are written; on insert, the others get their default)</small></label>
				<table class="table table-sm">
					<?php foreach($columns as $column) { ?>
					<tr class="qe-value-row" data-column="<?=$h($column["name"])?>">
						<td><input type="checkbox" class="form-check-input qe-set"></td>
						<td><?=$h($column["name"])?> <small class="text-muted"><?=$h($column["type"])?></small></td>
						<td><input type="text" class="form-control form-control-sm qe-value"></td>
						<td><label class="form-check"><input type="checkbox" class="form-check-input qe-null"> NULL</label></td>
					</tr>
					<?php } ?>
				</table>
			</div>

			<div class="mt-3" data-types="select update delete">
				<label>Where <small class="text-muted">(all rows must match; update and delete need at least one; IN takes a comma-separated list)</small></label>
				<div id="qe-where"></div>
				<?php $adminElements->Button("Add condition", "qeAddWhere();", ["btn-secondary", "btn-sm"]); ?>
				<template id="qe-where-template">
					<div class="row qe-where-row mb-1">
						<div class="col-4"><select class="form-select form-select-sm qe-where-column"><?=$columnOptions?></select></div>
						<div class="col-3"><select class="form-select form-select-sm qe-where-operator"><?=$operatorOptions?></select></div>
						<div class="col-4"><input type="text" class="form-control form-control-sm qe-where-value"></div>
						<div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest('.qe-where-row').remove();">&times;</button></div>
					</div>
				</template>
			</div>

			<div class="row mt-3" data-types="select">
				<div class="col-4">
					<label for="qe-order">Order by</label>
					<select id="qe-order" class="form-select"><option value="">(none)</option><?=$columnOptions?></select>
				</div>
				<div class="col-2">
					<label for="qe-direction">Direction</label>
					<select id="qe-direction" class="form-select">
						<?php foreach(QueryEditor::DIRECTIONS as $direction) echo '<option value="'.$direction.'">'.$direction.'</option>'; ?>
					</select>
				</div>
				<div class="col-3">
					<label for="qe-limit">Limit</label>
					<input type="number" min="1" id="qe-limit" class="form-control">
				</div>
				<div class="col-3">
					<label for="qe-page">Page <small class="text-muted">(from 0)</small></label>
					<input type="number" min="0" id="qe-page" class="form-control">
				</div>
			</div>

			<div class="right mt-3">
			<?php
			$adminElements->Button("Build", "qeSend(false);", ["btn-secondary"]);
			$adminElements->Button("Run", "qeSend(true);", ["btn-success"]);
			?>
			</div>
			<?php } ?>
		</div>
	</div>

	<div id="query-responses"></div>

</div>

<script type="text/javascript">
const qeTable = <?=json_encode($tableName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
const qePageUrl = <?=json_encode(AdminUrls::Instance()->GetPageUrl("query-editor"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

function qeChangeTable() {
	let table = $("#qe-table").val();
	window.location.href = qePageUrl + "&type=" + encodeURIComponent($("#qe-type").val()) + "&table=" + encodeURIComponent(table);
}
// each section lists the types it's for in data-types
function qeShowType() {
	let type = $("#qe-type").val();
	$("[data-types]").each((i, el) => {
		$(el).toggle($(el).attr("data-types").split(" ").includes(type));
	});
}
function qeAddWhere() {
	$("#qe-where").append($("#qe-where-template").html());
}
// the form as QueryEditor::Build()'s spec: only the keys the type takes. attr(), not data(): data() turns "1" into 1
function qeSpec() {
	let type = $("#qe-type").val();
	let spec = { type, table: qeTable };
	if(type === "select") {
		spec.fields = $(".qe-field:checked").map((i, el) => $(el).attr("data-column")).get();
		let order = $("#qe-order").val();
		if(order) spec.order = { column: order, direction: $("#qe-direction").val() };
		spec.limit = $("#qe-limit").val();
		spec.page = $("#qe-page").val();
	}
	if(type === "insert" || type === "update") {
		spec.values = {};
		$(".qe-value-row").each((i, row) => {
			if(!$(row).find(".qe-set").is(":checked")) return;
			let isNull = $(row).find(".qe-null").is(":checked");
			spec.values[$(row).attr("data-column")] = isNull ? null : $(row).find(".qe-value").val();
		});
	}
	if(type !== "insert") {
		spec.where = $("#qe-where .qe-where-row").map((i, row) => ({
			column: $(row).find(".qe-where-column").val(),
			operator: $(row).find(".qe-where-operator").val(),
			value: $(row).find(".qe-where-value").val(),
		})).get();
	}
	return spec;
}
function qeSend(run) {
	let spec = JSON.stringify(qeSpec());
	callAction("query-editor-card", "POST", { spec, run: run ? "1" : "" })
		.then((rs) => {
			$("#query-responses").prepend(rs);
		});
}
// a write comes back as a confirmation card: it posts the spec it was built from (not the form's) again
function ConfirmEditorQuery(el) {
	let card = $(el).closest(".card");
	let spec = $(card).find(".confirm-spec").val();
	callAction("query-editor-card", "POST", { spec, run: "1", confirm: "1" })
		.then((rs) => {
			$(card).replaceWith(rs);
		});
}
function copyFromCard(el, selector) {
	navigator.clipboard.writeText($(el).closest(".card-body").find(selector).text());
}

afterLoad().then(() => {
	qeShowType();
	// typing a value, or checking NULL, checks the column
	$(document).on("input", ".qe-value", (e) => $(e.target).closest(".qe-value-row").find(".qe-set").prop("checked", true));
	$(document).on("change", ".qe-null", (e) => {
		if($(e.target).is(":checked")) $(e.target).closest(".qe-value-row").find(".qe-set").prop("checked", true);
	});
});
</script>
