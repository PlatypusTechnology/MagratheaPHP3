# Schema — Database Introspection

**File:** `src/DB/Schema.php`, `src/DB/SchemaCheck.php`
**Namespace:** `Magrathea\DB`
**Type:** Instance classes (read-only)
**Since:** 3.0.0

`Schema` reads the structure; `SchemaCheck` ([below](#schemacheck-models-against-their-tables))
checks the models against it.

Reads the database's structure as arrays: tables, columns, indexes and `CREATE` statements. It
never writes and never prints, so the admin, the CLI and the Setup Wizard can render or return what it
gives. It reads the connection's current database (`DATABASE()`), the one `db_name` connects to.

```php
use Magrathea\DB\Schema;

$schema = new Schema();               // Database::Instance()
$tables = $schema->Tables(false);     // without the _magrathea_* tables
foreach($schema->Columns("articles") as $column) {
	echo $column["name"]." ".$column["type"]."\n";
}
```

The constructor takes an optional `Database` (default `Database::Instance()`), so tests can pass a
fake that records the SQL instead of running it.

---

## Tables

### `Tables(bool $withFramework = true): array`

Every table and view, sorted by name, from `information_schema.TABLES`:

| Key | Value |
|-----|-------|
| `name` | table name |
| `type` | `BASE TABLE` or `VIEW` |
| `engine` | `InnoDB`, …; `null` for a view |
| `rows` | approximate row count (an InnoDB estimate, not `COUNT(*)`), `int`; `null` for a view |
| `collation` | table collation |
| `comment` | table comment |

`false` leaves out the framework's `_magrathea_*` tables (the admin's users, config and logs).

### `HasTable(string $table): bool`

Whether the database has that table or view. Check names that come from a request with it before they
reach SQL.

---

## One table

These throw `MagratheaDBException("Unknown table [x]")` when the table doesn't exist. They look the name
up first and then use it as the database stores it, so a name that differs only in case works where the
database's collation allows it. Table names in the `information_schema` queries go through
`Database::Escape()`.

### `Columns(string $table): array`

The columns, in their order, from `information_schema.COLUMNS`:

| Key | Value |
|-----|-------|
| `name` | column name |
| `type` | full column type: `int(10) unsigned`, `varchar(255)`, `decimal(10,2)`, `enum('a','b')` |
| `nullable` | `bool` |
| `default` | the default as MySQL reports it (`CURRENT_TIMESTAMP`, a value, or `null`) |
| `extra` | `auto_increment`, `on update CURRENT_TIMESTAMP`, `DEFAULT_GENERATED`, … |
| `key` | `PRI`, `UNI`, `MUL`, or `""` |

### `Indexes(string $table): array`

The indexes, `PRIMARY` first and then by name. `information_schema.STATISTICS` has one row per indexed
column; `Indexes()` groups them, one row per index:

```php
[
	["name" => "PRIMARY",   "unique" => true,  "type" => "BTREE",    "columns" => ["id"]],
	["name" => "slug_lang", "unique" => true,  "type" => "BTREE",    "columns" => ["slug", "lang"]],
	["name" => "title_ft",  "unique" => false, "type" => "FULLTEXT", "columns" => ["title"]],
]
```

### `CreateStatement(string $table): string`

The `CREATE TABLE` statement from `SHOW CREATE TABLE` (a `CREATE VIEW` for a view). The name is
backtick-quoted.

---

## Column types

### `Schema::MagratheaType(string $columnType): ?string` (static)

The Magrathea field type (see `MagratheaModel::TYPES`) suggested for a SQL column type, as `Columns()`
gives it. Case-insensitive; `unsigned`, `zerofill` and display widths are ignored.

| Column type | Magrathea type |
|-------------|----------------|
| `tinyint(1)`, `bit(1)` | `boolean` |
| `tinyint`, `smallint`, `mediumint`, `int`, `bigint`, `year` | `int` |
| `decimal`, `numeric` | `decimal` |
| `float`, `double`, `real` | `float` |
| `char(36)` | `uuid` (a suggestion: the column may hold other strings) |
| `char`, `varchar`, `enum`, `set`, `time` | `string` |
| `tinytext`, `text`, `mediumtext`, `longtext`, `json` | `text` |
| `date` | `date` |
| `datetime`, `timestamp` | `datetime` |

Blobs, binary and spatial types give `null`: there's no Magrathea type for them.

---

## SchemaCheck: models against their tables

Nothing generates models in 3.0, so nothing keeps them in sync with their tables: a typo in
`$dbValues` surfaces as a SQL error at runtime, and a column missing from `$dbValues` is silently
never saved. `SchemaCheck` finds the models and reports where they and their tables disagree, as
findings. It never writes and never prints.

```php
use Magrathea\DB\SchemaCheck;

$check = new SchemaCheck();           // new Schema(), on Database::Instance()
foreach($check->CheckAll() as $finding) {
	echo $finding["level"].": ".$finding["message"]."\n";
	if(isset($finding["fix"])) echo "    ".$finding["fix"]."\n";
}
```

The constructor takes an optional `Schema`, so tests can pass one with fixed rows.

### Findings

Each finding has the same shape as `TimezoneCheck::Run()`'s, plus the keys that apply:

| Key | Value |
|-----|-------|
| `level` | `TimezoneCheck::OK`, `INFO`, `WARNING` or `ERROR` (`"ok"`, `"info"`, `"warning"`, `"error"`) |
| `message` | what's wrong, and why it matters |
| `group` | always `schema` |
| `model` | the model class |
| `table` | the table |
| `field` | the field or column |
| `fix` | SQL that fixes it (timestamp findings only): **shown, never run** |

### `FindModels(): array`

Scans each folder in `MagratheaPHP::Instance()->codeFolder` for `*.php` files, not recursively
(like the autoloader). It reads the classes each file declares with `token_get_all()`, so nothing
runs until a candidate is loaded. Only non-abstract classes with an `extends` are candidates.
Each one is loaded **through the autoloader**, which runs its file, and kept when it's a
`MagratheaModel`. When a model and one of its subclasses are both found (a generated `ArticleBase`
and its `Article`), only the subclass is kept: they map the same table. Each model is instantiated
with `new $class()` (no id, so no query) to read its `$dbTable`, `$dbPk` and `$dbValues`.

```php
[
	"models" => [
		["class" => "App\\Models\\Article", "file" => "/app/Models/Article.php",
		 "table" => "articles", "pk" => "id", "values" => ["id" => "int", "title" => "string"]],
	],
	"findings" => [ … ],
]
```

It never throws. These are findings:
- a class that fails to load (a parse error, a missing parent, a file not named after its class,
  so the autoloader can't find it): WARNING, since it can't tell whether it's a model;
- a model whose constructor throws: ERROR;
- no model found at all: WARNING, naming the folders scanned, so an empty report isn't mistaken for
  a clean one.

### `CheckModel(string $class): array`

| Finding | Level |
|---------|-------|
| the class can't be loaded, isn't a concrete `MagratheaModel`, or its constructor throws | ERROR |
| it has no `$dbTable` | ERROR |
| its table doesn't exist (nothing else is checked) | ERROR |
| `$dbPk` isn't the table's primary key (or the key is composite, or there's none) | WARNING |
| a `$dbValues` field has no column: writes fail | ERROR |
| a `$dbValues` type isn't one of `MagratheaModel::TYPES` | ERROR |
| `float` on a `DECIMAL` column: it rounds under strict types | WARNING |
| a primary key on a `char(36)` column declared `string`/`text`: `Insert()` sends no PK | WARNING |
| any other type that differs from `Schema::MagratheaType()` (skipped when that's `null`) | WARNING |
| a column that isn't in `$dbValues`: the model never reads or writes it | INFO |
| the table has no `created_at` or `updated_at`: every SELECT built from the model fails | ERROR |

`string`, `text` and `uuid` count as one type: they bind and load the same (as text, unchanged).
The difference only matters for a primary key, where `uuid` makes `Insert()` generate and send it.
Field and column names are compared case-insensitively, as MySQL does.

`created_at` and `updated_at` don't belong in `$dbValues` (see the MagratheaModel page). They're
skipped by both the field and the column comparisons, even when `$dbValues` lists them. Their
presence is the last row of the table above, and their definitions are `CheckTimestamps()`'s.

### `CheckTimestamps(string $table): array`

Checks `created_at` and `updated_at` against the table contract:
`created_at … DEFAULT CURRENT_TIMESTAMP` and
`updated_at … DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. It reports one finding per
column that breaks it, listing every problem:

| Problem | Level |
|---------|-------|
| no `DEFAULT CURRENT_TIMESTAMP`, `NOT NULL`: inserts fail (or store the literal default) | ERROR |
| no `DEFAULT CURRENT_TIMESTAMP`, nullable: it's `NULL` after an insert | WARNING |
| `created_at` with `ON UPDATE CURRENT_TIMESTAMP`: it changes on every update (MySQL 5.7 gives it to a table's first bare `TIMESTAMP`) | ERROR |
| `updated_at` without `ON UPDATE CURRENT_TIMESTAMP`: updates don't change it | ERROR |

Both columns need the default because `Insert()` never sends a `null` timestamp: the default is
what sets them, `updated_at` included (until the first update, it's the creation time). MySQL's
`CURRENT_TIMESTAMP` and MariaDB's `current_timestamp()` are both recognized, with or without
fractional seconds.

The `fix` is an `ALTER TABLE … MODIFY …` that brings the column to the contract, keeping its type
and nullability:

```sql
ALTER TABLE `posts` MODIFY `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);
```

Read it before running it:
- `MODIFY` redefines the whole column: a column `COMMENT` is dropped;
- it assumes a `DATETIME` or `TIMESTAMP` column. On any other type (a `varchar` timestamp), MySQL
  rejects the `DEFAULT`, and converting the column is a decision for whoever owns the data;
- on a large table it can rebuild the table and block writes while it runs.

A column that doesn't exist isn't reported here (`CheckModel()` does). An unknown table throws
`MagratheaDBException`.

### `CheckAll(): array`

`FindModels()`'s findings, then `CheckModel()` for every model found and `CheckTimestamps()` for
each existing table they map (once per table, even when two models map it), then an INFO for
each table or view no model maps. The framework's `_magrathea_*` tables are left out of that last
part. It throws `MagratheaDBException` when the database can't be read.

---

## Before 3.0

The admin had `AdminDatabase::GetTables()` and `GetTableColumns()`. They're gone: use `Tables()` and
`Columns()`. The rows have different keys (`name` instead of `table_name`; `key` instead of `pk`).
