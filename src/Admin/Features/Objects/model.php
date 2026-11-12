<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;

/** @var \Magrathea\Admin\ObjectsExplorer $manager */
/** @var \Magrathea\Admin\Features\Objects\AdminFeatureObjects $this */
/** @var string $objectClass */

$elements = AdminElements::Instance();
$urls = AdminUrls::Instance();

try {
	$structure = $manager->Structure($objectClass);
	$error = null;
} catch(\Exception $ex) {
	$structure = null;
	$error = $ex->getMessage();
}
$model = $structure["model"] ?? null;
$elements->Header($model ? htmlspecialchars($model["name"]) : "Objects");

// inside a <script> or an attribute: JSON strings, with <, >, &, ' and " as \u escapes
$jsString = fn($value) => json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div class="container">
	<a href="<?=htmlspecialchars($urls->GetFeatureUrl($this->featureId))?>">&larr; All objects</a>
	<?php
	if($structure === null) {
		$elements->ErrorCard(htmlspecialchars($error), "Couldn't open the object");
		echo "</div>";
		return;
	}
	?>
	<input type="hidden" id="objects-feature-id" value="<?=htmlspecialchars($this->featureId)?>" />
	<input type="hidden" id="objects-class" value="<?=htmlspecialchars($model["class"])?>" />

	<div class="row mt-2">
		<div class="col-12 right">
			<?php $elements->Button("New ".htmlspecialchars($model["name"]), "objectsNew()", ["btn-success"]); ?>
		</div>
	</div>

	<div class="row">
		<div class="col-12 mt-2" id="objects-form"></div>
	</div>

	<div class="row">
		<div class="col-12" id="objects-list">
			<?php $this->List(); ?>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Structure: <code><?=htmlspecialchars($model["class"])?></code>
			on table <a href="<?=htmlspecialchars($urls->GetPageUrl("db-explorer", null, ["table" => $model["table"]]))?>"><code><?=htmlspecialchars((string)$model["table"])?></code></a>
		</div>
		<div class="card-body table-scroll">
			<?php
			$elements->Findings($structure["findings"]);
			$elements->Table($structure["fields"], [
				["title" => "Field", "key" => fn($f) => "<code>".htmlspecialchars($f["field"])."</code>".($f["pk"] ? ' <span class="badge bg-secondary">PK</span>' : "")],
				["title" => "Type", "key" => "type"],
				["title" => "Relation", "key" => fn($f) => $f["relation"] ? "<code>".htmlspecialchars($f["relation"])."</code>" : ""],
			]);
			?>
			<div class="small text-muted">The structure comes from the model's code. To change it, change the model and its table.</div>
		</div>
	</div>
</div>

<?php
$openId = @$_GET["id"];
if($openId !== null && is_scalar($openId) && (string)$openId !== "") {
	?>
<script type="text/javascript">
	afterLoad().then(() => objectsEdit(<?=$jsString($openId)?>));
</script>
	<?php
}
