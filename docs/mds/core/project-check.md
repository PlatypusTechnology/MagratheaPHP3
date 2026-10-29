# ProjectCheck — Project Health Checks

**File:** `src/ProjectCheck.php`
**Namespace:** `Magrathea`
**Type:** Static utility class
**Since:** 3.0.0

Checks a project's setup and returns what it finds as a list of findings: PHP and the config, the
folders, the database, the timezone and the schema. It never prints and never throws: whatever fails
is a finding. The admin's Checks page, `magrathea check` and the Setup Wizard show it. It's never
called on a normal request, so its cost doesn't matter at runtime.

```php
use Magrathea\ProjectCheck;

foreach(ProjectCheck::Run() as $finding) {
	echo strtoupper($finding["level"])." [".$finding["group"]."] ".$finding["message"]."\n";
	if(isset($finding["fix"])) echo "    ".$finding["fix"]."\n";
}
```

Run it after `MagratheaPHP::Load()`, so the config's path is set. It connects by itself when nothing
has set the database's credentials yet (it calls `MagratheaPHP::Connect()`).

---

## `Run(bool $withDatabase = true): array`

Runs every check, group by group. `$withDatabase = false` skips everything that connects: the
`database`, `timezone` and `schema` groups. The Setup Wizard uses that before a database exists.

Each finding has [`TimezoneCheck::Run()`](timezone.md)'s shape, plus a `group`:

| Key | Value |
|-----|-------|
| `level` | `TimezoneCheck::OK`, `INFO`, `WARNING` or `ERROR` (`"ok"`, `"info"`, `"warning"`, `"error"`) |
| `message` | what was checked, or what's wrong and why it matters |
| `group` | `config`, `folders`, `database`, `timezone` or `schema` (the class constants `CONFIG`, `FOLDERS`, …) |
| `table`, `model`, `field`, `fix` | when they apply (mostly `schema`: see [SchemaCheck](../database/schema.md#findings)) |

A check that passes is an `OK` finding, so the list reads as a checklist. An empty group means it
didn't run, not that it's clean.

### What stops what

- **The config can't be read** (no file, a file that can't be parsed, no `use_environment`, or an
  environment with no section): only the `config` group runs. Every other check reads the config.
- **No database configured** (`db_host`, `db_name` and `db_user` all empty): a project without a
  database is fine. One `INFO`, and the `database`, `timezone` and `schema` groups are skipped.
- **A partly configured database**, or no `mysqli` extension: `ERROR`, and those groups are skipped.
- **The connection fails**: one `ERROR`, and `timezone` and `schema` are skipped. They would only
  report the same failure again.

---

## The checks

### `config`

| Check | Level when it fails |
|-------|---------------------|
| PHP 8.1 or later | `ERROR` |
| `proc_open()` is available (dumps run `mysqldump`/`mysql` through it) | `INFO` |
| the config file exists and is readable, and parses | `ERROR` (stops) |
| `[general]` has a `use_environment` naming one of the config's sections | `ERROR` (stops) |
| the active environment has a section (it differs only after `Config::SetEnvironment()`) | `ERROR` (stops) |
| `db_host`, `db_name`, `db_user`: all set or all empty | `ERROR` for each one missing, when some are set |
| the `mysqli` extension, when the database is configured | `ERROR` |
| `timezone`: [`TimezoneCheck::ConfigFindings()`](timezone.md#configfindings-array) | missing: `INFO`; old `time_zone` key: `WARNING`; invalid: `ERROR` |
| `bootstrap_unlocked` | `WARNING` outside an environment named `dev`; `INFO` in `dev` |

`db_pass` and `db_port` are optional: a local root without a password and the default port are real
setups.

### `folders`

Each folder must exist and be writable:

| Folder | Path checked | Level when it fails |
|--------|--------------|---------------------|
| Logs | `Logger::GetLogPath()`: `logs_path`, or `<magRoot>/logs` when it's not set | `ERROR` |
| Cache | `cache_path` | `WARNING` (only the cache breaks) |
| Backups | `Dump::Folder()`: `backups_path`, or `<magRoot>/backups` when it's not set | `WARNING` (only dumps break) |

An empty `cache_path` is a `WARNING`: the cache has no default, and `MagratheaCache` throws when it's
used. `logs_path` and `backups_path` have defaults, so leaving them out isn't a finding. The `OK`
message says when the default was used.

On the web, a `configs/` folder under `$_SERVER["DOCUMENT_ROOT"]` is a `WARNING`: unless the web server
blocks it, the config (with the database password) can be downloaded. It's not checked from the CLI,
which can't know the web root.

### `database`

| Check | Level when it fails |
|-------|---------------------|
| the connection opens | `ERROR` (stops) |
| the admin's tables exist (`_magrathea_config`) | `WARNING`: the admin isn't installed |

### `timezone`

[`TimezoneCheck::DatabaseFindings()`](timezone.md#databasefindings-array): PHP's and MySQL's clocks,
whether their offsets agree (`ERROR` when they don't: timestamps MySQL writes and `now()` disagree
silently), and the state of the sync. The `timezone` key itself is checked in `config`, so it's
reported once.

### `schema`

[`SchemaCheck::CheckAll()`](../database/schema.md): every model against its table, and the tables no
model maps. If it throws (it can't read the database), that's one `ERROR`.
