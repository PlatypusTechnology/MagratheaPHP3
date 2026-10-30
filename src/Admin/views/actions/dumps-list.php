<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\DumpManager;
use Magrathea\MagratheaHelper;

$adminElements = AdminElements::Instance();
try {
	$files = (new DumpManager())->Files();
} catch(\Exception $ex) {
	$adminElements->ErrorCard(htmlspecialchars($ex->getMessage()), "Couldn't list the dumps");
	return;
}

// names come from the folder: escaped, and passed to onclick as JSON
$actions = function(array $f): string {
	if(!$f["usable"]) return '<span class="text-muted">name not accepted: rename it</span>';
	$download = AdminUrls::Instance()->GetPageUrl(null, "dump-download", ["file" => $f["name"]]);
	$fileJs = htmlspecialchars(json_encode($f["name"]), ENT_QUOTES);
	return '<a href="'.htmlspecialchars($download).'">download</a> · '.
		'<a href="#" onclick="dumpImport('.$fileJs.'); return false;">import</a> · '.
		'<a href="#" class="text-danger" onclick="dumpDelete('.$fileJs.'); return false;">delete</a>';
};

?>

<div class="card">
	<div class="card-header">
		Files
	</div>
	<div class="card-body table-scroll">
		<?php
		if(empty($files)) {
			echo "No .sql file in the folder yet.";
		} else {
			$adminElements->Table($files, [
				["title" => "File", "key" => "name"],
				["title" => "Size", "key" => fn($f) => htmlspecialchars(MagratheaHelper::FormatSize((int)$f["size"]))],
				["title" => "Modified", "key" => fn($f) => date("Y-m-d H:i:s", $f["date"])],
				["title" => "...", "key" => $actions],
			]);
		}
		?>
	</div>
</div>
