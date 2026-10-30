<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\DumpManager;
use Magrathea\MagratheaHelper;

$adminElements = AdminElements::Instance();
// POST only: admin CSRF tokens are checked on POST
if(@$_SERVER["REQUEST_METHOD"] !== "POST") {
	http_response_code(405);
	$adminElements->Alert("POST only", "danger");
	return;
}
try {
	$rs = (new DumpManager())->Export(@$_POST["file"], @$_POST["tables"] ?? [], @$_POST["driver"]);
} catch(\Exception $ex) {
	$adminElements->Alert("Export failed: ".htmlspecialchars($ex->getMessage()), "danger");
	return;
}
$message = "Exported <b>".htmlspecialchars($rs["file"])."</b> (".htmlspecialchars(MagratheaHelper::FormatSize((int)$rs["size"])).
	") with the <b>".htmlspecialchars($rs["driver"])."</b> driver.";
if(!empty($rs["skipped"])) {
	$message .= "<br/>Not exported (the php driver can't export routines): ".htmlspecialchars(implode(", ", $rs["skipped"]));
}
$adminElements->Alert($message, empty($rs["skipped"]) ? "success" : "warning");
