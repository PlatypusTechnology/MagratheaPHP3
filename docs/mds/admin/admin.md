# Admin Panel System

**Files:** `src/Admin/Admin.php`, `src/Admin/AdminManager.php`
**Namespace:** `Magrathea\Admin`

MagratheaPHP ships with a built-in admin panel system. It provides a configurable web UI for managing application data, users, configuration, cache, CORS origins and API exploration, plus a Database section (explorer, queries, query editor, dumps, checks). The admin panel is feature-based: you enable only what you need.

---

## Quick Start

```php
<?php
// public/magrathea.php
use Magrathea\MagratheaPHP;
use Magrathea\Admin\AdminManager;

try {
    require __DIR__ . "/../app/_inc.php";       // the shared bootstrap (Load(), Connect())
    MagratheaPHP::Instance()->StartSession();
    AdminManager::Instance()->Start(new \App\MyAppAdmin());
    // or, with only the built-in features: AdminManager::Instance()->StartDefault("My App Admin");
} catch (\Throwable $ex) {
    error_log((string) $ex);                     // never print the exception on the page
    http_response_code(500);
    echo "Internal Server Error";
}
```

`Start()` does the rest: the first-run setup form, the login page, the auth check, the CSRF check, and
rendering the requested page, feature or action. Your code goes in the `Admin` subclass:

```php
<?php
// app/admin/MyAppAdmin.php
namespace App;

class MyAppAdmin extends \Magrathea\Admin\Admin implements \Magrathea\Admin\iAdmin {
    public function Initialize() {
        parent::Initialize();                    // registers the framework's tests (Tests page)
        $this->SetTitle("My App Admin");
        $this->SetPrimaryColor("#5a2672");
    }

    public function SetFeatures() {
        parent::SetFeatures();                   // App Config, Cache, Users, User Logs
        $this->AddCrudFeature(new \App\Article\ArticleAdmin());
    }
}
```

---

## Admin Class

**File:** `src/Admin/Admin.php`
**Implements:** `iAdmin`

The primary configuration object for the admin panel. You configure the panel and then pass it to `AdminManager::Start()`.

### Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$title` | `string` | `"Magrathea Admin"` | Panel title |
| `$primaryColor` | `string` | `"203, 128, 8"` | Primary color (RGB decimal) |
| `$adminLogo` | `string` | `src/Admin/views/logo.svg` | Absolute path to the logo file (`.svg`, `.png`, `.jpg`, `.jpeg`) |
| `$favicon` | `string` | `src/Admin/views/magrathea_logo.svg` | Absolute path to the favicon SVG (inlined as a data URI) |
| `$extraMenu` | `array` | | Extra menu items |
| `$adminFeatures` | `array` | | Registered admin features |
| `$crudFeatures` | `array` | | Registered CRUD features |

### Configuration Methods

#### `SetTitle(string $t): Admin`

```php
$admin->SetTitle("My App — Admin Panel");
```

#### `SetPrimaryColor(string $color): Admin`
Set primary color from a HEX value. Internally converts to RGB decimal.

```php
$admin->SetPrimaryColor("#3A7BD5");
```

#### `SetPrimaryColorDecimal(string $color): Admin`
Set primary color as an RGB decimal string.

```php
$admin->SetPrimaryColorDecimal("58, 123, 213");
```

#### `SetLogo(string $logo): Admin` / `SetAdminLogo(string $logo): Admin`
Set the logo file's absolute path. The admin runs from `vendor/`, so the logo is read from disk and
inlined (SVG) or printed as an `<img>` (PNG/JPEG), not linked by URL.

```php
$admin->SetAdminLogo(__DIR__ . "/logo.svg");
```

#### `AddFeaturesArray(array $arrFeatures): Admin`
Register multiple features at once, through `AddFeature()` (so not as CRUD features: use
`AddCrudFeature()` for those, in `SetFeatures()`). Call it before `AdminManager::Start()`, which renders
the page.

```php
$admin->AddFeaturesArray([
    new ReportsFeature(),
    new OpenApiAdmin("swagger.yaml"),
]);
```

#### `AddJs(string $filePath): Admin`
Add a custom JavaScript file to the admin panel.

#### `AddMenuItem(array ...$item): Admin`
Add custom menu items, after the Magrathea sections (in the default `BuildMenu()`). An item is an
`AdminMenu` array: `CreateLink()` builds a link.

```php
$menu = new \Magrathea\Admin\AdminMenu();
$admin->AddMenuItem($menu->CreateLink("Reports", "?magrathea_feature=reports"));
```

#### `BuildMenu(): AdminMenu`
Build and return the menu structure.

#### `Auth($user): bool`
Whether a logged-in user may use the admin. The default, `$user->IsAdmin()`, allows the Super Admin and
Admin roles (`role_id` 1 and 2), not Basic (3). Override it to change the rule.

---

## AdminManager Class

**File:** `src/Admin/AdminManager.php`
**Extends:** `Singleton`

The runtime manager for the admin panel. Handles initialization, authentication, JS/CSS compilation, and feature routing.

### Starting the Admin

#### `Start(Admin $admin): AdminManager`
Initialize with a custom `Admin` configuration object.

```php
$admin = new Admin();
$admin->SetTitle("My App")
      ->SetPrimaryColor("#e63946")
      ->AddFeaturesArray([...]);

AdminManager::Instance()->Start($admin);
```

#### `StartDefault(?string $title = null, ?string $color = null): AdminManager`
`Start()` with a plain `Admin`: the default built-in features (App Config, Cache, Users, User Logs).

#### First-run setup

On every request, `Start()` decides what to show (`Admin\Start::GetInstallState()`):

- **No admin users at all** → the first-run setup form, which creates a **super admin**. This is
  the only case where it appears. From a shell, `vendor/bin/magrathea admin:create-user <email>`
  creates the same first user, under the same rule (see [CLI](../core/cli.md)).
- **Database error** (server down, wrong credentials, admin tables not installed) → a 500 error
  page: "Database error: the admin can't be loaded right now." The exception message is added only
  in DEV, and the exception is logged. The setup form is never shown on a DB error.
- **Users exist** → login (or the admin, if logged in). If the `admin_install_date` config row is
  missing, the basic system configs are written on the way (`Install::SetBasicConfigs()`, which
  never overwrites existing values).

The setup form's POST re-checks that there are still no users (`Start::CanCreateFirstUser()`)
right before creating the account, so a setup page loaded earlier can't be replayed once a user
exists. (Since 2.4.0. Before that, a missing `admin_install_date` row or any DB error showed the
setup form to whoever opened the admin.)

```php
AdminManager::Instance()->StartDefault("My App", "#e63946");
```

#### Built-in pages and actions

Once logged in, `?magrathea_page=<name>` shows `src/Admin/views/pages/<name>.php` inside the admin layout, and
`?magrathea_subpage=<name>` runs `src/Admin/views/actions/<name>.php` alone (the AJAX actions). Only plain names
of files that exist in those two folders are accepted (`Admin\Start::GetViewFile()`): anything else (`../`,
sub-folders, a missing file) answers 404, "action not found" for an action and a "Page not found" alert for a
page. Before 2.4.0 the name was concatenated into an `include`, so `../` reached any PHP file on the server.
Your own pages belong in admin features (`?magrathea_feature=`), not in these folders.

#### The Database section

Explorer, Queries, the Query editor, Dumps and Checks (since 3.0), and the section's menu items, have their own page:
[Admin: the Database section](admin-database.md).

The Version page shows the app, Magrathea and PHP versions. Its "Server Time" card links to the Server page
(Debugging → Server), which shows the time, the timezone and its check (`TimezoneCheck::Run()`).

#### Backups, logs, and the removed Query Tester

- **Backups** (Database → Backups) were replaced in 3.0 by [Dumps](admin-database.md#dumps), which also imports,
  uploads, downloads and deletes. The `CreateBackupFile` API (`Admin\Api\DatabaseApi`) is gone. (Since 2.4.0
  backups ran without a shell and with the password in a defaults file; before that, the file name was put in a
  `system()` command, so any admin could run shell commands, the password was on the command line and in the
  page, and `../` read any file.)
- **Logs** (Debugging → Logs) are read through the `log-view` action, which takes a file name inside the logs
  folder (`logs_path`, or `<magRoot>/logs`), not a path, and escapes the file and the tail. The generic
  `file-view` action only shows a file chosen by the view that includes it; called directly, it answers 404.
  (Before 2.4.0, `file-view` read any path posted to it, and the tail printed log lines, which carry request
  data, as HTML.) Both checks go through `AdminFiles::ResolveInFolder($folder, $name)`: a plain name of an
  existing file inside the folder, or `null`.
- **The Query Tester** ("Magrathea Query", `?magrathea_page=query-creator`) was removed in 2.4.0: it ran posted
  code with `eval()`. Its link now gets "Page not found", and `AdminMenu::GetItem("mag-query")` is `null`
  (`AdminMenu::Add()` ignores `null`, so a custom menu that still adds it shows nothing there). Use the
  Queries page (Database → Queries) to run SQL.

### Authentication

#### `Auth(): bool`
Check if the current session has a valid admin user.

`Start()` already runs it on every request (a logged-in user who fails it gets "Access Denied"): call it
yourself only outside the admin's own pages.

#### `GetLoggedUser(): AdminUser|null`
Returns the currently logged-in admin user, or `null`.

```php
$user = AdminManager::Instance()->GetLoggedUser();
echo "Welcome, " . $user->email;
```

The session keeps only the user's id (`$_SESSION["magrathea_user"]`), and the user is loaded from
the database once per request. If the user was deleted or deactivated (`active = 0`) since logging
in, it returns `null` and logs the session out, so a role change or a deactivation applies on the
next request. Don't read `$_SESSION["magrathea_user"]` yourself: use this method. (Since 3.0.
Before, the session held a serialized copy of the user, taken at login.)

#### `PermissionDenied(): void`
Ends the request with "Permission denied!".

#### `ErrorPage(string $message): void`
Render an error page with the given message.

### UI Helpers

#### `GetTitle(): string`
Returns the panel title.

#### `GetColor(): string`
Returns the primary color as RGB decimal string.

#### `PrintLogo(?int $logoSize = 200): void`
Outputs the admin logo sized to `$logoSize` px — an `<img>` tag for
`.jpeg`/`.jpg`/`.png` logos, or the inlined SVG markup for `.svg` logos.

#### `GetFaviconTag(): string`
Returns the favicon `<link>` HTML tag.

### Feature Management

#### `GetFeature(string $featureId): AdminFeature|null`
Returns a registered feature by its ID.

```php
$cacheFeature = AdminManager::Instance()->GetFeature("AdminFeatureCache");
```

#### `GetActiveFeature(): AdminFeature|null`
Returns the feature matching the current URL segment.

### Asset Management

#### `AddJs(string $file): AdminManager`
Add a JS file to the admin bundle.

#### `GetJs(): string`
Returns all bundled JS, concatenated as is (not minified).

#### `GetJSManager(): JavascriptCompressor`
Returns the underlying JS compressor instance.

#### `AddCss(string $file): AdminManager`
Add a CSS file to the admin bundle (plain CSS: SCSS isn't compiled).

#### `GetCss(): string`
Returns all bundled CSS, concatenated as is.

#### `GetCSSManager(): CssCompressor`
Returns the underlying CSS compressor instance.

### Menu

#### `SetMenu(AdminMenu $m): AdminManager`
Set the current menu.

#### `GetMenu(): AdminMenu`
Returns the current menu object.

### Logging

#### `Log(string $action, mixed $victim = null, mixed $data = null, mixed $user_id = false): void`
Log an admin action.

```php
AdminManager::Instance()->Log("deleted_product", $product->id, $product->ToArray());
```

---

## Built-in Features

`Admin::SetFeatures()` (used by `StartDefault()`, and by any `Admin` subclass that calls
`parent::SetFeatures()`) registers these features:

| Feature ID | Description |
|-----------|-------------|
| `AdminFeatureAppConfig` | Database-stored app configuration |
| `AdminFeatureCache` | Cache management and clearing |
| `AdminFeatureUser` | Admin user management |
| `AdminFeatureUserLog` | User activity logs |

Not registered by default, opt-in from your `Admin` subclass:

| Feature ID | Description | How to enable |
|-----------|-------------|---------------|
| `ApiExplorer` | Browse registered API endpoints | `new ApiExplorer()`, `SetApi(...)`, `AddFeature(...)` |
| `AdminFeatureCors` | Edit the API's allowed origins (`configs/cors-origins.txt`, read by `MagratheaApi::AllowFromFile()`) | `$this->LoadCors()` in `SetFeatures()` |

The File Editor (`AdminFeatureFileEditor`, opt-in since 2.4.0 through `LoadFileEditor()`) was
removed in 3.0: the admin no longer edits files on the server.

---

## Adding Custom CRUD Features

The most common admin customization is adding CRUD management for your models:

```php
namespace App\Product;

use Magrathea\Admin\Features\CrudObject\AdminCrudObject;

class ProductAdmin extends AdminCrudObject {
    public string $featureName = "Products";

    public function Initialize() {
        $this->SetObject(new Product()); // uses App\Product\ProductControl
    }
}
```

```php
// in your Admin subclass's SetFeatures(), after parent::SetFeatures():
$this->AddCrudFeature(new \App\Product\ProductAdmin());
```

Without a second argument, `SetObject()` uses the control at `<model class>Control`, in the model's
namespace. If the control lives elsewhere, pass it: `$this->SetObject(new Product(), new ProductControl())`.
See the CRUD Object Feature in Admin Features for the details.

---

## Notes

- The admin panel requires `session_start()` — call `MagratheaPHP::StartSession()` in your bootstrap.
- The admin is designed to live under a protected path (e.g., `/admin/`) separate from the public API.
- Admin user management uses its own user table, separate from your application's user table.
- Admin login (the login page and `MagratheaApiAuth::AdminUserLogin()`) refuses users with
  `active = 0`, and answers every failure (unknown email, wrong password, inactive user) with the
  same message, "Invalid email or password", taking about the same time, so it doesn't tell which
  emails belong to admins. A successful login changes the session id. (Since 3.0.)
- The built-in API Explorer reads from `MagratheaApi::GetEndpointsDetail()` — make sure your API registers descriptions via the `$description` parameter of `Add()`.
- Every authenticated admin POST request is checked against a CSRF token (`Magrathea\Admin\AdminCsrf`), enforced automatically — no opt-out, no config flag. Forms built with `AdminForm` and AJAX calls made through the shared `ajax()`/`callAction()`/`callApi()`/`callFeature()` JS helpers get the token attached for free. If you build a raw `<form>` or a custom AJAX call outside those helpers, add the token yourself: a hidden `magrathea_csrf_token` field (`AdminCsrf::Instance()->GetToken()`) or an `X-Magrathea-CSRF-Token` header. GET requests are never checked.
- Admin API errors (`AdminApi`, called with the `callApi()` JS helper) come with a 4xx/5xx HTTP status since 2.4.0. `callApi()` still resolves them with the `{"success": false, ...}` body, so `.then(rs => { if(!rs.success) ... })` keeps working; it rejects only on network errors or non-JSON responses. The lower-level `ajax()`/`ajaxApi()` helpers reject on any 4xx/5xx (the body is in `err.data`).
