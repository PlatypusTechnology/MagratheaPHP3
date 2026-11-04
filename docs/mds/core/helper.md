# MagratheaHelper — Utility Functions

**File:** `src/MagratheaHelper.php`
**Namespace:** `Magrathea`
**Type:** Utility class: every method is `static` except `HexToRgb()`

A collection of general-purpose helper functions used throughout the framework and available to application code.

---

## Methods

### `RandomString(int $length = 10): string`
Generates a random alphanumeric string (`0-9a-zA-Z`) of the given length.

```php
use Magrathea\MagratheaHelper;

$code = MagratheaHelper::RandomString(8);
// e.g. "aB3xK9mQ"
```

**Not for secrets:** it uses `rand()`, which isn't cryptographically secure. For API keys, tokens and
passwords, use `bin2hex(random_bytes(16))` (what the CLI's `admin:create-user` does, with 8 bytes).
Fine for cache keys, file names and test data.

---

### `HexToRgb(string $hex): array`
Converts a hex color code to RGB decimals. It's an **instance** method: call it on a
`new MagratheaHelper()` (a static call is an `Error`).

```php
$rgb = (new MagratheaHelper())->HexToRgb("#FF8C00");
// Returns: ["r" => 255, "g" => 140, "b" => 0]

$rgb = (new MagratheaHelper())->HexToRgb("F80"); // the hash is optional; 3-digit codes work
```

**Returns:** `["r" => int, "g" => int, "b" => int]`

---

### `EnsureTrailingSlash(string $str): string|null`
Ensures the given path string ends with a `/`. Returns `null` if input is empty.

```php
$path = MagratheaHelper::EnsureTrailingSlash("/var/www/app");
// Returns: "/var/www/app/"

$path = MagratheaHelper::EnsureTrailingSlash("/var/www/app/");
// Returns: "/var/www/app/" (unchanged)
```

---

### `FormatSize(int $bytes, int $decimals = 2): string`
Converts a raw byte count into a human-readable file size string (`0` → `"0 bytes"`).

```php
echo MagratheaHelper::FormatSize(1024);
// "1.00 KB"

echo MagratheaHelper::FormatSize(1_048_576);
// "1.00 MB"

echo MagratheaHelper::FormatSize(1_073_741_824, 1);
// "1.0 GB"
```

**Units:** bytes, KB, MB, GB, TB, PB, EB, ZB, YB (powers of 1024)

---

## Practical Examples

### Generating an API key for a new user

```php
use Magrathea\MagratheaHelper;

$apiKey = bin2hex(random_bytes(20)); // not RandomString(): see above
$user->Set("api_key", $apiKey);
$user->Save();
```

### Using a hex color from config in CSS

```php
$hexColor = Config::Instance()->Get("primary_color"); // "#3A7BD5"
["r" => $r, "g" => $g, "b" => $b] = (new MagratheaHelper())->HexToRgb($hexColor);

echo "rgba($r, $g, $b, 0.8)"; // "rgba(58, 123, 213, 0.8)"
```

### Displaying file sizes in an admin UI

```php
$fileSize = filesize("/uploads/report.pdf");
echo MagratheaHelper::FormatSize($fileSize); // "2.34 MB"
```
