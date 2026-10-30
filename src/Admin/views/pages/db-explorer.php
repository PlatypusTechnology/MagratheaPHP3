<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\DatabaseExplorer;
use Magrathea\TimezoneCheck;

$adminElements = AdminElements::Instance();
$explorer = new DatabaseExplorer();
$tableName = @$_GET["table"];

// names, models and values come from the database and the code: escaped, here and in Table()
$statusBadge = function(?string $level): string {
	if($level === null) return '<span class="text-muted">no model</span>';
	$classes = [
		TimezoneCheck::OK => "success",
		TimezoneCheck::INFO => "secondary",
		TimezoneCheck::WARNING => "warning",
		TimezoneCheck::ERROR => "danger",
	];
	return '<span class="badge bg-'.($classes[$level] ?? "secondary").'">'.htmlspecialchars($level).'</span>';
};
$classList = fn(array $classes): string => implode("<br/>", array_map(fn($c) => "<code>".htmlspecialchars($c)."</code>", $classes));

if(!is_string($tableName) || $tableName === "") {
	$adminElements->Header("Database Explorer");
	try {
		$data = $explorer->Tables();
	} catch(\Exception $ex) {
		$data = null;
		$error = $ex->getMessage();
	}
?>

<div class="container">
	<?php
	if($data === null) {
		$adminElements->ErrorCard(htmlspecialchars($error), "Couldn't read the database");
	} else {
	?>
	<div class="card">
		<div class="card-header">
			Tables
		</div>
		<div class="card-body table-scroll">
			<?php
			$adminElements->Findings($data["findings"]);
			$adminElements->Table($data["tables"], [
				["title" => "Table", "key" => fn($t) => '<a href="'.htmlspecialchars(AdminUrls::Instance()->GetPageUrl("db-explorer", null, ["table" => $t["name"]])).'">'.htmlspecialchars($t["name"]).'</a>'],
				["title" => "Type", "key" => "type"],
				["title" => "Rows (approx.)", "key" => "rows"],
				["title" => "Engine", "key" => "engine"],
				["title" => "Model", "key" => fn($t) => $classList($t["models"])],
				["title" => "Status", "key" => fn($t) => $statusBadge($t["status"])],
			]);
			?>
		</div>
	</div>
	<?php } ?>
</div>

<?php
} else {
	$adminElements->Header("Table ".htmlspecialchars($tableName));
	try {
		$table = $explorer->Table($tableName);
	} catch(\Exception $ex) {
		$table = null;
		$error = $ex->getMessage();
	}
?>

<div class="container">
	<a href="<?=htmlspecialchars(AdminUrls::Instance()->GetPageUrl("db-explorer"))?>">&larr; All tables</a>
	<?php
	if($table === null) {
		$adminElements->ErrorCard(htmlspecialchars($error), "Couldn't read the table");
	} else {
	?>
	<div class="card">
		<div class="card-header">
			Columns
		</div>
		<div class="card-body table-scroll">
			<?php
			$adminElements->Table($table["columns"], [
				["title" => "Name", "key" => "name"],
				["title" => "Type", "key" => "type"],
				["title" => "Null", "key" => fn($c) => $c["nullable"] ? "yes" : "no"],
				["title" => "Default", "key" => fn($c) => $c["default"] === null ? '<em class="text-muted">none</em>' : htmlspecialchars((string)$c["default"])],
				["title" => "Extra", "key" => "extra"],
				["title" => "Key", "key" => "key"],
				["title" => "Magrathea type", "key" => fn($c) => $c["magrathea_type"] === null ? '<em class="text-muted">none</em>' : htmlspecialchars($c["magrathea_type"])],
			]);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Indexes
		</div>
		<div class="card-body table-scroll">
			<?php
			$adminElements->Table($table["indexes"], [
				["title" => "Name", "key" => "name"],
				["title" => "Unique", "key" => fn($i) => $i["unique"] ? "yes" : "no"],
				["title" => "Type", "key" => "type"],
				["title" => "Columns", "key" => fn($i) => htmlspecialchars(implode(", ", $i["columns"]))],
			]);
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Model
		</div>
		<div class="card-body">
			<?php
			if(empty($table["models"])) {
				echo "No model maps this table.";
			} else {
				echo "<p>".$classList($table["models"])."</p>";
				if(empty($table["findings"])) $adminElements->Findings([["level" => TimezoneCheck::OK, "message" => "The model and the table agree."]]);
				else $adminElements->Findings($table["findings"]);
			}
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			<code>SHOW CREATE TABLE</code>
		</div>
		<div class="card-body">
			<pre class="code-light" id="create-statement"><?=htmlspecialchars($table["create"])?></pre>
			<div class="right">
			<?php
			$adminElements->Button("Copy", "navigator.clipboard.writeText(document.getElementById('create-statement').innerText);", ["btn-secondary"]);
			?>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			First <?=DatabaseExplorer::PREVIEW_ROWS?> rows
		</div>
		<div class="card-body table-scroll">
			<?php
			if($table["preview"]["error"] !== null) {
				$adminElements->Alert(htmlspecialchars($table["preview"]["error"]), "danger", false);
			} else {
				$adminElements->Table($table["preview"]["rows"]);
			}
			?>
		</div>
	</div>
	<?php } ?>
</div>

<?php
}
