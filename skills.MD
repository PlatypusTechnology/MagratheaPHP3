---
name: magrathea-php
description: How to correctly write code for projects on MagratheaPHP 3.x (package `platypustechnology/magratheaphp`, namespace `Magrathea`). Read before writing any PHP code for such a project. Projects on 2.x (`platypustechnology/magratheaphp2`, namespace `Magrathea2`) use the `magrathea-php2` skill.
---

# skills.MD — How to Use MagratheaPHP 3 in a Project

This file teaches an AI agent how to write correct code for a project on MagratheaPHP 3. It's a practical, task-oriented cookbook. Read it before writing any PHP code for such a project.

The framework generates no code: models, controls, APIs and admin classes are written by hand, following this file. Every rule here describes MagratheaPHP `3.x`. Check the installed version first: `vendor/bin/magrathea version`, or `vendor/platypustechnology/magratheaphp/src/version`. A project on 2.x (see the description above) is out of this file's scope.

Reference files:
- `vendor/platypustechnology/magratheaphp/docs/mds/index.md`: the narrative docs for every class, shipped with the package (also at https://www.platypusweb.com.br/magratheaphp3/docs/ ). Method signatures are in the source, `vendor/platypustechnology/magratheaphp/src/`.

**Upgrading** a project from 2.x to 3.0: follow the migration guide, `vendor/platypustechnology/magratheaphp/docs/mds/migrations/migrate-3.0.md` (also at https://www.platypusweb.com.br/magratheaphp3/docs/?p=migrations/migrate-3.0 ). It's self-contained: each section has a check to tell whether it applies and the exact change to make.

---

## Table of Contents

1. [Setting Up a Project](#1-setting-up-a-project)
2. [Project Bootstrap](#2-project-bootstrap)
3. [Configuration](#3-configuration)
4. [Creating a Model](#4-creating-a-model)
5. [Creating a Control](#5-creating-a-control)
6. [Building Queries](#6-building-queries)
7. [Dumps](#7-dumps)
8. [Creating an API Class](#8-creating-an-api-class)
9. [Creating an API Controller](#9-creating-an-api-controller)
10. [JWT Authentication](#10-jwt-authentication)
11. [Caching](#11-caching)
12. [Logging & Debugging](#12-logging--debugging)
13. [Sending Email](#13-sending-email)
14. [Admin Panel — Entry Point](#14-admin-panel--entry-point)
15. [Admin Panel — Admin Class](#15-admin-panel--admin-class)
16. [Admin Panel — CRUD Features](#16-admin-panel--crud-features)
17. [Error Handling](#17-error-handling)
18. [Testing](#18-testing)
19. [Complete Application Skeleton](#19-complete-application-skeleton)
20. [Verify Your Work](#20-verify-your-work)
21. [Checklist Before Delivering Code](#21-checklist-before-delivering-code)

---

## 1. Setting Up a Project

1. `composer require platypustechnology/magratheaphp` (PHP 8.1+, with `mysqli` for a database).
2. Create the folders and files of the skeleton (§19): `app/_inc.php` (the bootstrap, §2), `configs/magrathea.conf` (§3), `public/` with the entry files, and `logs/`, `cache/`, `backups/` (writable by the web server).
3. Write `configs/magrathea.conf` **by hand** (§3). `configs/` must not be reachable from the web: it holds the database password.
4. The admin's tables (`_magrathea_config`, `_magrathea_roles`, `_magrathea_users`, `_magrathea_logs`) come from the admin's install SQL, `vendor/platypustechnology/magratheaphp/src/Admin/database.sql`. Run it once, with the human's go-ahead: `vendor/bin/magrathea db:query --write "$(cat vendor/platypustechnology/magratheaphp/src/Admin/database.sql)"`. Then create the first admin user, a super admin: `vendor/bin/magrathea admin:create-user <email>` generates a password and prints it once: hand it to the human. It works only while the admin has no users (exit `1` otherwise: never work around that, more users are added in the admin). `vendor/bin/magrathea admin:reset-password <email>` sets an existing user's password. Leave `--password=…` out of both: a given password lands in the shell's history (and needs 12+ characters).
5. Run `vendor/bin/magrathea check` (§20) until it reports no ERROR.

**The Setup Wizard** (`public/setup.php`) does steps 3 and 4 in a browser, for a human. Its entry file is exactly:

```php
<?php
// Magrathea setup wizard: locked by a token only someone with access to the server's files can read
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
```

Never call `MagratheaPHP::Load()` in it: the wizard works without a config. Every visit needs the 8-digit token it writes to `configs/.setup-token`. That token is for a human with access to the server's files: **an agent never needs it, never reads it, and never guesses it** (10 wrong tries lock the wizard until `configs/.setup-locked` is deleted). With a config present, the wizard also needs `bootstrap_unlocked = true` in the **active** environment's section, or it answers 403; its Finish step removes the flag everywhere but `[dev]`. Projects that don't use the wizard delete `public/setup.php`.

---

## 2. Project Bootstrap

**Every entry point starts the same way**, and the bootstrap lives in one shared file, `app/_inc.php`, that every entry point (`public/index.php`, `public/magrathea.php`) requires. The CLI (`vendor/bin/magrathea`, §20) loads the same file. Never skip a step.

Domain code is organized as **features**: one folder per table/domain under `app/features/<Name>/`, added with `->AddFeature(...)` (see §4–5 for what goes inside). `->AddRootCodeFolder(...)` registers the other folders, like `admin`, `api`, `shared`.

```php
<?php
// app/_inc.php: setup only. It prints nothing and catches nothing (the entry points do, §14, §19)
require __DIR__ . "/../vendor/autoload.php";

error_reporting(E_ALL);

Magrathea\MagratheaPHP::Instance()
    ->MinVersion("3.0.0")                   // optional, pins a minimum framework version
    ->AppPath(realpath(__DIR__))            // this folder (app/) becomes appRoot
    ->AppNamespace("App")                   // optional: in DEV, a missing App\… class adds a debug hint
    ->AddRootCodeFolder(                    // relative to AppPath
        "admin",
        "api",
        "shared",
    )
    ->AddFeature(                           // adds features/<Name> for each
        "Article", "Author",
    )
    ->Dev()                                 // or ->Prod() in production
    ->Load()                                // reads ../configs/magrathea.conf (AppPath's parent)
    ->Connect();                            // DB credentials; the connection itself opens on the first query
```

**Rules:**
- `AppPath()` must be called before `Load()`; `configs/magrathea.conf` (plural `configs`) is expected one level **above** `AppPath()` (`configs/` is a sibling of `app/`, not inside it)
- `AddRootCodeFolder()` paths are relative to `AppPath`; `AddCodeFolder()` takes paths **as given** (absolute, or relative to the current working directory, i.e. the entry script's folder under a web server). Call both, and `AddFeature()`, after `AppPath()`
- `AddFeature("Article", ...)` adds `<appRoot>/features/Article` to the autoloader. It also adds `features/Article/Base`, which only legacy projects use (2.x's generator put Base classes there); new features have no `Base` folder
- The autoloader is **not** PSR-4: it looks for `<ClassName>.php` (the basename, without namespace) in every code folder. Give every class a basename that's unique across the code folders. With duplicates, it loads the file whose `namespace` matches, and in DEV adds a warning on every request that loads one. A class it can't find fails with PHP's own `Error: Class "…" not found`, so `class_exists()` is safe
- Don't add an `autoload` entry for the app's classes to the project's `composer.json`: the code folders already load them. A mapping that doesn't match the real namespaces and folders (e.g. `"App\\": "./"` when no class is in `App\`) makes Composer print a PSR-4 "does not comply … Skipping." line per class on every optimized dump (`-o`, or `"optimize-autoloader": true`). `vendor/autoload.php` stays required: it loads the framework and registers its autoloader
- Use `->Dev()` locally, `->Prod()` on servers. They only set the Debugger mode (DEV prints errors, LOG writes them to the log); they do **not** choose the config environment (see §3)
- Call `->StartSession()` before any session use (admin panel, etc.)
- `->Connect()` (after `Load()`) is required before any DB use: it hands the config's `db_*` keys to `Database`; without it the first query throws `MagratheaDBException("DB not initialized")`. It doesn't open a connection: that happens on the first query, so calling it on every request is free
- `_inc.php` must not print anything (the CLI discards it, with a warning) and must not `exit`/`die`

---

## 3. Configuration

**Config file location:** `configs/magrathea.conf`, in `AppPath()`'s **parent** folder (`MagratheaPHP::Instance()->GetConfigRoot()`), e.g. `my-app/configs/magrathea.conf` when `AppPath()` is `my-app/app`.

Sections are named by **environment**, not by topic. `[general]` holds cross-environment settings, including `use_environment`: the name of the active section. It must name a section that exists, or every `Config::Instance()->Get()` throws "Could not find environment". The only other way to pick the environment is `Config::Instance()->SetEnvironment("production")` in code; `->Dev()`/`->Prod()` don't change it. Each environment section (`[dev]`, `[production]`, …) repeats the full flat set of keys it needs: there is no `[section:env]` override/inheritance syntax.

### Writing a config file

```ini
[general]
	use_environment = "dev"

[dev]
	db_host = "localhost"
	db_name = "my_db"
	db_user = "root"
	db_pass = "secret"
	db_port = "3306"
	logs_path = "../logs"
	cache_path = "../cache"
	backups_path = "../backups"
	timezone = "America/Sao_Paulo"
	app_url = "http://localhost:8080"
	jwt_key = "a-very-long-random-string-here"

[production]
	db_host = "db.prod.server"
	db_name = "my_db"
	db_user = "app_user"
	db_pass = "$=DB_PASSWORD"
	db_port = "3306"
	logs_path = "../logs"
	cache_path = "../cache"
	backups_path = "../backups"
	timezone = "America/Sao_Paulo"
	app_url = "https://myapp.example.com"
	jwt_key = "$=JWT_SECRET"
```

Keys are quoted strings; indent with a tab under each section header (not required by the parser, but keep it for consistency). `backups_path` is where dumps go (§7); without it, `<project>/backups`. `app_url` is only read by the admin's OpenApi feature. Any other key is the project's own: read it with `Get()`.

**Timezone:** the key is `timezone`, in each environment section (never `time_zone`, which is ignored, and never in `[general]`, which `Get()` doesn't read). `Load()` sets it as PHP's timezone, and every connection sets MySQL's session `time_zone` to PHP's, so `NOW()`/`CURRENT_TIMESTAMP` (which fill `created_at`/`updated_at`, §4) agree with PHP's clock. `db_sync_timezone = false` in the same section turns that off. Always set `timezone`; if MySQL lacks its timezone tables, the offset (`-03:00`) is used instead, which doesn't follow DST. `vendor/bin/magrathea check` includes the timezone checks.

**`bootstrap_unlocked`** opens the Setup Wizard (§1). It belongs only in `[dev]`, if anywhere.

### Reading config in code

```php
use Magrathea\Config;

// Read a single key from the active environment section (null if missing)
$host = Config::Instance()->Get("db_host");

// Read a key from a specific section: "section/key" (GetConfig() does NOT use the environment)
$prodHost = Config::Instance()->GetConfig("production/db_host");

// Read the full active-environment section (the section name is required)
$env = Config::Instance()->GetConfigSection(Config::Instance()->GetEnvironment());
echo $env["db_host"];
```

`Get()` and `GetConfig()` resolve `$=ENV_VAR` values; `GetConfigSection()` returns them raw.

**Never hardcode credentials.** Use `$=ENV_VAR_NAME` for secrets.

---

## 4. Creating a Model

A model maps to one database table. It's **one class**, in its feature folder (added with `->AddFeature("Article", ...)`, §2), extending `MagratheaModel` directly.

**Namespaces follow the feature, not the class kind** (with app namespace `App`): everything in `features/Article/` is `App\Article\…`: `App\Article\Article`, `App\Article\ArticleControl`, `App\Article\ArticleAdmin`. Don't mix in `App\Models`/`App\Controls` namespaces unless the project already uses them: the model finds its control, and the CRUD admin (§16) finds the control, as `<model class>Control`.

```php
<?php
// app/features/Article/Article.php
namespace App\Article;

use Magrathea\MagratheaModel;
use App\Author\Author;
use App\Comment\CommentControl;

class Article extends MagratheaModel {

    protected $dbTable = "articles";
    protected $dbPk = "id";
    protected $dbValues = [
        "id"        => "int",
        "title"     => "string",
        "body"      => "text",
        "author_id" => "int",
        "price"     => "decimal",
        "published" => "boolean",
        "views"     => "int",
    ];
    // optional: makes the CRUD admin (§16) show author_id as a select of authors
    protected $relations = [
        "external" => ["author_id" => "\\App\\Author\\Author"],
    ];

    // every $dbValues field as an untyped public property (created_at/updated_at are inherited)
    public $id, $title, $body, $author_id, $price, $published, $views;

    // relations are plain methods
    public function GetAuthor(): ?Author {
        return empty($this->author_id) ? null : new Author($this->author_id);
    }

    public function GetComments(): array {
        return CommentControl::GetWhere(["article_id" => $this->id]);
    }

    // business logic goes here too
    public function IsPublished(): bool {
        return (bool) $this->published;
    }
}
```

**`MagratheaModel` already provides**, so a model doesn't declare them:
- `__construct($id = 0)`: runs `MagratheaStart()` and, if `$id` isn't empty, loads that row (`MagratheaModelException` when it doesn't exist, code 400 when the id isn't valid for an int PK);
- `MagratheaStart()`: an empty hook, run first by the constructor. Set `$dbTable`/`$dbPk`/`$dbValues` there instead of as property defaults when they need code;
- `protected $autoload = null` (relations `GetById()` loads with a join);
- `public $created_at, $updated_at`;
- `GetControl()`: a new `<model class>Control` (`App\Article\Article` → `App\Article\ArticleControl`). Declare it only when the control is named otherwise.

**Rules:**
- Always set `$dbTable`, `$dbPk` (it has no default) and `$dbValues`, with the PK in `$dbValues` (`"id" => "int"`): without its type, loading and saving throw.
- Declare every `$dbValues` field as an untyped public property. Don't use `#[\AllowDynamicProperties]`.
- Don't redeclare `$created_at`/`$updated_at`/`$autoload`; if you must, keep them untyped and public (`$autoload` protected), or PHP refuses the class.
- Don't write a constructor unless it does more than load the row; if you do, keep `__construct($id = 0)` and call `parent::__construct($id)`.
- Don't `implements iMagratheaModel`: it's deprecated.
- Relations are explicit methods, as above: one query each, run only when called. Never call them in a loop over many objects (N+1 queries): use a join (§6) or one `GetWhere()` with `IN`.

Legacy projects may split a model into `features/Article/Base/ArticleBase.php` + `Article.php` (2.x generated the Base). That still works, and editing those Base files is fine; follow the project's existing convention when you extend one, and never add new Base classes.

### Supported field types

| Type | PHP value (with `$strictTypes`) | Column |
|------|------|--------|
| `int` | int | INT, BIGINT, SMALLINT… |
| `boolean` | bool | TINYINT(1), 0/1 |
| `string` | string | VARCHAR, CHAR, ENUM |
| `text` | string | TEXT, LONGTEXT, JSON |
| `float` | float | FLOAT, DOUBLE (approximate) |
| `decimal` | string (exact, never cast) | DECIMAL (money) |
| `datetime` | string `Y-m-d H:i:s` | DATETIME, TIMESTAMP |
| `date` | string `Y-m-d` | DATE |
| `uuid` | string | CHAR(36), generated on insert |

These are `MagratheaModel::TYPES`, with no aliases. Any other type (`"bool"`, `"varchar"`, `"tinyint"`, `"json"`, `"integer"`, `"double"`) throws `MagratheaModelException` when it's used: a write that binds that field, or a strict load. A type nothing uses yet doesn't throw, so don't rely on the exception: use only these types, and run `models:verify` (§20), which checks them all. `vendor/bin/magrathea db:describe <table>` shows the suggested type for each column.

**`uuid`:** a field declared `"uuid"` is filled with `Uuid::V7()` on `Insert()` whenever it's empty; a value set before the insert is kept. Nothing changes it on `Update()`. A `uuid` **primary key** is sent by `Insert()`, kept on the object and returned. `Save()` with a uuid already assigned calls `Update()`: call `Insert()` for a new row with a pre-set uuid. The framework doesn't check for collisions: declare the column `PRIMARY KEY`/`UNIQUE`.

**`date`:** normalized to `Y-m-d` on `Insert()`/`Update()`. It accepts `"YYYY-MM-DD"`, `"YYYY-MM-DD HH:MM:SS"` and ISO-8601 (`"YYYY-MM-DDTHH:MM:SS.sssZ"`), and throws `MagratheaModelException` for anything else. The date part is taken as written, with **no timezone conversion**, so clients should send plain `"YYYY-MM-DD"`.

**`decimal`:** declare SQL `DECIMAL` columns as `"decimal"`, never `"float"`: a strict `float` field rounds a large DECIMAL on load, and `Update()` writes the rounded value back. Do decimal math with `bcmath`, not `+`/`*`.

### Strict types

Models are strict by default (`$strictTypes = true`): values loaded from the database (`new Article(42)`, `GetById()`, any Control query) are cast, `int` → int, `boolean` → bool, `float` → float. `null` stays `null`, `decimal` is checked but stays the exact string, and the string types are untouched. A value that can't be cast throws `MagratheaModelException`. API JSON carries the native types (`42`, `true`).

**New models don't declare `$strictTypes`.** Models migrated from 2.x pin `protected $strictTypes = false;` (every value stays the driver's string) so their API JSON doesn't change. **Don't remove that pin without asking the human**: the model's JSON changes from `"42"` to `42` and from `"1"` to `true`, and its API consumers must accept that first.

### Timestamps and the table contract

The **database** owns `created_at` and `updated_at`. They don't go in `$dbValues`; `MagratheaModel` declares both and handles them by name:
- Every model's table has both columns, declared exactly like this (every SELECT built from a model reads them, and `GetAll()`/`GetWhere()` sort by `created_at`):
  ```sql
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ```
  Without `ON UPDATE`, `updated_at` never changes after the insert. Existing `TIMESTAMP` columns with those defaults work too. Other date columns the app sets itself are `DATETIME NULL`, never a bare `TIMESTAMP` (MySQL 5.7 may give it `ON UPDATE CURRENT_TIMESTAMP`).
- **Never** assign PHP's `now()` to them.
- `Insert()` doesn't send them while they're `null`, so the column's default fills them. One assigned on the object is sent **as assigned** (imports keep their dates).
- `Update()`/`Save()` never write them: `ON UPDATE CURRENT_TIMESTAMP` sets `updated_at`. `$obj->updated_at = …; $obj->Save();` writes nothing; `Set()`/`Assign()` ignore both. To set them explicitly, use `Query::Update()` (§6).
- Nothing reads the row back. After `Insert()`, both are `null` on the object (assigned ones too); after an `Update()` that wrote something, `updated_at` is `null` (`created_at` unchanged). `null` means "not read": `$obj->Reload()` (one query, returns the object) gets the stored values. Call it only where they're needed, never in loops or bulk saves.
- **API responses** have `null` timestamps after a create or update unless you reload. `MagratheaApiControl::Create()`/`Update()` don't reload; when the client needs the values, override:
  ```php
  public function Create($data = false) { return parent::Create($data)->Reload(); }
  public function Update($params) { return parent::Update($params)->Reload(); }
  ```
  In your own actions: `$article->Insert(); return $article->Reload();`.
- `$obj->Touch()` bumps `updated_at` on purpose ("last seen", "touch the parent"): `UPDATE … SET updated_at = CURRENT_TIMESTAMP WHERE pk = ?`, nothing else written, `updated_at` is `null` on the object afterwards; it throws without a PK. `Save()` on an unchanged object runs no query, so it can't do this.

### Using a model

```php
// Create and insert
$article = new Article();
$article->title     = "Hello World";
$article->body      = "My first article.";
$article->published = true;
$id = $article->Save(); // returns the new id (or ->Insert() explicitly)

// Load by PK
$article = new Article(42);
echo $article->title;

// Update
$article->title = "Updated Title";
$article->Save(); // existing PK → UPDATE of the changed fields

// Delete
$article->Delete();

// Fill from input: Assign(), never LoadObjectFromTableRow()
$article = new Article();
$article->Assign(["title" => $post["title"], "body" => $post["body"]]);
$article->Insert();
```

**What `Update()` writes:**
- **Loaded object** (`new Article(42)`, `GetById()`, any Control query, or after its own `Insert()`/`Update()`): only the fields changed since then, by plain assignment or `Set()`/`Assign()`. Other columns are left alone; with nothing changed, no query runs (and `Update()` returns `true`). `5`/`"5"` and `true`/`"1"` count as the same value; `null` only equals `null`.
- **Never-loaded object** (`new Article(); $a->id = 42; … Update()`): with any `Set()`/`Assign()`, only those fields (plain assignments are dropped); otherwise every field, except `null` int/boolean fields (skipped). So for updates, load the object first (`new Article($id)`) instead of building one by hand.
- **Clearing an int/boolean (e.g. a nullable FK):** on a loaded object, `$a->category_id = null; $a->Save();` writes SQL `NULL`. `""` (an empty form field) in an int/boolean field is always written as `NULL`, never `0`: a `NOT NULL` int column needs a real value.
- `LoadObjectFromTableRow()` marks the object as loaded: use it for DB rows only, never for input (`$_POST`, `GetPut()`): use `Assign()`.

**Ids from input:** on an int PK, `new Article($id)` accepts only an int or a string of digits; anything else throws `MagratheaModelException` with code 400 (an API answers HTTP 400). To check an id before using it: `$id = (new Article())->ValidateId($input);`.

**Serializing:** an API action returns the model itself (or an array of models): the response is `json_encode()` of its public properties, `created_at`/`updated_at` included. `ToArray()` returns only the `$dbValues` fields (plus relation properties), so it leaves the timestamps out. Never return `ToJson()`, which wraps the fields in an envelope.

---

## 5. Creating a Control

Every model has a companion Control, `<Model>Control`, in the same feature folder and namespace: static data access for that model's table. It's one class:

```php
<?php
// app/features/Article/ArticleControl.php
namespace App\Article;

use Magrathea\MagratheaModelControl;

class ArticleControl extends MagratheaModelControl {
    protected static $model = Article::class;

    // query methods are static
    public static function GetPublished(): array {
        return static::GetWhere(["published" => 1]);
    }

    public static function GetByAuthor(int $authorId): array {
        return static::RunQuery(
            "SELECT * FROM ".static::GetDbTable()." WHERE author_id = ".$authorId." ORDER BY created_at DESC"
        );
    }
}
```

- `$model` is all a Control needs: the table comes from the model, through `static::GetDbTable()`. In custom methods, read the table with `static::GetDbTable()`, never `static::$dbTable` (it's empty when only `$model` is set).
- Don't declare your own `$model` property or `GetDbTable()` method in another shape: PHP refuses the class.
- Legacy Controls declaring `$modelNamespace`, `$modelName` and `$dbTable` (and legacy `…ControlBase` classes) keep working; don't convert them unasked.

### Using a control

```php
use App\Article\ArticleControl;
use App\Article\Article;
use Magrathea\DB\Query;

// All records (ordered by created_at)
$all = ArticleControl::GetAll();

// Filtered (array values are escaped)
$published = ArticleControl::GetWhere(["published" => 1]);

// First match, or null
$article = ArticleControl::GetRowWhere(["id" => 42]);

// Raw WHERE string: you escape it yourself
$recent = ArticleControl::GetSimpleWhere("created_at > '2024-01-01'");

// Custom SQL returning models
$top = ArticleControl::RunQuery("SELECT * FROM articles ORDER BY views DESC LIMIT 5");

// Scalar
$total = ArticleControl::QueryOne("SELECT COUNT(*) FROM articles WHERE published = 1");

// Paginated, with total (costs an extra COUNT(*) query)
$total = 0;
$query = Query::Select()
    ->Obj(new Article())
    ->Where(["published" => 1])
    ->Order("created_at DESC");
$page = ArticleControl::RunPagination($query, $total, page: 0, limit: 10);

// Paginated for an API response: no COUNT(*), has_more from a limit+1 fetch.
// Return the MagratheaPagination directly from a controller action (§9).
$pagination = ArticleControl::GetPagination($query, page: 0, limit: 10);
```

`GetRowWhere()` returns one object or `null`, with an array or a string; `GetWhere()` and `GetSimpleWhere()` return arrays.

---

## 6. Building Queries

Use the Query Builder for anything beyond simple `GetWhere` calls.

```php
use Magrathea\DB\Query;
use Magrathea\DB\Database;

// Basic SELECT
$sql = Query::Select()
    ->Table("articles")
    ->Where(["published" => 1])
    ->Order("created_at DESC")
    ->Limit(10)
    ->Page(0)          // page * limit = OFFSET
    ->SQL();

// SELECT with model (fills table and fields)
$sql = Query::Select()
    ->Obj(\App\Article\Article::class)
    ->Where(["published" => 1])
    ->SQL();

// JOIN
$sql = Query::Select()
    ->Obj(\App\Article\Article::class)
    ->SelectExtra("u.name AS author_name")
    ->Inner("users u", "u.id = articles.author_id")
    ->Where(["articles.published" => 1])
    ->SQL();

// INSERT
$sql = Query::Insert()
    ->Table("articles")
    ->Values(["title" => "New Post", "published" => 0])
    ->SQL();

// UPDATE (the explicit way to set a timestamp, e.g. an import)
$sql = Query::Update()
    ->Table("articles")
    ->SetArray(["title" => "New Title", "created_at" => "2001-01-01 00:00:00"])
    ->Where("id = 42")
    ->SQL();

// DELETE
$sql = Query::Delete()
    ->Table("articles")
    ->Where("id = 42")
    ->SQL();

// Execute
$rows = Database::Instance()->QueryAll($sql);
$one  = Database::Instance()->QueryRow($sql);
$val  = Database::Instance()->QueryOne($sql);
```

**Never interpolate user input into SQL unescaped.** The query builder's array forms (`Where([...])`, `SetArray()`, `Values()`) escape values for you; `null` writes `NULL` and a boolean `'1'`/`'0'`. A string `Where("…")` is written as given. For a hand-built fragment, escape with `Database::Instance()->Escape()` (it wraps `mysqli::real_escape_string`), or cast ints with `(int)`:

```php
$safe = Database::Instance()->Escape($_GET["search"]);
$rows = Database::Instance()->QueryAll("SELECT * FROM articles WHERE title LIKE '%$safe%'");
```

`PrepareAndExecute()` is for **writes**: it returns the insert id (or `true`), never rows, so don't use it for a SELECT. Its types are Magrathea field types (`"int"`, `"boolean"`, `"float"`, `"string"`, `"text"`, …), not mysqli letters:

```php
$id = Database::Instance()->PrepareAndExecute(
    "INSERT INTO articles (title, author_id) VALUES (?, ?)",
    ["string", "int"],
    [$post["title"], $userId]
);
```

`Database::Instance()->QueryStream($sql)` yields rows one at a time (unbuffered), for result sets too large for memory. `Query()`/`QueryMulti()` split multi-statement SQL correctly (quotes, comments, `DELIMITER`).

Every DB error throws `MagratheaDBException`: `getCode()` is MySQL's errno (`1062` duplicate entry…), `query`/`values` hold the statement, and mysqli's own exception is `getPrevious()`. That includes the model's `Insert()`/`Update()`/`Save()`/`Delete()`. Catch `MagratheaDBException`, never `mysqli_sql_exception`, and never check `if (!$obj->Insert())`. In an API it's always a 500; turn expected errors into a `MagratheaApiException`:

```php
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaApiException;

try {
    $user->Insert();
} catch (MagratheaDBException $ex) {
    if ($ex->getCode() !== 1062) throw $ex;
    throw new MagratheaApiException("Email already registered", 409);
}
```

### Connections and long-running workers

Each `Database` instance opens one connection on its first query and keeps it until the end of the request. Session state (`SET @var`, `GET_LOCK()`, temporary tables, `LAST_INSERT_ID()`) lasts the whole request. `Database::Instance()->LastAffectedRows()` returns the rows affected by the last query.

**CLI scripts that loop (workers, daemons) must call `Disconnect()` at the start of each job**, or the first query after an idle longer than MySQL's `wait_timeout` fails:

```php
use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaDBConnectionLostException;

while (true) {
    Database::Instance()->Disconnect(); // next query opens a fresh connection
    $job = NextJob();
    try {
        ProcessJob($job);
    } catch (MagratheaDBConnectionLostException $ex) {
        // code 2006: statement probably not executed; 4031 (idle timeout): not executed;
        // 2013: it may have executed.
        // The framework never retries. Retry only if ProcessJob() is idempotent.
        Requeue($job);
    }
}
```

`MagratheaDBConnectionLostException` extends `MagratheaDBException`; the dead connection is already dropped when it's thrown, so the next call reconnects. A second database is a subclass (`class ReportsDb extends Database {}` → `ReportsDb::Instance()`), with its own connection; models, controls and the query builder always use `Database::Instance()`.

### Transactions

Wrap related writes in `Database::Instance()->Transaction(fn)`: everything inside (model `Insert()`/`Update()`/`Delete()`, controls, raw queries, `SELECT … FOR UPDATE`) commits when the callback returns, or rolls back if it throws (the exception is rethrown unchanged). It returns the callback's result.

```php
$orderId = Database::Instance()->Transaction(function() use ($cart) {
    $stock = Database::Instance()->QueryOne("SELECT stock FROM products WHERE id = 5 FOR UPDATE");
    if ($stock < 1) throw new MagratheaApiException("Out of stock", 409);
    $order = new Order();
    $order->user_id = $cart->user_id;
    $order->Insert();
    Database::Instance()->Query("UPDATE products SET stock = stock - 1 WHERE id = 5");
    return $order->id;
});
```

**Rules:**
- Nested `Transaction()` calls run inline (no savepoints): an inner throw rolls back everything, even if outer code catches it.
- Never catch-and-continue DB exceptions inside the callback: after a lost connection, later queries would run on a new autocommit connection, outside the transaction.
- No DDL (`CREATE`/`ALTER`/`TRUNCATE`) inside: MySQL commits implicitly.
- Don't call `QueryTransaction()` inside it (it throws), and don't end the request (`die`, `Json()`) inside it.
- Prefer it over `QueryTransaction(array $sql)`, which only takes fixed SQL strings.

---

## 7. Dumps

`Magrathea\DB\Dump` exports the database to a `.sql` file in the backups folder (`backups_path`, §3) and imports one back. It uses `mysqldump`/`mysql` when they're available (the password never reaches the command line), and a PHP driver otherwise.

```php
use Magrathea\DB\Dump;

$dump = new Dump();
$rs = $dump->Export("before-migration");                 // backups/before-migration.sql
// ["file" => "before-migration.sql", "size" => 48213, "driver" => "binary"]
$dump->Export("articles-only", ["tables" => ["articles"]]);

$dump->Import("before-migration");                       // stops at the first failing statement
$dump->Import("before-migration", ["continue" => true]); // keeps going; returns the errors
```

- Files are **names** in the backups folder, never paths (`Dump::ValidateBackupFileName()`: `.sql` is appended, `/`, `\` and `..` are refused). `Dump::Folder()` is the folder, `Dump::GetDefaultFileName()` is `backup_<Ymd>.sql`.
- `Export()` never overwrites an existing file. With the PHP driver, procedures and functions are skipped (listed in `skipped`).
- From the shell: `vendor/bin/magrathea db:dump [file]` and `vendor/bin/magrathea db:import <file> --yes`.
- **Before anything that changes data or schema (an ALTER, a migration, an import), take a dump, and ask the human before importing anything**: an import overwrites tables.

---

## 8. Creating an API Class

Encapsulate all route definitions in a class that extends `MagratheaApi`. Both the HTTP entry point and the admin's ApiExplorer instantiate the same class.

```php
<?php
namespace App;

use Magrathea\MagratheaApi;
use App\Api\AuthControl;
use App\Api\ArticleApiControl;

class MyAppApi extends MagratheaApi {

    public function __construct() {
        $this->Initialize();
    }

    public function Initialize() {
        $this->AllowAll(); // production: $this->AllowFromFile(); (configs/cors-origins.txt)
        $this->SetAddress("/api/v1");
        $this->HealthCheck(true); // includes "database": "ok"|"fail" in the response
        $this->SetAuth();
        $this->Articles();
    }

    private function SetAuth() {
        $auth = new AuthControl();
        $this->BaseAuthorization($auth, "ValidateToken");
        $this->Add("POST", "auth/login", $auth, "Login");
        $this->Add("GET",  "me",         $auth, "Me",    true);
    }

    private function Articles() {
        $api = new ArticleApiControl();
        $this->Add("GET",    "articles",     $api, "List",   true);
        $this->Add("GET",    "articles/:id", $api, "Read",   true);
        $this->Add("POST",   "articles",     $api, "Create", true);
        $this->Add("PUT",    "articles/:id", $api, "Update", true);
        $this->Add("DELETE", "articles/:id", $api, "Delete", true);
    }
}
```

**HTTP entry point** (`public/index.php`): runs the API class, and answers a generic error if the bootstrap fails (§17):

```php
<?php
try {
    $api = require __DIR__ . "/api.php";
} catch (\Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    header("Content-Type: application/json");
    die(json_encode(["success" => false, "message" => "Internal Server Error"]));
}
$api->Run();
```

**Shared setup file** (`public/api.php`): returns the configured instance, importable by both the entry point and the admin:

```php
<?php
require_once __DIR__ . "/../app/_inc.php"; // the shared bootstrap from §2
return new \App\MyAppApi();
```

**Key rules:**
- Group routes into private methods by domain (Auth, Articles, etc.)
- `Run()` is called only in the HTTP entry point, never inside the class
- `Add($method, $url, $control, $action, $auth = false)`: `$auth` is `false` (public), `true` (the `BaseAuthorization()` method) or the name of another method of the auth class
- `Crud("article", $control, true)` registers `POST articles`, `GET articles`, `GET article/:id`, `PUT article/:id`, `DELETE article/:id` on `MagratheaApiControl`'s default `Create`/`Read`/`Update`/`Delete`
- `AllowAll()` is fine for development (it sends no `Access-Control-Allow-Credentials`). In production use `AllowFromFile()`, which reads `configs/cors-origins.txt` (one `scheme://host[:port]` per line, `#` comments; the admin edits it with `$this->LoadCors()`, §15), or `Allow([...])` for a fixed list. Origins match exactly: no trailing slash
- `AddAcceptHeaders([...])` appends to the accepted headers (`Authorization`, `Content-Type`); `SetAcceptHeaders([...])` replaces them
- Route params use `:name` syntax: they arrive as `$params["name"]` in the action. A route without params passes `false`, so never type the parameter as `array`; query strings are read from `$_GET`

---

## 9. Creating an API Controller

```php
<?php
namespace App\Api;

use Magrathea\MagratheaApiControl;
use Magrathea\MagratheaPagination;
use Magrathea\Exceptions\MagratheaApiException;
use Magrathea\DB\Query;
use App\Article\ArticleControl;
use App\Article\Article;

class ArticleApiControl extends MagratheaApiControl {

    // GET /articles
    public function List(): array {
        return ArticleControl::GetWhere(["published" => 1]);
    }

    // GET /articles/paginated?page=0: returning a MagratheaPagination makes the response
    // {success, data, page, count, has_more}. Query strings are read from $_GET: $params has only route params
    public function ListPaginated(): MagratheaPagination {
        $query = Query::Select()
            ->Obj(new Article())
            ->Where(["published" => 1])
            ->Order("created_at DESC");
        return ArticleControl::GetPagination($query, page: (int)($_GET["page"] ?? 0), limit: 20);
    }

    // GET /articles/:id
    public function Read($params = false): Article {
        $id = (new Article())->ValidateId($params["id"]); // malformed id: code 400 → HTTP 400
        $article = ArticleControl::GetRowWhere(["id" => $id]);
        if (!$article) {
            throw new MagratheaApiException("Article not found", 404);
        }
        return $article;
    }

    // POST /articles
    public function Create($data = false): Article {
        $post = $this->GetPost(); // reads JSON or form body

        $article = new Article();
        $article->title     = $post["title"] ?? "";
        $article->body      = $post["body"]  ?? "";
        $article->author_id = $this->GetUserId(); // from the JWT
        $article->published = false;
        $article->Insert();

        return $article->Reload(); // the client gets created_at/updated_at
    }

    // PUT /articles/:id
    public function Update($params): Article {
        $article = $this->Read($params);
        $put = $this->GetPut();
        $article->Assign(array_intersect_key($put, array_flip(["title", "body"])));
        $article->Save();
        return $article->Reload();
    }

    // DELETE /articles/:id
    public function Delete($params = false): bool {
        return $this->Read($params)->Delete();
    }
}
```

**Controller rules:**
- Throw `MagratheaApiException` for expected errors (404, 400, 403): the router converts it to JSON with that HTTP status
- Use `$this->GetPost()` for a POST body, `$this->GetPut()` for a PUT body; `GetPut()` also reads a `PATCH` body (or call its alias `$this->GetPatch()`). `Crud()` only registers `Update` under `PUT`: add a `PATCH` route with `Add("PATCH", ...)`
- Whitelist the fields you take from input (`array_intersect_key()` as above); never `Assign()` a whole body to a model with fields the client mustn't set
- Use `$this->GetUserId()` to read the user id from the decoded JWT: it reads the payload's `id` claim (then `user_id`), so encode the user's id as `"id"`
- Return models (or arrays of models) directly; see §4, "Serializing". After a write, `->Reload()` if the client needs the timestamps
- A controller that only needs the default CRUD sets `protected $model = Article::class;` and `$this->service = new ArticleControl();` in its constructor, and is registered with `Crud()`
- Never `echo`, `var_dump` or `die()` inside controllers

---

## 10. JWT Authentication

### Step 1: Auth controller

```php
<?php
namespace App\Api;

use Magrathea\MagratheaApiAuth;
use Magrathea\Exceptions\MagratheaApiException;
use App\User\UserControl;

// The class passed to BaseAuthorization() must extend MagratheaApiAuth (a MagratheaApiControl
// subclass); a plain MagratheaApiControl throws a TypeError there.
class AuthControl extends MagratheaApiAuth {

    // GetSecret() reads "jwt_key" from the active environment section by default:
    // override it only for another key name.

    // POST /auth/login
    public function Login(): array {
        $post = $this->GetPost();
        $user = UserControl::GetRowWhere(["email" => $post["email"] ?? ""]);

        if (!$user || !password_verify($post["password"] ?? "", $user->password_hash)) {
            throw new MagratheaApiException("Invalid credentials", 401);
        }

        $token = $this->jwtEncode([
            "id"      => $user->id,     // GetUserId() reads "id"
            "email"   => $user->email,
            "role"    => $user->role,
            "exp"     => time() + 86400, // 24 hours
        ]);

        return ["token" => $token, "user" => ["id" => $user->id, "email" => $user->email]];
    }

    // Base authorization for all protected routes
    public function ValidateToken(): bool {
        $token   = $this->GetAuthorizationToken(); // reads the Bearer token
        $payload = $this->GetTokenInfo($token);    // decodes + verifies

        if (!$payload || !isset($payload->id)) {
            throw new MagratheaApiException("Unauthorized", 401); // code 0 would be HTTP 500
        }

        $this->userInfo = $payload; // stored for downstream use
        return true;
    }
}
```

Never return a user model with its password hash: build the array, as above.

### Step 2: Access user in other controllers

```php
public function Create($data = false): Article {
    $userId = $this->GetUserId();       // int|null from the token
    $info   = $this->GetUserInfo();     // full decoded payload object
    $role   = $info->role ?? "guest";
    // ...
}
```

**JWT rules:**
- Always set `"exp"` in the payload
- Never store passwords or sensitive PII in the token
- The secret comes from the config (`jwt_key`, read by the default `GetSecret()`), never a hardcoded string
- `GetAuthorizationToken()` throws if the header is missing: call it inside `try/catch` or rely on the route's auth parameter

### Step 3: Cookie-based auth (opt-in)

`GetTokenInfo()` also falls back to a cookie after `Bearer`/`Basic`, but only once a project overrides `$cookieName`: it's `null` by default. Useful for sharing a session across sibling subdomains via a cookie scoped to a parent domain.

```php
class AuthControl extends MagratheaApiAuth {

    // Enables the cookie fallback in GetTokenInfo(); override on a shared
    // base ApiControl class if more than one controller needs it.
    protected ?string $cookieName = "app_session";

    public function Login(): array {
        // ... validate credentials, build $token as above ...

        // Issues the cookie; expiry is derived from the token's own "exp" claim
        $this->SetAuthCookie($token, ".example.com"); // domain scoped to share across subdomains
        return ["token" => $token];
    }

    // HttpOnly cookies can't be cleared client-side: logout needs a real endpoint
    public function Logout(): array {
        $this->ClearAuthCookie(".example.com");
        return ["success" => true];
    }
}
```

**Cookie auth rules:**
- The header wins when both a header and a matching cookie are present
- `SetAuthCookie()`/`ClearAuthCookie()` are `Secure` by default (`$forceSecureCookie`, default `true`): for local `http://localhost` development, override `$forceSecureCookie` to `false` on your `ApiControl` subclass, or pass `forceSecure: false` per call
- Two `ApiControl`s sharing a cookie need the *same* `$cookieName` **and** the same `$domain` passed to `SetAuthCookie()`

---

## 11. Caching

`Cache($name, $data=null)` does **not** take the response payload as its second argument. `$data` is an optional key *suffix*: it's appended to `$name` (as `"{$name}-{$data}"`) to key the cache by an id or page number. Never pass a JSON blob or the computed result as `$data`.

Calling `$this->Cache($name, $data)` only *peeks*: if a cache file for that key exists, it outputs it and ends the request (`die`) right there. It never writes anything itself. The write happens automatically later, when the framework serializes the action's return value to JSON: no second call is needed or correct.

Only successful responses are cached. `CacheClear()`/`CacheClearPattern()` don't touch the key set by `Cache()`, so an action may call both. API calls in raw mode (`SetRaw()`, `Run(true)`, e.g. from tests) bypass the cache entirely: the action always runs and nothing is saved.

### In a controller

```php
public function List(): array {
    // Serve from cache if available (outputs and exits on a hit).
    // Saving happens automatically when the response is emitted.
    $this->Cache("articles_published");
    return ArticleControl::GetWhere(["published" => 1]);
}

public function View(array $params = []): Article {
    // $data suffix keys the cache per id
    $this->Cache("article", $params["id"]);
    return new Article($params["id"]);
}

public function Create($data = false): Article {
    // ... create ...

    // Invalidate the related cache (same $name/$data used to build the key)
    $this->CacheClear("articles_published");
    return $article->Reload();
}
```

### Invalidate by pattern (when many related keys exist)

```php
$this->CacheClearPattern("articles_*");
```

### Cache key naming

Always include the parameters that affect the result. Use the `$data` suffix for a single discriminator (id, page); bake the rest into `$name`:

```php
$this->Cache("articles_cat_{$categoryId}", $page);
$this->Cache("user_profile", $userId);
```

---

## 12. Logging & Debugging

### Logging (production)

```php
use Magrathea\Logger;

Logger::Instance()->Log("Order #$orderId processed by user #$userId");

try {
    // something risky
} catch (\Exception $e) {
    Logger::Instance()->LogError($e);
}

Logger::Instance()->SetLogFile("payments")->Log("Payment success: $amount");
```

Logs go to `logs_path` (§3), one file per month by default.

### Debugging (development only)

```php
use Magrathea\Debugger;

Debugger::Instance()->Info("Cache miss for: articles_published");
Debugger::Instance()->Add(["user_id" => $id, "role" => $role]);
Debugger::Instance()->Show();
```

In DEV, debug items (including every `MagratheaException`, even caught ones) print as `<pre>` where they're added; `->Debug()` mode (`Debugger::DEBUG`) stores them and prints them all at the end of the request (plus query logging). Inside API calls neither prints: the items go to the JSON response as `_debug`, which only DEV/DEBUG responses have. Never `echo`/`var_dump` in an endpoint to debug it: that breaks the JSON; use `Debugger::Instance()->Add(...)` and read `_debug`.

---

## 13. Sending Email

### Native mail()

```php
use Magrathea\MagratheaMail;

$mail = new MagratheaMail();
$mail->SetTo($user->email)
     ->SetFrom("noreply@myapp.com")
     ->SetSubject("Welcome!")
     ->SetHTMLMessage("<h1>Hi!</h1>")
     ->SetTXTMessage("Hi!")
     ->Send();
```

### SMTP

```php
use Magrathea\MagratheaMailSMTP;
use Magrathea\Config;
use Magrathea\Logger;

// SMTP settings are never read from the config automatically: pass them in
$config = Config::Instance();
$mail = new MagratheaMailSMTP();
$mail->SetSMTP($config->Get("smtp_host"), $config->Get("smtp_port"), $config->Get("smtp_user"), $config->Get("smtp_pass"));
$mail->SetFrom("noreply@myapp.com")
     ->SetTo($user->email)
     ->SetSubject("Reset")
     ->SetHTMLMessage($html);
if (!$mail->Send()) {
    Logger::Instance()->Log("mail failed: " . $mail->GetError());
}
```

The `smtp_*` key names above are the project's choice (any names work). Port `587` uses STARTTLS, other ports SMTPS, unless `SetSMTPArray()` passes `smtp_encryption`. `Send()` returns `false` when validation fails (missing message or SMTP setting; `GetError()` says which); a failed SMTP send throws PHPMailer's exception.

---

## 14. Admin Panel — Entry Point

The admin entry point bootstraps Magrathea (including `StartSession()`), then hands off to `AdminManager`.

```php
<?php
// public/magrathea.php
use Magrathea\Admin\AdminManager;
use Magrathea\MagratheaPHP;

try {
    require __DIR__ . "/../app/_inc.php"; // shared bootstrap from §2 (no Run())
    MagratheaPHP::Instance()->StartSession();
    AdminManager::Instance()->Start(new \App\MyAppAdmin()); // app/admin/MyAppAdmin.php, autoloaded
} catch (\Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    echo "Internal Server Error";
}
```

**Rules:**
- `->StartSession()` must run before `Start()` for admin auth to work (here, or in `_inc.php`)
- `AdminManager::Instance()->Start()` handles auth, rendering and routing: it does not return
- Never call `Run()` on the API inside an admin entry point
- Never print an exception (`p_r($ex)`, `var_dump`, `getMessage()`) to the page: it exposes paths, SQL and arguments. Log it and answer a generic error (§17)

---

## 15. Admin Panel — Admin Class

Extend `\Magrathea\Admin\Admin` and implement `\Magrathea\Admin\iAdmin`.

```php
<?php
namespace App;

use Magrathea\Admin\AdminMenu;
use Magrathea\Admin\Features\ApiExplorer\ApiExplorer;
use Magrathea\Admin\Features\AppConfig\AdminFeatureAppConfig;
use App\Article\ArticleAdmin;

class MyAppAdmin extends \Magrathea\Admin\Admin implements \Magrathea\Admin\iAdmin {

    private $features = [];
    private $apiFeature;

    public function Initialize() {
        $this->SetTitle("My App Admin");
        $this->SetPrimaryColor("#5a2672");
        $this->SetAdminLogo(__DIR__ . "/logo.svg"); // absolute path
    }

    public function Auth($user): bool {
        return parent::Auth($user); // default Magrathea admin auth
    }

    public function SetFeatures() {
        parent::SetFeatures();
        $this->LoadCors();      // optional: edits configs/cors-origins.txt (AllowFromFile(), §8)
        $this->LoadConfig();
        $this->LoadFeatures();
        $this->LoadApi();
    }

    public function LoadApi() {
        $this->apiFeature = new ApiExplorer();
        $this->apiFeature->SetApi(new MyAppApi()); // instantiate the API class
        $this->AddFeature($this->apiFeature);
    }

    public function LoadConfig() {
        $this->features["app-config"] = new AdminFeatureAppConfig(true); // true: hide the system keys
        $this->features["app-config"]->featureId   = "AppConfig";
        $this->features["app-config"]->featureName = "Settings";
        $this->AddFeature($this->features["app-config"]);
    }

    public function LoadFeatures() {
        $this->AddCrudFeature(new ArticleAdmin());
        // add more CRUD features here
    }

    public function BuildMenu(): AdminMenu {
        $menu = new AdminMenu();

        $menu->Add($this->features["app-config"]->GetMenuItem());

        $menu->Add($menu->CreateTitle("Api"))
             ->Add($this->apiFeature->GetMenuItem());

        $this->AddFeaturesMenu($menu); // adds every CRUD feature

        $menu->Add(["title" => "Magrathea", "type" => "main"]);
        $this->AddMagratheaMenu($menu);
        $menu->Add($menu->GetLogoutMenuItem());

        return $menu;
    }
}
```

**Key methods:**
- `Initialize()`: title, primary color, logo path (called once on load)
- `Auth($user)`: return `parent::Auth($user)` for the default Magrathea auth
- `SetFeatures()`: always call `parent::SetFeatures()` first (it loads App Config, Cache, Users and User Logs), then your own
- `LoadApi()`: creates `ApiExplorer`, calls `SetApi(new YourApiClass())`, adds the feature
- `LoadFeatures()`: calls `AddCrudFeature()` for each CRUD admin class
- `BuildMenu()`: builds the sidebar; `CreateTitle()` for section headers, `AddFeaturesMenu()` for the CRUD items, `AddMagratheaMenu()` for the framework's pages (including the Database section: explorer, queries, dumps, checks); always end with `GetLogoutMenuItem()`
- `AddFeature()` vs `AddCrudFeature()`: `AddCrudFeature` for `AdminCrudObject` subclasses, `AddFeature` for everything else

---

## 16. Admin Panel — CRUD Features

Each model gets a companion CRUD admin class extending `AdminCrudObject`, `features/Article/ArticleAdmin.php`. Configure it in `Initialize()` by handing it a model instance.

```php
<?php
// app/features/Article/ArticleAdmin.php
namespace App\Article;

class ArticleAdmin extends \Magrathea\Admin\Features\CrudObject\AdminCrudObject {
    public string $featureName = "Articles"; // menu/page title (default: "Article CRUD")

    public function Initialize() {
        $this->SetObject(new Article());
    }
}
```

**How `SetObject()` finds the control:** `SetObject($model, $control = null)`. Without a control, it instantiates `<model's full class name>Control`, so `App\Article\Article` → `App\Article\ArticleControl` (the feature layout from §4). If the control lives elsewhere, pass it: `$this->SetObject(new Article(), new ArticleControl())`; otherwise `SetObject()` throws a `MagratheaModelException` naming the missing class. The feature id defaults to `CRUD<Model>` (`CRUDArticle`) and becomes the menu link (`?magrathea_feature=CRUDArticle`). The form shows a field listed in the model's `$relations["external"]` (§4) as a select of that model's rows.

### Permissions

By default `AdminCrudObject` allows everything to any logged-in admin. Override `HasPermission()`: it's called with the subpage about to run (`null` for the list page, then `"List"`, `"Form"`, `"Save"`, `"Delete"`); returning `false` shows the permission-denied page.

```php
class ArticleAdmin extends \Magrathea\Admin\Features\CrudObject\AdminCrudObject {
    // ... Initialize() ...

    public function HasPermission($action = null): bool {
        return !in_array($action, ["Save", "Delete"]); // read-only
    }
}
```

### CSRF protection

The admin enforces a CSRF token on every authenticated POST, automatically. Forms rendered through `AdminForm`/`AdminCrudObject` and AJAX calls made through the shared `ajax()`/`callAction()`/`callApi()`/`callFeature()` JS helpers already carry it; nothing to do for standard CRUD features or forms.

Only for a raw `<form>` or a raw AJAX call that bypasses those helpers:

```php
use Magrathea\Admin\AdminCsrf;

// inside a raw <form method="post">
echo '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'" />';
```

Or, for a raw AJAX call, send the same value as an `X-Magrathea-CSRF-Token` header. A POST with a missing or invalid token is rejected before it reaches your code. GET requests are never checked.

### Custom admin pages

Render tables and forms through `AdminElements` (`Table()` and the form elements escape every value). A feature's actions are its own public, non-static methods with no required parameters; an action that writes must refuse anything but POST.

---

## 17. Error Handling

### In API controllers: MagratheaApiException

```php
use Magrathea\Exceptions\MagratheaApiException;

throw new MagratheaApiException("Email is required", 400);
throw new MagratheaApiException("Unauthorized", 401);
throw new MagratheaApiException("You don't have permission", 403);
throw new MagratheaApiException("Resource not found", 404);

throw (new MagratheaApiException("Validation failed", 422))
    ->SetData(["errors" => $validationErrors]);

try {
    // some operation
} catch (\Exception $e) {
    throw MagratheaApiException::FromException($e, 500);
}
```

The code sets the HTTP status of the error response: `404` → 404, `4031` → 403 (4 digits: code / 10, for app sub-codes), `40312` → 403 (5 digits: code / 100). Code `0`, or anything that doesn't map to 400-599 (e.g. MySQL's `1062`), gives **500**, so never throw code 0 for an expected error. To send a status that differs from the code: `(new MagratheaApiException("Token missing", 400))->SetStatus(401)`. Exceptions that aren't `MagratheaApiException` get the same mapping (a `MagratheaModelException` with code 400, from a malformed id, answers 400). A DB error (`MagratheaDBException`, whose code is MySQL's errno) is always 500: to answer a duplicate entry with a 409, catch it and throw a `MagratheaApiException` (§6).

A PHP `\Error` in an action (`TypeError`, calling an undefined method...) becomes a JSON 500, logged with `Logger::LogError()`; the client gets the message only in DEV ("Internal Server Error" otherwise). Don't catch `\Error`s in controllers just to return JSON: the router already does.

Every error response is a real 4xx/5xx. Client code reads errors in the HTTP client's error path (axios `catch`, jQuery `.fail`), not by checking `success === false` in the success handler.

### In entry points

Entry points never print an exception: no `p_r($ex)`, no `var_dump`, no `getMessage()` on a production page. They log it and answer a generic error, as in §8 and §14. Use `error_log()` there, not `Logger`: when the bootstrap fails, the config (and so the log folder) may not be loaded. Errors the framework catches itself (a missing config, `MinVersion()` failing) show its error page, with the trace only in DEV; in the CLI they print to stderr and exit with code 1.

### Global exception handler (optional, add to an entry point)

```php
set_exception_handler(function (\Throwable $e) {
    \Magrathea\Logger::Instance()->LogError($e);
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Internal Server Error"]);
    exit;
});
```

---

## 18. Testing

### PHPUnit bootstrap (phpunit.xml)

```xml
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="App">
            <directory>tests/</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Mocking for unit tests

```php
use PHPUnit\Framework\TestCase;
use Magrathea\DB\Database;
use Magrathea\Config;

class MyTest extends TestCase {
    protected function setUp(): void {
        Database::Instance()->Mock(); // queries go to DatabaseSimulate, no MySQL needed
        // same shape as magrathea.conf: [general] + one flat environment section
        Config::Instance()->SetConfig([
            "general" => ["use_environment" => "test"],
            "test"    => ["db_host" => "localhost", "db_name" => "test", "jwt_key" => "test-secret"],
        ])->SetEnvironment("test");
    }
}
```

`Logger` writes nothing while the environment is `test`.

---

## 19. Complete Application Skeleton

```
my-app/
├── composer.json
├── AGENTS.md                 ← points agents at the framework's docs
├── configs/                  ← AppPath's parent + /configs; never under the web root
│   └── magrathea.conf
├── app/                      ← AppPath
│   ├── _inc.php              ← shared bootstrap (§2), no output
│   ├── features/
│   │   └── Article/          ← namespace App\Article
│   │       ├── Article.php            ← model
│   │       ├── ArticleControl.php     ← control
│   │       └── ArticleAdmin.php       ← CRUD feature
│   ├── api/                  ← namespace App / App\Api
│   │   ├── MyAppApi.php      ← extends MagratheaApi
│   │   ├── AuthControl.php
│   │   └── ArticleApiControl.php
│   └── admin/
│       ├── MyAppAdmin.php    ← Admin class
│       └── logo.svg
├── public/                   ← web root
│   ├── api.php               ← returns new MyAppApi() (importable)
│   ├── index.php             ← require api.php → Run()
│   ├── magrathea.php         ← AdminManager::Start(new MyAppAdmin())
│   └── setup.php             ← Setup Wizard (§1); delete it if unused
├── backups/
├── cache/
├── logs/
└── vendor/
```

**`app/_inc.php`**: shared setup, no output (full version in §2):

```php
<?php
require __DIR__ . "/../vendor/autoload.php";

Magrathea\MagratheaPHP::Instance()
    ->AppPath(realpath(__DIR__))   // app/ → configs are read from ../configs/
    ->AddRootCodeFolder("admin", "api")
    ->AddFeature("Article")
    ->Prod()
    ->Load()
    ->Connect();
```

**`public/api.php`**: importable, returns the configured API:

```php
<?php
require_once __DIR__ . "/../app/_inc.php";
return new \App\MyAppApi();
```

**`public/index.php`**: HTTP entry point:

```php
<?php
try {
    $api = require __DIR__ . "/api.php";
} catch (\Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    header("Content-Type: application/json");
    die(json_encode(["success" => false, "message" => "Internal Server Error"]));
}
$api->Run();
```

**`public/magrathea.php`**: admin entry point:

```php
<?php
use Magrathea\Admin\AdminManager;

try {
    require __DIR__ . "/../app/_inc.php";
    \Magrathea\MagratheaPHP::Instance()->StartSession();
    AdminManager::Instance()->Start(new \App\MyAppAdmin());
} catch (\Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    echo "Internal Server Error";
}
```

**`public/setup.php`**: the Setup Wizard (§1):

```php
<?php
// Magrathea setup wizard: locked by a token only someone with access to the server's files can read
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
```

**`AGENTS.md`**: for agents working on the project:

```markdown
# AGENTS.md
This project runs on MagratheaPHP 3 (`platypustechnology/magratheaphp`, namespace `Magrathea`).
Before writing code, read `vendor/platypustechnology/magratheaphp/skills.MD`.
Class reference: `vendor/platypustechnology/magratheaphp/docs/mds/index.md`.
Verify your work with `vendor/bin/magrathea check` and `vendor/bin/magrathea models:verify`.
```

---

## 20. Verify Your Work

The CLI, `vendor/bin/magrathea`, shows the project's state as data. It loads `app/_inc.php` (or `--bootstrap=<file>`, or `extra.magrathea.bootstrap` in `composer.json`). Add `--json` for one machine-readable JSON document on stdout. Exit codes: `0` ok, `1` an ERROR finding or a failed command, `2` a usage error.

After changing models, tables, config or entry points, run:

```bash
vendor/bin/magrathea check                     # config, folders, database, timezone, schema
vendor/bin/magrathea models:verify             # every model against its table
vendor/bin/magrathea models:verify Article     # one model
vendor/bin/magrathea db:describe articles --json   # columns, suggested types, indexes, the models that map it
```

- **`check`** must report no ERROR. `--no-db` skips everything that connects.
- **`models:verify`** finds every model in the code folders and reports, per model: a missing table, a `$dbPk` that isn't the table's primary key, unknown field types and types that don't match their column, `$dbValues` fields with no column (ERROR: writes fail), columns the model doesn't list (INFO), missing `created_at`/`updated_at`, and timestamp columns that break the table contract (§4). A timestamp finding carries a `fix`: the `ALTER TABLE` that fixes it. **The fix is shown, never run: ask the human before running an ALTER**, and on a large table, warn that it can lock writes.
- **`db:describe <table>`** is how to read a table before writing its model: copy the suggested type of each column into `$dbValues`.
- `db:tables` lists the tables; `db:query "<sql>"` runs read-only SQL (one `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN`). Anything else needs `--write`, and `db:import` needs `--yes`: never add them without the human's go-ahead.
- `admin:create-user <email>` creates the admin's first user, a super admin, only while there are none (§1); `admin:reset-password <email>` sets an admin's password. Both generate the password and print it once, unless `--password=…` (12+ characters).
- `version` prints the framework's, the app's and PHP's versions.

Also run the project's own test suite, and `php -l` on every PHP file you wrote.

The admin's **Database** section (Explorer, Queries, Query editor, Dumps, Checks) shows the same data to humans.

---

## 21. Checklist Before Delivering Code

### Bootstrap
- [ ] `vendor/autoload.php` is required first
- [ ] `AppPath()` is set with `__DIR__` or an absolute path
- [ ] Every code folder is registered: `AddFeature()` for each feature, `AddRootCodeFolder()` for the rest (including `admin/`)
- [ ] `->Load()->Connect()` is called before any DB operation
- [ ] `->StartSession()` is called when the admin panel is used
- [ ] Mode is `->Dev()` for local, `->Prod()` for servers
- [ ] `_inc.php` prints nothing and doesn't catch exceptions; entry points log them and answer a generic error (no `p_r($ex)`)

### Models
- [ ] One class per model, in `features/<Name>/`, extending `MagratheaModel` (no new Base classes, no `implements iMagratheaModel`)
- [ ] `$dbTable`, `$dbPk` and `$dbValues` are set
- [ ] Every `$dbValues` type is in `MagratheaModel::TYPES`; SQL `DECIMAL` columns are `"decimal"`
- [ ] Each `$dbValues` field has an untyped public property; no `#[\AllowDynamicProperties]`
- [ ] `created_at`/`updated_at` are not in `$dbValues`, not redeclared, and never assigned `now()`
- [ ] No constructor unless it does more than load the row
- [ ] New models don't declare `$strictTypes`; models with a `protected $strictTypes = false;` pin keep it unless the human says otherwise
- [ ] Relations are explicit methods, not called inside loops
- [ ] UUID ids are declared `"uuid"`, not hand-rolled
- [ ] Code that reads timestamps after `Insert()`/`Update()` calls `Reload()` first; `Touch()` for "bump `updated_at`"

### Tables
- [ ] Every model table has `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` and `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- [ ] Schema changes were shown to the human, with a dump taken first (§7)

### Controls
- [ ] `<Model>Control` in the model's namespace, with `protected static $model = Model::class;`
- [ ] Custom methods are static and read the table with `static::GetDbTable()`

### API Class
- [ ] Extends `MagratheaApi`, and its constructor calls `Initialize()`
- [ ] Routes grouped into domain methods
- [ ] `Run()` is NOT called inside the class, only in the HTTP entry point
- [ ] Production CORS uses `AllowFromFile()` or `Allow([...])`, not `AllowAll()`

### API Controllers
- [ ] Every controller extends `MagratheaApiControl`, except the class passed to `BaseAuthorization()`, which extends `MagratheaApiAuth`
- [ ] Every expected error throws `MagratheaApiException` with a 4xx code
- [ ] User input is never interpolated into SQL unescaped
- [ ] `GetPost()`/`GetPut()` are used (not `$_POST` directly), and the fields taken from them are whitelisted
- [ ] Actions return models (or arrays/`MagratheaPagination`), never `ToJson()`; `->Reload()` after a write when the client needs the timestamps
- [ ] No password hashes or secrets in any response

### Admin Class
- [ ] Extends `\Magrathea\Admin\Admin` and implements `\Magrathea\Admin\iAdmin`
- [ ] `SetFeatures()` calls `parent::SetFeatures()` first
- [ ] `LoadApi()` uses `SetApi(new YourApiClass())`, not `require`
- [ ] `BuildMenu()` ends with `GetLogoutMenuItem()`
- [ ] `AddCrudFeature()` is used for `AdminCrudObject` subclasses

### Admin CRUD Features
- [ ] Extends `AdminCrudObject`
- [ ] `Initialize()` calls `$this->SetObject(new Model())` (or `SetObject(new Model(), new ModelControl())` when the control is named otherwise)
- [ ] Optional: `public string $featureName` for the title, `HasPermission($action)` to restrict

### Config
- [ ] Secrets use `$=ENV_VAR`, never hardcoded
- [ ] The config is at `configs/magrathea.conf` in `AppPath`'s **parent** folder, outside the web root
- [ ] `[general] use_environment` names a section that exists; every environment section sets `timezone`, `logs_path`, `cache_path` and `backups_path`
- [ ] `bootstrap_unlocked = true`, if present, is only in `[dev]`

### Verification (§20)
- [ ] `vendor/bin/magrathea check` reports no ERROR
- [ ] `vendor/bin/magrathea models:verify` reports no ERROR
- [ ] The project's tests pass

### General
- [ ] `now()` is used only for date columns the app sets itself, never for `created_at`/`updated_at`
- [ ] The cache is invalidated when data changes
- [ ] Singletons are accessed via `::Instance()`, never `new ClassName()`
