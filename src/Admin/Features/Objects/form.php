<?php

use Magrathea\Admin\AdminForm;

/** @var \Magrathea\Admin\ObjectsExplorer $manager */
/** @var \Magrathea\MagratheaModel $object */
/** @var array $model ObjectsExplorer::Model() */
/** @var bool $isNew */

$fields = $manager->GetModelFields()->Fields($object, $isNew);
$fields[] = ["key" => "magrathea_object", "type" => "hidden", "size" => "hidden"];
$fields[] = ["key" => "magrathea_object_id", "type" => "hidden", "size" => "hidden"];
if(count(array_filter($fields, fn($f) => $f["type"] !== "hidden")) % 2 != 0) {
	$fields[] = ["type" => "empty", "key" => "objects-form-spacer"];
}
if($isNew) {
	$fields[] = ["type" => "empty", "key" => "objects-form-gap", "size" => "col-9"];
} else {
	$fields[] = ["type" => "empty", "key" => "objects-form-gap", "size" => "col-6"];
	$fields[] = ["name" => "Delete", "type" => "button", "class" => ["w-100", "btn-danger"], "size" => "col-3", "action" => "objectsDelete(this)"];
}
$fields[] = ["name" => "Save", "type" => "button", "class" => ["w-100", "btn-success"], "size" => "col-3", "action" => "objectsSave(this)"];

// an array, not the model: AdminForm::Build() adds no hidden PK, the form posts magrathea_object_id instead
$values = [];
foreach(array_keys((array)$object->GetDbValues()) as $key) $values[$key] = $object->$key;
$values["magrathea_object"] = $model["class"];
$values["magrathea_object_id"] = $isNew ? "" : (string)$object->GetID();
$title = $isNew ? "New <b>".htmlspecialchars($model["name"])."</b>" : "Edit <b>".htmlspecialchars($object->Ref())."</b>";
$timestamps = array_filter([
	"created" => $object->created_at,
	"updated" => $object->updated_at,
], fn($v) => $v !== null && $v !== "");
?>

<div class="card">
	<div class="card-header">
		<?=$title?>
		<?php foreach($timestamps as $label => $value) { ?>
			<span class="text-muted small ms-2"><?=$label?> <?=htmlspecialchars((string)$value)?></span>
		<?php } ?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?php
		$form = new AdminForm();
		$form->SetName("objects-form-data")->Build($fields, $values)->Print();
		?>
	</div>
</div>
