<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\UsageException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    AdminResetPasswordCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea admin:reset-password <email> [--password=…]`: sets an admin user's password
* (`AdminUserControl::SetNewPassword()`, which logs `change-password`). Without `--password` it generates one
* (`GENERATED_BYTES` random bytes, in hex) and prints it, once. It doesn't activate an inactive user
*/
class AdminResetPasswordCommand extends Command {

	/** a generated password: 16 hex characters */
	const GENERATED_BYTES = 8;

	private ?AdminUserControl $control;

	/** @param 	AdminUserControl|null 	$control 	the users (default: `new AdminUserControl()`, created when it runs) */
	public function __construct(?AdminUserControl $control = null) {
		$this->control = $control;
	}

	public function Name(): string { return "admin:reset-password"; }
	public function Summary(): string { return "Sets an admin user's password; without --password, generates one and prints it once"; }
	public function Arguments(): array { return ["email" => true]; }
	public function Options(): array { return ["password" => true]; }

	public function Execute(array $arguments, array $options): Result {
		$this->control ??= new AdminUserControl();
		$email = $arguments["email"];
		$user = $this->control->GetByEmail($email);
		if(!$user) throw new \RuntimeException("No admin user with the e-mail [".$email."]");
		$generated = !isset($options["password"]);
		$password = $generated ? bin2hex(random_bytes(self::GENERATED_BYTES)) : $options["password"];
		$rs = $this->control->SetNewPassword($user, $password);
		if(!empty($rs["error"])) throw new UsageException($rs["error"]);
		if(empty($rs["success"])) throw new \RuntimeException("The password for [".$email."] couldn't be saved");

		$active = !empty($user->active);
		$data = ["email" => $user->email, "id" => $user->id, "active" => $active, "generated" => $generated];
		$text = "Password changed for ".$user->email.".";
		if($generated) {
			$data["password"] = $password;
			$text .= "\nNew password: ".$password."\nIt isn't shown again.";
		}
		if(!$active) $text .= "\nThe user is inactive: they can't log in until they're activated.";
		return new Result($data, $text);
	}

}
