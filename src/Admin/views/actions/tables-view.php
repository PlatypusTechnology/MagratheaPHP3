<?php
$allTables = @$_GET["show-all"];
$showAll = ($allTables == "true");

$tables = (new Magrathea\DB\Schema())->Tables($showAll);
?>
<div class="list-group list-group-flush bg-light">
<?php
	foreach($tables as $t) {
		// names come from the database: escaped (a JS string inside the attribute)
		$tableClick = "tableClick(".json_encode($t["name"]).")";
		echo '<a class="list-group-item pointer" onclick="'.htmlspecialchars($tableClick).'">'.htmlspecialchars($t["name"]).'</a>';
	}
?>
</div>


