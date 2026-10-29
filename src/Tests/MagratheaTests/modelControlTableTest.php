<?php

namespace ModelControlTableTest;

use Magrathea\Admin\Features\AppConfig\AppConfig;
use Magrathea\Admin\Features\AppConfig\AppConfigControl;
use Magrathea\Admin\Features\User\AdminUser;
use Magrathea\Admin\Features\User\AdminUserControl;
use Magrathea\Admin\Features\UserLogs\AdminLog;
use Magrathea\Admin\Features\UserLogs\AdminLogControl;
use Magrathea\DB\Database;
use Magrathea\MagratheaModel;
use Magrathea\MagratheaModelControl;
use Magrathea\Tests\TestsHelper;

// Database that records queries instead of running them, and answers with $rows
class TableRecordingDatabase extends Database {
	public $queries = [];
	public $rows = [];
	public function Escape($value): string {
		return addslashes((string)$value);
	}
	public function QueryAll(string $sql) {
		$this->queries[] = $sql;
		return $this->rows;
	}
	public function QueryRow(string $sql): array|object {
		$this->queries[] = $sql;
		return $this->rows[0] ?? [];
	}
	public function QueryOne($sql) {
		$this->queries[] = $sql;
		return 0;
	}
}

class Article extends MagratheaModel {
	public static $constructed = 0;
	public $id, $title;
	public function MagratheaStart() {
		self::$constructed++;
		$this->dbTable = "articles";
		$this->dbPk = "id";
		$this->dbValues = [ "id" => "int", "title" => "string" ];
	}
}

// only $model
class ArticleControl extends MagratheaModelControl {
	protected static $model = Article::class;
}
// a second Control on the same model: its own cache entry
class OtherArticleControl extends MagratheaModelControl {
	protected static $model = Article::class;
}
// the three-property form (2.x)
class LegacyArticleControl extends MagratheaModelControl {
	protected static $modelNamespace = "ModelControlTableTest";
	protected static $modelName = "Article";
	protected static $dbTable = "articles";
}
// $model, but an explicit $dbTable
class ArchivedArticleControl extends MagratheaModelControl {
	protected static $model = Article::class;
	protected static $dbTable = "articles_archive";
}
// $model wins over $modelNamespace/$modelName
class MixedArticleControl extends MagratheaModelControl {
	protected static $model = Article::class;
	protected static $modelNamespace = "Nowhere";
	protected static $modelName = "Nothing";
}

/**
 * MagratheaModelControl's `$model` and `GetDbTable()` (3.0 item 8): a Control can name only its
 * model's class, and its table comes from the model.
 */
class modelControlTableTest extends \PHPUnit\Framework\TestCase {

	private $previousDb;
	private TableRecordingDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previousDb = Database::Instance();
		$this->db = TableRecordingDatabase::Instance();
		$this->db->queries = [];
		$this->db->rows = [];
		Database::MockClass($this->db);
	}
	protected function tearDown(): void {
		Database::MockClass($this->previousDb);
		parent::tearDown();
	}

	public static function readers(): array {
		return [
			"GetAll" => [fn($c) => $c::GetAll()],
			"GetListPage" => [fn($c) => $c::GetListPage(10, 2)],
			"GetSimpleWhere" => [fn($c) => $c::GetSimpleWhere("id = 1")],
			"GetWhere" => [fn($c) => $c::GetWhere(["id" => 1])],
			"GetRowWhere" => [fn($c) => $c::GetRowWhere(["id" => 1])],
			"GetMultipleObjects" => [fn($c) => $c::GetMultipleObjects([new Article()], "")],
		];
	}

	function testModelNameFromModel() {
		TestsHelper::Print("testing GetModelName() returns \$model when it's set...");
		$this->assertSame(Article::class, ArticleControl::GetModelName());
		$this->assertSame(Article::class, MixedArticleControl::GetModelName());
		$this->assertSame("ModelControlTableTest\\Article", LegacyArticleControl::GetModelName());
	}

	function testDbTableFromModel() {
		TestsHelper::Print("testing GetDbTable() reads the model's table when \$dbTable isn't set...");
		$this->assertSame("articles", ArticleControl::GetDbTable());
		$this->assertSame("articles", LegacyArticleControl::GetDbTable());
		$this->assertCount(0, $this->db->queries);
	}

	function testExplicitDbTableWins() {
		TestsHelper::Print("testing an explicit \$dbTable wins over the model's...");
		$this->assertSame("articles_archive", ArchivedArticleControl::GetDbTable());
	}

	function testDbTableIsCachedPerControl() {
		TestsHelper::Print("testing GetDbTable() builds the model once per Control...");
		ArticleControl::GetDbTable();
		$before = Article::$constructed;
		ArticleControl::GetDbTable();
		ArticleControl::GetDbTable();
		$this->assertSame($before, Article::$constructed);
		$this->assertSame("articles", OtherArticleControl::GetDbTable());
		$this->assertSame($before + 1, Article::$constructed);
		OtherArticleControl::GetDbTable();
		$this->assertSame($before + 1, Article::$constructed);
	}

	/**
	 * @dataProvider readers
	 */
	function testModelOnlyControlQueriesModelTable(callable $read) {
		TestsHelper::Print("testing a Control with only \$model queries the model's table...");
		$read(ArticleControl::class);
		$this->assertCount(1, $this->db->queries);
		$this->assertMatchesRegularExpression("/FROM `?articles`? /", $this->db->queries[0]." ");
	}

	/**
	 * @dataProvider readers
	 */
	function testLegacyControlUnchanged(callable $read) {
		TestsHelper::Print("testing the three-property Control still queries its \$dbTable...");
		$read(LegacyArticleControl::class);
		$this->assertCount(1, $this->db->queries);
		$this->assertMatchesRegularExpression("/FROM `?articles`? /", $this->db->queries[0]." ");
	}

	/**
	 * @dataProvider readers
	 */
	function testExplicitDbTableIsQueried(callable $read) {
		TestsHelper::Print("testing a Control's explicit \$dbTable is the table queried...");
		$read(ArchivedArticleControl::class);
		$this->assertCount(1, $this->db->queries);
		$this->assertMatchesRegularExpression("/FROM `?articles_archive`? /", $this->db->queries[0]." ");
	}

	function testRunBuildsTheModel() {
		TestsHelper::Print("testing a Control with only \$model returns its model's objects...");
		$this->db->rows = [ ["articles/id" => "1", "articles/title" => "first"] ];
		$articles = ArticleControl::RunQuery("SELECT * FROM articles");
		$this->assertCount(1, $articles);
		$this->assertInstanceOf(Article::class, $articles[0]);
		$this->assertSame("first", $articles[0]->title);
	}

	function testGetRowWhereStringReturnsFirstObject() {
		TestsHelper::Print("testing GetRowWhere() with a string returns the first object, not an array...");
		$this->db->rows = [ ["articles/id" => "1", "articles/title" => "first"] ];
		$article = ArticleControl::GetRowWhere("title = 'first'");
		$this->assertInstanceOf(Article::class, $article);
		$this->assertSame("first", $article->title);
		$this->assertSame("SELECT * FROM articles WHERE title = 'first'", $this->db->queries[0]);
	}

	function testGetRowWhereReturnsNullWhenNoRow() {
		TestsHelper::Print("testing GetRowWhere() returns null when nothing matches, in both forms...");
		$this->assertNull(ArticleControl::GetRowWhere("title = 'none'"));
		$this->assertNull(ArticleControl::GetRowWhere(["title" => "none"]));
	}

	public static function frameworkControls(): array {
		return [
			"AdminUser" => [AdminUserControl::class, AdminUser::class, "_magrathea_users"],
			"AppConfig" => [AppConfigControl::class, AppConfig::class, "_magrathea_config"],
			"AdminLog" => [AdminLogControl::class, AdminLog::class, "_magrathea_logs"],
		];
	}

	/**
	 * @dataProvider frameworkControls
	 */
	function testFrameworkControlsDeclareOnlyModel(string $control, string $model, string $table) {
		TestsHelper::Print("testing ".$control." declares only \$model and reads its model's table...");
		$this->assertSame($model, $control::GetModelName());
		$this->assertSame($table, $control::GetDbTable());
		foreach(["modelNamespace", "modelName", "dbTable"] as $prop) {
			$this->assertNull((new \ReflectionProperty($control, $prop))->getValue(), $control."::\$".$prop." is set");
		}
		$control::GetAll();
		$this->assertStringContainsString("FROM ".$table." ", $this->db->queries[0]);
	}

	function testAdminUserControlQueriesUsersTable() {
		TestsHelper::Print("testing AdminUserControl's own queries read the model's table...");
		$control = new AdminUserControl();
		$control->CountUsers();
		$user = new AdminUser();
		$user->id = 3;
		$control->SetLoginAsNow($user);
		$this->assertCount(2, $this->db->queries);
		$this->assertStringContainsString("FROM `_magrathea_users`", $this->db->queries[0]);
		$this->assertStringContainsString("UPDATE _magrathea_users SET last_login = NOW()", $this->db->queries[1]);
	}

	function testToString() {
		TestsHelper::Print("testing a Control's __toString() names the model and the table...");
		$control = new ArticleControl();
		$this->assertStringContainsString("Model: ".Article::class." / Table: articles", (string)$control);
	}
}
