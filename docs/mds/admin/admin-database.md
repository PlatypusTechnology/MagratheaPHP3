# Admin: the Database section

**Files:** `src/Admin/DatabaseExplorer.php`, `src/Admin/QueryRunner.php`, `src/Admin/QueryEditor.php`, `src/Admin/DumpManager.php`
**Namespace:** `Magrathea\Admin`

The admin's Database menu section (since 3.0). Every page renders its data through `AdminElements::Table()`,
`Findings()` and the form elements, which escape it. Table and column names are checked against
[`Schema`](../database/schema.md) before they reach SQL. Each page's logic lives in a small class that returns
arrays (tests cover it with fakes); the views only render.

---

## Explorer

Database → Explorer (`?magrathea_page=db-explorer`) is read-only. Its data comes from `Admin\DatabaseExplorer`.

- **Tables:** every table and view of the database (`_magrathea_*` included): name, type, approximate rows
  (`information_schema`'s estimate, not a `COUNT(*)`), engine, the models that map it
  ([`SchemaCheck::FindModels()`](../database/schema.md), table names compared case-insensitively) and its
  status: the most severe level among those models' `CheckModel()` findings and the table's
  `CheckTimestamps()` findings (`ok` with none, "no model" when no model maps it). Models that couldn't be
  loaded are listed above the table.
- **A table** (`&table=<name>`): its columns (name, type, null, default, extra, key, and the Magrathea type
  `Schema::MagratheaType()` suggests), its indexes, `SHOW CREATE TABLE` with a Copy button, the mapping
  models and their findings, and the first 20 rows (`DatabaseExplorer::PREVIEW_ROWS`). The name must be
  exactly one that `Schema::Tables()` lists, or the page shows "Unknown table" and runs no other query; the
  preview backtick-quotes it. A preview that fails (a view over a dropped table) shows its error, and the
  rest of the page still shows.

`DatabaseExplorer`'s constructor takes an optional `Database`, `Schema` and `SchemaCheck` (defaults:
`Database::Instance()`, a `Schema` on it, a `SchemaCheck` on that), so tests can pass fakes.

---

## Queries

Database → Queries (`?magrathea_page=db-query`) runs the SQL typed in it, through the `query-run-card` action
(a POST, so it carries the CSRF token). The logic is `Admin\QueryRunner::Run($sql, $confirmed)`.

- **Read-only SQL runs right away.** That's exactly one statement starting with `SELECT`, `SHOW`, `DESCRIBE`,
  `DESC` or `EXPLAIN`, without `INTO OUTFILE`/`INTO DUMPFILE`
  ([`SqlSplitter::IsReadOnly()`](../database/dump.md#sqlsplitter-sql-into-statements), the same rule as the
  CLI's `db:query`).
- **Anything else asks first.** The page shows each statement it would run and a "Run it" button; nothing has
  run yet. The button posts the SQL shown in that card again, with `confirm=1` (not what's in the textarea
  by then). Two `SELECT`s together count as "anything else": the rule is one statement.
- **Statements run one at a time**, split with `SqlSplitter`, and the first error stops the run. The
  statements before it already ran (there's no transaction: DDL commits anyway), and the page says how many
  didn't. A read-only statement shows its rows; any other shows its affected rows (and its rows, when it
  returns some, like `CHECK TABLE`). It runs through `Database::QueryMulti([$statement])`, which doesn't split
  it again, so a `DELIMITER` block's statement arrives whole.
- **At most 1000 rows per statement** (`QueryRunner::MAX_ROWS`), with a notice when there are more. Read-only
  statements stream through `Database::QueryStream()` (unbuffered) and stop reading at row 1001, so a
  `SELECT * FROM` a huge table doesn't fill PHP's memory. MySQL still sends the rest of the result to the
  connection, where it's thrown away, so the query costs the server its full time. `QueryStream()` lowercases
  the column names: `SELECT Name` shows `name`.

The confirmation is a guard against mistakes, not access control: whoever opens the admin can already run any
SQL, and a function a `SELECT` calls can still write.

```php
use Magrathea\Admin\QueryRunner;

$run = (new QueryRunner())->Run("DELETE FROM sessions WHERE expired = 1");
// ["status" => "confirm", "readOnly" => false, "statements" => [["sql" => "DELETE FROM …", "type" => "DELETE", …]], …]
$run = (new QueryRunner())->Run("DELETE FROM sessions WHERE expired = 1", true);
// ["status" => "ok", "statements" => [[…, "affected" => 12, "error" => null]], "notRun" => 0, …]
```

`Run()` answers `status` `QueryRunner::EMPTY` (no statement), `CONFIRM`, `OK` or `ERROR`, plus `readOnly`,
`error` (the error, or `null`), `notRun` (the statements left after the failing one) and `statements`. Each
statement has `sql`, `type` (its first keyword), `readOnly`, `rows` (`null` when it returned none),
`truncated`, `affected` (`null` for a read-only one) and `error`. SQL that can't be split (`DELIMITER` without
a delimiter) is an `ERROR` with no statements. The constructor takes an optional `Database` (default
`Database::Instance()`).

---

## Query editor

Database → Query editor (`?magrathea_page=query-editor`) builds a `Query` from a form and shows its PHP chain and
its SQL. It isn't in the menu yet (item 23). The logic is `Admin\QueryEditor::Build($spec)`; the page posts the
spec as JSON to the `query-editor-card` action (a POST, so it carries the CSRF token).

- **The form:** the type (select, insert, update or delete) and the table (from `Schema::Tables()`; choosing
  one reloads the page with `&table=<name>&type=<type>`), then, for that table's columns:
  - select: the fields to select (none checked: `*`), where rows, order (one column, `ASC` or `DESC`), limit
    and page (from 0, like `Query::Page()`; it needs a limit);
  - insert and update: values. Only the checked columns are written (typing a value checks it). On an insert
    the others get their column default. A value's NULL box writes `NULL`;
  - update and delete: where rows, **at least one**.

  A where row is a column, an operator (`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `IS NULL`, `IS NOT NULL`, `IN`)
  and a value. Rows are joined with AND. `IN` takes a comma-separated list (spaces trimmed, empty items dropped,
  so a value can't contain a comma); `IS NULL`/`IS NOT NULL` ignore the value; `LIKE` takes the value as typed,
  `%` included.
- **Build** shows the PHP and the SQL, each with a Copy button. **Run** also runs the SQL through
  [`QueryRunner`](#queries), with the Queries page's rules: a select runs right away and shows up to 1000 rows;
  a write asks for confirmation first, and its "Run it" button posts the spec that card was built from again
  (not what the form says by then), then shows the affected rows.

`Build()` makes a real `Query` through method calls (never `eval`): it keeps a list of calls, runs it on a
`Query`, and renders the same list as the PHP chain, so the two always agree.

```php
use Magrathea\Admin\QueryEditor;

QueryEditor::Build([
	"type" => "select", "table" => "articles",
	"fields" => ["id", "title"],
	"where" => [["column" => "author_id", "operator" => "=", "value" => "3"], ["column" => "price", "operator" => ">", "value" => "10"]],
	"order" => ["column" => "price", "direction" => "DESC"],
	"limit" => 20, "page" => 2,
]);
// ["php" => 'Query::Select("`id`, `title`")->Table("articles")->Where(["author_id" => "3"])->Where("`price` > \'10\'")->Order("`price` DESC")->Limit(20)->Page(2)',
//  "sql" => "SELECT `id`, `title` FROM `articles` WHERE  `author_id` = '3'  AND `price` > '10' ORDER BY `price` DESC LIMIT 40, 20"]

QueryEditor::Build(["type" => "update", "table" => "articles", "values" => ["title" => "Hi", "price" => null],
	"where" => [["column" => "id", "value" => "5"]]]);
// ["php" => 'Query::Update()->Table("articles")->SetArray(["title" => "Hi", "price" => null])->Where(["id" => "5"])',
//  "sql" => "UPDATE articles SET title = 'Hi', price = NULL WHERE  `id` = '5' "]
```

What `Build()` checks:
- the table must be one `Schema::Tables()` lists, exactly (case included), and every column (fields, values,
  where, order) one `Schema::Columns()` lists. Otherwise it throws `MagratheaDBException("Unknown table [x]")`
  or `("Unknown column [x] in [table]")`;
- `=` goes through `Where([column => value])`, which escapes it. The other operators are string conditions,
  built from the backtick-quoted column and `Escape()`d values;
- a key the type doesn't take, when it isn't empty (`null`, `""` or `[]`), is rejected: no `order`, `limit`,
  `page` or `fields` on a write (`QueryUpdate` and `QueryDelete` ignore `Order()` and `Limit()`: a "delete,
  limit 1" would delete every matching row), no `values` on a select or a delete, no `where` on an insert. An
  unknown key (`feilds`) is rejected too;
- an unknown type, operator or direction, a limit below 1, a negative page, a page without a limit, an `IN`
  without items, a value that isn't a string or a number (or `null`, in values), an update or delete without
  where rows, and an insert or update without values all throw `InvalidArgumentException`.

The editor backtick-quotes what it builds (the select's fields, conditions and order), and `Query` quotes the
table on a select and an insert. `QueryUpdate` and `QueryDelete` write the table and the `SET` column names bare,
so an update or a delete on a table or column named after a reserved word (`order`, `key`) fails with MySQL's
syntax error.

The second argument of `Build()` is an optional `Schema` (default `new Schema()`), so tests can pass a fake. Values
are escaped through `Database::Instance()`, as `Query` does.

---

## Dumps

Database → Dumps (`?magrathea_page=dumps`) replaces the Backups page (since 3.0). It isn't in the menu yet (item 23):
the menu's Backups item still points to the removed page. Exports and imports go through
[`Dump`](../database/dump.md); the logic is `Admin\DumpManager`.

- **The files:** every `.sql` file in the backups folder (`Dump::Folder()`: `backups_path`, or `<magRoot>/backups`), the
  most recent first, with its size and modification date. A file whose name `Dump::ValidateBackupFileName()` doesn't
  accept as it is (`My Backup.sql`), or a symlink pointing out of the folder, is listed as "name not accepted",
  without actions: rename it on the server.
- **Export:** a file name (default `backup_<Ymd>.sql`), the tables (checkboxes from `Schema::Tables()`; none
  checked: every table) and the driver (auto, `binary` or `php`). The result names the file, its size and the
  driver that ran, and what the php driver skipped (routines). An existing file is never overwritten.
- **Upload:** a file whose name ends with `.sql`, saved under the "Save as" name, or its own name when that's
  empty, both through `ValidateBackupFileName()`. An existing file is refused. It's only saved: nothing runs it.
  PHP's `upload_max_filesize` limits it (the error names the limit). A file bigger than `post_max_size` empties the
  whole POST, CSRF token included, so it's answered as a permission error.
- **Download** (`?magrathea_subpage=dump-download&file=<name>`, a GET link): the file, streamed with `readfile()`,
  as `Content-Disposition: attachment`, `Content-Type: application/sql`, `X-Content-Type-Options: nosniff` and
  `Cache-Control: no-store`. A bad or missing name answers 404.
- **Import:** a confirmation card first, naming the file and the database (`db_name`). It runs only when the
  database's name is typed: the button stays disabled until it matches, and the server checks it again (exactly:
  case and spaces count), so a request without it runs nothing. The card also takes the driver and "Continue on
  errors" (`Dump::Import()`'s `continue`). The result shows the driver, the statements that ran (counted by the php
  driver only), and the errors it continued past.
- **Delete:** after a browser confirmation.

Export, upload, import and delete are POST actions (`dump-export`, `dump-upload`, `dump-import`, `dump-delete`),
so they carry the CSRF token, and they answer 405 to anything else. The upload sends `FormData`, with the token in
the body. Download, import and delete take the name through `DumpManager::Resolve()`: `ValidateBackupFileName()`,
then [`AdminFiles::ResolveInFolder()`](admin.md#backups-logs-and-the-removed-query-tester), so `../`, sub-folders and
symlinks out of the folder are refused (`"invalid file name"`, `"file not found: x.sql"`).

```php
use Magrathea\Admin\DumpManager;

$dumps = new DumpManager();
$dumps->Files();          // [["name" => "backup_20261030.sql", "size" => 18342, "date" => 1793318400, "usable" => true], …]
$dumps->Export("before-migration", ["articles"], "php");   // ["file" => "before-migration.sql", "size" => …, "driver" => "php"]

$dumps->Import("before-migration");               // ["status" => "confirm", "file" => "before-migration.sql", "database" => "app", "error" => null, "result" => null]
$dumps->Import("before-migration", "App");        // still "confirm", "error" => "the database name doesn't match: nothing ran"
$dumps->Import("before-migration", "app");        // ["status" => "ok", …, "result" => ["driver" => "binary", "statements" => null]]
```

`DumpManager`'s constructor takes an optional `Dump` (default `new Dump()`) and the database name an import is
confirmed with (default the config's `db_name`), so tests can pass fakes. Every method throws `MagratheaException`
on a bad name or a failure; the views show its message. The protected `MoveUploadedFile()` moves only a file PHP
received in this request (`is_uploaded_file()`); tests override it.
