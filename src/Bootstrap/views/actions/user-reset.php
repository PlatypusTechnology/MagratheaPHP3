<?php


use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\MagratheaPHP;

MagratheaPHP::Instance()->Connect();

$control = new AdminUserControl();
$data = $_POST;

$user = new AdminUser($data["id"]);
$rs = $control->SetNewPassword($user, $data["new_pass"]);
echo $rs["success"];
