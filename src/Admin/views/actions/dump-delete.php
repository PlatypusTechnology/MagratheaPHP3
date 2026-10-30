<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\DumpManager;

$adminElements = AdminElements::Instance();
// POST only: admin CSRF tokens are checked on POST
if(@$_SERVER["REQUEST_METHOD"] !== "POST") {
	http_response_code(405);
	$adminElements->Alert("POST only", "danger");
	return;
}
try {
	$rs = (new DumpManager())->Delete(@$_POST["file"]);
} catch(\Exception $ex) {
	$adminElements->Alert("Delete failed: ".htmlspecialchars($ex->getMessage()), "danger");
	return;
}
$adminElements->Alert("Deleted <b>".htmlspecialchars($rs["file"])."</b>.", "success");
