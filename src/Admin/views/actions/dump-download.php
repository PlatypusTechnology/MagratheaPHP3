<?php

use Magrathea\Admin\DumpManager;

// a GET (a link): it only reads. The name is checked by DumpManager::Resolve()
try {
	$download = (new DumpManager())->Download(@$_GET["file"]);
} catch(\Exception $ex) {
	http_response_code(404);
	header("Content-Type: text/plain; charset=utf-8");
	echo "dump not found";
	return;
}
while(ob_get_level() > 0) ob_end_clean();
foreach($download["headers"] as $header) header($header);
readfile($download["path"]);
