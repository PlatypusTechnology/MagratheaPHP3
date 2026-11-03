# CLI — `vendor/bin/magrathea`

**File:** `src/Cli/Console.php`
**Namespace:** `Magrathea\Cli`
**Type:** Static dispatcher (`Console`), with `Command`, `Result`, `Text`, `UsageException` and the commands in `Cli\Commands`
**Since:** 3.0.0

The framework's command line, for agents and scripts: what the admin shows in a browser, as text or
JSON in a shell. Composer installs it as `vendor/bin/magrathea` (the package's `"bin"`).

```bash
vendor/bin/magrathea help
vendor/bin/magrathea help --json
```

`magrathea help` lists the commands; `magrathea help <command>` shows one's usage.

---

## Running it

```
magrathea <command> [arguments] [options]
```

- Options are `--flag` or `--name=value` (always with `=`), before or after the command. `--` ends the
  options: everything after it is an argument. Short options (`-x`) don't exist. An argument that
  starts with `--` (SQL that opens with a comment) goes after a `--`:
  `magrathea db:query -- "-- why\nSELECT 1"`; options go before that `--`.
- Every command takes `--json`, `--bootstrap=<file>` and `--help` (its usage, without running it or
  loading the bootstrap).
- An unknown option, a flag given a value, an option without its value, a missing or an extra
  argument: all usage errors, and nothing runs. A typo in a flag is never silently ignored.
- It refuses to run outside the CLI (`PHP_SAPI !== "cli"`).

### Output

Without `--json`, the result is text on stdout; errors are `magrathea: <message>` on stderr, followed
by the command's usage for a usage error.

With `--json`, stdout is always **one** JSON document, errors included, and stderr stays empty
(except a bootstrap's warning, below):

```json
{ "command": "help", "exit": 0, "data": { … } }
{ "command": "nope", "exit": 2, "error": "Unknown command [nope]" }
```

`command` is `null` when the arguments couldn't be parsed. A result that can't be encoded (`NAN`, for
instance) becomes an `error` document with exit code 1. Invalid UTF-8 is replaced, not refused.

### Exit codes

| Code | Constant | Meaning |
|------|----------|---------|
| `0` | `Console::OK` | ok |
| `1` | `Console::FAILED` | an ERROR finding, or a failed command (any exception, a bootstrap that can't be found or fails) |
| `2` | `Console::USAGE` | a usage error (`UsageException`), including running outside the CLI |

---

## Commands

| Command | Uses | Notes |
|---|---|---|
| `check [--no-db]` | `ProjectCheck::Run()` | `--no-db` skips everything that connects. Exit `1` on any ERROR |
| `db:tables` | `Schema::Tables()` | tables and views, the framework's included |
| `db:describe <table>` | `DatabaseExplorer::Table($table, false)` | columns (with the suggested Magrathea type), indexes, the models that map it and their findings, `SHOW CREATE TABLE`; no rows. The name must match exactly. Its findings don't change the exit code |
| `db:query "<sql>" [--write]` | `Admin\QueryRunner` | read-only SQL prints its rows (at most 1000 per statement); anything else needs `--write`. Exit `1` when a statement fails |
| `db:dump [file] [--tables=a,b] [--driver=binary\|php]` | `Dump::Export()` | into the backups folder; the default name is `Dump::GetDefaultFileName()` (`backup_<Ymd>.sql`), and an existing file is never overwritten |
| `db:import <file> --yes [--driver=binary\|php] [--continue]` | `Dump::Import()` | from the backups folder. With `--continue`, exit `1` when any statement failed |
| `models:verify [Class]` | `SchemaCheck` | every model (`CheckAll()`), or one (`CheckModel()` and its table's `CheckTimestamps()`). Exit `1` on any ERROR |
| `admin:create-user <email> [--password=…]` | `AdminUserControl::CountUsers()` + `Install::CreateFirstUser()` | the admin's **first** user, a super admin, only while there are no users. Without `--password`, it generates one (16 hex characters) and prints it once |
| `admin:reset-password <email> [--password=…]` | `AdminUserControl::GetByEmail()` + `SetNewPassword()` | without `--password`, it generates one (16 hex characters) and prints it once |
| `version` | `MagratheaPHP::Version()`, `AppVersion()` | and PHP's |

- **Files** for `db:dump` and `db:import` are names in the backups folder (`Dump::Folder()`), not paths:
  a `/` or `\` is a usage error. `.sql` is appended when it's missing.
- **`models:verify <Class>`** takes the full name (`App\Models\Article`, a leading `\` is fine) or, when
  only one model found in the code folders has it, the short one (`Article`, case-insensitive). A short
  name two models share is a usage error. A class that isn't found is still checked: `CheckModel()` says
  what's wrong with it.
- **`admin:create-user`** is a setup step, not user management: when the admin already has a user, it
  creates nothing and exits with `1` (add more users in the admin). It's what the admin's first-run form
  and the Setup Wizard's Admin step do, from a shell; the admin's tables must be installed first (a
  missing table is a database error, exit `1`). An invalid e-mail is a usage error.
- **`admin:reset-password`** doesn't activate an inactive user (it says so).
- **Passwords** given with `--password` land in the shell's history: leave it out to get a generated
  one. Both `admin:` commands refuse one shorter than 12 characters (`Bootstrap\Steps::MIN_PASSWORD`,
  the wizard's) as a usage error, before reading any user. The admin's own password form
  (`AdminUserControl::SetNewPassword()`) still takes 8.
- **Findings** print one per line, `LEVEL   [group] message`, each `fix` indented below, then the count
  per level. With `--json`, `check` and `models:verify` answer `{"level", "counts", "findings"}` (plus
  `"class"` for `models:verify`).

### Writes need their flag

A command that writes refuses to run without its flag, with exit code 2: `db:query` with SQL that
isn't read-only (`SqlSplitter::IsReadOnly()`: exactly one statement, `SELECT`/`SHOW`/`DESCRIBE`/`DESC`/`EXPLAIN`,
no `INTO OUTFILE`) needs `--write`, and `db:import` needs `--yes`:

```
$ magrathea db:query "DELETE FROM sessions"
magrathea: [db:query] writes: it runs only with --write
Usage: magrathea db:query <sql> [--write]
$ magrathea db:query "DELETE FROM sessions" --write
Affected rows: 12
```

There are no prompts, since agents can't answer them: the flag is the consent. The refusal comes
**before** the bootstrap loads, so nothing connects. Two `SELECT`s in one call need `--write` too, as
on the admin's Queries page. `--help` never needs the flag. `db:dump` writes only a new file, and
`admin:create-user` (which runs only on an admin with no users) and `admin:reset-password` are named
for what they do, so none of them has one.

---

## The bootstrap

Every command except `help` first `require`s the project's bootstrap, the file that sets up
`MagratheaPHP` (`AppPath()`, `Load()`, …). It's found in this order:

1. `--bootstrap=<file>`, relative to the current folder (exit code 2 when it doesn't exist);
2. `extra.magrathea.bootstrap` in the **nearest** `composer.json` above the current folder, relative to
   that file;
3. `app/_inc.php` next to that `composer.json` (`Console::DEFAULT_BOOTSTRAP`, the `skills.MD`
   convention).

```json
"extra": { "magrathea": { "bootstrap": "src/bootstrap.php" } }
```

When none is found (no `composer.json`, a declared file that doesn't exist, no `app/_inc.php`), the
command fails with exit code 1 and says how to declare it.

**The bootstrap must not print.** Anything it prints is discarded, with a warning on stderr, so
`--json` stays valid. It runs inside a function: its top-level variables don't leak out. An exception
it throws fails the command (exit code 1, "The bootstrap [file] failed: …").

---

## Error pages in the CLI

`ErrorManager::DisplayException()` and `DisplayMesage()` show the framework's HTML error page (a
missing config, `MinVersion()` failing, …) and stop. Since 3.0.0, when `PHP_SAPI === "cli"`, they
print text to **stderr** and exit with code **1** instead (it was HTML and a `die` with status 0):

```
Magrathea Fatal Error: File could not be found: /var/www/project/configs/magrathea.conf
Magrathea\Exceptions\MagratheaConfigException (code 424041) at /var/www/…/Config.php:52
Magrathea's config file is missing: a sample is at /var/www/…/src/Bootstrap/docs/magrathea.conf.sample
#0 …
```

The first line is the message; with an exception, its class, code and place follow, then the trace.
`ErrorManager::CliText()` builds this text. A cron job or a worker that hits an error page now fails
visibly.

While `Console::Run()` runs, it turns on `ErrorManager::ThrowErrors()`: the error pages throw instead
(`DisplayException()` throws its exception again, `DisplayMesage()` a `\RuntimeException`), and the
console answers them like any failure: exit code 1, and a JSON document with `--json`. It restores
the previous setting when it's done.

---

## Writing a command

The commands are the framework's: a project can't add its own (`Console::CommandClasses()` is the
list). A command extends `Magrathea\Cli\Command`:

```php
use Magrathea\Cli\Command;
use Magrathea\Cli\Console;
use Magrathea\Cli\Result;

class GreetCommand extends Command {
	public function Name(): string { return "greet"; }
	public function Summary(): string { return "Greets someone"; }
	public function Arguments(): array { return ["name" => true]; }    // name => required?
	public function Options(): array { return ["loud" => false]; }     // name => takes a value?
	public function Execute(array $arguments, array $options): Result {
		$text = "Hello, ".$arguments["name"].(isset($options["loud"]) ? "!" : ".");
		return new Result(["greeting" => $text], $text, Console::OK);
	}
}
```

| Method | Default | Notes |
|--------|---------|-------|
| `Name()` | — | what it's called by |
| `Summary()` | — | one line, for `help` |
| `Arguments()` | `[]` | positional arguments in order, `name => required?`; required ones first |
| `Options()` | `[]` | its own options, `name => takes a value?`; the global ones are added |
| `NeedsBootstrap()` | `true` | `false` runs without the project (like `help`) |
| `ConsentFlag($arguments)` | `null` | the flag this run needs because it writes (`"write"`, `"yes"`): without it, exit 2 before the bootstrap. It must be one of `Options()` |
| `Usage()` | built | `name <required> [optional] [--flag] [--option=…]` |
| `Execute($arguments, $options)` | — | returns a `Result` |

`Execute()` gets the arguments by name (a missing optional one isn't set) and only its own options
(`true` for a flag, the string for a value). It returns `new Result($data, $text, $code)`: `$data` is
what `--json` prints, `$text` what's printed otherwise, `$code` the exit code. Throwing
`UsageException` exits with 2; any other exception with 1.

`Console::Commands()` returns every command, `help` included, by name.

`Magrathea\Cli\Text` builds the commands' text: `Table($rows, $columns = null)` (aligned columns; `NULL`,
`yes`/`no`, line breaks printed as `\n`), `Findings($findings)`, and for exit codes `Counts()`,
`WorstLevel()` and `ExitCode()` (`Console::FAILED` when there's an ERROR finding).

---

## In tests

`Console::Run(array $argv, $out, $err): int` takes the streams, so it runs in-process:

```php
$out = fopen("php://memory", "w+");
$err = fopen("php://memory", "w+");
$code = Console::Run(["magrathea", "help", "--json"], $out, $err);
rewind($out);
$doc = json_decode(stream_get_contents($out), true);
```

`Sapi()`, `WorkingDirectory()` and `CommandClasses()` are protected static methods that a test
subclass overrides (see `src/Tests/MagratheaTests/cliConsoleTest.php`). The framework's commands take
what they use as optional constructor arguments (`new DbQueryCommand($runner)`, `new DbDumpCommand($dump)`,
`new CheckCommand(FakeProjectCheck::class)`, …), created only when they run, so a test passes fakes
(see `cliCommandsTest.php`). `bin/magrathea` itself only
finds Composer's autoloader (`$GLOBALS['_composer_autoload_path']`, else the checkout's `vendor/`,
else the project's), refuses to run outside the CLI, and exits with `Run()`'s code.
