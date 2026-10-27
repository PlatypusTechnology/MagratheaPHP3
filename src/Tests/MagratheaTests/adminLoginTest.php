<?php

use Magrathea2\Admin\AdminUsers;
use Magrathea2\Admin\Features\User\AdminUser;
use Magrathea2\Admin\Features\User\AdminUserControl;
use Magrathea2\DB\Database;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiAuth;
use Magrathea2\Tests\TestsHelper;

// Database that serves admin users from an array and records writes
class AdminLoginRecordingDatabase extends Database {
	public $users = [];
	public $queries = [];
	public $executed = [];
	public function Escape($value): string {
		return addcslashes((string)$value, "\x00\n\r\\'\"\x1a");
	}
	public function QueryRow(string $sql): array|object {
		$this->queries[] = $sql;
		$found = null;
		if(preg_match("/`email` = '([^']*)'/", $sql, $m)) {
			$found = current(array_filter($this->users, fn($u) => $u["email"] === $m[1])) ?: null;
		} else if(preg_match("/_magrathea_users\.id = (-?\d+)/", $sql, $m)) {
			$found = $this->users[(int)$m[1]] ?? null;
		}
		if(!$found) return [];
		$row = [];
		foreach($found as $field => $value) $row["_magrathea_users/".$field] = $value;
		return $row;
	}
	public function QueryAll(string $sql) {
		$this->queries[] = $sql;
		return [];
	}
	public function PrepareAndExecute($query, $arrTypes, $arrValues) {
		$this->executed[] = ["query" => $query, "values" => $arrValues];
		return 7;
	}
}

/**
 * Admin login and session (3.0 item 1): the session keeps only the user id,
 * the user is reloaded once per request, inactive users are refused,
 * and every failed login answers the same message.
 */
class adminLoginTest extends \PHPUnit\Framework\TestCase {

	private $previousDb;
	private $previousSession;
	private AdminLoginRecordingDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		$this->previousSession = $_SESSION ?? null;
		$_SESSION = [];
		$this->db = AdminLoginRecordingDatabase::Instance();
		$this->db->queries = [];
		$this->db->executed = [];
		$this->db->users = [
			5 => $this->UserRow(5, "admin@magrathea.test", "right-password", 2, 1),
			6 => $this->UserRow(6, "inactive@magrathea.test", "right-password", 2, 0),
		];
		Database::MockClass($this->db);
		$this->NextRequest();
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		if($this->previousSession === null) unset($_SESSION);
		else $_SESSION = $this->previousSession;
		$this->NextRequest();
		parent::tearDown();
	}

	private function UserRow(int $id, string $email, string $password, int $role, int $active): array {
		return [
			"id" => (string)$id,
			"email" => $email,
			"password" => password_hash($password, PASSWORD_BCRYPT, ["cost" => 4]),
			"last_login" => null,
			"role_id" => (string)$role,
			"active" => (string)$active,
			"created_at" => "2026-01-01 00:00:00",
			"updated_at" => "2026-01-01 00:00:00",
		];
	}

	/** a new request: AdminUsers' cache of the logged user starts empty */
	private function NextRequest(): void {
		$instances = new \ReflectionProperty(\Magrathea2\Singleton::class, "instance");
		$all = $instances->getValue();
		unset($all[AdminUsers::class]);
		$instances->setValue(null, $all);
	}

	function testLoginStoresOnlyTheUserId() {
		TestsHelper::Print("testing a login stores the user id (an int) in the session...");
		$login = AdminUsers::Instance()->Login("admin@magrathea.test", "right-password");
		$this->assertTrue($login["success"]);
		$this->assertSame(5, $_SESSION["magrathea_user"]);
	}

	function testGetLoggedUserReloadsTheUser() {
		TestsHelper::Print("testing GetLoggedUser() reloads the user, so a role change shows on the next request...");
		AdminUsers::Instance()->Login("admin@magrathea.test", "right-password");
		$this->NextRequest();
		$user = AdminUsers::Instance()->GetLoggedUser();
		$this->assertInstanceOf(AdminUser::class, $user);
		$this->assertTrue($user->IsAdmin());

		$this->db->users[5]["role_id"] = "3";
		$this->NextRequest();
		$this->assertFalse(AdminUsers::Instance()->GetLoggedUser()->IsAdmin());
	}

	function testGetLoggedUserLoadsOncePerRequest() {
		TestsHelper::Print("testing GetLoggedUser() queries the database once per request...");
		$_SESSION["magrathea_user"] = 5;
		AdminUsers::Instance()->GetLoggedUser();
		AdminUsers::Instance()->GetLoggedUser();
		$this->assertCount(1, $this->db->queries);
	}

	function testGetLoggedUserDropsA2xSession() {
		TestsHelper::Print("testing GetLoggedUser() logs out a 2.x session (a serialized AdminUser)...");
		$old = new AdminUser();
		$old->id = 5;
		$_SESSION["magrathea_user"] = serialize($old);
		$this->assertNull(AdminUsers::Instance()->GetLoggedUser());
		$this->assertArrayNotHasKey("magrathea_user", $_SESSION);
		$this->assertSame([], $this->db->queries);
	}

	function testGetLoggedUserDropsAMissingUser() {
		TestsHelper::Print("testing GetLoggedUser() logs out a user that no longer exists...");
		$_SESSION["magrathea_user"] = 99;
		$this->assertNull(AdminUsers::Instance()->GetLoggedUser());
		$this->assertArrayNotHasKey("magrathea_user", $_SESSION);
	}

	function testGetLoggedUserDropsAnInactiveUser() {
		TestsHelper::Print("testing GetLoggedUser() logs out a user deactivated after logging in...");
		AdminUsers::Instance()->Login("admin@magrathea.test", "right-password");
		$this->db->users[5]["active"] = "0";
		$this->NextRequest();
		$this->assertNull(AdminUsers::Instance()->GetLoggedUser());
		$this->assertArrayNotHasKey("magrathea_user", $_SESSION);
	}

	function testEveryFailedLoginLooksTheSame() {
		TestsHelper::Print("testing unknown e-mail, wrong password and inactive user get the same answer...");
		$control = new AdminUserControl();
		$expected = [ "success" => false, "user" => null, "message" => "Invalid email or password" ];
		$this->assertSame($expected, $control->Login("nobody@magrathea.test", "right-password"));
		$this->assertSame($expected, $control->Login("admin@magrathea.test", "wrong-password"));
		$this->assertSame($expected, $control->Login("inactive@magrathea.test", "right-password"));
		$this->assertSame([], $this->db->executed, "a failed login writes nothing (no last_login, no log)");
		$this->assertArrayNotHasKey("magrathea_user", $_SESSION);
	}

	function testFailedLoginLooksTheSameThroughAdminUserLogin() {
		TestsHelper::Print("testing MagratheaApiAuth::AdminUserLogin() answers 403 with the same message for every failure...");
		$auth = new MagratheaApiAuth();
		foreach ([["nobody@magrathea.test", "right-password"], ["admin@magrathea.test", "wrong-password"], ["inactive@magrathea.test", "right-password"]] as [$email, $password]) {
			try {
				$auth->AdminUserLogin($email, $password);
				$this->fail("expected a MagratheaApiException for ".$email);
			} catch(MagratheaApiException $ex) {
				$this->assertSame(403, $ex->getCode());
				$this->assertSame("Invalid email or password", $ex->getMessage());
			}
		}
	}

}
