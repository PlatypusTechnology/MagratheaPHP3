<?php
use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminManager;

$adminElements = AdminElements::Instance();
$adminManager = AdminManager::Instance();

$pageTitle = $adminManager->GetTitle();
$adminElements->Header($pageTitle);

?>

<div id="wrapper" class="container setup-wrapper center">
	<?php
		Magrathea\Admin\AdminManager::Instance()->PrintLogo(400);
	?>
</div>
