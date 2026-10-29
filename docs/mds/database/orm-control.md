# MagratheaModelControl — Static ORM Query Interface

**File:** `src/MagratheaModelControl.php`
**Namespace:** `Magrathea`
**Type:** Abstract Class

The companion to `MagratheaModel`. Provides a static, repository-style interface for querying the database and returning typed model objects. You define one `Control` class per model.

---

## Defining a Control

```php
<?php
namespace App\Controls;

use Magrathea\MagratheaModelControl;
use App\Models\Product;

class ProductControl extends MagratheaModelControl {
    protected static $model = Product::class;
}
```

That's all a Control needs: its table comes from the model (`Product`'s `$dbTable`), so the two can't disagree.

### Static Properties

| Property | Type | Example | |
|----------|------|---------|---|
| `$model` | `string` | `Product::class` | The model's class name. |
| `$dbTable` | `string` | `"products"` | Optional. When set, it wins over the model's table. |
| `$modelNamespace` | `string` | `"App\\Models"` | The 2.x form: used only when `$model` isn't set. |
| `$modelName` | `string` | `"Product"` | The 2.x form: used only when `$model` isn't set. |

Controls written for 2.x (and the generated `ControlBase` classes) declare `$modelNamespace`, `$modelName` and `$dbTable`; they keep working unchanged:

```php
class ProductControl extends MagratheaModelControl {
    protected static $modelName      = "Product";
    protected static $modelNamespace = "App\\Models";
    protected static $dbTable        = "products";
}
```

---

## Static Query Methods

### `GetAll(): array<MagratheaModel>`
Returns all records in the table, ordered by `created_at` ascending (oldest first).

```php
$products = ProductControl::GetAll();
foreach ($products as $product) {
    echo $product->name . "\n";
}
```

### `GetListPage(int $limit = 20, int $page = 0): array<MagratheaModel>`
Paginated list of all records, ordered by `created_at` descending (newest first).

```php
$page1 = ProductControl::GetListPage(20, 0); // first 20
$page2 = ProductControl::GetListPage(20, 1); // next 20
```

### `GetWhere(string|array $arr, string $condition = "AND"): array<MagratheaModel>`
Fetch records matching a WHERE condition. Pass a raw SQL string or an associative array. The array form orders by `created_at` descending; a string is passed to `GetSimpleWhere()`, unordered.

```php
// Array form (recommended — safer)
$active = ProductControl::GetWhere(["active" => 1]);
$affordable = ProductControl::GetWhere(["active" => 1, "price <=" => "50.00"]);

// Raw SQL form
$recent = ProductControl::GetWhere("created_at > '2024-01-01'");

// OR condition
$either = ProductControl::GetWhere(["status" => "draft", "status" => "review"], "OR");
```

### `GetRowWhere(string|array $arr, string $condition = "AND"): object|null`
Same as `GetWhere`, but returns only the **first matching record**, or `null` when nothing matches. Unordered: add your own `ORDER BY` through `Run()` with a `Query` when it matters which row comes first.

> Before 3.0, the string form (`GetRowWhere("email = '…'")`) returned an array of every matching record, like `GetSimpleWhere()`. It now returns the first object, like the array form.

```php
$product = ProductControl::GetRowWhere(["id" => 42]);
echo $product->name;
```

### `GetSimpleWhere(string $whereSql): array<MagratheaModel>`
Fetch records with a raw WHERE SQL string (no table prefix, no `WHERE` keyword).

```php
$results = ProductControl::GetSimpleWhere("price > 100 AND active = 1");
```

### `GetSelectArray(): array`
Returns an associative array suitable for populating `<select>` dropdowns: `[id => name]`.

```php
$options = ProductControl::GetSelectArray();
// [1 => "Widget", 2 => "Gadget", ...]
```

---

## Query Builder Integration

### `Run(Query $magQuery, bool $onlyFirst = false): array<MagratheaModel>`
Execute a `Query` object and return typed model instances.

```php
use Magrathea\DB\Query;

$query = Query::Select()
    ->Obj(new Product())
    ->Where(["active" => 1])
    ->Order("price ASC")
    ->Limit(10);

$products = ProductControl::Run($query);
```

### `RunMagQuery(Query $magQuery): array<MagratheaModel>`
Alias for `Run`.

### `Count(Query $magQuery): int`
Execute a COUNT query for the given query builder.

```php
$query = Query::Select()->Obj(new Product())->Where(["active" => 1]);
$total = ProductControl::Count($query);
echo "Total active products: $total";
```

### `RunPagination(Query $magQuery, &$total, int $page = 0, int $limit = 20, bool $withTotal = true): array<MagratheaModel>`
Executes a query with pagination, also returning the total count by reference. Pass `$withTotal = false` to skip the `COUNT(*)` query — `$total` is then left as `null`.

```php
$query = Query::Select()->Obj(new Product())->Where(["active" => 1]);

$total = 0;
$products = ProductControl::RunPagination($query, $total, page: 0, limit: 20);

echo "Showing " . count($products) . " of $total";
```

### `GetPagination(Query $magQuery, int $page = 0, int $limit = 20, bool $withTotal = false): MagratheaPagination`
Builds a `MagratheaPagination` object directly from a query — the recommended way to paginate an API endpoint. Return it straight from an API controller action and `MagratheaApi::ReturnSuccess()` will build the paginated JSON envelope (`{success, data, page, count, has_more, total?}`) automatically.

By default (`$withTotal = false`) it avoids an extra `COUNT(*)` query: it fetches `$limit + 1` rows, and if that extra row comes back, sets `has_more = true` and trims the result back down to `$limit`. Pass `$withTotal = true` to also compute and include `total` (at the cost of a `COUNT(*)` query).

```php
use Magrathea\DB\Query;
use App\Controls\ProductControl;

// In an API controller:
public function List(array $params = []): \Magrathea\MagratheaPagination {
    $query = Query::Select()->Obj(new \App\Models\Product())->Where(["active" => 1])->Order("name ASC");
    return ProductControl::GetPagination($query, page: (int)($params["page"] ?? 0), limit: 20);
}
```

```json
{"success": true, "data": [ ... ], "page": 0, "count": 20, "has_more": true}
```

---

## Raw SQL Methods

### `RunQuery(string $sql): array<MagratheaModel>`
Execute a raw SQL SELECT and map results to model objects.

```php
$products = ProductControl::RunQuery(
    "SELECT * FROM products WHERE category_id IN (1,2,3) ORDER BY name"
);
```

### `RunRow(string $sql): object|null`
Execute raw SQL and return only the first result as a model object.

```php
$product = ProductControl::RunRow("SELECT * FROM products WHERE sku = 'WIDGET-001'");
```

### `QueryResult(string $sql): array`
Execute raw SQL and return raw result rows (not mapped to models).

### `QueryRow(string $sql): array`
Execute raw SQL and return the first row as an array.

### `QueryOne(string $sql): mixed`
Execute raw SQL and return the first column of the first row.

```php
$maxPrice = ProductControl::QueryOne("SELECT MAX(price) FROM products");
```

---

## Multi-Object Joins

### `GetMultipleObjects(array $array_objects, string $joinGlue, string $where = ""): array`
Build a multi-table query using multiple models and return combined results.

```php
$results = ProductControl::GetMultipleObjects(
    [new Product(), new Category()],
    "products.category_id = categories.id",
    "products.active = 1"
);
```

---

## Utility

### `GetModelName(): string`
Returns the model class name: `$model` when it's set, else `$modelNamespace\$modelName`.

### `GetDbTable(): string`
Returns the table every query helper reads (`GetAll()`, `GetListPage()`, `GetWhere()`, `GetRowWhere()`, `GetSimpleWhere()`, `GetMultipleObjects()`): `$dbTable` when it's set, else the table of a new, empty model (no query runs), built once per Control class and cached.

Use it in your own Control methods instead of `static::$dbTable`, which is empty on a Control that declares only `$model`:

```php
public static function CountActive(): int {
    $query = Query::Select()->SelectStr("count(1)")->Table(static::GetDbTable())->Where(["active" => 1]);
    return intval(static::QueryOne($query->SQL()));
}
```

---

## Full Example: Paginated Product List

```php
use Magrathea\DB\Query;
use App\Controls\ProductControl;

// Build query
$query = Query::Select()
    ->Obj(new \App\Models\Product())
    ->Where(["active" => 1])
    ->Order("name ASC");

// Get page 2, 15 items per page
$total    = 0;
$products = ProductControl::RunPagination($query, $total, page: 1, limit: 15);

// Output
echo json_encode([
    "total"    => $total,
    "page"     => 1,
    "per_page" => 15,
    "data"     => array_map(fn($p) => $p->ToJson(), $products),
]);
```

---

## Full Example: Search & Filter

```php
use Magrathea\DB\Database;
use Magrathea\DB\Query;
use App\Controls\ProductControl;

$search = Database::Instance()->Escape($_GET["q"] ?? "");
$minPrice = (float)($_GET["min"] ?? 0);

$query = Query::Select()
    ->Obj(new \App\Models\Product())
    ->Where(["active" => 1]);

if ($search) {
    $query->Where("name LIKE '%$search%'");
}

if ($minPrice > 0) {
    $query->Where("price >= $minPrice");
}

$query->Order("name ASC")->Limit(20);

$results = ProductControl::Run($query);
```

---

## Notes

- The query helpers are static: call them on the class (`ProductControl::GetAll()`). A Control can still be instantiated, and it's what `$model->GetControl()` returns; add instance methods for app-specific operations if you like (the framework's `AdminUserControl` does: `$control->Login()`, `$control->CountUsers()`). Static methods work on an instance too.
- Methods that return model arrays always instantiate the correct model type using `GetModelName()`.
- Use `Run()` with a `Query` object for complex queries; use simple helpers (`GetAll`, `GetWhere`) for quick lookups.
