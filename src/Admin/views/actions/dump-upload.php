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
	$rs = (new DumpManager())->Upload(@$_FILES["upload"], @$_POST["name"]);
} catch(\Exception $ex) {
	$adminElements->Alert("Upload failed: ".htmlspecialchars($ex->getMessage()), "danger");
	return;
}
$adminElements->Alert("Uploaded <b>".htmlspecialchars($rs["file"])."</b> (".htmlspecialchars(MagratheaHelper::FormatSize((int)$rs["size"])).
	"). Nothing ran: import it from the list.", "success");
