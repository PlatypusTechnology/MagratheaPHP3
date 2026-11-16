<?php

namespace ObjectsTest;

use Magrathea\Admin\Admin;
use Magrathea\Admin\AdminForm;
use Magrathea\Admin\AdminMenu;
use Magrathea\Admin\Features\CrudObject\AdminCrudObject;
use Magrathea\Admin\Features\Objects\AdminFeatureObjects;
use Magrathea\Admin\ModelFields;
use Magrathea\Admin\ObjectsExplorer;
use Magrathea\DB\Database;
use Magrathea\DB\Schema;
use Magrathea\DB\SchemaCheck;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;
use Magrathea\MagratheaModelControl;
use Magrathea\Tests\TestsHelper;

// records what a model writes, instead of writing it; GetById() "loads" $rows[id]
abstract class RecordingModel extends MagratheaModel {
	public static $log = [];
	public static $rows = [];
	public function GetById($id) {
		static::$log[] = "load:".$id;
		if(!array_key_exists($id, static::$rows)) throw new MagratheaModelException("Could not find ".static::class." with id ".$id."!");
		$this->LoadObjectFromTableRow(static::$rows[$id]);
	}
	public function Insert() {
		static::$log[] = "insert";
		$pk = $this->dbPk;
		$this->$pk = 42;
		return 42;
	}
	public function InsertWithPk(): bool {
		static::$log[] = "insertWithPk:".$this->GetID();
		return true;
	}
	public function Update() {
		static::$log[] = "update:".$this->GetID();
		return true;
	}
	public function Delete() {
		static::$log[] = "delete:".$this->GetID();
		return true;
	}
}

class Author extends RecordingModel {
	protected $dbTable = "authors";
	protected $dbPk = "id";
	protected $dbValues = ["id" => "int", "name" => "string"];
	public $id, $name;
	public function Ref(): string { return (string)$this->name; }
}
class Article extends RecordingModel {
	protected $dbTable = "articles";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int", "title" => "string", "body" => "text", "published" => "boolean",
		"price" => "decimal", "author_id" => "int", "published_on" => "date", "seen_at" => "datetime",
	];
	protected $relations = ["external" => ["author_id" => "\\ObjectsTest\\Author"]];
	public $id, $title, $body, $published, $price, $author_id, $published_on, $seen_at;
}
class Tag extends RecordingModel {
	protected $dbTable = "tags";
	protected $dbPk = "slug";
	protected $dbValues = ["slug" => "string", "label" => "string"];
	public $slug, $label;
}

class ObjectsFakeCheck extends SchemaCheck {
	public $models = [];
	public $loadFindings = [];
	public $modelFindings = [];
	public $findModelsCalls = 0;
	public function __construct() {}
	public function FindModels(): array {
		$this->findModelsCalls++;
		return ["models" => $this->models, "findings" => $this->loadFindings];
	}
	public function CheckModel(string $class): array {
		return $this->modelFindings[$class] ?? [];
	}
}
class ObjectsFakeSchema extends Schema {
	public $tables = [];
	public $error = null;
	public function __construct() {}
	public function Tables(bool $withFramework = true): array {
		if($this->error) throw new MagratheaDBException($this->error);
		return $this->tables;
	}
}
// records QueryAll()/QueryOne() and answers $rows (by table, from the SQL) and $total
class ObjectsRecordingDatabase extends Database {
	public $log = [];
	public $rows = [];
	public $total = 0;
	public function QueryAll(string $sql) {
		$this->log[] = $sql;
		preg_match('/FROM `([^`]+)`/', $sql, $m);
		return $this->rows[$m[1] ?? ""] ?? [];
	}
	public function QueryOne($sql) {
		$this->log[] = $sql;
		return (string)$this->total;
	}
}

class ArticleControl extends MagratheaModelControl {
	protected static $modelNamespace = "ObjectsTest";
	protected static $modelName = "Article";
	protected static $dbTable = "articles";
}
class TagAdmin extends AdminCrudObject {
	public function Initialize() {
		$this->SetObject(new Tag(), new ArticleControl());
	}
}

class adminObjectsTest extends \PHPUnit\Framework\TestCase {

	private ObjectsFakeCheck $check;
	private ObjectsFakeSchema $schema;
	private ObjectsRecordingDatabase $db;
	private ObjectsExplorer $explorer;

	protected function setUp(): void {
		parent::setUp();
		RecordingModel::$log = [];
		RecordingModel::$rows = [];
		$this->check = new ObjectsFakeCheck();
		$this->check->models = [
			self::Found(Tag::class, "tags", "slug", ["slug" => "string", "label" => "string"]),
			self::Found("Magrathea\\Admin\\Features\\User\\AdminUser", "_magrathea_users", "id", ["id" => "int"]),
			self::Found(Article::class, "articles", "id", (new Article())->GetDbValues()),
			self::Found(Author::class, "authors", "id", ["id" => "int", "name" => "string"]),
		];
		$this->schema = new ObjectsFakeSchema();
		$this->db = ObjectsRecordingDatabase::Instance();
		$this->db->log = [];
		$this->db->rows = [];
		$this->db->total = 0;
		$this->explorer = new ObjectsExplorer($this->db, $this->check, $this->schema);
	}

	private static function Found(string $class, string $table, string $pk, array $values): array {
		return ["file" => "/app/".$class.".php", "class" => $class, "table" => $table, "pk" => $pk, "values" => $values];
	}

	function testModelsLeaveOutTheFrameworkAndAreSorted() {
		TestsHelper::Print("testing ObjectsExplorer::Models() lists the project's models by name, with their tables' rows...");
		$this->check->loadFindings = [["level" => "warning", "message" => "x", "group" => "schema"]];
		$this->schema->tables = [
			["name" => "ARTICLES", "rows" => 12],
			["name" => "tags", "rows" => null],
		];
		$data = $this->explorer->Models();
		$this->assertSame(["Article", "Author", "Tag"], array_column($data["models"], "name"));
		$this->assertSame([12, null, null], array_column($data["models"], "rows"));
		$this->assertSame([true, false, true], array_column($data["models"], "hasTable"));
		$this->assertSame($this->check->loadFindings, $data["findings"]);
		$this->assertNull($data["error"]);
		$this->explorer->Models();
		$this->assertSame(1, $this->check->findModelsCalls);
	}

	function testModelsWithoutTables() {
		TestsHelper::Print("testing Models() still lists the models when the tables can't be read...");
		$this->schema->error = "no database";
		$data = $this->explorer->Models();
		$this->assertCount(3, $data["models"]);
		$this->assertSame([null, null, null], array_column($data["models"], "hasTable"));
		$this->assertSame("no database", $data["error"]);
	}

	function testIsFramework() {
		$this->assertTrue(ObjectsExplorer::IsFramework("Magrathea\\Admin\\Features\\User\\AdminUser"));
		$this->assertTrue(ObjectsExplorer::IsFramework("\\magrathea\\Something"));
		$this->assertFalse(ObjectsExplorer::IsFramework("App\\Magrathea\\Thing"));
		$this->assertFalse(ObjectsExplorer::IsFramework("MagratheaApp\\Thing"));
	}

	function testModelOnlyOpensListedModels() {
		TestsHelper::Print("testing Model() opens only the listed models: not the framework's, not any class...");
		$this->assertSame(Article::class, $this->explorer->Model("\\objectstest\\ARTICLE")["class"]);
		foreach(["Magrathea\\Admin\\Features\\User\\AdminUser", "ObjectsTest\\ObjectsFakeCheck", "", null, ["x"]] as $class) {
			try {
				$this->explorer->Model($class);
				$this->fail("opened ".json_encode($class));
			} catch(MagratheaModelException $ex) {
				$this->assertSame(404, $ex->getCode());
			}
		}
	}

	function testStructure() {
		TestsHelper::Print("testing Structure() lists the fields, the PK, the relations and the findings...");
		$finding = ["level" => "warning", "message" => "drift", "group" => "schema"];
		$this->check->modelFindings[Article::class] = [$finding];
		$structure = $this->explorer->Structure(Article::class);
		$this->assertSame([$finding], $structure["findings"]);
		$fields = array_column($structure["fields"], null, "field");
		$this->assertTrue($fields["id"]["pk"]);
		$this->assertFalse($fields["title"]["pk"]);
		$this->assertSame("ObjectsTest\\Author", $fields["author_id"]["relation"]);
		$this->assertNull($fields["title"]["relation"]);
		$this->assertSame("boolean", $fields["published"]["type"]);
	}

	function testPage() {
		TestsHelper::Print("testing Page() reads a page of rows, newest PK first, cutting long values...");
		$this->db->total = 51;
		$long = str_repeat("á", ObjectsExplorer::CELL_LENGTH + 5);
		$this->db->rows["articles"] = [
			["ID" => "7", "title" => $long, "created_at" => "2026-11-01 10:00:00"],
			["id" => "6", "title" => "short"],
		];
		$page = $this->explorer->Page(Article::class, 2);
		$this->assertSame([
			"SELECT COUNT(*) FROM `articles`",
			"SELECT * FROM `articles` ORDER BY `id` DESC LIMIT 25 OFFSET 50",
		], $this->db->log);
		$this->assertSame(51, $page["total"]);
		$this->assertSame(3, $page["pages"]);
		$this->assertSame(2, $page["page"]);
		$this->assertSame("created_at", end($page["columns"]));
		$this->assertNotContains("updated_at", $page["columns"]);
		$this->assertSame(["7", "6"], array_column($page["rows"], "id"));
		$this->assertSame(mb_substr($long, 0, ObjectsExplorer::CELL_LENGTH)."…", $page["rows"][0]["cells"]["title"]);
		$this->assertNull($page["rows"][1]["cells"]["created_at"]);
	}

	function testPageClampsItsArguments() {
		$page = $this->explorer->Page(Tag::class, -3, 1000);
		$this->assertSame(0, $page["page"]);
		$this->assertSame(ObjectsExplorer::MAX_PAGE_SIZE, $page["limit"]);
		$this->assertSame("SELECT * FROM `tags` ORDER BY `slug` DESC LIMIT 100 OFFSET 0", end($this->db->log));
		$this->assertSame(0, $page["pages"]);
	}

	function testFieldsMapTypesToElements() {
		TestsHelper::Print("testing ModelFields::Fields() maps each type to a form element, the PK first...");
		$this->db->rows["authors"] = [["id" => 1, "name" => "Ford"], ["id" => 2, "name" => "Arthur"]];
		$fields = (new ModelFields($this->db))->Fields(new Article(), true);
		$byKey = array_column($fields, null, "key");
		$this->assertSame("id", $fields[0]["key"]);
		$this->assertSame("disabled", $byKey["id"]["type"]);
		$this->assertSame("text", $byKey["title"]["type"]);
		$this->assertSame("textarea", $byKey["body"]["type"]);
		$this->assertSame("switch", $byKey["published"]["type"]);
		$this->assertSame("number", $byKey["price"]["type"]);
		$this->assertSame(["step" => "any"], $byKey["price"]["attributes"]);
		$this->assertSame("date", $byKey["published_on"]["type"]);
		$this->assertSame("text", $byKey["seen_at"]["type"]);
		$this->assertSame(["" => "(none)", 1 => "Ford", 2 => "Arthur"], $byKey["author_id"]["type"]);
		$this->assertStringContainsString("ORDER BY `id` LIMIT ".(ModelFields::RELATION_OPTIONS_LIMIT + 1), end($this->db->log));
		$this->assertArrayNotHasKey("created_at", $byKey);
	}

	function testRelationWithTooManyRowsIsAnInput() {
		$this->db->rows["authors"] = array_fill(0, ModelFields::RELATION_OPTIONS_LIMIT + 1, ["id" => 1, "name" => "x"]);
		$field = (new ModelFields($this->db))->Field("author_id", "int", Author::class);
		$this->assertSame("text", $field["type"]);
		$this->assertSame("id of a Author", $field["placeholder"]);
	}

	function testStringPkIsEditableOnlyWhenNew() {
		$fields = new ModelFields($this->db);
		$this->assertSame("text", $fields->Fields(new Tag(), true)[0]["type"]);
		$this->assertSame("disabled", $fields->Fields(new Tag(), false)[0]["type"]);
	}

	function testLabelsAreEscaped() {
		$field = (new ModelFields($this->db))->Field("<b>", "string");
		$this->assertStringStartsWith("&lt;b&gt;", $field["name"]);
	}

	function testValues() {
		TestsHelper::Print("testing ModelFields::Values() keeps only the form's editable fields, and normalizes them...");
		$values = (new ModelFields($this->db))->Values(new Article(), [
			"id" => "99", "title" => "", "body" => "text", "price" => "", "author_id" => "3",
			"seen_at" => ["x"], "created_at" => "2026-01-01", "magrathea_csrf_token" => "t", "dbTable" => "users",
		]);
		$this->assertSame([
			"title" => "", "body" => "text", "published" => false, "price" => null, "author_id" => "3",
		], $values);
	}

	function testValuesFollowTheForm() {
		$form = [
			["key" => "title", "type" => "disabled"],
			["key" => "published", "type" => "switch"],
			["key" => "body", "type" => "textarea"],
		];
		$values = (new ModelFields($this->db))->Values(new Article(), ["title" => "x", "published" => "1", "price" => "3"], $form);
		$this->assertSame(["published" => true], $values);
	}

	function testSaveInsertsAndUpdates() {
		TestsHelper::Print("testing ObjectsExplorer::Save() inserts new objects and updates loaded ones...");
		$saved = $this->explorer->Save(Article::class, "", ["title" => "Don't panic", "id" => "5"]);
		$this->assertSame(["insert"], RecordingModel::$log);
		$this->assertSame(["insert", 42], [$saved["type"], $saved["id"]]);
		$this->assertSame("Don't panic", $saved["object"]->title);

		RecordingModel::$log = [];
		RecordingModel::$rows = [7 => ["id" => 7, "title" => "old"]];
		$saved = $this->explorer->Save(Article::class, "7", ["title" => "new", "id" => "8"]);
		$this->assertSame(["load:7", "update:7"], RecordingModel::$log);
		$this->assertSame("new", $saved["object"]->title);
	}

	function testSaveWithAnEnteredPk() {
		$saved = $this->explorer->Save(Tag::class, "", ["slug" => "towel", "label" => "Towel"]);
		$this->assertSame(["insertWithPk:towel"], RecordingModel::$log);
		$this->assertSame("towel", $saved["id"]);
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionCode(400);
		$this->explorer->Save(Tag::class, "", ["label" => "no slug"]);
	}

	function testSaveRefusesUnlistedModelsAndMissingObjects() {
		try {
			$this->explorer->Save("Magrathea\\Admin\\Features\\User\\AdminUser", "1", []);
			$this->fail("saved a framework model");
		} catch(MagratheaModelException $ex) {
			$this->assertSame(404, $ex->getCode());
		}
		$this->expectException(MagratheaModelException::class);
		$this->explorer->Save(Article::class, "8", ["title" => "x"]);
	}

	function testDelete() {
		RecordingModel::$rows = [3 => ["id" => 3]];
		$this->assertTrue($this->explorer->Delete(Article::class, "3"));
		$this->assertSame(["load:3", "delete:3"], RecordingModel::$log);
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionCode(400);
		$this->explorer->Delete(Article::class, "");
	}

	function testObjectsIsADefaultFeatureAndTheFirstSection() {
		TestsHelper::Print("testing Objects is a default feature, the menu's first section (before Setup), and RemoveFeature() takes it out...");
		$admin = new Admin();
		$admin->SetFeatures();
		$this->assertInstanceOf(AdminFeatureObjects::class, $admin->GetFeatures()["AdminFeatureObjects"] ?? null);
		$menu = new AdminMenu();
		$admin->AddMagratheaMenu($menu);
		$items = $menu->GetMenu();
		// the items above the first section (Version, App Configuration) are adminMenuTest's
		$sections = array_keys(array_filter($items, fn($item) => ($item["type"] ?? null) === "sub"));
		$objects = $sections[0];
		$this->assertSame(["title" => "Objects", "type" => "sub"], $items[$objects], "the first section");
		$this->assertSame("AdminFeatureObjects", $items[$objects + 1]["feature"]);
		$this->assertSame(["title" => "Setup", "type" => "sub"], $items[$objects + 2]);

		$admin->RemoveFeature("AdminFeatureObjects")->RemoveFeature("NotAFeature");
		$this->assertArrayNotHasKey("AdminFeatureObjects", $admin->GetFeatures());
		$menu = new AdminMenu();
		$admin->AddMagratheaMenu($menu);
		$items = $menu->GetMenu();
		$sections = array_keys(array_filter($items, fn($item) => ($item["type"] ?? null) === "sub"));
		$this->assertSame(["title" => "Setup", "type" => "sub"], $items[$sections[0]], "without Objects, Setup is the first section");
		$this->assertNotContains("Objects", array_column($items, "title"));
		$this->assertNotContains("AdminFeatureObjects", array_column($items, "feature"));
	}

	function testOnlyTheFeaturesActionsAreDispatchable() {
		$feature = new AdminFeatureObjects();
		foreach(["Index", "List", "Form", "Save", "Delete"] as $method) $this->assertTrue($feature->IsDispatchable($method), $method);
		foreach(["SetManager", "GetManager", "RefuseIfNotPost", "Json", "LogAction"] as $method) $this->assertFalse($feature->IsDispatchable($method), $method);
	}

	function testListPrintsEscapedCellsAndEditLinks() {
		TestsHelper::Print("testing the Objects list escapes values and puts ids in the onclick as JSON...");
		$this->db->total = 1;
		$this->db->rows["tags"] = [["slug" => "a'b\"<c>", "label" => "<script>x</script>"]];
		$_GET["object"] = Tag::class;
		try {
			ob_start();
			(new AdminFeatureObjects())->SetManager($this->explorer)->List();
			$html = ob_get_clean();
		} finally {
			unset($_GET["object"]);
		}
		$this->assertStringNotContainsString("<script>x", $html);
		$this->assertStringContainsString("&lt;script&gt;x", $html);
		$this->assertStringContainsString('objectsEdit(&quot;a\u0027b\u0022\u003Cc\u003E&quot;)', $html);
	}

	function testCrudObjectEditColumnEscapesStringIds() {
		TestsHelper::Print("testing AdminCrudObject's edit link quotes and escapes string PKs...");
		$tag = new Tag();
		$tag->slug = "x');alert(1);//";
		$link = (new TagAdmin())->GetEditColumn()["key"]($tag);
		$this->assertStringContainsString('editCrudObject(&quot;x\u0027);alert(1);\/\/&quot;)', $link);
	}

	function testAdminFormHiddenPkUsesThePkName() {
		$form = (new AdminForm())->Build([], new Tag());
		$this->assertSame("slug", end($form->elements)["key"]);
	}
}
