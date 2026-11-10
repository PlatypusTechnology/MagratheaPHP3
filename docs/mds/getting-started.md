# Getting Started with MagratheaPHP

## Installation

```bash
composer require platypustechnology/magratheaphp
```

Or clone and install dependencies:

```bash
git clone https://github.com/PlatypusTechnology/MagratheaPHP3.git
cd MagratheaPHP3
composer install
```

---

## Configuration File

Create `configs/magrathea.conf` (INI format) one level **above** the folder you pass to
`AppPath()`: with `AppPath(__DIR__ . "/app")`, that's `configs/` next to `app/`.

Sections are **environments**, not topics. `[general]` says which one is active; each environment
section has the full, flat set of keys (nothing is inherited between sections, and there's no
`[section:environment]` syntax):

```ini
[general]
	use_environment = "dev"

[dev]
	db_host = "localhost"
	db_name = "my_app_db"
	db_user = "root"
	db_pass = "secret"
	db_port = "3306"
	timezone = "America/Sao_Paulo"

[production]
	db_host = "db.myserver.com"
	db_name = "my_app_prod"
	db_user = "app_user"
	db_pass = "$=DB_PASSWORD"
	timezone = "America/Sao_Paulo"
```

The `$=VAR_NAME` syntax reads from environment variables. `use_environment` must name a section
that exists. See the [Configuration reference](core/config.md) for every key.

---

## Bootstrapping

The bootstrap lives in one file, `app/_inc.php`, that every entry point (`public/index.php`,
`public/magrathea.php`) requires, and that the [CLI](core/cli.md) loads too. It prints nothing:

```php
<?php
// app/_inc.php
require __DIR__ . "/../vendor/autoload.php";

Magrathea\MagratheaPHP::Instance()
    ->AppPath(realpath(__DIR__))     // app/ is the app root; the config is read from ../configs/
    ->AddRootCodeFolder("api")       // folders the autoloader searches, relative to app/
    ->AddFeature("User")             // app/features/User/
    ->Dev()                          // debug mode only (Prod() on servers); the environment comes from the config
    ->Load()                         // load configuration
    ->Connect();                     // DB credentials (the connection opens on the first query)
```

The layout this assumes:

```
my-app/
├── configs/magrathea.conf    ← never under the web root
├── app/
│   ├── _inc.php
│   ├── api/
│   └── features/User/        ← User.php, UserControl.php
├── public/                   ← web root: index.php, magrathea.php, setup.php
└── vendor/
```

---

## Minimal API Example

```php
<?php
// public/index.php
require __DIR__ . "/../app/_inc.php";

use Magrathea\MagratheaApi;

$api = new MagratheaApi();
$api->AllowAll(); // CORS for any origin: AllowFromFile() or Allow([...]) in production

// Simple GET endpoint. Routes have no leading slash: "/hello" would never match
$api->Add("GET", "hello", null, function() {
    return ["message" => "Hello, World!"];
});

// Run the API
$api->Run();
```

---

## Minimal Model + API Example

```php
<?php
// app/features/User/User.php
namespace App\User;

use Magrathea\MagratheaModel;

class User extends MagratheaModel {
    protected $dbTable = "users";
    protected $dbPk = "id";
    protected $dbValues = [
        "id"         => "int",
        "name"       => "string",
        "email"      => "string",
    ];

    // every $dbValues field as an untyped public property
    // (created_at/updated_at come from MagratheaModel: the table needs both columns)
    public $id, $name, $email;
}
```

```php
<?php
// app/features/User/UserControl.php
namespace App\User;

use Magrathea\MagratheaModelControl;

class UserControl extends MagratheaModelControl {
    protected static $model = User::class;
}
```

```php
<?php
// app/api/UserApiControl.php
namespace App\Api;

use Magrathea\MagratheaApiControl;
use App\User\User;
use App\User\UserControl;

class UserApiControl extends MagratheaApiControl {
    // the built-in Read(), Create(), Update() and Delete() work on this model
    protected $model = User::class;

    // the built-in List() calls $this->service->GetAll(): without a service, override it
    public function List(): array {
        return UserControl::GetAll();
    }
}
```

```php
<?php
// public/index.php
require __DIR__ . "/../app/_inc.php";

use Magrathea\MagratheaApi;
use App\Api\UserApiControl;

$api = new MagratheaApi();
$api->AllowAll();

// Registers GET users, POST users, GET user/:id, PUT user/:id, DELETE user/:id
// (Crud("user") adds an "s" for the list routes; Crud(["person", "people"]) names both)
$api->Crud("user", new UserApiControl());

$api->Run();
```

---

## Running in Different Environments

These only set the Debugger mode; the config environment (`[dev]`, `[production]`) comes from
`use_environment`. See [MagratheaPHP](core/magrathea-php.md#debug-modes-comparison).

```php
// Development — PHP errors displayed, debug items printed where they're added
MagratheaPHP::Instance()->Dev();

// Debug — debug items stored and printed at the end of the request (in an API: `_debug`), plus query logging
MagratheaPHP::Instance()->Debug();

// Production — debug items and exceptions go to the log file (turn display_errors off in php.ini)
MagratheaPHP::Instance()->Prod();
```

---

## Setup Wizard

`public/setup.php` runs a browser wizard that writes the config, checks the database, imports a dump,
installs the admin and its first user, and runs the project's checks:

```php
<?php
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
```

It works with no config at all, and it's locked: every visit needs the 8-digit token it writes to
`configs/.setup-token`, which only someone with access to the server's files can read. With a config,
it also needs `bootstrap_unlocked = true` in the active environment. Ten wrong tokens lock it until
`configs/.setup-locked` is deleted. See [Setup Wizard](setup-wizard.md).

---

## Checking the project

`vendor/bin/magrathea check` reports what's wrong with the config, the folders, the database
connection, the timezone, and the models against their tables; `vendor/bin/magrathea models:verify`
checks only the models. Both exit with `1` on an ERROR. See [CLI](core/cli.md) and
[ProjectCheck](core/project-check.md).

---

## Next Steps

- [Setup Wizard →](setup-wizard.md)
- [Configuration reference →](core/config.md)
- [CLI →](core/cli.md)
- [Database & Query Builder →](database/database.md)
- [ORM Model →](database/orm-model.md)
- [API Framework →](api/magrathea-api.md)
