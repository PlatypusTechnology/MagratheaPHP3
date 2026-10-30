<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\CheckReport;

$adminElements = AdminElements::Instance();
$adminElements->Header("Checks");

?>

<div class="container">
	<?php
	// ProjectCheck::Run() never throws: whatever fails is a finding
	$adminElements->CheckReport(CheckReport::Build());
	?>
</div>
