<?php

/**
 * Curated "example of use" snippets shown in modals on class pages and on
 * the Examples page. `topics` links a snippet to one or more page slugs
 * (category/slug from DocMap) so it surfaces in the right place.
 * Sourced from skills.MD where noted; others are original, written for this
 * site only (not duplicated back into skills.MD) - see CLAUDE.md.
 */

return [

	[
		"id" => "setup-php",
		"title" => "The Setup Wizard — public/setup.php",
		"source" => "original",
		"topics" => ["core/magrathea-php", "getting-started/setup-wizard"],
		"code" => <<<'PHP'
<?php
// Magrathea setup wizard: locked by a token only someone with access to the server's files can read
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
PHP,
		"note" => "Don't call `MagratheaPHP::Load()` here: the wizard reads `configs/magrathea.conf` itself and works without one. Every visit asks for the 8-digit token in `configs/.setup-token` (`cat` it on the server); with a config, the active environment also needs `bootstrap_unlocked = true`. Delete the file on servers that don't need it.",
	],

	[
		"id" => "app-bootstrap",
		"title" => "Real project entry point (app/_inc.php)",
		"source" => "skills.MD",
		"topics" => ["core/magrathea-php"],
		"code" => <<<'PHP'
<?php
require __DIR__ . "/../vendor/autoload.php";

error_reporting(E_ALL);

// setup only: it prints nothing and catches nothing (the entry points do)
Magrathea\MagratheaPHP::Instance()
    ->MinVersion("3.0.0")                   // optional, pins a minimum framework version
    ->AppPath(realpath(__DIR__))            // this folder (app/) becomes appRoot
    ->AppNamespace("App")                   // optional: in DEV, a missing App\… class adds a debug hint
    ->AddRootCodeFolder("admin", "api", "shared")
    ->AddFeature("Article", "Author")       // app/features/<Name>
    ->Dev()                                 // or ->Prod() in production
    ->Load()                                // reads ../configs/magrathea.conf
    ->Connect();                            // the connection itself opens on the first query
PHP,
		"note" => "public/index.php and public/magrathea.php require it, and so does vendor/bin/magrathea: it must not print or exit.",
	],

	[
		"id" => "model-usage",
		"title" => "Create, load, update, delete a Model",
		"source" => "skills.MD",
		"topics" => ["database/orm-model"],
		"code" => <<<'PHP'
// Create and insert
$article = new Article();
$article->title      = "Hello World";
$article->body       = "My first article.";
$article->published  = true;
$id = $article->Save(); // returns new ID

// Load by primary key
$article = new Article(42);
echo $article->title;

// Update
$article->title = "Updated Title";
$article->Save(); // existing PK -> UPDATE

// Delete
$article->Delete();

// In an API action, return the model itself: its public properties are
// encoded, created_at/updated_at included (ToArray() leaves those out)
$article = new Article(42);
return $article;
// After Insert()/Save() the timestamps are null: return $article->Reload();
PHP,
	],

	[
		"id" => "control-usage",
		"title" => "Query data through a Control",
		"source" => "skills.MD",
		"topics" => ["database/orm-control"],
		"code" => <<<'PHP'
use App\Controls\ArticleControl;

$all       = ArticleControl::GetAll();
$published = ArticleControl::GetWhere(["published" => 1]);
$article   = ArticleControl::GetRowWhere(["id" => 42]);

$recent = ArticleControl::RunQuery(
    "SELECT * FROM articles WHERE created_at > '2024-01-01' ORDER BY created_at DESC LIMIT 5"
);

// Paginated for an API response (no COUNT(*), has_more via limit+1 trick)
$query = \Magrathea\DB\Query::Select()
    ->Obj(new \App\Models\Article())
    ->Where(["published" => 1])
    ->Order("created_at DESC");
$pagination = ArticleControl::GetPagination($query, page: 0, limit: 10);
PHP,
	],

	[
		"id" => "query-builder",
		"title" => "Fluent Query Builder",
		"source" => "skills.MD",
		"topics" => ["database/query-builder"],
		"code" => <<<'PHP'
use Magrathea\DB\Query;
use Magrathea\DB\Database;

// SELECT with model + JOIN
$sql = Query::Select()
    ->Obj(\App\Models\Article::class)
    ->SelectExtra("u.name AS author_name")
    ->Inner("users u", "u.id = articles.author_id")
    ->Where("articles.published = 1")       // a prefixed column: the array form would quote `articles.published` as one name
    ->Order("created_at DESC")
    ->Limit(10)
    ->SQL();

$rows = Database::Instance()->QueryAll($sql);

// User input in a hand-built fragment must be escaped
$safe = Database::Instance()->Escape($_GET["search"]);
$found = Database::Instance()->QueryAll("SELECT * FROM articles WHERE title LIKE '%$safe%'");
PHP,
		"note" => 'Never interpolate raw $_GET/$_POST into SQL — use the builder\'s array forms (Where([...])), Database::Instance()->Escape(), or PrepareAndExecute() for writes.',
	],

	[
		"id" => "api-controller",
		"title" => "API Controller action",
		"source" => "original",
		"topics" => ["api/api-controller", "api/magrathea-api"],
		"code" => <<<'PHP'
namespace App\Api;

use Magrathea\MagratheaApiControl;
use Magrathea\Exceptions\MagratheaApiException;
use App\Controls\ArticleControl;

class ArticleApi extends MagratheaApiControl {

    public function GetList() {
        $page  = (int)($_GET["page"] ?? 0);
        $query = \Magrathea\DB\Query::Select()
            ->Obj(new \App\Models\Article())
            ->Where(["published" => 1]);

        return ArticleControl::GetPagination($query, page: $page, limit: 20);
    }

    // GET /articles/:id: the action receives the route's params array
    public function Get($params = false) {
        $id = (new \App\Models\Article())->ValidateId($params["id"]); // malformed id: code 400 → HTTP 400
        $article = ArticleControl::GetRowWhere(["id" => $id]);
        if (!$article) {
            throw new MagratheaApiException("Article not found", 404);
        }
        return $article;
    }
}
PHP,
	],

	[
		"id" => "jwt-auth",
		"title" => "JWT Authentication",
		"source" => "skills.MD",
		"topics" => ["api/authentication"],
		"code" => <<<'PHP'
// Login, in the auth class (extends MagratheaApiAuth)
public function Login(): array {
    $post = $this->GetPost();
    $user = UserControl::GetRowWhere(["email" => $post["email"] ?? ""]);
    if (!$user || !password_verify($post["password"] ?? "", $user->password_hash)) {
        throw new MagratheaApiException("Invalid credentials", 401);
    }
    $token = $this->jwtEncode([
        "id"   => $user->id,          // GetUserId() reads "id"
        "role" => $user->role,
        "exp"  => time() + 86400,
    ]);
    return ["token" => $token, "user" => ["id" => $user->id, "email" => $user->email]];
}

// Reading the current user in any other controller
$userId = $this->GetUserId();         // the token's "id" claim, or null
$info   = $this->GetUserInfo();       // the whole decoded payload
PHP,
		"note" => "The secret is jwt_key from the config (GetSecret()). Never return a user model here: it carries the password hash.",
	],

	[
		"id" => "caching",
		"title" => "Caching a controller response",
		"source" => "skills.MD",
		"topics" => ["utilities/cache"],
		"code" => <<<'PHP'
// In an API controller action
public function List(): array {
    // a hit outputs the cached response and ends the request;
    // a miss returns, and the action's response is saved automatically
    $this->Cache("articles_published", $_GET["page"] ?? null); // key: articles_published-<page> (no suffix for 0)
    return ArticleControl::GetWhere(["published" => 1]);
}

public function Create($data = false) {
    $article = parent::Create($data);
    $this->CacheClearPattern("articles_published*");
    return $article;
}
PHP,
		"note" => "The second argument of Cache() is a key suffix (an id, a page), never the response.",
	],

	[
		"id" => "logging-debug",
		"title" => "Logging & Debugging",
		"source" => "skills.MD",
		"topics" => ["utilities/logger", "utilities/debugger"],
		"code" => <<<'PHP'
use Magrathea\Logger;
use Magrathea\Debugger;

// Production-safe logging (to logs_path)
Logger::Instance()->Log("Order #{$order->id} processed");
Logger::Instance()->SetLogFile("payments")->Log("Payment success: $amount");

try {
    // something risky
} catch (\Exception $e) {
    Logger::Instance()->LogError($e);
}

// Debug items: printed in DEV, stored in DEBUG, in an API response's _debug
Debugger::Instance()->Add(["order" => $order->id, "step" => "paid"]);
Debugger::Instance()->Info("Cache miss for: articles_published");
PHP,
	],

	[
		"id" => "mail",
		"title" => "Sending Email",
		"source" => "skills.MD",
		"topics" => ["utilities/mail"],
		"code" => <<<'PHP'
use Magrathea\MagratheaMailSMTP;
use Magrathea\Config;
use Magrathea\Logger;

// SMTP settings are never read from the config automatically: pass them in
$config = Config::Instance();
$mail = new MagratheaMailSMTP();
$mail->SetSMTP($config->Get("smtp_host"), $config->Get("smtp_port"), $config->Get("smtp_user"), $config->Get("smtp_pass"));
$mail->SetFrom("noreply@myapp.com")
     ->SetTo("someone@example.com")
     ->SetSubject("Welcome!")
     ->SetHTMLMessage("<p>Thanks for signing up.</p>");
if (!$mail->Send()) {
    Logger::Instance()->Log("mail failed: " . $mail->GetError());
}
PHP,
	],

	[
		"id" => "admin-entry",
		"title" => "Admin panel entry point",
		"source" => "skills.MD",
		"topics" => ["admin/admin", "admin/admin-manager"],
		"code" => <<<'PHP'
<?php
// public/magrathea.php
use Magrathea\Admin\AdminManager;

try {
    require __DIR__ . "/../app/_inc.php";
    \Magrathea\MagratheaPHP::Instance()->StartSession();
    AdminManager::Instance()->Start(new \App\MyAppAdmin()); // handles login, auth, routing and rendering
} catch (\Throwable $ex) {
    error_log((string) $ex);   // never print the exception on the page
    http_response_code(500);
    echo "Internal Server Error";
}
PHP,
	],

	[
		"id" => "admin-crud-feature",
		"title" => "Registering a CRUD admin feature",
		"source" => "skills.MD",
		"topics" => ["admin/admin-features"],
		"code" => <<<'PHP'
<?php
// app/features/Article/ArticleAdmin.php
namespace App\Article;

class ArticleAdmin extends \Magrathea\Admin\Features\CrudObject\AdminCrudObject {
    public string $featureName = "Articles"; // menu/page title (default: "Article CRUD")

    public function Initialize() {
        $this->SetObject(new Article()); // uses App\Article\ArticleControl
    }
}

// in your Admin subclass:
public function SetFeatures() {
    parent::SetFeatures();
    $this->AddCrudFeature(new \App\Article\ArticleAdmin());
}
PHP,
	],

	[
		"id" => "config-file",
		"title" => "Writing configs/magrathea.conf",
		"source" => "skills.MD",
		"topics" => ["core/config"],
		"lang" => "ini",
		"code" => <<<'PHP'
[general]
	use_environment = "dev"

[dev]
	db_host = "localhost"
	db_name = "my_db"
	db_user = "root"
	db_pass = "secret"
	db_port = "3306"
	logs_path = "/var/www/my-app/logs"
	cache_path = "/var/www/my-app/cache"
	backups_path = "/var/www/my-app/backups"
	timezone = "America/Sao_Paulo"
	app_url = "http://localhost:8080"
	jwt_key = "a-very-long-random-string-here"
	bootstrap_unlocked = true

[production]
	db_host = "db.prod.server"
	db_name = "my_db"
	db_user = "app_user"
	db_pass = "$=DB_PASSWORD"
	db_port = "3306"
	logs_path = "/var/www/my-app/logs"
	cache_path = "/var/www/my-app/cache"
	backups_path = "/var/www/my-app/backups"
	timezone = "America/Sao_Paulo"
	app_url = "https://myapp.example.com"
	jwt_key = "$=JWT_SECRET"
PHP,
		"note" => "Sections are named by environment (not by topic) and each repeats its own full flat key set — there is no [section:env] inheritance. Use \$=ENV_VAR for secrets, never hardcode credentials. bootstrap_unlocked opens the Setup Wizard: only in [dev], if anywhere. vendor/bin/magrathea check validates the file.",
	],

	[
		"id" => "config",
		"title" => "Reading configuration",
		"source" => "original",
		"topics" => ["core/config"],
		"code" => <<<'PHP'
use Magrathea\Config;

// Get() reads a bare key from the ACTIVE environment section
// (general/use_environment, or whatever ->SetEnvironment() set)
$host = Config::Instance()->Get("db_host");

// GetConfig() reads from the config root, independent of environment -
// use "section/key" slash notation to reach into a named section
$prodHost = Config::Instance()->GetConfig("production/db_host");

// GetConfigSection() returns any named section as an array
// (the section name is required - there is no "active section" default)
$prodSection = Config::Instance()->GetConfigSection("production");
echo $prodSection["db_host"];
PHP,
		"note" => "Get() and GetConfig() are not interchangeable: Get() is environment-scoped, GetConfig() is not.",
	],

];
