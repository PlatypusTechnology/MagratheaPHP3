<?php

namespace Magrathea\Cli\Commands;

use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Admin\Install;
use Magrathea\Bootstrap\Steps;
use Magrathea\Cli\Command;
use Magrathea\Cli\Result;
use Magrathea\Cli\UsageException;

#######################################################################################
####
####    MAGRATHEA PHP
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    AdminCreateUserCommand created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* `magrathea admin:create-user <email> [--password=…]`: creates the admin's **first** user, a super admin
* (`Install::CreateFirstUser()`), only while there are no users at all (`AdminUserControl::CountUsers()`).
* With users, it creates nothing and fails: it's a setup step, not a way around the admin's user management.
* Without `--password` it generates one (`GENERATED_BYTES` random bytes, in hex) and prints it, once.
* Its name is the consent: it has no `--yes`
*/
class AdminCreateUserCommand extends Command {

	/** a generated password: 16 hex characters */
	const GENERATED_BYTES = 8;
	/** the first super admin's minimum password length: the Setup Wizard's */
	const MIN_PASSWORD = Steps::MIN_PASSWORD;

	private ?AdminUserControl $control;
	private ?Install $install;

	/**
	* @param 	AdminUserControl|null 	$control 	the users, for the count (default: `new AdminUserControl()`, created when it runs)
	* @param 	Install|null 			$install 	creates the user (default: `new Install()`, created when it runs)
	*/
	public function __construct(?AdminUserControl $control = null, ?Install $install = null) {
		$this->control = $control;
		$this->install = $install;
	}

	public function Name(): string { return "admin:create-user"; }
	public function Summary(): string { return "Creates the admin's first user (a super admin), only while there are none; without --password, generates one and prints it once"; }
	public function Arguments(): array { return ["email" => true]; }
	public function Options(): array { return ["password" => true]; }

	public function Execute(array $arguments, array $options): Result {
		$email = trim($arguments["email"]);
		if(filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new UsageException("[".$email."] isn't a valid e-mail");
		}
		$generated = !isset($options["password"]);
		$password = $generated ? bin2hex(random_bytes(self::GENERATED_BYTES)) : $options["password"];
		if(!is_string($password) || mb_strlen($password) < self::MIN_PASSWORD) {
			throw new UsageException("The password must have at least ".self::MIN_PASSWORD." characters");
		}

		$this->control ??= new AdminUserControl();
		if($this->control->CountUsers() > 0) {
			throw new \RuntimeException("The admin already has users: the first one is created only once. Reset a password with `magrathea admin:reset-password <email>`");
		}
		$this->install ??= new Install();
		$user = $this->install->CreateFirstUser($email, $password);

		$data = ["email" => $user->email, "id" => $user->id, "generated" => $generated];
		$text = "Created the first admin user, a super admin: ".$user->email.".";
		if($generated) {
			$data["password"] = $password;
			$text .= "\nPassword: ".$password."\nIt isn't shown again.";
		}
		return new Result($data, $text);
	}

}
