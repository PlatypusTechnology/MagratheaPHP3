# Global Functions & Autoloader

**Files:** `src/_Functions.php`, `src/_FunctionsDebug.php`
**Namespace:** `Magrathea`

These files define procedural helper functions and register the framework's custom autoloader (not PSR-4: it goes by class name, not by namespace path). Composer loads them on `require "vendor/autoload.php"`.

The functions live in the `Magrathea` namespace (2.x had a versioned one): call them fully qualified, or import them with `use function`:

```php
echo \Magrathea\now();

use function Magrathea\now;
echo now();
```

---

## Autoloader

MagratheaPHP uses a custom `spl_autoload_register` callback. It's registered as soon as `vendor/autoload.php` is required (Composer loads `src/_Functions.php` as a `files` autoload), and it scans all registered code folders to resolve class names.

### How it works

1. Requiring `vendor/autoload.php` registers the autoloader (`src/_Functions.php`).
2. When PHP encounters an unknown class `App\Models\User`, the autoloader takes the class's basename (`User`) and looks for `User.php` in **every** registered folder. The namespace doesn't choose the folder.
3. One match: that file is loaded.
4. Several matches (two `User.php` in different folders): it loads the one whose `namespace` declaration matches the requested class's namespace (`App\Models`, case-insensitive; a class with no namespace matches a file with no `namespace`). If none matches, the first folder's file is loaded, as before 2.4.0. In DEV, a debug item lists the files and the one loaded. The same file reached through two folder entries (e.g. `AddFeature("User")` plus `AddRootCodeFolder("features/User")`) counts once.
5. No match: the autoloader returns without loading anything (since 2.4.0; it threw `MagratheaException` for app classes before), so `class_exists()` returns `false`, autoloaders registered after it still run, and `new X()` fails with PHP's own `Error: Class "X" not found`. In DEV, a class within the app namespace (`MagratheaPHP::AppNamespace()`) adds a "Could not find class [...]. Are the code folders correct?" debug item listing the folders.
6. Folders are registered with `AddCodeFolder()` (paths used as given: a relative one resolves against the current working directory, not the app root), `AddRootCodeFolder()` (relative to the app root) or `AddFeature()` (`<appRoot>/features/<Name>` and its `Base`).

```php
MagratheaPHP::Instance()
    ->AppPath(realpath(__DIR__))
    ->AddRootCodeFolder("admin", "api", "shared")  // <appRoot>/admin, ...
    ->AddFeature("Article");                       // <appRoot>/features/Article
```

Keep class basenames unique across code folders anyway: every lookup of a duplicated name reads the candidate files, and the DEV warning shows up on every request that loads it.

**Composer and the app's own classes.** An app's `composer.json` needs no `autoload` entry for classes in its registered code folders: this autoloader loads them, and never goes through Composer's mappings. Composer's autoload is for the vendor packages (the framework included), so `vendor/autoload.php` stays required: it's also what registers this autoloader. If an app maps its own namespace anyway (say `"App\\": "./"`), the mapping must match the classes' real namespaces and folders; otherwise Composer prints a "does not comply with psr-4 autoloading standard … Skipping." line per class whenever it builds an optimized autoloader (`composer install -o`, `composer dump-autoload -o`, or `"optimize-autoloader": true` in `config`).

---

## Global Functions

### `now(): string`
Returns the current date/time formatted as `"Y-m-d H:i:s"`.

```php
echo \Magrathea\now(); // "2024-03-15 14:30:00"
```

Useful for datetime fields your code sets itself, without importing a class. Not for a model's
`created_at` / `updated_at`: the database sets those (see the Model page, "Timestamps").

---

### `arrToStr(array $array): string`
Converts an array to a one-line string of `[key] = "value"` pairs, separated by `, `. Values are cast to string, so nested arrays print as `Array`.

```php
$arr = ["a" => 1, "b" => 2];
echo \Magrathea\arrToStr($arr);
// [a] = "1", [b] = "2"
```

---

### `p_r(mixed $debugme): void`
Pretty-prints a value wrapped in `<pre>` tags. Equivalent to `echo "<pre>" . print_r($debugme, true) . "</pre>"`.

```php
$user = UserControl::GetRowWhere(["id" => 1]);
\Magrathea\p_r($user);
```

A debugging aid: never use it to report an exception on a page (`p_r($ex)` prints paths, SQL and
arguments to the visitor). Log the exception and answer a generic error instead.

---

### `isMagratheaModel(mixed $object): bool`
Returns `true` if the given value is an instance of `MagratheaModel`.

```php
if (\Magrathea\isMagratheaModel($obj)) {
    echo json_encode($obj);
}
```

---

### `getClassNameOfClass(string $fullClassName): string`
Strips the namespace from a fully-qualified class name and returns just the class name.

```php
echo \Magrathea\getClassNameOfClass("App\\Models\\User"); // "User"
echo \Magrathea\getClassNameOfClass("Magrathea\\DB\\Database"); // "Database"
```

---

### `fileDeclaresNamespace(string $file, string $namespace): bool`
Returns `true` if the PHP file has a `namespace` declaration equal to `$namespace` (case-insensitive; any of them, if the file has several blocks). `""` means the global namespace: it matches a file with no `namespace` declaration. Unreadable files return `false`. Used by the autoloader to choose between files with the same name.

```php
\Magrathea\fileDeclaresNamespace(__DIR__."/models/User.php", "App\\Models"); // true
```

---

## Debug Functions (`_FunctionsDebug.php`)

Shortcuts to the `Debugger` singleton, also in the `Magrathea` namespace:

### `Debug(mixed $bug): void`
`Debugger::Instance()->Add($bug)`.

### `DebugError($error): void`
`Debugger::Instance()->AddError($error)`.

### `Info(mixed $bug): void`
`Debugger::Instance()->Info($bug)`.

```php
\Magrathea\Debug(["step" => "validation", "passed" => true]);
```

---

## Notes

- The functions are defined unconditionally when the files are loaded, with no `function_exists()` guards. They're namespaced, so they don't collide with global functions of the same name; a project function `Magrathea\now()` would.
- `p_r()` outputs HTML, so it should only be used in web/CLI debug contexts, not in JSON API responses.
