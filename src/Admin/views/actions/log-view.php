<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFiles;
use Magrathea\Logger;

// a log file: a plain name of a file inside the logs folder
$viewFile = AdminFiles::ResolveInFolder(Logger::Instance()->GetLogPath(), @$_POST["file"]);
if(!$viewFile) {
	AdminElements::Instance()->ErrorCard("invalid log file");
	return;
}

// tail: the last 1–10000 lines
$viewLines = null;
if(!empty($_POST["lines"])) {
	$viewLines = min(max((int)$_POST["lines"], 1), 10000);
}

include(__DIR__."/file-view.php");
