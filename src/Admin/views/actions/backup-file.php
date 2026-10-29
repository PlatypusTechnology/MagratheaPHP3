<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFiles;
use Magrathea\DB\Dump;
use Magrathea\Exceptions\MagratheaException;

try {
	$filename = Dump::ValidateBackupFileName(@$_POST["filename"]);
	$viewFile = AdminFiles::ResolveInFolder(Dump::Folder(), $filename);
} catch(MagratheaException $ex) {
	$viewFile = null;
}
if(!$viewFile) {
	AdminElements::Instance()->ErrorCard("invalid backup file");
	return;
}

?>

<div class="card">
	<div class="card-header">
		View File: <?=htmlspecialchars($filename)?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?php include(__DIR__."/../actions/file-view.php"); ?>
	</div>
</div>
