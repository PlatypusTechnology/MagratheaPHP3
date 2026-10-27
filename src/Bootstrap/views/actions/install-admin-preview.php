<?php

$view = $_GET["view"];

if($view == "code") {
	$adminInstall = new Magrathea\Admin\Install();
	$adminCode = htmlspecialchars($adminInstall->GetAdminCode());
	die($adminCode);
}

if($view == "db") {
	$adminDb = Magrathea\Admin\AdminDatabase::Instance();
	$adminSQL = $adminDb->getSQLFileContents();
	die($adminSQL);
}
