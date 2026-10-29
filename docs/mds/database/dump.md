# Dump — Export and Import

**File:** `src/DB/Dump.php`, `src/DB/SqlSplitter.php`
**Namespace:** `Magrathea\DB`
**Type:** `Dump`: instance class; `SqlSplitter`: static methods
**Since:** 3.0.0

`Dump` exports the database to a `.sql` file in the backups folder and imports one back. `SqlSplitter`
([below](#sqlsplitter-sql-into-statements)) splits SQL into statements; `Dump`, `Database::Query()`,
`QueryMulti()` and `ImportFile()` use it.

```php
use Magrathea\DB\Dump;

$dump = new Dump();                                     // Database::Instance()
$rs = $dump->Export("before-migration");               // backups_path/before-migration.sql
// ["file" => "before-migration.sql", "size" => 48213, "driver" => "binary"]

$dump->Import("before-migration", ["continue" => true]);
```

It never prints: the admin's Backups page shows what it returns. The constructor takes an optional `Database` (default `Database::Instance()`), so tests
can pass a fake.

---

## Files and names

Every file lives in the backups folder, and every name goes through `ValidateBackupFileName()`.
Paths are never accepted.

### `Dump::Folder(): string` (static)

`backups_path`, or `<magRoot>/backups` when it's not set. Throws `MagratheaConfigException` when neither
is known. It reads the config on every call (`AdminDatabase::GetBackupFolder()` cached it).

### `Dump::ValidateBackupFileName(mixed $name): string` (static)

Appends `.sql` when it's missing, then accepts only `^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$` without `..`:
no `/`, `\`, leading `-` or `.`. Anything else (or a non-string) throws
`MagratheaException("invalid file name")`.

### `Dump::GetDefaultFileName(): string` (static)

`backup_<Ymd>.sql`.

---

## Drivers

| Driver | Export | Import |
|--------|--------|--------|
| `binary` | `mysqldump` | `mysql`, with the file as its stdin |
| `php` | through the connection | `SqlSplitter`, one statement at a time |

Without a `driver` option, `binary` is used when `proc_open()` exists and the program (`mysqldump` to
export, `mysql` to import) answers `--version`; otherwise `php`. The check runs once per `Dump` instance.
`"driver" => "binary"` when it isn't available throws; any value other than `binary` or `php` throws.

### The binary driver

The programs run through `proc_open()` with an argument array: there's no shell, so nothing in a name
or a value can run a command. The credentials come from the config (`db_user`, `db_pass`, `db_host`,
`db_port`; the database is `db_name`) and go in a temporary `[client]` defaults file, `0600`, deleted
afterwards: the password is never on the command line, where other server users could read it in `ps`.
A user or password with both quote characters or a line break can't be written to that file and throws
before anything runs.

```
mysqldump --defaults-extra-file=… --single-transaction --routines --triggers
          --default-character-set=utf8mb4 --result-file=<backups>/<file> <db_name> [tables]
mysql --defaults-extra-file=… [--force] <db_name>      < <backups>/<file>
```

Because the binary driver reads the config, a `Dump` built on another `Database` still dumps `db_name`
with the config's credentials. Pass `"driver" => "php"` to dump that connection.

### The php driver

**Export.** The file starts with:

```sql
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
```

Then, per table (in name order): `DROP TABLE IF EXISTS`, `SHOW CREATE TABLE`, and the rows as multi-row
`INSERT`s with the column names. Rows are read with `Database::QueryStream()` (unbuffered), so a big
table never sits in memory, and an `INSERT` is closed when it passes 1 MB (`Dump::INSERT_BYTES`), under
MySQL's default `max_allowed_packet`. Values are `NULL`, escaped strings, or `0x…` for binary, blob,
`bit` and spatial columns. Generated columns (`VIRTUAL`/`STORED GENERATED`) are left out: MySQL refuses
values for them. Then the views (`DROP VIEW IF EXISTS` + their `CREATE VIEW`), then the triggers between
`DELIMITER ;;` lines (their bodies have `;`), and `SET FOREIGN_KEY_CHECKS = 1` at the end.

The export's own session reads in `+00:00` too, so `TIMESTAMP` values round-trip exactly whatever the
zone. The connection is shared for the whole request, so the session's `time_zone` (and
`foreign_key_checks`) are read first and restored afterwards, even when the export fails.

Routines (procedures and functions) aren't exported: `Export()` lists them in `skipped`
(`"PROCEDURE cleanup"`), and the file ends with a comment naming them. Use the binary driver for a
database that has routines. Events aren't exported by either driver.

Limits: views are created in name order, so a view built on another view whose name sorts after it
fails on import (`mysqldump` works around this; the php driver doesn't). `DEFINER` clauses of views and
triggers are kept as `SHOW CREATE` gives them: importing on a server without that user needs a user
allowed to set any definer.

**Import.** `SqlSplitter::FromFile()` streams the file, and each statement runs alone through
`QueryMulti([$statement])`, which doesn't split again: nothing accumulates. As with the export,
`time_zone` and `foreign_key_checks` are restored afterwards (a dump sets both), even when it stops at
an error. If restoring fails, the connection is dropped, so the next query opens a clean one.

---

## Export

### `Export(mixed $file, array $options = []): array`

| Option | Value |
|--------|-------|
| `tables` | a list of table (or view) names: only those. Checked against `Schema::Tables()` first (a name that only differs in case is replaced by the database's); an unknown table throws `MagratheaDBException` before anything runs |
| `driver` | `binary` or `php` (default: see [Drivers](#drivers)) |

Returns `["file" => <name>, "size" => <bytes>, "driver" => "binary"|"php"]`, plus `skipped` (a list)
when the php driver left routines out. With `tables`, routines aren't looked at.

An existing file is never overwritten (`"file already exists"`), and a failed export removes its partial
file. A failed `mysqldump` throws with its exit code and stderr.

## Import

### `Import(mixed $file, array $options = []): array`

| Option | Value |
|--------|-------|
| `driver` | `binary` or `php` (default: see [Drivers](#drivers)) |
| `continue` | `true`: don't stop at a failing statement (`mysql --force` with the binary driver) |

Returns `["driver", "statements"]`: how many statements ran with the php driver, `null` with the binary
one (it doesn't say). With `continue`, there's also `errors`: with php,
`["statement" => <number>, "error" => <MySQL's message>]` per failing statement; with binary, mysql's
error lines.

Without `continue`, the first error throws. With php it's a `MagratheaDBException`:
`"import stopped at statement 7: <MySQL's message>"`, with MySQL's errno as its code, the statement as
its `query` and MySQL's exception as its previous; the statements before it stay run (there's no
transaction: DDL commits implicitly anyway). With binary, `"mysql failed (exit code 1): <stderr>"`.
A lost connection always throws.

A missing file throws `"file not found: <name>"`.

---

## SqlSplitter: SQL into statements

```php
use Magrathea\DB\SqlSplitter;

SqlSplitter::Split("INSERT INTO t VALUES ('a;b'); SELECT 1 -- done;");
// ["INSERT INTO t VALUES ('a;b')", "SELECT 1"]

foreach(SqlSplitter::FromFile("/path/big.sql") as $statement) { … }
```

### `SqlSplitter::Split(string $sql): array`
The statements of a string.

### `SqlSplitter::FromFile(string $file, int $chunkSize = 65536): \Generator`
Yields a file's statements, reading `$chunkSize` bytes at a time: the file is never loaded whole. Throws
`MagratheaDBException` when the file can't be opened (when the generator starts).

### `SqlSplitter::Statements(iterable $chunks): \Generator`
Yields the statements of SQL given in pieces. A statement, a quote, a comment or the delimiter can be
cut between two pieces.

It reads SQL the way the `mysql` client does:
- `'…'` and `"…"` with backslash escapes and doubled quotes; `` `…` `` with a doubled backtick (no
  backslash escapes in identifiers: `` `a\` `` is a valid name);
- `-- ` (two dashes and a space or a control character: `1--1` isn't a comment) and `#` line comments,
  and `/* … */` blocks, are removed (a block becomes one space);
- `/*!…*/` conditional comments (`mysqldump` writes them) and `/*+…*/` optimizer hints are SQL and kept.
  The delimiter isn't looked for inside them;
- `DELIMITER $$` at the start of a statement changes the delimiter (case-insensitive; the first word
  after it); `DELIMITER` without one throws;
- CRLF line ends.

Statements come out trimmed, without their delimiter; empty ones are skipped. A statement left open at
the end (no delimiter after it) is yielded too.

Not handled: the `NO_BACKSLASH_ESCAPES` SQL mode (a string ending in `\` is read as escaping its quote),
and the `mysql` client's other commands (`\d`, `source`, `use`…).
