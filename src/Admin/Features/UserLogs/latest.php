<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\Features\UserLogs\AdminLogControl;
use function Magrathea\p_r;

$adminElements = AdminElements::Instance();
$control = new AdminLogControl();
$latest = $control->GetLatest();
?>

<div class="card">
	<div class="card-header">
		Latest Logs
	</div>
	<div class="card-body">
		<?php
		$adminElements->Table($latest,
		[
			"user_id" => "User ID",
			"action" => "Action",
			"victim" => "Victim",
			"created_at" => "Date",
			[
				"title" => "",
				"key" => 	function($item) {
					$viewAction = "javascript:detailLog('".$item->id."')";
					return '<a href="'.$viewAction.'">View</a>';
				}
			]
		]);
		?>
	</div>
</div>


