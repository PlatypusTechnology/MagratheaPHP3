# AdminManager — Admin Runtime Singleton

**File:** `src/Admin/AdminManager.php`
**Namespace:** `Magrathea\Admin`
**Extends:** `Singleton`

The runtime singleton for the admin panel. Manages initialization, authentication, session state, asset compilation, feature routing, and UI helpers. You interact with `AdminManager` throughout an admin request lifecycle.

---

## Starting the Admin

### `Start(Admin $admin): AdminManager`
Runs the admin with your `Admin` object: calls its `Initialize()` and `SetFeatures()`, then handles the
whole request (first-run setup, login, auth, CSRF, and the requested page, feature or action). It
renders the page itself, so it's the last call of the entry point.

```php
// public/magrathea.php, after the bootstrap and StartSession()
AdminManager::Instance()->Start(new \App\MyAppAdmin()); // an Admin subclass, see Admin System Overview
```

### `StartDefault(?string $title = null, ?string $color = null): AdminManager`
`Start()` with a plain `Admin`, optionally titled and colored.

```php
AdminManager::Instance()->StartDefault("My App", "#e74c3c");
```

Built-in features included by default: App Config, Cache, Users and User Logs. The API Explorer, the
CORS Origins feature and your CRUD features need an `Admin` subclass.

### `GetAdmin(): ?Admin`
Returns the current `Admin` configuration object.

---

## Authentication

### `Auth(): bool`
Runs `Admin::Auth()` on the logged-in user. `Start()` calls it on every request once someone is logged
in; it expects a logged-in user (check `GetLoggedUser()` first when you call it yourself).

### `GetLoggedUser(): AdminUser|null`
Return the currently logged-in `AdminUser` object, or `null`.

```php
$user = AdminManager::Instance()->GetLoggedUser();
if ($user) {
    echo "Logged in as: " . $user->email;
}
```

The session keeps only the user's id; the user is loaded from the database once per request.
A user deleted or deactivated (`active = 0`) since logging in gets `null`, and the session is
logged out. (Since 3.0.)

### `PermissionDenied(): void`
Ends the request with "Permission denied!". Features call it when `HasPermission()` refuses, and the
CSRF check when a POST has no valid token.

### `ErrorPage(string $message): void`
Renders the admin's message page with `$message` and ends the request.

```php
AdminManager::Instance()->ErrorPage("Database connection failed.");
```

---

## UI Helpers

### `GetTitle(): string`
Returns the panel title string.

```php
echo "<title>" . AdminManager::Instance()->GetTitle() . "</title>";
```

### `GetColor(): string`
Returns the primary color as an RGB decimal string (e.g., `"58, 123, 213"`).

```php
$color = AdminManager::Instance()->GetColor();
echo "style='color: rgb($color)'";
```

### `PrintLogo(?int $logoSize = 200): void`
Outputs the admin logo, sized to `$logoSize` px. The markup depends on the file
extension of `Admin::$adminLogo`: `.jpeg`/`.jpg`/`.png` render as an `<img>` tag,
while `.svg` inlines the SVG file's contents inside a sized wrapper `<div>`.

```php
AdminManager::Instance()->PrintLogo(150);
// PNG/JPEG: <img src="/assets/logo.png" width="150" ...>
// SVG:      <div class="logo_image" style="...width: 150px; height: 150px">...inline <svg>...</div>
```

### `GetFaviconTag(): string`
Returns the `<link rel="icon">` tag string for the favicon.

```php
echo AdminManager::Instance()->GetFaviconTag();
```

---

## Feature Management

### `GetFeature(string $featureId): AdminFeature|null`
Retrieve a registered feature by its ID (`$featureId`: the built-in ones are named after their class,
e.g. `AdminFeatureCache`).

```php
$cacheFeature = AdminManager::Instance()->GetFeature("AdminFeatureCache");
```

### `GetActiveFeature(): AdminFeature|null`
Returns the feature named by `?magrathea_feature=` (by `$featureId`), or `null`. The admin's layout
uses it to render the feature's page (see Feature Routing in Admin Features).

---

## Menu

### `SetMenu(AdminMenu $m): AdminManager`
Set the current admin menu.

### `GetMenu(): AdminMenu`
Returns the current `AdminMenu` object. The layout builds it with `Admin::BuildMenu()`; override
`BuildMenu()` in your `Admin` subclass to change the sidebar.

---

## Asset Management

### `AddJs(string $file): AdminManager`
Add a JavaScript file (absolute path on disk) to the admin bundle.

```php
AdminManager::Instance()->AddJs(__DIR__ . "/assets/charts.js");
```

### `GetJs(): string`
Returns all registered JS files concatenated, as they are (not minified: `GetCode()`).

```php
echo "<script>" . AdminManager::Instance()->GetJs() . "</script>";
```

### `GetJSManager(): JavascriptCompressor`
Returns the underlying `JavascriptCompressor` instance for advanced use.

### `AddCss(string $file): AdminManager`
Add a CSS file (absolute path on disk) to the admin bundle. Plain CSS only: the bundle isn't
compiled, so SCSS would be printed as is.

```php
AdminManager::Instance()->AddCss(__DIR__ . "/assets/custom.css");
```

### `GetCss(): string`
Returns all registered CSS files concatenated, as they are (`GetCode()`).

```php
echo "<style>" . AdminManager::Instance()->GetCss() . "</style>";
```

### `GetCSSManager(): CssCompressor`
Returns the underlying `CssCompressor` instance.

---

## Action Logging

### `Log(string $action, mixed $victim = null, mixed $data = null, mixed $user_id = false): void`
Record an admin action. All calls appear in the UserLogs feature.

```php
AdminManager::Instance()->Log(
    "product_deleted",
    $product->id,
    $product->ToArray()
);
```

---

## Full Admin Entry Point Example

```php
<?php
// public/magrathea.php
use Magrathea\MagratheaPHP;
use Magrathea\Admin\AdminManager;

try {
    require __DIR__ . "/../app/_inc.php";       // the shared bootstrap: Load(), Connect()
    MagratheaPHP::Instance()->StartSession();    // admin auth needs the session
    AdminManager::Instance()->Start(new \App\MyAppAdmin());
} catch (\Throwable $ex) {
    error_log((string) $ex);                     // never print the exception on the page
    http_response_code(500);
    echo "Internal Server Error";
}
```

Features, the title, the color, the logo and the menu all go in the `Admin` subclass (see
[Admin System Overview](admin.md) and [Admin Features](admin-features.md)), not in the entry point:
`Start()` renders the page as soon as it's called.

---

## Notes

- `Start()` (or `StartDefault()`) must run before the other admin methods: they read the `Admin` it stores.
- `StartDefault()` is the fastest way to get a functional admin panel. Use `Start()` with an `Admin` subclass for full customization.
- Asset methods (`GetJs()`, `GetCss()`) read the files on every call, and the layout calls each once per page. See [Compressors](../utilities/compressors.md).
- Routing is query-string based: `?magrathea_page=`, `?magrathea_subpage=` and `?magrathea_feature=` (see [Admin System Overview](admin.md#built-in-pages-and-actions) and Feature Routing in [Admin Features](admin-features.md#feature-routing)).
