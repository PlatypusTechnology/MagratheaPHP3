<?php
use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\AdminUrls;
use Magrathea\MagratheaPHP;

$adminElements = AdminElements::Instance();
$adminManager = AdminManager::Instance();

$pageTitle = $adminManager->GetTitle();
$adminElements->Header($pageTitle);

$magPhp = MagratheaPHP::Instance();
$appVersion = $magPhp->AppVersion();
$versionLine = "MagratheaPHP ".MagratheaPHP::Version();
if ($appVersion !== "???") $versionLine = "v".ltrim($appVersion, "vV")." · ".$versionLine;

?>

<div id="wrapper" class="container setup-wrapper center">
	<?php
		$adminManager->PrintLogo(400);
	?>
	<div class="small mt-2">
		<a class="text-muted text-decoration-none" href="<?=htmlspecialchars(AdminUrls::Instance()->GetPageUrl("version"))?>"><?=htmlspecialchars($versionLine)?></a>
	</div>
</div>
