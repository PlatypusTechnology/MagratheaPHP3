<?php

use Magrathea\Admin\AdminElements;

/** @var array $data ObjectsExplorer::Page() */

$elements = AdminElements::Instance();
$model = $data["model"];

// the id goes inside an onclick: a JSON string (\u escapes), then escaped for the attribute
$editLink = function($id): string {
	$idJs = json_encode((string)$id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	return '<a href="#" onclick="event.preventDefault(); objectsEdit('.htmlspecialchars($idJs, ENT_QUOTES).');">Edit</a>';
};
// cells are data: Table() escapes them. The edit link is a callable row value: printed raw
$rows = array_map(function($row) use ($editLink) {
	$id = $row["id"];
	return $row["cells"] + ["magrathea_edit" => fn() => $editLink($id)];
}, $data["rows"]);
// column names come from the model's code; Table() prints titles raw
$columns = [];
foreach($data["columns"] as $column) $columns[$column] = htmlspecialchars($column);
$columns["magrathea_edit"] = "";

$first = $data["total"] === 0 ? 0 : $data["page"] * $data["limit"] + 1;
$last = $data["page"] * $data["limit"] + count($data["rows"]);
?>

<div class="card">
	<div class="card-header">
		<?=htmlspecialchars($model["name"])?> List
		<span class="text-muted small">(<?=$first?>–<?=$last?> of <?=$data["total"]?>)</span>
	</div>
	<div class="card-body table-scroll">
		<?php
		if(empty($rows)) {
			echo $data["total"] > 0 ? "No objects on this page." : "No objects yet.";
		} else {
			$elements->Table($rows, $columns);
		}
		if($data["pages"] > 1) {
			?>
			<div class="d-flex justify-content-between align-items-center">
				<div>
					<?php if($data["page"] > 0) $elements->Button("&larr; Previous", "objectsLoadList(".($data["page"] - 1).")", ["btn-secondary"]); ?>
				</div>
				<div class="small text-muted">page <?=$data["page"] + 1?> of <?=$data["pages"]?></div>
				<div>
					<?php if($data["page"] + 1 < $data["pages"]) $elements->Button("Next &rarr;", "objectsLoadList(".($data["page"] + 1).")", ["btn-secondary"]); ?>
				</div>
			</div>
			<?php
		}
		?>
		<input type="hidden" id="objects-page" value="<?=$data["page"]?>" />
	</div>
</div>
