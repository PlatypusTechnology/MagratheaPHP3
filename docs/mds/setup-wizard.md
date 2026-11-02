# Setup Wizard

**File:** `src/Bootstrap/Start.php`
**Namespace:** `Magrathea\Bootstrap`
**Since:** 3.0.0 (rebuilt; 2.x had a different wizard)

A browser wizard that takes a project from "no config" to a working install: requirements, the
config file, the database, an optional dump import, the admin and its first user, and the project's
checks. It's a remote control for the server, so it's locked by a **token only someone with access
to the server's files can read**.

Classes:
- `Start`: the entry, the modes, the token's session, the request handling (`Handle()`, `Load()`);
- `SetupToken` (`src/Bootstrap/SetupToken.php`): the token file;
- `Steps` (`src/Bootstrap/Steps.php`): the steps, as arrays (what each shows, what its forms do).

The views only render. They load nothing external and have no JavaScript: every form posts to the
server, which validates it.

---

## Entry file

`public/setup.php`, part of the project skeleton:

```php
<?php
// Magrathea setup wizard: locked by a token only someone with access to the server's files can read
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
```

`AppPath($path)` calls `MagratheaPHP::Instance()->AppPath($path)`, so the project's root (`magRoot`)
is the app folder's parent, and points `Config` at `<magRoot>/configs`. The wizard reads
`configs/magrathea.conf` itself and never calls `MagratheaPHP::Load()`, which stops at a missing
config: it works with no config at all. Don't call `MagratheaPHP::Load()` in this file.

Delete the file on servers that don't need the wizard.

---

## Modes

| Mode | When | Needs |
|------|------|-------|
| `Start::SETUP` | there's no `configs/magrathea.conf` | the token |
| `Start::MAINTENANCE` | the config exists, and its **active** environment has `bootstrap_unlocked = true` | the token |
| `Start::LOCKED` | the config exists without the flag | nothing opens it: **403**, a page with the environment's name |

`Mode()` tells which. The flag reads as in 2.4.0: `true`, `1`, `on` or `yes` (`FILTER_VALIDATE_BOOLEAN`)
in the active environment's section. In `[general]` or another section it does nothing, and an
unreadable config means locked.

The Configuration step writes `bootstrap_unlocked = true` into the section it saves, so the steps
after it keep opening (the config exists from then on). **Finish** removes it again.

---

## The token

`configs/.setup-token`, written by `SetupToken::Issue()`:

- it's created when it's missing, on a visit that's allowed to ask for one (setup or maintenance), with
  mode `0600`. If `configs/` is missing, the wizard creates it (`0750`); if it can't, a page (503) says
  what to create;
- line 1 is the token, `xxxx-xxxx`: 8 digits from `random_int()`. Read it on the server:
  `cat configs/.setup-token`;
- line 2 counts the failed attempts, updated under `flock()`.

The form accepts `xxxx-xxxx` or `xxxxxxxx` (spaces and dashes are ignored: `SetupToken::Normalize()`),
compared with `hash_equals()`.

**Lockout.** 10⁸ tokens is too few without one: at **10 failures** (`SetupToken::MAX_FAILURES`, counted
over the token's life, not reset by a correct one) the file is renamed to `configs/.setup-locked`, and no
token is accepted, the right one included, until the owner deletes that file. The next visit then
issues a new token.

**Session.** A correct token runs `session_regenerate_id(true)` and keeps `hash("sha256", $token)` in
`$_SESSION["magrathea_setup"]`. `IsVerified()` compares it with the file on every request, so deleting
or replacing the token file revokes every session. Finish deletes it.

Before the token, the page shows no absolute path, only `configs/.setup-token`.

---

## Requests

`Start::Load()` starts the session, calls `Handle($method, $_GET, $_POST, $_FILES)` and renders what
it returns. `Handle()` has no output, so it's what tests call:

1. locked: 403, the locked page;
2. locked out: 403, a page naming `.setup-locked`;
3. the token is issued if it's missing (or 503 when the folder can't be written);
4. every POST needs the admin's CSRF token (`AdminCsrf`, field `csrf`): without it, 403 and nothing runs;
5. the token form posts `action=verify`; a correct token redirects (303) to the first step;
6. without a verified session, any step (`?step=`) or action gets 403 and the token form;
7. verified: `?step=` must be one of `Start::STEPS` and a POST's `action` one of `Start::ACTIONS`
   (the whitelist), or 404.

Every `Steps` method checks `IsVerified()` too, and throws `MagratheaException` with code 403 without
it.

---

## Steps

`Start::STEPS`, in order. Every step can be opened from the side menu; the ones that need a config say
so until there's one.

| Step | Shows | Actions |
|------|-------|---------|
| 1. Requirements | `ProjectCheck::Requirements()`: PHP, `proc_open()`, `mysqli`, the configs folder writable and outside the web root | |
| 2. Configuration | the config's form | `save-config` |
| 3. Database | connects with the config's values, `TimezoneCheck::Run()`, the tables (`Schema::Tables()`) | |
| 4. Import (optional) | the `.sql` files of the backups folder | `upload-dump`, `import-dump` |
| 5. Admin | the admin's tables, the first super admin | `install-admin`, `create-admin` |
| 6. Checks | `ProjectCheck::Run()`, grouped as the admin's Checks page (`CheckReport::Group()`), with the timestamp and drift fixes (shown, never run) | |
| 7. Finish | what it removes | `finish` |

### 2. Configuration

Writes `configs/magrathea.conf` with `ConfigFile::Save()`:

- setup: `[general] use_environment` (the environment's name, default `dev`) and that section;
- maintenance: only the active section. The other sections, and keys the form doesn't know, stay.

Fields: `db_host`, `db_port`, `db_name`, `db_user`, `db_pass`, `timezone` (a list of
`timezone_identifiers_list()`), `app_url`, `logs_path`, `cache_path`, `backups_path` (default: folders
under `magRoot`) and `jwt_key` (default: `bin2hex(random_bytes(32))`). The section also gets
`bootstrap_unlocked = true`.

Validation, server side:
- `ConfigFile::IsStorable()`: `Save()` wraps values in `"…"` without escaping, and the INI parser
  expands `${…}` and reads `yes`/`no`/`true`/`false`/`0` as booleans. A value with `"`, a line break, or
  one that wouldn't read back the same, is refused;
- the timezone must be in the list; the port 1–65535 or empty; `app_url` `http(s)://…` or empty;
- `db_host`, `db_name` and `db_user` all set, or none (a project without a database);
- the environment: letters, digits, `_` and `-`, not `general`.

The password is never sent back to the form: in maintenance, an empty one keeps the current one. The
folders aren't created: the Checks step tells which are missing.

`Save()` rewrites the whole file, so comments in it are lost.

### 3. Database

Runs on opening the step, with the saved config: connection (the error when it fails), the timezone
findings (this used to be in the 2.x `config-edit` step), and the tables.

### 4. Import

Through the admin's `DumpManager`: an upload is only saved in the backups folder (`Upload()`); an
import (`Import()`) runs only when the database's name is typed to confirm. It shows the driver, the
statement count (php driver only) and, with "continue past errors", how many failed and the first
error. Without it, the first error stops the import and is shown.

### 5. Admin

- **Install the admin's tables**: `Install::InstallDatabase()`, only when `_magrathea_config` is
  missing;
- **The first super admin**: an e-mail and a password of 12 or more characters (`Steps::MIN_PASSWORD`),
  typed twice, only when `Admin\Start::CanCreateFirstUser()` allows it (there are no users yet). Then
  `Install::CreateFirstUser()`.

The admin's entry file (`public/admin.php`) comes with the skeleton: the wizard doesn't write it. A
forgotten password is `vendor/bin/magrathea admin:reset-password <email>`: shell access proves
ownership, a web form doesn't.

### 7. Finish

- removes `bootstrap_unlocked` from every section but `[dev]`, and from `[dev]` too unless "keep" is
  checked (it's offered when `[dev]` has it);
- deletes the token and forgets the session.

With the flag kept in `[dev]`, the next visit there asks for a new token. Elsewhere, the wizard is
locked until someone adds the flag by hand.

---

## Security notes

- `configs/` must not be reachable from the web: it holds the config and the token. The Requirements
  and Checks steps warn when it's under the document root.
- The wizard never runs arbitrary SQL, never resets passwords, and never writes code. The 2.x steps
  (`structure`, `config-file`, `config-edit`, `log-and-debug`, `db-connect`, `db-run`, `db-query`,
  `install-admin`, `develop`), `tools.php` and the actions (`run-query`, `user-reset`, `users-get`,
  `show-tables`, `install-admin`, `install-admin-preview`, `database-test`, `save-config`) are gone.
- A production error page (`ErrorManager`, outside the CLI) shows the message only; the exception's
  dump, with its stack trace and arguments, only when the Debugger is `DEV`.

## Testing

`Start::Instance()->SetMagRoot($folder)` points the wizard at a temporary project;
`SetSteps(new Steps($start, $dumps, $install, $adminStart))` injects fakes. `Handle()` and `Render()`
need no web server (see `src/Tests/MagratheaTests/bootstrapTest.php`).
