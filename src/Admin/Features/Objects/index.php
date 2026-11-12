<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;

/** @var \Magrathea\Admin\ObjectsExplorer $manager */
/** @var \Magrathea\Admin\Features\Objects\AdminFeatureObjects $this */

$elements = AdminElements::Instance();
$elements->Header("Objects");

try {
	$data = $manager->Models();
	$error = null;
} catch(\Exception $ex) {
	$data = null;
	$error = $ex->getMessage();
}

// class names, tables and files come from the code: escaped, here and in Table()
$featureId = $this->featureId;
$objectLink = function(array $m) use ($featureId): string {
	$url = AdminUrls::Instance()->GetFeatureUrl($featureId, null, ["object" => $m["class"]]);
	return '<a href="'.htmlspecialchars($url).'"><b>'.htmlspecialchars($m["name"]).'</b></a>';
};
$rowsCell = function(array $m): string {
	if($m["hasTable"] === false) return '<span class="badge bg-danger">no table</span>';
	return $m["rows"] === null ? '<span class="text-muted">?</span>' : htmlspecialchars((string)$m["rows"]);
};
?>

<div class="container">
	<?php
	if($data === null) {
		$elements->ErrorCard(htmlspecialchars($error), "Couldn't find the models");
	} else {
	?>
	<div class="card">
		<div class="card-header">
			Models
		</div>
		<div class="card-body table-scroll">
			<?php
			$elements->Findings($data["findings"]);
			if($data["error"]) $elements->Alert("Couldn't read the tables: ".htmlspecialchars($data["error"]), "warning", false);
			if(empty($data["models"])) {
				$elements->Alert("No model found in the code folders.", "info", false);
			} else {
				$elements->Table($data["models"], [
					["title" => "Object", "key" => $objectLink],
					["title" => "Class", "key" => fn($m) => "<code>".htmlspecialchars($m["class"])."</code>"],
					["title" => "Table", "key" => "table"],
					["title" => "Fields", "key" => fn($m) => (string)count($m["values"])],
					["title" => "Rows (approx.)", "key" => $rowsCell],
				]);
			}
			?>
		</div>
	</div>
	<?php } ?>
</div>
