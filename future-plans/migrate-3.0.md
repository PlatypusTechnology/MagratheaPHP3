# Migrating to MagratheaPHP 3.0.0

**From:** `2.3.*` or `2.4.*` · **To:** `3.0.0`

> **Draft**, written with `future-plans/plan-3.0.md`. Section **M\<n\>** matches plan item \<n\>
> and is finalized when that item is implemented. Plan item 34 moves this file to
> `docs/mds/migrations/migrate-3.0.md`, which ships in `vendor/`, and removes this note.

## For the agent applying this migration

This file is self-contained: it's written to be handed to an AI agent, or a person, upgrading a
project, without the rest of the docs.

- Work on a **branch** of the project and against a **staging database**, never on production.
- Go through the sections **in order**. For each one:
  1. run its **Applies if** check;
  2. if it applies, do **What to do**;
  3. record in your report what you changed, or why the section didn't apply.
- **Stop and ask the human at every ⚠.** Those steps can silently change data, API output or
  production schemas. Don't decide them alone.
- Commands are POSIX shell, run from the project root, and every search skips `vendor/`. A removed
  method fails only **when the line runs**, so search **all** PHP files (views, cron jobs, CLI
  scripts, tests), not just classes.
- From section 2 until M2, the project can't run: the installed package is 3.0, but the code still
  names the old namespace, `Magrathea2`. The steps in between only edit code; run things again from
  M2 on.
- Several steps use the new CLI, `vendor/bin/magrathea`. It loads the project's bootstrap
  (`app/_inc.php` by default; M24 shows how to declare another file), so it works once that
  bootstrap runs on 3.0, after M2 (the rename) and M5 (removed methods).
- When the project doesn't match what this file describes, report it instead of guessing.

## Summary

| # | Change | Breaking? | Action | ⚠ |
|---|--------|-----------|--------|---|
| M0 | `GetById()` rejects non-numeric ids on int primary keys (SQL injection fix) | Only for code passing malformed ids | Validate ids built from input | |
| M1 | The admin session keeps only the user id; inactive users can't log in; one message for every failed login | Admins are logged out once; inactive users lose access | Check inactive users and code matching the old login messages | ⚠ |
| M2 | Namespace `Magrathea2` → `Magrathea`; package `platypustechnology/magratheaphp` | **Yes**: every file that names a framework class or function | Replace `Magrathea2` with `Magrathea`, `composer dump-autoload` | |
| M3 | Code generator and `magrathea_objects.conf` removed | Only for code that calls the generator | Delete the conf, strip the "generated" headers | |
| M4 | File Editor removed | Yes, if `LoadFileEditor()` is called | Remove the call | |
| M5 | Deprecated and dead methods removed (`ShowAll()`, `LoadVendor()`, `Clean()`, `CloseConnectionThanks()`, …) | Yes, where they're called | Replace each call | |
| M6 | `MagratheaModel` provides a constructor, `$autoload`, `$created_at`/`$updated_at` and `GetControl()` | Rarely: redeclarations PHP now refuses (non-public, typed, static), constructor-less models called with an argument | Fix those | |
| M7 | Unknown `$dbValues` types throw | Yes, for models with invalid types (`"bool"`, …) | Fix the types | |
| M8 | Controls can declare `$model = X::class` | No | None | |
| M9 | `$strictTypes` defaults to `true` | **Yes**: int/bool/float fields become native types, in PHP and in JSON | Pin `false` on every existing model | ⚠ |
| M10 | `Insert()` sends assigned timestamps as they are and no longer stamps `now()` on the object; uuid PKs work with `Insert()` | Where code assigns timestamps before inserting, or reads them right after | Check assignments; reload to read the database's values | |
| M11 | `Update()` never writes `created_at`/`updated_at`; an unchanged object runs no query | **Yes**: `updated_at` needs `ON UPDATE CURRENT_TIMESTAMP` | Run the schema check, ALTER the tables | ⚠ |
| M12 | New `Touch()`; `Save()` no longer bumps `updated_at` on its own | Where code relied on that | Use `Touch()` | ⚠ |
| M13 | Every model table needs `created_at`/`updated_at` (already true, now checked) | No | Covered by M11's check | |
| M14–M17 | New `DB\Schema`, `DB\SchemaCheck`, `ProjectCheck`, `DB\Dump`; `AdminDatabase` loses `GetTables()`, `GetTableColumns()`, `DoBackup()` | Only where those three are called | Use `Schema`/`Dump` | |
| M18–M23 | The admin's Database section (explorer, query editor, dumps, checks); menu items changed | Only for custom menus using removed items | Fix the menu | |
| M24–M26 | New `vendor/bin/magrathea` CLI; framework error pages print text in the CLI | No | Declare the bootstrap if it isn't `app/_inc.php` | |
| M27–M28 | Setup Wizard rebuilt: dedicated entry file, token lock | Yes, for projects with a 2.x wizard entry file | Replace or delete that file | ⚠ |
| M29 | CORS origins file read by `AllowFromFile()` and edited by the opt-in admin feature; `Vary: Origin`; `AllowAll()` drops credentials; `AddAcceptHeaders()` appends | Only for code relying on `AddAcceptHeaders()` replacing the list | Switch those calls to `SetAcceptHeaders()` | |
| M30–M34 | `skills.MD` rewritten for 3.0; `instructions.MD` removed; the docs ship in `vendor/` | Only for agent configs pointing at `instructions.MD` | Update those pointers | |

**Coming from 2.3?** This table covers only 3.0. A 2.3 project also inherits the 2.4.0 changes,
and some of them break silently: section 3 lists them.

---

## 1. Preflight

1. **Installed version:**
   ```bash
   cat vendor/platypustechnology/magratheaphp2/src/version; echo
   grep -n "platypustechnology/magratheaphp2" composer.json
   ```
   - `2.3.*`: **⚠ ask the human** whether to upgrade in two hops or one.
     - Two hops (safer, since the project can be tested in between): require
       `platypustechnology/magratheaphp2:^2.4`, apply `migrate-2.4.0.md` completely
       (https://www.platypusweb.com.br/magratheaphp2/docs/?p=migrations/migrate-2.4.0 ), run the
       tests (deploy, if the human wants), then start this file again as a 2.4 project.
     - One hop: continue here; section 3 applies.
   - `2.4.*`: skip section 3.
   - Anything older: stop and ask the human.
2. **A clean git tree, on a new branch:**
   ```bash
   git status --porcelain
   git checkout -b upgrade/magrathea-3.0
   ```
3. **⚠ Ask the human for:**
   - a database backup, or confirmation that one exists;
   - a staging database to run against.

   Don't run anything in this file against production until the human says so.
4. **Record API snapshots** to compare after the upgrade. For the main GET endpoints, and at least
   one per model the API returns, save the current JSON responses in `upgrade-snapshots/before/`,
   outside the web root. Don't commit them: they may hold real data. Ask the human which endpoints
   matter if you can't tell. Diffing these catches most of M9 and M10.
5. **Run the project's test suite** and record the result. It's your baseline.

## 2. Composer

3.0 is a new package, `platypustechnology/magratheaphp`, so no `magratheaphp2` constraint ever
receives it. Install it, then remove the old one (in this order, the project always has a framework
installed):
```bash
composer require "platypustechnology/magratheaphp:^3.0"
composer remove platypustechnology/magratheaphp2
```
3.0 needs PHP 8.1+, as 2.4.0 did. From now on, this file and `migrate-2.4.0.md` are in
`vendor/platypustechnology/magratheaphp/docs/mds/migrations/`. The project can't run again until M2
renames the namespace.

## 3. Coming from 2.3 (skip on 2.4)

3.0 includes every 2.4.0 change. Apply
`vendor/platypustechnology/magratheaphp/docs/mds/migrations/migrate-2.4.0.md` now (it's also at
https://www.platypusweb.com.br/magratheaphp3/docs/?p=migrations/migrate-2.4.0 ), including its admin
security sections 43–48. The project's code still names `Magrathea2`, as that guide does: follow it
literally, and write any new code with `Magrathea2\` like the rest of the project; M2 renames it
all. Run the checks it asks for (tests, the timezone check) after M2, when the project runs again.

Skip its "Composer constraint" section, since you're on the new package, and these items, which 3.0
supersedes:

| 2.4.0 item | Why skip | Instead |
|---|---|---|
| 1: the wizard's `bootstrap_unlocked` lock | the wizard is rebuilt | M27 |
| 5: the File Editor is opt-in | the File Editor is removed | M4 |
| 6: `Query::Clean()` deprecated | it's removed | M5 |
| 22: timestamps unchanged, documented | timestamps change | M10–M13 |
| 37: `ShowAll()` | it's removed | M5 |
| 38: `LoadVendor()` | it's removed | M5 |
| 40: the "Strict types" select | the generator is removed | M9 |
| 41: the `mvc` generator | the generator is removed | M3 |
| 44: backups without a shell; `GetCommand()` removed | `DoBackup()` is removed too | M5, M17 |
| 47: `onclose` on the object and code views | those views are removed | M3 |

### ⚠ The 2.4.0 changes that break silently

Every item not skipped above still applies, and a 2.3 project has seen none of them. These change
behavior **without an error**, so they're the easiest to miss. Their full steps are in the 2.4.0
guide; this table makes sure you don't skip one.

| 2.4.0 item | What changes for a 2.3 project | Fix (full steps in the 2.4.0 guide) |
|---|---|---|
| **16** | API errors return real 4xx/5xx statuses (they were HTTP 200 with `success: false`). Clients that read `success` in their success handler break. | Move client error handling to the HTTP error path, and **deploy the clients before the backend**. Ask the human how clients are released. |
| **23** | `Update()` on a loaded object writes only the fields that **changed**. Data loaded with `LoadObjectFromTableRow()` isn't saved; stray display-only assignments are. | Use `Assign()` for input data; audit the assignments made before `Save()`. |
| **25** | `Update()` writes SQL `NULL` for an int/boolean nulled on a loaded object, and for `""` always. | Check int assignments, and forms posting empty ints to `NOT NULL` columns. |
| **27, 28** | Failed writes **throw** `MagratheaDBException` (2.3 printed `got error!` and returned `null` or `false`). | Catch `MagratheaDBException`; stop testing `if(!$obj->Insert())`. |
| **30** | One shared database connection per request, instead of one per query. A long-running CLI worker keeps a single connection open. | Call `Database::Instance()->Disconnect()` at the start of each job. |
| **34** | With `timezone` set, MySQL's session `time_zone` is synced to PHP's (`db_sync_timezone`, on by default): `TIMESTAMP` columns read back **shifted** if MySQL runs in another zone. In 3.0 every timestamp comes from the database (M10, M11), so this sync decides whether they match PHP's clock. | Run the timezone checks. `db_sync_timezone = false` opts out, and then timestamps are in the database's zone. |

Smaller ones that still apply. Apply each 2.4.0 item if its own search finds something:
- **3**: in raw mode, a failed authorization no longer runs the action;
- **17**: `MagratheaApiException::SetStatus()` is honored, and a missing token is now HTTP 401;
- **29**: int primary keys above `PHP_INT_MAX` must be declared `"string"`;
- **32**: `db_port` from the config is now used;
- **36**: a missing class throws PHP's `\Error`, not `MagratheaException`;
- **46**: custom admin pages: `Table()` and form values are escaped now;
- **48**: custom admin features: URLs reach only the feature's own methods, and writes are POST.

Projects on 2.3.0 to 2.3.4: also skim the `### 2.3.1` to `### 2.3.5` entries in
`vendor/platypustechnology/magratheaphp/src/changelog.md`. They're mostly fixes, but two change
behavior: in 2.3.1, a custom `HasPermission()` override also runs for feature actions and explicit
subpages; in 2.3.5, an expired or invalid JWT answers HTTP 401 (code `4010` when it's expired)
instead of HTTP 200.

---

## M0. `GetById()` rejects non-numeric ids on int primary keys (security)

**Applies if:** always: it's a framework fix. Code needs a change only where it builds ids from
user input.

**What changed.** `MagratheaModel::GetById()`, and so `new Model($id)`, put the id of an int-PK model
straight into the SQL, and the API's `:id` route segment reached it: a SQL injection. In 3.0, an
int PK accepts only an int or a string of digits. Anything else (`"5abc"`, `" 5"`, `"5.0"`) throws
`MagratheaModelException` with code 400, which the API answers with HTTP 400. String-PK models are
unchanged.

**What to do.** Nothing for ids that come from the database or from the API's routes. Where code
builds an id from other input (a form field, a CSV import) before `new Model($id)`, trim and
validate it first. Note in your report that API clients sending a malformed id now get HTTP 400
instead of a 500 or a SQL error.

## M1. ⚠ Admin login and sessions

**Applies if:** always: the behavior changes for every admin. Code needs a change only where the
project reads the admin session itself, or matches the old login messages:
```bash
grep -rnE 'magrathea_user|User not found|Password incorrect' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

**What changed.**
- The admin session keeps only the user's id, and the user is reloaded on every admin request.
  Admins logged in when 3.0 is deployed are logged out once: their 2.x session can't be read.
- A role change applies on the admin's next request. A user with `active = 0` can't log in, and
  loses access on their next request.
- Every failed login (unknown email, wrong password, inactive user) answers "Invalid email or
  password", on the admin login page and in `MagratheaApiAuth::AdminUserLogin()`'s 403.
- The session id changes at login.

**What to do.**
1. Code that read `$_SESSION["magrathea_user"]` (a serialized `AdminUser`): call
   `AdminManager::Instance()->GetLoggedUser()` instead.
2. Clients or tests that matched "User not found" or "Password incorrect": match the new message,
   or just the 403.
3. **⚠ Ask the human** about the users this query lists: they lose admin access in 3.0.
   ```sql
   SELECT id, email, active FROM `_magrathea_users` WHERE active IS NULL OR active = 0;
   ```
   Also warn them that every admin has to log in again after the deploy.

## M2. The rename: `Magrathea2` → `Magrathea`

**Applies if:** always.

**What changed.**
- The namespace `Magrathea2` is now `Magrathea`. Class and function names don't change:
  `Magrathea2\MagratheaModel` is now `Magrathea\MagratheaModel`, and `\Magrathea2\p_r()` is
  `\Magrathea\p_r()`.
- The package is `platypustechnology/magratheaphp` (section 2 already swapped it). The docs are at
  https://www.platypusweb.com.br/magratheaphp3/docs/ and the repository is
  `PlatypusTechnology/MagratheaPHP3`. The 2.x docs and repository stay where they were.
- There's no compatibility layer: PHP can alias classes, but not functions.
- `ToJson()` puts the class name in its `object` key, so in API JSON that value changes for the
  framework's own models (`AdminUser`, `AppConfig`, `AdminLog`). App models keep theirs.

**What to do.**
1. List every file that names the old namespace, PHP or not (configs, scripts, docs, tests):
   ```bash
   grep -rlF 'Magrathea2' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
   ```
2. Replace `Magrathea2` with `Magrathea` in them: `namespace` and `use` lines, fully-qualified names,
   `use function` lines, and class names inside strings (`"Magrathea2\\Admin\\…"`). Only the
   namespace changes, so a plain text substitution is always correct. For the PHP files
   (`sed -i.bak` works with both GNU and BSD `sed`):
   ```bash
   grep -rlF 'Magrathea2' --include='*.php' --exclude-dir=vendor . | while IFS= read -r f; do sed -i.bak 's/Magrathea2/Magrathea/g' "$f" && rm "$f.bak"; done
   ```
   Review the other files from step 1 one by one.
3. Run `composer dump-autoload`. Step 1's search must now find nothing, apart from history the
   project chooses to keep (its own changelog, for example).
4. In your report, note any API consumer that reads `object` from a framework model's `ToJson()`.

## M3. The code generator and `magrathea_objects.conf` are removed

**Applies if** any of these finds something:
```bash
find . -name "magrathea_objects.conf" -not -path "./vendor/*"
grep -rln "FILE GENERATED BY MAGRATHEA" --include='*.php' --exclude-dir=vendor .
grep -rnE 'CodeCreator|CodeManager|ObjectManager|ObjectsApi|AdminConfigApi|GetObjectSection|JsCallback|"(objects-create|objects-conf|objects|objects-edit|code-gen)"' --include='*.php' --exclude-dir=vendor .
```

**What changed.**
- The admin no longer creates objects or generates code, and nothing reads
  `magrathea_objects.conf`.
- Generated Base classes keep working unchanged: they're plain PHP.
- The `_magrathea_config` rows `code_path`, `code_structure` and `code_namespace` are no longer read.
- `AdminElements::JsCallback()` is removed: only the generator's views used it.

**What to do.**
1. Delete `magrathea_objects.conf`. Git history keeps it.
2. Remove the generator's header from every generated file. It's three comment lines right after
   `<?php`:
   ```
   ## FILE GENERATED BY MAGRATHEA.
   ## This file was automatically generated and changes can be overwritten through the admin
   ## -- date of creation: [...]
   ```
   Nothing overwrites these files anymore, and the header tells agents not to edit them.
3. Custom admin menus: remove calls to `GetObjectSection()`, and `GetItem()` calls with the keys
   `objects-create`, `objects-conf`, `objects`, `objects-edit` or `code-gen`. They now return
   `null`, which `Add()` ignores, so they're dead code.
4. Custom admin code calling `JsCallback($fn, $args)`: build the `onclick` yourself, passing data
   through `htmlspecialchars(json_encode($x))`.
5. Optional, on the human's go-ahead:
   ```sql
   DELETE FROM `_magrathea_config` WHERE `name` IN ('code_path','code_structure','code_namespace');
   ```

Base classes can stay forever. Merging them into single classes is optional: see "Optional: merge
Base classes" at the end.

## M4. The File Editor is removed

**Applies if:**
```bash
grep -rnE 'LoadFileEditor|AdminFeatureFileEditor|tools-file-editor' --include='*.php' --exclude-dir=vendor .
```

**What changed.** `Admin::LoadFileEditor()` and the File Editor feature don't exist. An admin that
calls `$this->LoadFileEditor()` dies with "Call to undefined method" when it loads.

**What to do.** Remove the call, and any menu item for it.

## M5. Deprecated and dead methods are removed

**Applies if** any of these finds something:
```bash
grep -rnE 'ShowAll\(|LoadVendor|Query::Clean|::Clean\(|CloseConnectionThanks|IncludeAllModels|GetNextID|GetAutoLoad|ResponseUserPayload|StartDB\(' --include='*.php' --exclude-dir=vendor .
grep -rn --include='*.php' --exclude-dir=vendor -e '->Start()' .   # only calls on a MagratheaApi object matter
grep -rnE '(GetTables|GetTableColumns|DoBackup|GetCommand)\(' --include='*.php' --exclude-dir=vendor .   # only calls on AdminDatabase matter
```

**What to do.** Replace each call:

| Removed | Replace with |
|---|---|
| `SomeControl::ShowAll()` | nothing: it was a debug helper that echoed an HTML table. Delete the call |
| `MagratheaPHP::LoadVendor()` | nothing: the call never did anything, since entry files already `require __DIR__."/../vendor/autoload.php";`. Delete it |
| `Query::Clean($x)` | `Database::Instance()->Escape($x)`, or better, a prepared statement |
| `->CloseConnectionThanks()` | nothing: it's been a no-op since 2.4.0. To really close the connection, call `Disconnect()` |
| `MagratheaModel::IncludeAllModels()` | register the folder with `AddRootCodeFolder()` or `AddFeature()`, and delete the call |
| `$model->GetNextID()` | the id `Insert()` returns. Reading the next id beforehand is a race |
| `->GetAutoLoad()` | nothing: it always returned `null` |
| `MagratheaApiAuth::ResponseUserPayload($user)` | `ResponsePayload($payload)`, or a payload built in your auth class. The keys differ: `token`, `expires`, `data`, `refresh_token` |
| `$api->Start()` on a `MagratheaApi` | nothing: `Run()` does the work. Delete the call |
| `AdminDatabase::Instance()->GetTables()` / `GetTableColumns($t)` | `(new \Magrathea\DB\Schema())->Tables()` / `->Columns($t)` (M14). The rows have different keys; `Tables(false)` leaves out the `_magrathea_*` tables, as `GetTables(false)` did |
| `AdminDatabase::Instance()->DoBackup($name)` / `GetCommand()` | `(new \Magrathea\DB\Dump())->Export($name)` (M17) |
| `MagratheaPHP::Instance()->StartDB()` | `Connect()`. `StartDB()` still works in 3.0 (deprecated, removed in 4.0) |

## M6. `MagratheaModel` defaults

**What changed.** `MagratheaModel` now provides what the generator used to write into every Base
class:
- a constructor, `__construct($id = 0)`, that runs `MagratheaStart()` and loads `$id`;
- a no-op `MagratheaStart()`;
- `protected $autoload = null`;
- `public $created_at, $updated_at`;
- a default `GetControl()`, which returns a new `<model class>Control`.

A model's own constructor, `GetControl()` and declarations override these, as before.

**Applies if** either of these is true:
1. A model **redeclares** one of them in a way PHP now refuses, which is a fatal error when the
   class loads ("Access level … must be public", "Type of … must not be defined", "Cannot make
   non static method … static"):
   - `$created_at`/`$updated_at` as `protected` or `private`, or `$autoload` as `private`;
   - any of the three properties with a type (`public ?string $created_at`);
   - `MagratheaStart()` or `GetControl()` as `protected`, `private` or `static`.
   ```bash
   grep -rnE '(protected|private)[^;(]*\$(created_at|updated_at)([^A-Za-z0-9_]|$)|private[^;(]*\$autoload([^A-Za-z0-9_]|$)|(public|protected|private|var)( +static| +readonly)* +\??[A-Za-z_\\][A-Za-z0-9_\\|]* +[^;(]*\$(created_at|updated_at|autoload)([^A-Za-z0-9_]|$)|(protected|private|static)( +[a-z]+)* +function +(MagratheaStart|GetControl) *\(' --include='*.php' --exclude-dir=vendor .
   ```
2. A model class has **no** constructor (neither its own nor a parent's), and code instantiates it
   **with an argument**. The argument used to be ignored; now that row is loaded, and a missing row
   throws. List the model classes, then, for each one without a `__construct` in its hierarchy,
   search for `new ClassName(` with an argument:
   ```bash
   grep -rlE 'extends +\\?(Magrathea\\)?MagratheaModel([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
   ```

**What to do.**
1. Fix each redeclaration found: an untyped `public` property (`protected` is enough for
   `$autoload`), and public, non-static methods. Or delete the property: the base class declares it
   now.
2. For each constructor-less model instantiated with an argument, check that loading the row is
   what the code wants.

## M7. Unknown field types throw

**Applies if** a model declares a type other than `int`, `integer`, `boolean`, `string`, `text`,
`float`, `double`, `decimal`, `datetime`, `date` or `uuid`. List the types in use:
```bash
# style 1: $this->dbValues["x"] = "type";
grep -rhoE "dbValues\[[\"'][A-Za-z0-9_]+[\"']\] *= *[\"'][A-Za-z]+[\"']" --include='*.php' --exclude-dir=vendor . | sed -E "s/.*= *[\"']([A-Za-z]+)[\"']/\1/" | sort | uniq -c
# style 2: protected $dbValues = [ "x" => "type", ... ];
grep -rlE 'dbValues *= *(\[|array\()' --include='*.php' --exclude-dir=vendor . | xargs awk '/dbValues *= *(\[|array\()/,/(\]|\));/' | grep -oE "=> *[\"'][A-Za-z]+[\"']" | sed -E "s/=> *[\"']([A-Za-z]+)[\"']/\1/" | sort | uniq -c
```
Once the bootstrap runs on 3.0, `vendor/bin/magrathea models:verify` reports each unknown type as
an ERROR, with its model and field.

**What changed.** An unknown type used to be bound as a string, silently. Now the model's first
write throws `MagratheaModelException("Unknown field type …")`.

**What to do.** Fix each unknown type according to its column (check it with
`vendor/bin/magrathea db:describe <table>`):

| Found | Use |
|---|---|
| `bool` | `boolean` |
| `varchar`, `char`, `enum` | `string` |
| `tinyint` | `boolean` for `tinyint(1)` flags, else `int` |
| `json`, `longtext` | `text` |
| `timestamp` | `datetime` |
| `money`, `numeric` | `decimal` |

## M8. Controls can declare `$model`

No impact. New Controls can declare `protected static $model = Article::class;` instead of
`$modelNamespace`, `$modelName` and `$dbTable`; `$dbTable` then comes from the model.

## M9. ⚠ `$strictTypes` defaults to `true`

**Applies if** any model doesn't declare `$strictTypes`, which is almost every project:
```bash
grep -rlE 'extends +\\?(Magrathea\\)?MagratheaModel([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
grep -rn 'strictTypes' --include='*.php' --exclude-dir=vendor .
```

**What changed.** Values loaded from the database are cast to their declared type: `int` fields
become PHP ints, `boolean` fields `true`/`false`, and `float` fields floats; `decimal` fields stay
exact strings. That changes **API JSON** (`"42"` → `42`, `"1"` → `true`) and every `===`
comparison against strings.

**What to do.**
1. Add `protected $strictTypes = false;` to every class that extends `MagratheaModel` directly
   (generated `…Base` classes included) and doesn't declare `$strictTypes` itself. Behavior and
   JSON stay exactly as in 2.x, and a subclass's own declaration still wins.
2. **Check that none was missed**: every model class needs its own declaration or an inherited one.
   A missed model silently changes its API output.
3. **⚠ Ask the human** before removing the pin (opting into strict types) on any model: its API
   consumers have to accept native types first.
4. New models don't declare it: they're strict by default.

## M10. `Insert()` sends assigned timestamps and doesn't invent `now()`

**Applies if** code assigns `created_at`/`updated_at` before inserting, reads them right after an
insert, or inserts models with a `uuid` primary key:
```bash
grep -rn --include='*.php' --exclude-dir=vendor -E -e '->(created_at|updated_at)' .
grep -rnE "[\"']uuid[\"']" --include='*.php' --exclude-dir=vendor .
```

**What changed.**
- An assigned timestamp is stored as assigned; 2.x silently stored `now()` instead. That fixes
  imports through `InsertWithPk()`.
- An unset timestamp is left to the column's `DEFAULT`, and the object keeps it `null` after the
  insert. The framework no longer stamps PHP's `now()` on the object, and it doesn't read the row
  back: reload the object to get the database's value. API create responses reload automatically.
- `MagratheaApiControl::Create()` ignores `created_at`/`updated_at` sent by the client (2.x
  replaced them with `now()`).
- A model with a `uuid` primary key now sends it on `Insert()` and keeps it, and `Insert()` returns
  it. In 2.x, `Insert()` and `Save()` left the uuid out of the INSERT and set the PK to `true`; only
  `InsertWithPk()` worked.

**What to do.**
1. Check each assignment found before an `Insert()` or `Save()`. If it set the value only because
   "the framework needs it", delete it and let the database default fill it.
2. Where code reads `$obj->created_at` or `$obj->updated_at` right after a plain `Insert()`, reload
   the object.
3. uuid-PK models: code that worked around the bug with `InsertWithPk()` keeps working, and can now
   use `Insert()` or `Save()`. Report any code that relied on the PK being `true` after `Insert()`.

## M11. ⚠ `Update()` never writes timestamps; `updated_at` needs `ON UPDATE CURRENT_TIMESTAMP`

**Applies if:** always.

**What changed.**
- `Update()` and `Save()` never write `created_at` or `updated_at`. MySQL sets `updated_at` through
  `ON UPDATE CURRENT_TIMESTAMP`.
- A loaded object with no changes runs **no query** at all.
- After an update that changed something, the object sets its own `updated_at` to `null`, so it
  never reports the stale pre-update value; `created_at` is untouched. Reload the object, or read
  the API's update response (which reloads), to get the new value. The framework doesn't read the
  row back.
- `MagratheaApiControl::Update()` refuses a malformed id (HTTP 400) before writing.
- **A table whose `updated_at` has no `ON UPDATE CURRENT_TIMESTAMP` (and no trigger) silently stops
  updating it.**

**What to do.**
1. On **staging**, once the code changes are in, run the checks:
   ```bash
   vendor/bin/magrathea check --json
   vendor/bin/magrathea models:verify --json
   ```
   Every table whose `created_at` lacks `DEFAULT CURRENT_TIMESTAMP`, or whose `updated_at` lacks
   `ON UPDATE CURRENT_TIMESTAMP`, is listed with the `ALTER TABLE … MODIFY …` that fixes it (the
   finding's `fix`). The ALTER keeps the column's type and nullability.
2. Apply the ALTERs on **staging**, then rerun the checks until they're clean.
3. **⚠ Production:** show the human the list of ALTERs and the table sizes:
   ```sql
   SELECT table_name, table_rows FROM information_schema.tables
   WHERE table_schema = DATABASE() ORDER BY table_rows DESC;
   ```
   Changing a column definition can rebuild a large table and block writes while it runs. The human
   decides when, after a backup. Existing `NULL` `updated_at` values stay `NULL` until the row's
   next update. To fill them:
   ```sql
   UPDATE t SET updated_at = created_at WHERE updated_at IS NULL;
   ```
4. `TIMESTAMP` columns don't need converting to `DATETIME`: both support these defaults.

## M12. ⚠ `Touch()`

**Applies if** code relied on `Save()` or `Update()` bumping `updated_at` when nothing else
changed: "last seen", "touch the parent", sorting by recent activity. A search can't find this
reliably; start with:
```bash
grep -rn 'updated_at' --include='*.php' --exclude-dir=vendor .
grep -rniE 'order +by +updated_at|Order\(.updated_at' --include='*.php' --exclude-dir=vendor .
```

**What to do.**
- Where code saves an object only to bump its timestamp, call `$obj->Touch()` instead
  (`UPDATE … SET updated_at = CURRENT_TIMESTAMP`, on MySQL's clock). `$obj->updated_at` is `null`
  afterwards: reload to read the new value.
- **⚠ Ask the human** about features that depend on `updated_at` being fresh: activity feeds, sync
  jobs, caches keyed on `updated_at`.

## M13. Every model table needs `created_at` and `updated_at`

This isn't a new requirement: every SELECT built from a model has always included both columns,
and Controls sort by `created_at`. 3.0 documents it and checks it as part of M11's check, which
reports a model table missing them as an ERROR.

**Optional, on the human's go-ahead.** In databases installed by the 2.x admin, `_magrathea_users`'s
`last_login` may update itself on every change to the row: under MySQL 5.7's
`explicit_defaults_for_timestamp=OFF`, it got `ON UPDATE CURRENT_TIMESTAMP`. Check:
```sql
SHOW CREATE TABLE `_magrathea_users`;
```
If `last_login` has `ON UPDATE CURRENT_TIMESTAMP`:
```sql
ALTER TABLE `_magrathea_users` MODIFY `last_login` TIMESTAMP NULL DEFAULT NULL;
```

## M14–M17. New services; `AdminDatabase` slimmed down

**Applies if** M5's `AdminDatabase` search found something.

**What changed.**
- New classes: `Magrathea\DB\Schema` (introspection), `DB\SchemaCheck` (model ↔ table drift and
  timestamps), `ProjectCheck` (config, folders, database, timezone), `DB\Dump` (export and import,
  through `mysqldump` or in PHP) and `DB\SqlSplitter`.
- `AdminDatabase` keeps only the backup folder and the admin's SQL file helpers.
- `Database::Query()`, `QueryMulti()` and `ImportFile()` now split SQL correctly: they respect
  quotes, comments, backticks and `DELIMITER`, instead of splitting on `";\n"`.

**What to do.** Replace the calls (M5's table).

## M18–M23. The admin "Database" section

**Applies if** the project's admin builds its own menu:
```bash
grep -rnE 'GetDatabaseSection|GetItem\(.(backups|structure|mag-query).\)' --include='*.php' --exclude-dir=vendor .
```

**What changed.**
- The Database menu section has Explorer (`db-explorer`), Queries (`query`), Query editor
  (`query-editor`), Dumps (`dumps`, replacing Backups), Checks (`checks`, replacing Structure) and
  Connection (`connect`).
- The keys `backups` and `structure` are gone: `GetItem()` returns `null` for them, and `Add(null)`
  adds nothing.
- The Version page's "Server Time" card is now a link to the Server page's timezone check.

**What to do.** In custom menus, replace `backups` with `dumps` and `structure` with `checks`.

## M24–M26. The CLI

**What changed.** There's a new `vendor/bin/magrathea`:
- commands: `check`, `db:tables`, `db:describe`, `db:query`, `db:dump`, `db:import`,
  `models:verify`, `admin:reset-password` and `version`;
- `--json` for machine-readable output;
- commands that write need `--write` (`db:query`) or `--yes` (`db:import`).

In the CLI, framework error pages (a missing config, a failed version check) now print text to
stderr and exit with code 1, instead of HTML and exit code 0. Cron jobs and workers that hit one now
show up as failed.

**What to do.** If the project's bootstrap isn't `app/_inc.php`, declare it in `composer.json`:
```json
"extra": { "magrathea": { "bootstrap": "src/bootstrap.php" } }
```
The file must not print anything. Check with `vendor/bin/magrathea version`.

## M27–M28. ⚠ The Setup Wizard

**Applies if** the project has a 2.x wizard entry file, or uses `bootstrap_unlocked`:
```bash
grep -rlE 'Bootstrap\\+Start|PreLoad\(' --include='*.php' --exclude-dir=vendor .
grep -rn 'bootstrap_unlocked' configs/ 2>/dev/null
```

**What changed.**
- The wizard (`Magrathea\Bootstrap\Start`) has its own entry file, and works with no config at
  all.
- Every visit needs an **8-digit token** (`xxxx-xxxx`) that the wizard writes to
  `configs/.setup-token`, where only someone with access to the server's files can read it. Ten
  wrong tries lock the wizard until `configs/.setup-locked` is deleted.
- With a config present, it also needs `bootstrap_unlocked = true` in the active environment, as in
  2.4.0.
- `PreLoad()` is gone. It copied the sample config, which arrived unlocked.
- The wizard's admin password reset is now `vendor/bin/magrathea admin:reset-password <email>`.

**What to do.**
1. Replace the old entry file's content with:
   ```php
   <?php
   require __DIR__."/../vendor/autoload.php";
   Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
   ```
   Adjust the paths to the project's layout. If the project doesn't need the wizard, delete the
   file.
2. **⚠ Servers:** `configs/` must not be reachable from the web: it holds `magrathea.conf` and the
   token. The admin's Checks page warns when it's under the document root (the CLI can't tell).
   Ask the human to confirm the web root.
3. Remove `bootstrap_unlocked` from every section except `dev`.

## M29. CORS origins

**Applies if:**
```bash
grep -rn 'AddAcceptHeaders(' --include='*.php' --exclude-dir=vendor .
```

**What changed.**
- `AddAcceptHeaders()` appends to the accepted headers (it used to replace the list), dropping
  case-insensitive duplicates. `SetAcceptHeaders()` replaces it.
- `AllowAll()` no longer sends `Access-Control-Allow-Credentials` (browsers reject it with `*`, so
  credentialed requests never worked). `Allow()` also sends `Vary: Origin`.
- New: `MagratheaApi::AllowFromFile()` reads the allowed origins from
  `configs/cors-origins.txt` (`GetCorsOriginsFile()`), and the opt-in admin feature
  `$this->LoadCors()` edits that file, rejecting lines that aren't `scheme://host[:port]`.

**What to do.**
1. For each `AddAcceptHeaders()` call: if it was meant to replace the list, call
   `SetAcceptHeaders()` instead. A list that already includes `Authorization` and `Content-Type`
   gives the same result either way.
2. Optional: a project that reads its own origins file (`cors-origins.txt` in `configs/`) can
   replace that code with `$this->AllowFromFile()` and its own CORS admin page with
   `$this->LoadCors()`. The file stays where it is.

## M30–M34. AI docs and packaging

**Applies if** the project's agent instructions (`AGENTS.md`, `CLAUDE.md`, editor rules) point at
`instructions.MD`:
```bash
grep -rn 'instructions.MD' --include='*.md' --include='*.MD' --exclude-dir=vendor .
```

**What changed.**
- `instructions.MD` no longer exists.
- The guide for writing code with the framework is
  `vendor/platypustechnology/magratheaphp/skills.MD`, rewritten for 3.0.
- The narrative docs, migration guides included, now ship in
  `vendor/platypustechnology/magratheaphp/docs/mds/`.

**What to do.** Point the project's agent instructions at `skills.MD` and `docs/mds/index.md`. If
the project has no `AGENTS.md`, `skills.MD` §17 has a five-line stub.

---

## Changes with no impact

- New classes and the CLI: M8, M14–M17, M24–M26.
- The admin's new Database pages: M18–M23, unless the project builds its own menu.

## Verify after upgrading

- [ ] The project's test suite passes (compare it with the preflight baseline).
- [ ] `vendor/bin/magrathea check` reports no ERROR.
- [ ] `vendor/bin/magrathea models:verify` reports no ERROR.
- [ ] The API snapshots in `upgrade-snapshots/before/` match the same calls now. With M9's pins in
      place, expect no differences; report every one to the human.
- [ ] Create a record through the API, wait at least a second (timestamps have one-second
      precision), then update it: `created_at` stays, and `updated_at` changes (M11's
      `ON UPDATE` works).
- [ ] Admin: log in, open a CRUD list, an edit form and the Database section.
- [ ] The health check endpoint answers.
- [ ] The logs show no `Unknown field type`, `Call to undefined method`, `Class … not found` or
      `Access level`.
- [ ] No `FILE GENERATED BY MAGRATHEA` header and no `magrathea_objects.conf` remain (M3).
- [ ] No `bootstrap_unlocked` outside `[dev]`, and `configs/` isn't reachable from the web (M27).
- [ ] `upgrade-snapshots/` isn't committed.

## Optional: merge Base classes

Only if the human asks for it, after everything above passes, and **one feature at a time**. The
framework doesn't need it: a project can keep its Base classes forever. Never do it in bulk.

For a feature `Article`:
1. Find everything that references the Base names first: `GetControl()` methods returning
   `…ControlBase`, has_many relations calling `…ControlBase::GetWhere()`, `use …\Base\…` lines,
   type hints.
2. Move the properties, `MagratheaStart()`, `$autoload` (when it isn't `null`), `$strictTypes` (M9's
   pin) and the relation methods from `Base/ArticleBase.php` into `Article.php`, and change
   `extends Base\ArticleBase` to `extends \Magrathea\MagratheaModel`. Drop the generated
   constructors, the `public $created_at, $updated_at;` line and `GetControl()`: `MagratheaModel`
   provides them (M6). The default `GetControl()` returns `ArticleControl`, so the CRUD admin also
   stops skipping `ArticleControl`'s overrides.
3. Point has_many relations at `ArticleControl::GetWhere()`.
4. Make `ArticleControl` extend `\Magrathea\MagratheaModelControl` directly, with
   `protected static $model = Article::class;` (M8), and delete `ArticleControlBase.php`.
5. Delete `ArticleBase.php`. `$dbValues` may keep listing `created_at` and `updated_at`; new code
   doesn't need them there.
6. Run the tests, and diff the feature's API snapshots, before moving to the next feature.
