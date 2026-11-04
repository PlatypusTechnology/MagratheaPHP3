# Compressors — CSS & JavaScript Bundling

**Files:** `src/Compressors/MagratheaCompressor.php`, `src/Compressors/CssCompressor.php`, `src/Compressors/JavascriptCompressor.php`
**Namespace:** `Magrathea\Compressors`

Bundle files into one string, and optionally compile (SCSS) or minify (JavaScript) them. The admin panel
uses them for its assets; application code can too.

---

## Base: MagratheaCompressor

Holds the list of files. The subclasses only add `GetMinCode()`.

### Methods

#### `AddFile(string $file): MagratheaCompressor`
Register a file (an absolute path on disk) to be bundled.

```php
$compressor->AddFile(__DIR__ . "/assets/style.css");
```

#### `AddArray(array $fs): MagratheaCompressor`
Register several files at once.

#### `GetCode(): string`
The files' contents concatenated, in the order they were added, **unchanged**: no compiling, no
minifying. Same as `GetRawCode()`.

#### `GetRawCode(): string`
The files' contents concatenated.

#### `GetMinCode(): string`
Processed code: see each subclass. On the base class it throws (`"Parent Compressor does not minify code"`).

---

## CssCompressor

`GetMinCode()` compiles the concatenated files as SCSS with `scssphp/scssphp` and returns the CSS. Plain
CSS is valid SCSS, so it works for both. The output isn't minified: scssphp's default style is expanded.

```php
use Magrathea\Compressors\CssCompressor;

$css = new CssCompressor();
$css->AddFile(__DIR__ . "/assets/bootstrap.css")
    ->AddFile(__DIR__ . "/assets/app.scss");

echo "<style>" . $css->GetMinCode() . "</style>";  // app.scss compiled
```

---

## JavascriptCompressor

`GetMinCode()` minifies each file with `tedivm/jshrink` and concatenates the results.

```php
use Magrathea\Compressors\JavascriptCompressor;

$js = new JavascriptCompressor();
$js->AddFile(__DIR__ . "/assets/app.js")
   ->AddFile(__DIR__ . "/assets/charts.js");

echo "<script>" . $js->GetMinCode() . "</script>";
```

---

## Admin Panel Usage

`AdminManager` keeps one of each, and its layout prints `GetCode()`: the files concatenated **as they
are**. So files added to the admin must be plain CSS and JavaScript (an `.scss` file would be printed
uncompiled).

```php
use Magrathea\Admin\AdminManager;

$manager = AdminManager::Instance();
$manager->AddJs(__DIR__ . "/my-feature/custom.js");   // or AdminFeature::AddJs() from a feature
$manager->AddCss(__DIR__ . "/my-feature/custom.css");

echo $manager->GetJs();  // all JS, concatenated
echo $manager->GetCss(); // all CSS, concatenated
```

---

## Notes

- File paths must be absolute: the files are read with `file_get_contents()`.
- Files are processed in the order they were added: respect dependency order.
- Nothing is cached: each call reads (and compiles or minifies) the files again. Cache the output yourself
  if it's on a hot path:

```php
$cacheFile = MagratheaPHP::Instance()->GetAppRoot() . "/../cache/bundle.css";

if (!file_exists($cacheFile)) {
    $css = new CssCompressor();
    $css->AddFile(__DIR__ . "/assets/app.scss");
    file_put_contents($cacheFile, $css->GetMinCode());
}

echo file_get_contents($cacheFile);
```
