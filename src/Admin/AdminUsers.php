<?php

namespace Magrathea\Admin;

use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\Singleton;
use Magrathea\DB\Database;

class AdminUsers extends Singleton {

	private $tableName = "MagratheaUsers";
	private $sessionName = "magrathea_user";
	/** user loaded for the id in session (once per request) */
	private AdminUser|null $loggedUser = null;
	private int|null $loggedUserId = null;

	public function IsUsersSet(): bool {
		$db = \Magrathea\Config::Instance()->GetConfigFromDefault("db_name");
		$magdb = Database::Instance();
		$query = "SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE ".
			"TABLE_SCHEMA = '".$db."' AND TABLE_NAME = '".$this->tableName."'";
		$rs = $magdb->QueryAll($query);
		return ($rs === 1);
	}

	/**
	 * Logs in: the session keeps only the user id, under a new session id
	 * @param 	string 	$user 			user e-mail
	 * @param 	string 	$password 	user password
	 * @return 	array 	[ success, user, message ], from AdminUserControl::Login()
	 */
	public function Login($user, $password): array {
		$control = new AdminUserControl();
		$loginData = $control->Login($user, $password);
		if(!$loginData["success"]) return $loginData;
		$user = $loginData["user"];
		if(session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
		$_SESSION[$this->sessionName] = (int)$user->id;
		$this->loggedUser = $user;
		$this->loggedUserId = (int)$user->id;
		AdminCsrf::Instance()->Regenerate();
		AdminManager::Instance()->Log("login", $user, $user->id);
		return $loginData;
	}

	/**
	 * Gets the logged user, loaded from the database (once per request).
	 * Logs out (and returns null) when the session doesn't hold a valid id,
	 * or the user doesn't exist or isn't active anymore.
	 * @return 	AdminUser|null 		logged user
	 * @throws 	\Magrathea\Exceptions\MagratheaDBException
	 */
	public function GetLoggedUser(): AdminUser|null {
		$id = $_SESSION[$this->sessionName] ?? null;
		if(!is_int($id) || $id <= 0) {
			$this->Logout();
			return null;
		}
		if($this->loggedUserId === $id) return $this->loggedUser;
		try {
			$user = new AdminUser($id);
		} catch(MagratheaModelException $ex) {
			$user = null;
		}
		if(!$user || empty($user->active)) {
			$this->Logout();
			return null;
		}
		$this->loggedUser = $user;
		$this->loggedUserId = $id;
		return $user;
	}

	public function Logout() {
		unset($_SESSION[$this->sessionName]);
		$this->loggedUser = null;
		$this->loggedUserId = null;
	}

}
