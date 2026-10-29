# MagratheaModel — ORM Base Model

**File:** `src/MagratheaModel.php`
**Namespace:** `Magrathea`
**Type:** Abstract Class

The base class for all database-backed domain models. Provides field mapping, automatic CRUD operations, property access via `__get`/`__set`, serialization, and relationship definitions.

---

## Interface: iMagratheaModel

```php
interface iMagratheaModel {
    public function __construct($id);
    public function Save();
    public function Insert();
    public function Update();
    public function GetID();
}
```

**Deprecated since 3.0, removed in 4.0.** Nothing in the framework implements it: `MagratheaModel`
provides these methods, and implementing the interface forces the `__construct($id)` signature on
the model's constructor (a `__construct()` with no parameter is a fatal error when the class loads).
It's kept only so 2.x generated Base classes, which declare `implements iMagratheaModel`, still
load. New models don't implement it.

---

## Defining a Model

```php
<?php
namespace App\Models;

use Magrathea\MagratheaModel;

class Product extends MagratheaModel {

    // Required: database table name
    protected $dbTable = "products";

    // Optional: primary key column (default: "id")
    protected $dbPk = "id";

    // Required: column definitions [column_name => type]
    protected $dbValues = [
        "id"          => "int",
        "name"        => "string",
        "description" => "text",
        "price"       => "decimal",   // DECIMAL column: exact, kept as a string
        "stock"       => "int",
        "active"      => "boolean",
    ];

    // Optional: property aliases [alias => real_column]
    protected $dbAlias = [
        "title" => "name",   // $product->title is the same as $product->name
    ];

    // every $dbValues field as an untyped public property
    public $id, $name, $description, $price, $stock, $active;
}
```

The table settings can also be set in `MagratheaStart()`, which the constructor runs first:

```php
class Product extends MagratheaModel {
    public $id, $name;

    public function MagratheaStart() {
        $this->dbTable = "products";
        $this->dbPk = "id";
        $this->dbValues = [ "id" => "int", "name" => "string" ];
    }
}
```

### What `MagratheaModel` already provides

A model doesn't declare these (2.x's generator wrote them into every Base class):

| Member | Default |
|--------|---------|
| `__construct($id = 0)` | runs `MagratheaStart()`, then, if `$id` isn't empty, sets the PK and loads that row (`GetById()`) |
| `MagratheaStart()` | does nothing: a hook for the model's table settings |
| `protected $autoload` | `null`: relations `GetById()` loads with a join |
| `public $created_at, $updated_at` | `null`; loaded with the row, returned by `ToJson()` and `json_encode()` |
| `GetControl()` | a new `<model full class name>Control` (`App\Article\Article` → `App\Article\ArticleControl`); throws `MagratheaModelException` naming that class if it doesn't exist |

A model can still declare its own, as generated models do. A redeclaration must keep the base's
shape, or PHP refuses the class when it loads: `$created_at`/`$updated_at` public, `$autoload`
protected or public, none of the three typed (`public ?string $created_at` is a fatal error), and
`MagratheaStart()`/`GetControl()` public and non-static. Declare `GetControl()` when the control
isn't named `<model class>Control`; the admin CRUD (`AdminCrudObject::GetField()`) calls it for
every relation field.

### Supported Field Types

| Type | PHP type | Notes |
|------|----------|-------|
| `int` | `int` | Integer values |
| `boolean` | `bool` | Stored as TINYINT(1) |
| `string` | `string` | VARCHAR, CHAR, etc. |
| `text` | `string` | TEXT columns |
| `float` | `float` | FLOAT, DOUBLE (approximate) |
| `decimal` | `string` | DECIMAL (exact, e.g. money) — never cast, see note below |
| `datetime` | `string` | MySQL datetime format (`Y-m-d H:i:s`) |
| `date` | `string` | MySQL date format (`Y-m-d`) — see note below |
| `uuid` | `string` | Auto-generated UUIDv7 on `Insert()` if left unset — see note below |

`created_at` and `updated_at` don't go in `$dbValues`: `MagratheaModel` declares both, and
`Insert()`/`Update()` handle them by name (see [Timestamps](#timestamps-created-at-updated-at)).
Generated models list them, which still works.

The list is `MagratheaModel::TYPES`, with no aliases (2.x also accepted `integer` and `double`).
Any other type (`"bool"`, `"varchar"`, `"tinyint"`, `"json"`, `"integer"`, a typo) throws
`MagratheaModelException("Unknown field type [x]: use one of …")` where it's used: when `Insert()`,
`InsertWithPk()` or `Update()` binds that field, when `Delete()` binds the PK, and when a
`$strictTypes` load reads that field. A type nothing uses doesn't throw at runtime (there's no extra
check on every call). `ValidateTypes()` checks every `$dbValues` type, naming the model and the
field; it's for the admin and CLI checks, and nothing calls it at runtime. Requires MagratheaPHP
`3.0`+; before that, an unknown type was silently bound as a string.

**`date` normalization:** on `Insert()`/`Update()`, a `date` field's value is normalized
to `Y-m-d` before binding. Accepted inputs: `"YYYY-MM-DD"`, `"YYYY-MM-DD HH:MM:SS"`, and
ISO-8601 (`"YYYY-MM-DDTHH:MM:SS.sssZ"`). Anything else throws a `MagratheaModelException`.
The date part is taken **as written** — no timezone conversion happens, so
`"1990-05-10T03:00:00.000Z"` stores `1990-05-10` regardless of server timezone. That is
the right behavior for birthdate-style fields, but clients east/west of UTC should prefer
sending a plain `"YYYY-MM-DD"` to avoid off-by-one-day surprises.

**`decimal` vs `float`:** use `decimal` for SQL `DECIMAL` columns. Its value is always the
exact string the driver returns (`"12345678901234567.89"`), with `$strictTypes` on or off, and
it's bound as a string on `Insert()`/`Update()`, so it never goes through a PHP float. A
`float` field under `$strictTypes` is cast with `(float)`, which rounds a large `DECIMAL`
on load, and a later `Update()` writes the rounded value back. Do the math with `bcmath` (or
in SQL), not with `+`/`*` on the string. `DB\Schema::MagratheaType()` suggests `decimal` for
`DECIMAL`/`NUMERIC` columns and `float` for `FLOAT`/`DOUBLE`/`REAL`. Requires MagratheaPHP `2.4.0`+.

**`uuid` generation:** on `Insert()`/`InsertWithPk()`, any field declared `"uuid"` in
`$dbValues` that is still empty gets filled in with `Uuid::V7()` (`src/Uuid.php`) — a
time-ordered RFC 9562 UUIDv7: a 48-bit millisecond timestamp prefix followed by 10 bytes
from PHP's CSPRNG (`random_bytes()`), with the version/variant bits set per spec. A field
that already has a value (set explicitly before `Insert()`) is left alone and never
overwritten. Nothing regenerates it on `Update()` — UUIDs are treated as immutable once
assigned. A `uuid` **primary key** is sent in the INSERT, and `Insert()` keeps it and returns it
(before 3.0, `Insert()` and `Save()` left it out and set the PK to `true`; only `InsertWithPk()`
worked). `Save()` on a new object with an empty uuid PK inserts it; with a uuid already assigned,
`Save()` calls `Update()`, as with any PK that's set: call `Insert()` for that.

Collision-safety is **not** enforced by the framework: there is no pre-insert uniqueness
check and no catch/retry around a duplicate-key error on `INSERT` — a collision would
surface as a rethrown DB exception. In practice this is safe because `random_bytes(10)`
supplies enough entropy that a same-millisecond collision is statistically negligible, but
the real backstop is the database: the `uuid` column must actually be declared
`PRIMARY KEY`/`UNIQUE` in the schema for uniqueness to be *guaranteed* rather than merely
"very likely."

### Strict typing (`$strictTypes`)

mysqli/PDO return every column as a string, whatever its SQL type. By default (since 3.0),
`LoadObjectFromTableRow()` casts each value to its declared `$dbValues` PHP type on load —
`int` → `(int)`, `boolean` → `(bool)`, `float` → `(float)`. `null` is always left as `null`, and
`string`/`text`/`uuid`/`date`/`datetime` fields are left untouched (the DB driver already
returns those as strings). `decimal` fields are checked but never cast: they stay the exact
string. A value that can't be cast — schema drift, e.g. a non-numeric string landing in an
`int`, `float` or `decimal` column — throws `MagratheaModelException` naming the field and
value.

New models don't declare `$strictTypes`: they're strict by default. Use `decimal`, not `float`,
for exact numbers.

`protected $strictTypes = false;` turns it off: every value stays the string the driver returned,
as in 2.x. Projects migrated from 2.x pin it on their existing models, because the default changes
their API JSON (`"42"` → `42`, `"1"` → `true`); remove a pin only once that model's API consumers
accept native types. Typed hydration exists since MagratheaPHP `2.3.0`; it's the default since `3.0`
(it was `false` before).

---

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$dbTable` | `string` | Database table name |
| `$dbPk` | `string` | Primary key column name |
| `$dbValues` | `array` | Column → type definitions |
| `$dbAlias` | `array` | Alias → column mappings |
| `$relations` | `array` | Related object definitions |
| `$dirtyValues` | `array` | Fields set through `Set()`/`Assign()` since the last save (cleared after `Insert()`/`Update()`) |
| `$autoload` | `array\|null` | Relations `GetById()` loads with a join (default `null`) |
| `$created_at`, `$updated_at` | `string\|null` | Public row timestamps (default `null`) — see [Timestamps](#timestamps-created-at-updated-at) |
| `$strictTypes` | `bool` | Typed hydration on load — see [Strict typing](#strict-typing-stricttypes) above (default `true` since 3.0; `false` keeps the driver's strings) |

---

## Instantiation

```php
// Empty model
$product = new Product();

// Load by primary key
$product = new Product(42);
// Equivalent to: SELECT * FROM products WHERE id = 42
```

`new Product(42)` throws `MagratheaModelException` when the row doesn't exist, or (code 400) when
`42` isn't a valid id for an int PK. An empty id (`0`, `""`, `null`) loads nothing and runs no query.

---

## Persistence Methods

### `Save(): int|bool`
Smart save: calls `Insert()` if the model has no PK set, or `Update()` if it does.

```php
// Create new record
$product = new Product();
$product->name  = "Widget";
$product->price = 9.99;
$newId = $product->Save(); // returns inserted ID

// Update existing record
$product = new Product(42);
$product->price = 12.99;
$product->Save(); // returns true
```

### `Insert(): int|string`
Executes an INSERT and sets the PK on the model. Returns the new auto-increment ID (for a `uuid`
PK, the uuid it sent). It doesn't read the row back: `created_at`/`updated_at` are `null` on the
object afterwards (see [Timestamps](#timestamps-created-at-updated-at)), and `Reload()` reads them.
A failed INSERT (duplicate key, `NOT NULL` column…) throws `MagratheaDBException` and leaves the PK
untouched, whatever mysqli's error reporting mode (before 2.4.0, with reporting off, it set the PK to
`null` and returned it).

```php
$product = new Product();
$product->name = "New Product";
$id = $product->Insert();
```

### `InsertWithPk(): bool`
Inserts a record that already has a PK set (e.g., UUID or custom integer).

```php
$product = new Product();
$product->id   = 9999;
$product->name = "Special Product";
$product->InsertWithPk();
```

### `Update(): bool`
Executes an UPDATE for the current model (uses PK in WHERE clause). It never writes
`created_at` or `updated_at` (see [Timestamps](#timestamps-created-at-updated-at)), and when
there's no field to write it runs no query and returns `true`.

```php
$product = new Product(42);
$product->name = "Updated Name";
$product->Update(); // UPDATE products SET name = 'Updated Name' WHERE id = 42
```

Which columns it writes depends on whether the object was **loaded**: loaded from the DB
(`new Product(42)`, `GetById()`, any Control query, `LoadObjectFromTableRow()`), or saved
once with `Insert()`/`InsertWithPk()`/`Update()`:

- **Loaded object:** the fields whose value changed since the load or the last save, by plain
  assignment (`$obj->name = …`) or `Set()`/`Assign()`. If nothing changed, no query runs.
  Columns this object didn't change are left alone, so a value changed by another process in the
  meantime isn't overwritten.
  - "Changed" ignores the type: `5` and `"5"`, `true` and `"1"`, `false` and `"0"` are the same
    value. `null` is only equal to `null` (`null` → `""` is a change).
  - A partial row (custom `SELECT` with some columns) is fine: columns it didn't have are
    written only if you assign them.
- **Never-loaded object** (`new Product(); $p->id = 42; … Update()`), as before 2.4.0: if
  anything was set through `Set()`/`Assign()`, only those fields (plain assignments are
  dropped); otherwise every field, including `null`s (except `null` ints and booleans, which are
  skipped).

**`null` in int/boolean fields:** a written int/boolean field that is `null` or `""` (an empty
form input) is written as SQL `NULL`, so a nullable FK can be cleared:

```php
$article = new Article(42);
$article->category_id = null; // or Assign(["category_id" => ""]) from a form
$article->Save();             // UPDATE … SET category_id = NULL …
```

A `NOT NULL` column gets a DB error instead. Other values go through `intval()` (`"7"` → `7`,
`true` → `1`). On a never-loaded object, `null` ints are still skipped (see above), but `""` is
written as `NULL`.

`LoadObjectFromTableRow()` is for DB rows: an object filled from input with it counts as
loaded, so its `Update()` writes nothing. Fill objects from input with `Assign()`.

**A field can hold a related model** (`$article->author_id = $author;`): `Insert()` and
`Update()` write that model's id. If it has no id yet, it's inserted first (its own `Insert()`).
On a loaded object it's compared by id, so assigning the model the field already points to isn't
a change.

### `Delete(): bool`
Deletes the record from the database.

```php
$product = new Product(42);
$product->Delete(); // DELETE FROM products WHERE id = 42
```

### Timestamps (`created_at` / `updated_at`)

The database owns both columns: one clock, MySQL's. The model never sends PHP's `now()` and never
invents a value on the object. It handles them by name, whether `$dbValues` lists them or not:

- **`Insert()` / `InsertWithPk()`:** a timestamp left unset (`null`) isn't sent, so the column's
  `DEFAULT CURRENT_TIMESTAMP` (or a `BEFORE INSERT` trigger) sets it. A timestamp assigned on the
  object (`$obj->created_at = "2001-01-01 00:00:00"`) is sent **as assigned**, so imports keep
  their dates.
- **`Update()`:** never writes either one, loaded object or not. `ON UPDATE CURRENT_TIMESTAMP`
  (or a `BEFORE UPDATE` trigger) sets `updated_at`; `$obj->updated_at = …; $obj->Save();` writes
  nothing. To set them explicitly, use `Query::Update()`.
- **No read-back:** neither method runs a `SELECT` afterwards (too costly for bulk saves), so the
  object never holds a guessed value. After `Insert()`, both are `null`, even one that was
  assigned and sent (the database may store it differently). After an `Update()` that ran a
  query, `updated_at` is `null` and `created_at` stays as it was; when nothing changed, no query
  runs and both stay. `null` means "not read".
- **`Reload()`** reads them: it runs `GetById()` with the current PK (one query) and returns the
  object, so `return $product->Reload();` works. It throws `MagratheaModelException` without a PK.
  Nothing calls it implicitly, `MagratheaApiControl::Create()`/`Update()` included (see the API
  Controller page for the one-line override).
- **`Touch()`** bumps `updated_at` on purpose ("last seen", "touch the parent"): MySQL fires
  `ON UPDATE` only when a value actually changes, so saving an unchanged object does nothing. It
  runs `UPDATE <table> SET updated_at = CURRENT_TIMESTAMP WHERE <pk> = ?` (MySQL's clock), writes
  no other field (pending changes stay pending for the next `Save()`), sets `updated_at` to `null`
  on the object and returns `true`. It throws `MagratheaModelException` without a PK.
- **`Set()`** and **`Assign()`** ignore both fields.

```php
$product = new Product();
$product->name = "Widget";
$product->Insert();      // $product->created_at === null
$product->Reload();      // SELECT …: created_at/updated_at as stored
$product->Touch();       // UPDATE … SET updated_at = CURRENT_TIMESTAMP; $product->updated_at === null
```

#### The table contract

Every table a model maps needs both columns: every SELECT built from a model reads them, and
`GetAll()`, `GetListPage()` and `GetWhere()` sort by `created_at`. Declare them like this:

```sql
`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
```

`DATETIME` has no 2038 limit and no session-zone conversion; `TIMESTAMP` columns with the same
defaults work too, and don't need converting. Without `ON UPDATE`, `updated_at` stops changing
after the insert. A `NOT NULL` column with no default makes every insert fail. Other date columns
the application sets itself are `DATETIME NULL`, never a bare `TIMESTAMP`: with MySQL 5.7's
`explicit_defaults_for_timestamp=OFF`, a table's first bare `TIMESTAMP` column silently gets
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. The admin's own tables
(`src/Admin/database.sql`) follow this contract.

Since MySQL computes the values, they're in MySQL's session zone. When the config has a
`timezone`, each connection sets that session zone to PHP's (see the Database page, "Timezone
sync"), unless `db_sync_timezone = false`.

---

## Property Access

Models support both method-style and magic property access:

```php
// Magic access (recommended)
echo $product->name;
$product->price = 19.99;

// Method access
echo $product->Get("name");
$product->Set("price", 19.99);

// Suppress missing-field exceptions
$val = $product->Get("nonexistent", true); // returns null instead of throwing
```

### Setting the Primary Key

```php
$product->SetPK(42);
echo $product->GetPK(); // 42
echo $product->GetID(); // same
```

---

## Loading Methods

### `LoadObjectFromTableRow(array|object $row): void`
Populates model properties from a database row (array or stdClass). Used internally by `MagratheaModelControl`. Unless `$strictTypes` is off, values are cast to their declared `$dbValues` type — see [Strict typing](#strict-typing-stricttypes) above. The object then counts as loaded: `Update()` writes only the fields changed after this call. Don't use it for input data (use `Assign()`).

```php
$row = Database::Instance()->QueryRow("SELECT * FROM products WHERE id = 1");
$product = new Product();
$product->LoadObjectFromTableRow($row);
```

### `Assign(array $data): MagratheaModel`
Assign multiple properties from an associative array (e.g., from `$_POST`).

```php
$product = new Product();
$product->Assign([
    "name"  => "Widget",
    "price" => 9.99,
]);
$product->Save();
```

### `GetById(mixed $id): void|object`
Load the model data from the database by primary key. Throws `MagratheaModelException` if not found.

```php
$product = new Product();
$product->GetById(42);
```

On an `int` primary key, the id must be an int or a string of digits (`42`, `"42"`, `"-1"`).
Anything else (`"42abc"`, `" 42"`, `"4.2"`, an array) throws `MagratheaModelException` with code
`400` before any query runs, so the API answers HTTP 400 for a malformed `:id`. The same applies to
`new Product($id)`, which calls `GetById()`. String primary keys (`string`, `uuid`) are escaped and
not checked. (Since 3.0. Before, an `int` PK id went into the SQL unescaped: a SQL injection
through any `/:id` route.)

### `ValidateId(mixed $id): mixed`
The check `GetById()` runs on the id. For an `int` primary key, it returns the id as an `int`, or
throws `MagratheaModelException` (code `400`). For any other primary key type, it returns the id
unchanged. Use it to check ids built from input before writing with them.

```php
$id = (new Product())->ValidateId($_POST["product_id"]);
```

---

## Introspection Methods

### `GetDbTable(): string`
Returns the table name.

### `GetPkName(): string`
Returns the primary key column name.

### `GetDbValues(): array`
Returns the column definitions array.

### `GetFields(): array`
Returns the list of column names.

### `GetFieldsForSelect(): string`
Returns a comma-separated SQL field list with table prefix.

### `GetProperties(): array`
Returns the model's current field values as an associative array.

### `IsEmpty(): bool`
Returns `true` if the PK is not set (model not loaded from DB).

### `ModelName(): string`
Returns the class short name.

### `Ref(): string`
Returns a human-readable reference string (e.g., `"Product#42"`).

---

## Serialization

### `ToArray(): array`
Returns all field values as an associative array.

```php
$arr = $product->ToArray();
// ["id" => 42, "name" => "Widget", "price" => 9.99, ...]
```

### `ToJson(): array`
Same as `ToArray()` but intended for JSON API responses. Relations are recursively serialized.

```php
echo json_encode($product->ToJson());
```

### `ToString(): string` / `__toString(): string`

```php
echo $product; // uses __toString
```

---

## Static Methods

### `GetDataTypeFromField(string $field): string`
Takes a field's **type** (a `$dbValues` value like `"int"`, not the field name) and returns the category
`Insert()`/`Update()`/`Delete()` pass to `PrepareAndExecute()`: `"integer"` (`int`, `boolean`),
`"decimal"` (`float`), `"text"` (`string`, `text`, `uuid`, `decimal`) or `"date"` (`date`, `datetime`);
An unknown type throws `MagratheaModelException("Unknown field type [x]: use one of …")` (it returned
`null` before 3.0). `"integer"` is bound as an integer, the others as strings. Mind the names:
the `decimal` **field type** maps to `"text"`; the `"decimal"` **category** is the float one.

---

## Full Lifecycle Example

```php
use App\Models\Product;

// 1. Create
$product = new Product();
$product->name  = "Gadget";
$product->price = 29.99;
$product->stock = 100;
$product->active = true;
$id = $product->Save();

echo "Created product #$id";

// 2. Read
$found = new Product($id);
echo $found->name;  // "Gadget"
echo $found->price; // 29.99

// 3. Update
$found->price = 24.99;
$found->Save();

// 4. Serialize
echo json_encode($found->ToJson());

// 5. Delete
$found->Delete();
```

---

## Notes

- The constructor accepts an optional `$id`. If provided, it immediately queries the database.
- `Save()` is the idiomatic method — it detects insert vs. update automatically based on whether the PK is set.
- Fields not in `$dbValues` are silently ignored during insert/update (they won't corrupt the DB).
- `Update()` on a loaded object writes only what changed since the load (see [`Update()`](#update-bool)).
