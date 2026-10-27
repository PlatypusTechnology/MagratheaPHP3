<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("User Logs");

?>
<style>
<?php
	include("style.css");
?>
</style>

<div class="container">
	<?php include("latest.php"); ?>
	<div id="log-rs"></div>
</div>

<script type="text/javascript">
<?php
	include("scripts.js");
?>
</script>
