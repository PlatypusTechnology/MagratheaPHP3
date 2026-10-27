<?php

namespace Magrathea\Admin\Features\User;

use Exception;
use Magrathea\Admin\AdminManager;
use Magrathea\MagratheaModelControl;
use Magrathea\DB\Query;

class AdminUserControl extends MagratheaModelControl { 

	protected static $modelNamespace = "Magrathea\Admin\Features\User";
	protected static $modelName = "AdminUser";
	protected static $dbTable = "_magrathea_users";

	/**
	 * Gets a user by e-mail
	 * @param 	string 			$email	user e-mail
	 * @return 	AdminUser|null	user or null if not found
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function GetByEmail(string $email): AdminUser|null {
		$user = $this->GetWhere(["email" => $email]);
		if(count($user) == 0) return null;
		return $user[0];
	}

	/**
	 * Logs in.
	 * Every failure (unknown e-mail, wrong password, inactive user) returns the same message and no user.
	 * @param string $email			user e-mail
	 * @param string $password	user password
	 * @return array		returns array with [ success, user, message ]
	 */
	public function Login(string $email, string $password): array {
		$failed = [ "success" => false, "user" => null, "message" => "Invalid email or password" ];
		try {
			$query = Query::Select()
				->Where(["email" => $email])
				->Obj(new AdminUser());
			$user = self::RunRow($query);
			if ($user == null) {
				// one bcrypt run, as a wrong password costs, so the time doesn't tell the e-mail is unknown
				password_hash($password, PASSWORD_BCRYPT);
				return $failed;
			}
			$pwdCorrect = $user->CheckPassword($password);
			if (!$pwdCorrect || empty($user->active)) {
				return $failed;
			}
			$this->SetLoginAsNow($user);
			return [ "success" => true, "user" => $user ];
		} catch(Exception $ex) {
			throw $ex;
		}
	}

	/**
	 * Sets last_login for a user as now
	 * @param 	AdminUser 	$user 	user to be updated
	 * @return 	mixed 			query result
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function SetLoginAsNow(AdminUser $user) {
		try {
			$query = Query::Update();
			$query->Table(static::$dbTable);
			$query
				->SetRaw("last_login = NOW()")
				->Where([ "id" => $user->id ]);
			return self::Run($query);
		} catch(Exception $ex) {
			throw $ex;
		}
	}

	/**
	 * Counts how many users are in the database
	 * @return 	int 		amount of users
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function CountUsers(): int {
		try {
			$query = Query::Select()
				->SelectStr("count(1) as c")
				->Table(static::$dbTable);
			$c = self::QueryOne($query->SQL());
			return intval($c);
		} catch(Exception $ex) {
			throw $ex;
		}
	}

	/**
	 * Sets a new password for a user
	 * @param 	AdminUser 	$user 	user to be updated
	 * @param 	string 			$pwd 	new password
	 * @return 	array 			["success" => bool, "error" => string]
	 */
	public function SetNewPassword(AdminUser $user, string $pwd) {
		// Debugger::Instance()->SetDev();
		if(strlen($pwd) < 8) {
			return ["success" => false, "error" => "Password must be at least 8 chars long"];
		}
		$user->SetPassword($pwd);
		$saved = $user->Save();
		AdminManager::Instance()->Log("change-password", $user);
		return [ "success" => ($saved === true) ];
	}

	/**
	 * Gets an array of users for using in a select
	 * @return 	array 	users as `["id" => id, "name" => email]`
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function GetSelect() {
		return array_map(function($i) {
			return [
				"id" => $i->id,
				"name" => $i->email
			];
		}, $this->GetAll());
	}

	/**
	 * Gets an array of users with their roles for using in a select
	 * @return 	array 	users as `["id" => id, "name" => "email (role)"]`
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function  GetSelectWithRoles() {
		$user = new AdminUser();
		$roles = $user->GetRoles();
		return array_map( function($i) use ($roles) {
			$role = @$roles[$i->role_id];
			return [
				"id" => $i->id,
				"name" => $i->email." (".$role.") "
			];
		}, $this->GetAll());		
	}

}
