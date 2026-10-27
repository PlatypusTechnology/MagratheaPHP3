<?php

use Magrathea\Admin\AdminElements;
use Magrathea\MagratheaPHP;

$base = MagratheaPHP::Instance()->appRoot;

if(isset($viewFile)) {
	$file = $viewFile;
} else {
	$file = @$_POST["file"];
}
if(empty($file)) {
	\Magrathea\Admin\AdminElements::Instance()->Alert("file can't be empty", "danger");
	die;
}

$file = $base."/".$file;

if(!file_exists($file)) {
	$msg = "file [".$file."] does not exist";
	\Magrathea\Admin\AdminElements::Instance()->Alert($msg, "danger");
	die;
}

?>

<div class="card">
	<div class="card-header">
		<?=$file?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<pre class="code file-pre">
<?php
$lines = @$_POST["lines"];
if(empty($lines)) {
	echo htmlentities(file_get_contents($file));
	die;
}
?>
		</pre>
	</div>
</div>
