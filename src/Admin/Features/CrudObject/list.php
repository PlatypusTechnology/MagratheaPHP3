<div class="card">
	<div class="card-header">
		<?=\Magrathea\Admin\AdminManager::Instance()->GetActiveFeature()->objectName?> List
	</div>
	<div class="card-body">
<?php
Magrathea\Admin\AdminElements::Instance()->Table($list, $columns);
?>
	</div>
</div>

