# Plan: MagratheaPHP 3.0

**Target:** `3.0.0`, on branch `version/3.0` (today `src/version` is `2.4.0`). The code moves to
the new repository `PlatypusTechnology/MagratheaPHP3` (see Release); nothing in this plan depends on
which repository it's in. Line numbers refer to the code as of 2026-10-25, before any item. Earlier
items shift some of them, so when a line doesn't match, find the code by name.

**Companion file:** `future-plans/migrate-3.0.md`, the migration guide for projects on 2.3/2.4. Each
item updates its section (**M0**, **M1**, … match the item numbers), and item 34 moves the file
into the docs.

## Goal

In 3.0, agents write the application code and the framework stops generating it. That means:
1. **Nothing generates or edits code** (Group A). The Code Creator, `magrathea_objects.conf` and
   the File Editor go.
2. **What the generator used to write becomes an explicit contract that's safe to write by hand**
   (Groups B and C): base-class defaults, one list of field types, and timestamps owned by the
   database.
3. **The project's state is readable as data** (Groups D to G). Schema, model ↔ table drift, checks
   and dumps are framework classes that return arrays. Humans reach them through the admin's new
   Database section and the Setup Wizard; agents reach them through a CLI.
4. **The AI-facing docs are exact and ship with the package** (Group H). `skills.MD` is rewritten
   for 3.0, `AGENTS.md` replaces `instructions.MD`, and the docs and migration guides ship in
   `vendor/`.

3.0 also takes the version number out of the framework's names (Group R): the namespace becomes
`Magrathea` and the package `platypustechnology/magratheaphp`. Every item after the rename works
under the new names.

3.0 also lets APIs keep their CORS origins in a file that the admin edits (Group O).

## Rules for the implementing agent

1. **Conventions.** Load the `magrathea-php2` skill for the framework's conventions. It describes
   2.4.0, so where it disagrees with `src/`, `src/` wins. Its examples use the 2.x namespace,
   `Magrathea2`: since item 2, 3.0 code uses `Magrathea` (`use Magrathea\…`, `\Magrathea\p_r()`),
   and so does every file you write or touch. Use tabs, the existing naming and PHPDoc
   style, and the changelog prefixes `**new:**`, `**fix:**`, `**note:**` and `**removed:**`.
2. **Order.** Work group by group and item by item, in number order: later items build on earlier
   ones. For each item:
   1. change the code;
   2. add or adjust tests in `src/Tests/`;
   3. run the full suite: `php phpunit.phar --bootstrap src/Tests/phpUnitBootstrap.php src/Tests`.
      Record the baseline before the first change. On 2026-10-25 it was 400 tests, 1546
      assertions and 3 deprecations, all from `firebase/php-jwt`. Every item ends with the suite
      green. From item 3 on, also run item 2's check, which catches the old names in files no test
      loads (views, docs):
      `grep -rnE "Magrathea2|magratheaphp2|MagratheaPHP2" src docs skills.MD instructions.MD README.md composer.json`.
      It must find only what item 2 leaves alone, the `### 3.0.0` changelog lines that name the old
      namespace on purpose and, after item 34, `docs/mds/migrations/migrate-3.0.md`. Drop
      `instructions.MD` from the command once item 31 deletes it;
   4. update the narrative docs in `docs/mds/`. Signatures are read live from `src/`; only the
      prose needs care;
   5. fix any `skills.MD` section the item makes wrong (item 30 then rewrites the whole file).
      **Don't copy it to `~/.claude/skills/`**: the local skill serves projects still on 2.x, and
      the release splits it in two (see Release);
   6. bring the item's section **M\<n\>** in `future-plans/migrate-3.0.md` in line with what was
      implemented;
   7. add the item's line(s) to a `### 3.0.0` section at the top of `src/changelog.md`;
   8. for each new doc page, add a `docs/lib/DocMap.php` entry and its `docs/mds/index.md` row,
      inside the existing categories. Changing categories needs Paulo (`docs/CLAUDE.md`);
   9. tick the item's checkbox.
3. **Breaking changes are allowed.** When a change makes sense for 3.0 but breaks legacy code,
   implement it, with no compatibility shim, and document it in the migration guide: how to detect
   it (a `grep` or a command) and what to change.
4. **Git.** Don't commit, push or run `scripts/release.sh`. Never run `git stash` or
   `git checkout -- <file>` on the working tree. Until item 3 deletes it, the suite rewrites a
   timestamp in the tracked `src/Tests/MagratheaTests/test-dump/magrathea-object-test.conf`: leave
   that diff alone.
5. **Environment.** The local PHP CLI has no `mysqli`, no MySQL and no `mysqldump`. Test database
   behavior with:
   - `DatabaseSimulate` (`Database::Instance()->Mock()`);
   - recording `Database` subclasses installed with `Database::MockClass()` (see
     `modelTimestampsTest.php`);
   - a fake mysqli connection from an overridden `CreateConnection()` (see
     `FakeConnectionDatabase` in `databaseConnectionTest.php`);
   - an overridden process runner, `RunProcess()` (see `adminBackupsTest.php`);
   - the CLI run in-process through `Console::Run()` (item 24).

   List anything that can't be covered this way in the final report.
6. **Contradictions.** If the code contradicts this plan, stop and report it instead of
   improvising.

## What already exists

3.0 builds on these. Reuse them; don't rewrite them:
- **The process runner and the mysqldump credentials** (`Admin/AdminDatabase.php`). `RunProcess()`
  runs a command through `proc_open()` with an argument array, so there's no shell.
  `GetDefaultsFileContent()` writes the credentials to a temporary `[client]` defaults file (`0600`,
  deleted afterwards), so the password never reaches the command line. `ValidateBackupFileName()`
  accepts only plain `.sql` names. All three move into `DB\Dump` in item 17.
- **`Admin\AdminFiles::ResolveInFolder($folder, $name)`** resolves a plain file name inside a folder,
  or returns `null`. The logs and backups views use it, and so will the Dumps page (item 21).
- **Escaping in the admin.** `AdminElements::Table()` and the form elements escape every value;
  callable columns and callable row values are trusted HTML. Every new admin page renders through
  them.
- **Admin dispatch rules.** `AdminFeature::IsDispatchable()` lets URLs reach only a feature's own
  public, non-static methods with no required parameters. Writing actions answer 405 to anything
  but POST (`AdminCrudObject::RefuseIfNotPost()`). New admin actions follow both rules.
- **`AdminCsrf`** (`GetToken()`/`Validate()`): the CSRF token for admin and wizard forms.
- **No short open tags.** `shortOpenTagTest.php` fails if a file in `src/` opens with `<?`. New files
  use `<?php`, including `bin/magrathea` and the skeleton files, which that test doesn't scan.

## Shared conventions for the new code

Several items rely on these. Keep them identical everywhere:
- **Findings.** Every check returns a list of findings shaped like `TimezoneCheck::Run()`'s:
  `["level" => …, "message" => "…"]`, with the levels `TimezoneCheck::OK`, `INFO`, `WARNING` and
  `ERROR`. Optional keys: `group` (`config`, `folders`, `database`, `timezone` or `schema`),
  `table`, `model`, `field`, and `fix` (the SQL that fixes the finding: shown, never run
  automatically).
- **Service classes.** `DB\Schema`, `DB\SchemaCheck` and `DB\Dump` are instance classes. Their
  constructor takes an optional `Database` (default `Database::Instance()`), so tests can pass a
  fake. `ProjectCheck::Run()` and `Admin\QueryEditor::Build()` are static.
- **Read-only SQL.** The Queries page (item 19) and `db:query` (item 26) treat SQL as read-only only
  when `DB\SqlSplitter` (item 17) finds exactly one statement, starting with `SELECT`, `SHOW`,
  `DESCRIBE`, `DESC` or `EXPLAIN`. Anything else is a write: the admin asks for confirmation, and
  the CLI needs `--write`.

---

## Group S — Security (do it first)

### 0. `GetById()`: SQL injection through integer primary keys
- [x] done

**Error.** `MagratheaModel::GetById()` builds its WHERE clause by hand
(`src/MagratheaModel.php:241-245`):
- string PK: `… = '".Database::Instance()->Escape($id)."'"`, escaped and quoted, so it's safe;
- int PK: `… = ".$id`, concatenated raw.

The id comes straight from the URL. `MagratheaApiControl::Read()` and `Delete()` run
`new $this->model($id)` with the `:id` segment, and `MagratheaApi::GetParamsFromRoute()` doesn't
validate it. The query runs through `queryRow()`, not a prepared statement. So on any model with an
int PK, `GET /articles/0 UNION SELECT …` runs the attacker's SQL (verified with a recording
database; the API's `strtolower()` doesn't stop it). Every 2.x release has it: 2.3.4's SQL-injection
fix escaped the string branch and left this one.

**Solution.**
- Int PK: reject an id that isn't an int or a string of digits (`/^-?\d+$/`) with
  `throw new MagratheaModelException("Invalid id for ".get_class($this).": [".$id."]", 400);`.
  The code `400` makes the API answer HTTP 400 (`MagratheaApi::HttpStatusFromCode()`); with the
  default code `0` it would answer 500. Then build the clause with the cast id:
  `$sql->Where($this->dbTable.".".$this->dbPk." = ".(int)$id);`. Without the check, `5abc` would
  quietly load row 5.
- String PK: unchanged.
- The autoload path and the plain path share `$sql`, so this is the only edit in `GetById()`.
  `Update()` and `Delete()` already bind the PK through `PrepareAndExecute()`.
- Keep the table-qualified column (`<table>.<pk>`): the autoload path joins other tables.
- Write the check so that `MagratheaApiControl::Update()` can reuse it (item 11).

**Tests** (`sqlInjectionTest.php`, with a recording database): an injection payload and `5abc`
throw with code 400 and run no query; `5` and `"5"` build `… = 5`; a string-PK model still escapes.

**Impact** (M0). Valid ids behave as before. Any other id on an int-PK model (letters, spaces, a
decimal point) throws, and the API answers HTTP 400, instead of a SQL error or an injection.
Changelog: `**fix:**`.

### 1. Admin login and sessions
- [x] done

**Error.**
- `AdminUsers::Login()` stores the whole `AdminUser` object in the session with `serialize()`
  (`Admin/AdminUsers.php:28`), and `GetLoggedUser()` unserializes it on every admin request
  (`:34-40`). Two problems:
  - the serialized text carries the class name. Item 2 renames every class, so at deploy each live
    admin session unserializes into `__PHP_Incomplete_Class`. `AdminManager::GetLoggedUser()`
    declares `AdminUser|null` (`AdminManager.php:236`), so every admin page throws a `TypeError`
    until that session ends (verified);
  - `Admin::Auth()` runs `IsAdmin()` on that stored copy (`Admin.php:309-311`), so a role change
    applies only after the admin logs out and back in.
- `AdminUserControl::Login()` (`Features/User/AdminUserControl.php:34-48`) never checks `active`: a
  deactivated user still logs in.
- It answers "User not found" for an unknown email and "Password incorrect" for a known one. The
  admin login page shows that message (`views/login.php:18`), and `MagratheaApiAuth::AdminUserLogin()`
  sends it with its 403 (`MagratheaApiAuth.php:45-48`), so anyone can find out which emails belong
  to admins.
- Nothing in `src/` calls `session_regenerate_id()`, so the session id survives the login: session
  fixation.

**Solution.**
- The session stores only the user id. `Login()` calls `session_regenerate_id(true)` (when a session
  is active), then writes `(int)$user->id`. It keeps regenerating the CSRF token, as today.
- `GetLoggedUser()` loads the user from the database once per request (cached on the `AdminUsers`
  instance). It returns `null`, and removes the session entry, when the stored value isn't an int,
  the user doesn't exist, or the user isn't `active`. A 2.x session holds serialized text, so it's
  simply logged out.
- `AdminUserControl::Login()` refuses inactive users. Every failure (unknown email, wrong password,
  inactive user) returns the same message, "Invalid email or password", with `"user" => null`. The
  unknown-email path still runs bcrypt once (`password_hash($password, PASSWORD_BCRYPT)`, result
  discarded), so the response time doesn't give it away either. Hashing costs the same as a
  `password_verify()` at the default cost, with no hard-coded dummy hash in the code. Hashes stored
  at an older cost (10, before PHP 8.4) still verify faster than that; rehashing them on login
  isn't part of this item.
- `MagratheaApiAuth::AdminUserLogin()` doesn't change: it already forwards the message with its 403.
- Tests (new `adminLoginTest.php`, with a recording database and a session array):
  - a login stores an int id in the session;
  - `GetLoggedUser()` reloads the user, so a role change shows on the next request;
  - it returns `null` and clears the entry for a serialized object (a 2.x session), a missing user
    and an inactive user;
  - an unknown email, a wrong password and an inactive user get the same message and
    `"user" => null`, from `Login()` and through `AdminUserLogin()`.

**Impact** (M1).
- Admins logged in when 3.0 is deployed are logged out once.
- Deactivated users can't log in, and lose access on their next request. Role changes also apply on
  the next request.
- Every failed login answers "Invalid email or password". Clients or tests that matched the old
  messages change.
- Code that read `$_SESSION["magrathea_user"]` itself gets an id instead of a serialized
  `AdminUser`.

---

## Group R — The rename

### 2. `Magrathea2` → `Magrathea`
- [x] done

**Error.** The version number got into the names: the namespace `Magrathea2`, the package
`platypustechnology/magratheaphp2`, the product name "MagratheaPHP2". Every major version after 2
carries the wrong number, and the namespace is written into every project's code.

**Solution.** 3.0 uses:
- the namespace `Magrathea`. Class and function names don't change: `Magrathea2\MagratheaModel`
  becomes `Magrathea\MagratheaModel`, and `\Magrathea2\p_r()` becomes `\Magrathea\p_r()`;
- the package `platypustechnology/magratheaphp`;
- the product name "MagratheaPHP";
- the repository `PlatypusTechnology/MagratheaPHP3` and the docs at
  `https://www.platypusweb.com.br/magratheaphp3/docs/`. These names are per major version, and no
  project's code contains them. `MagratheaPHP2` and `/magratheaphp2/` stay for 2.x.

Do it as a text substitution, **line for line**: later items cite line numbers, so don't add or
remove lines.
- In `src/`, `docs/`, `skills.MD`, `instructions.MD`, `README.md` and `composer.json`:
  - `Magrathea2` → `Magrathea` everywhere: `namespace` declarations, `use` lines, fully-qualified
    names, class names inside strings (`Debugger.php:363`, the `$modelNamespace`/`$modelName`
    strings of the framework's own Controls, `Admin/Api/AdminApi.php:41`), comments and test
    assertions (`debuggerTest.php:310`);
  - `platypustechnology/magratheaphp2` → `platypustechnology/magratheaphp`, vendor paths included;
  - `platypusweb.com.br/magratheaphp2` → `platypusweb.com.br/magratheaphp3`
    (`MagratheaPHP::GetDocumentationLink()` at `MagratheaPHP.php:267`, `README.md`, `skills.MD`,
    the docs site's views);
  - `github.com/PlatypusTechnology/MagratheaPHP2` → `github.com/PlatypusTechnology/MagratheaPHP3`
    (`AdminMenu.php:188`);
  - the product name: `MagratheaPHP2` → `MagratheaPHP`, `Magrathea PHP 2` → `Magrathea PHP`, and
    `PHP2` → `PHP` in the `####` file banners.
- Leave alone what documents 2.x: `docs/mds/migrations/migrate-2.4.0.md`, the `### 2.x` sections of
  `src/changelog.md`, and `future-plans/`.
- `composer.json`: `"name"`, the `psr-4` prefix (`"Magrathea\\": "src/"`), `"description"` and
  `"homepage"`. Then run `composer dump-autoload`: the tests and the docs site load classes through
  `vendor/autoload.php`.
- `docs/lib/DocMap.php`'s `FqcnFromSrc()` builds `Magrathea\…` names: the substitution covers it,
  but check it.
- Check:
  - `grep -rnE "Magrathea2|magratheaphp2|MagratheaPHP2" src docs skills.MD instructions.MD README.md composer.json`
    finds only the 2.x history left alone above;
  - the full suite passes with the same counts;
  - a class page of the docs site renders, which proves reflection loads the renamed classes (for
    example, `php -S 127.0.0.1:8080 -t docs` in the background, then `curl` the page).
- Docs: the install command and the name in `getting-started.md` and `index.md`.

**Impact ⚠** (M2).
- Every project file that names a framework class or function changes: `use Magrathea2\…`,
  `\Magrathea2\p_r()`, `use function Magrathea2\now`, class names in strings. So does the package in
  `composer.json`. There's no compatibility layer: PHP can alias classes (`class_alias()`) but not
  functions.
- `ToJson()` puts `get_class()` in its `"object"` key, so that value changes in API JSON for the
  framework's own models (`AdminUser`, `AppConfig`, `AdminLog`).
- Live admin sessions are handled by item 1.

---

## Group A — Removals

### 3. The code generator
- [x] done

**Error.** 3.0 doesn't generate code. The object editor and the code generator are a whole
subsystem built around `magrathea_objects.conf`.

**Solution.**
1. **First, save the SQL → Magrathea type mapping.** Create `src/DB/Schema.php`
   (`Magrathea\DB\Schema`) with only a static `MagratheaType(string $columnType): ?string`, which
   replaces `ObjectManager::GetType()` (`ObjectManager.php:498-537`). The old one matches only the
   first three letters, so `tinytext` is a `boolean`, `time` a `datetime`, and `smallint` or
   `char(2)` `unknown`. The new mapping (case-insensitive, ignoring `unsigned` and display widths
   except `tinyint(1)`/`bit(1)`):

   | Column type | Magrathea type |
   |---|---|
   | `tinyint(1)`, `bit(1)` | `boolean` |
   | `tinyint`, `smallint`, `mediumint`, `int`, `bigint`, `year` | `int` |
   | `decimal`, `numeric` | `decimal` |
   | `float`, `double`, `real` | `float` |
   | `char(36)` | `uuid` (a suggestion: the column may hold other strings) |
   | `char`, `varchar`, `enum`, `set`, `time` | `string` |
   | `tinytext`, `text`, `mediumtext`, `longtext`, `json` | `text` |
   | `date` | `date` |
   | `datetime`, `timestamp` | `datetime` |
   | anything else (blobs, binary, spatial types) | `null`: no suggestion |

   Item 14 completes the class.
2. **Delete:**
   - the classes `src/Admin/CodeCreator.php`, `CodeManager.php`, `ObjectManager.php`,
     `Api/ObjectsApi.php` and `Api/AdminConfigApi.php`. `AdminConfigApi`'s only method,
     `SaveConfig()`, returns `CodeManager::GetCodeCreationData()`, and only `code-creation.js`
     calls it;
   - the pages `views/pages/objects-config.php`, `objects-create.php`, `objects-edit.php`,
     `objects-view.php` and `generate-code.php`;
   - the actions `views/actions/object-create.php`, `object-creation-save.php`, `object-detail.php`,
     `object-edit.php`, `object-list.php`, `object-relation-edit.php`, `object-relation-form.php`,
     `object-relations.php`, `object-remove.php`, `code-create.php`, `code-gen-view.php` and
     `tables-list.php` (only `objects-create.php` includes it);
   - the scripts `views/javascript/object-creation.js` and `code-creation.js`;
   - from the menu: `AdminMenu::GetObjectSection()` (`AdminMenu.php:114-125`) and its items
     `objects-create`, `objects-conf`, `objects`, `objects-edit` and `code-gen` (`:282-286`);
     `objects-create` in `GetDatabaseSection()` (`:106`); the `GetObjectSection()` call in
     `Admin::AddMagratheaMenu()` (`Admin.php:264`);
   - in the Bootstrap: `src/Bootstrap/views/steps/code-generate.php` (already unreachable) and
     `db-load-magrathea-objects.php`, with its include in `db-run.php:20`;
   - the keys `code_path`, `code_structure` and `code_namespace` in `Install::SetBasicConfigs()`
     (`Install.php:97-99`). Only `CodeCreator` reads them;
   - `magrathea_getTypesArr()` (`_Functions.php:70-73`, no callers; item 7 adds
     `MagratheaModel::TYPES`);
   - `AdminElements::JsCallback()` (`AdminElements.php:138`): only the deleted views call it;
   - the dead `use Magrathea\Admin\ObjectManager;` lines in `views/pages/htaccess.php:4` and
     `caddy.php:4`;
   - the tests `codeCreatorTest.php` and `objectManagerTest.php`, and the fixture
     `test-dump/magrathea-object-test.conf`;
   - in `adminEscapingTest.php`: the `ObjectManager` setup in `setUp()` (`:5`, `:34-36`), and
     `testJsCallback()`, `testObjectDetailGetParams()` and `testObjectEditGetParams()`
     (`:142-178`). Its `Table()`, form and `query-run-card` tests stay.
3. Grep `src/`, `docs/`, `skills.MD` and `docs/data/examples.php` for
   `CodeCreator|CodeManager|ObjectManager|magrathea_objects|objects-|code-gen|GetObjectSection|JsCallback`
   and clean every hit. (`appConfigTest.php` uses `code_structure` only as sample data for a parser
   test: leave it.)
4. Docs: `admin/admin.md` loses its "Objects and the code generator" and "Code structure"
   subsections; `admin/admin-features.md` loses the `JsCallback()` paragraph (`:294`) and the
   "what the admin's code generator writes" comment (`:96`); `database/orm-model.md:134-139` and
   `core/config.md:229` lose their generator mentions.

**Impact** (M3).
- Generated files keep working: they're plain PHP.
- Nothing reads `magrathea_objects.conf`, nor the `_magrathea_config` rows `code_path`,
  `code_structure` and `code_namespace`.
- The generated files' "FILE GENERATED BY MAGRATHEA … can be overwritten through the admin" header
  is now false, and it tells agents not to edit the file. The migration removes it.
- Calls to the removed classes, `GetObjectSection()` or `JsCallback()` fail. `GetItem()` with a
  removed key returns `null`, which `AdminMenu::Add()` ignores.

### 4. The File Editor
- [x] done

**Error.** 3.0 doesn't edit files from the browser. The File Editor is already opt-in
(`Admin::LoadFileEditor()`).

**Solution.**
- Delete:
  - `src/Admin/Features/FileEditor/` (`AdminFeatureFileEditor.php`, `editor.php`, `index.php`,
    `scripts.js`, `styles.css`);
  - `Admin::LoadFileEditor()` (`Admin.php:164-171`) and its mention in the docblock at `:137`;
  - the "Tools" block in `AddMagratheaMenu()` (`:282-285`);
  - the menu item `tools-file-editor` (`AdminMenu.php:290`).
- Tests: in `MagratheaAdminTests/adminFeaturesTest.php`, delete `FileEditorOptInAdmin` and
  `testFileEditorOptIn()` (the two "no File Editor by default" tests can stay as regression checks);
  in `adminMenuTest.php`, delete the `tools-file-editor` assertions (`:37-46`).
- Docs: `admin/admin.md` (the features table row at `:314`, "Enabling the File Editor"),
  `admin/admin-features.md` ("File Editor Feature"), and `skills.MD` (`:1043`, `:1319`).

**Impact** (M4). An `Admin` subclass that calls `$this->LoadFileEditor()` dies with "Call to
undefined method" when the admin loads.

### 5. Deprecated and dead APIs
- [x] done

**Error.** Deprecated or dead methods still in the code:

| Symbol | Where | Why it goes |
|---|---|---|
| `MagratheaModelControl::ShowAll()` | `MagratheaModelControl.php:314` | debug helper that echoes an HTML table; `@deprecated 2.4.0` |
| `MagratheaPHP::LoadVendor()` | `MagratheaPHP.php:253` | `@deprecated 2.4.0`; the class can't load before `vendor/autoload.php` anyway |
| `Query::Clean()` | `DB/Query.php:99` | `@deprecated 2.4.0`; `Database::Escape()` replaces it |
| `MagratheaApi::Start()` | `MagratheaApi.php:91` | `@deprecated`, no callers |
| `MagratheaApiAuth::ResponseUserPayload()` | `MagratheaApiAuth.php:61` | `@deprecated`, no callers |
| `Database::CloseConnectionThanks()` | `DB/Database.php:255`, `DB/DatabaseSimulate.php:145` | a no-op since 2.4.0 |
| `MagratheaModel::IncludeAllModels()` | `MagratheaModel.php:596` | legacy: includes `<site_path>/Models/*.php` |
| `MagratheaModel::GetNextID()` | `MagratheaModel.php:275` | racy (`SHOW TABLE STATUS`, which MySQL 8 may answer from cached statistics); the id `Insert()` returns replaces it |
| `MagratheaModel::GetAutoLoad()` and `$autoLoad` | `MagratheaModel.php:38`, `:121` | always `null`: `GetById()` reads `$autoload` (item 6) |

`MagratheaPHP::StartDB()` (`MagratheaPHP.php:198`), an alias of `Connect()`, stays in 3.0: it gets
`@deprecated 3.0` and a `Debugger` notice in DEV, and goes in 4.0.

**Solution.**
- Delete the symbols in the table and fix their callers:
  - `CloseConnectionThanks()`: `MagratheaApi::HealthCheck()` (`:771`),
    `Admin/views/actions/database-test.php:14`, `Bootstrap/views/actions/database-test.php:14`,
    `DatabaseSimulate`'s own calls (`:253-388`), and `databaseConnectionTest.php:251` and `:260`;
  - `StartDB()`: `Admin/Install.php:107` switches to `Connect()`. The text in
    `Bootstrap/views/steps/db-query.php:17` goes with the old wizard in item 28.
- Tests: delete `modelControlShowAllTest.php`, `sqlInjectionTest.php`'s
  `testCleanEscapesBackslashes()` (`:75-82`), and `autoloaderTest.php`'s
  `testLoadVendorFindsAutoload()` (`:209`).

**Impact** (M5). Each removed method is a fatal error where it's still called, but only when that
line runs. So the migration greps all PHP (views, cron jobs, CLI scripts), not just classes.

---

## Group B — The model contract

Without the generator, agents write models by hand. `MagratheaModel` and `MagratheaModelControl`
provide what the generator used to write into every Base class, so a minimal hand-written model
works.

New code uses **one class per model and one per control**, extending `MagratheaModel` and
`MagratheaModelControl` directly. The generated Base layer existed only so that regenerating
wouldn't overwrite hand-written code. Existing Base-split code keeps working.

### 6. `MagratheaModel` defaults
- [x] done

**Error.** A hand-written model has to repeat the generator's boilerplate, or it breaks in ways
that don't explain themselves (all four verified):
- **`$autoload`**: `MagratheaModel` declares `$autoLoad` (`:38`), but `GetById()` reads
  `$this->autoload` (`:246`). A model without its own `protected $autoload` fails on
  `new Model($id)` with "Property autoload does not exists" (`__get()` → `Get()`, `:471-491`).
- **The timestamp properties**: every Base class declared `public $created_at, $updated_at;`.
  Without them, `ToJson()` throws "Property created_at does not exists", and
  `LoadObjectFromTableRow()`, which only sets properties that exist, never loads them.
- **The constructor**: the generated `__construct($id = 0)` ran `MagratheaStart()` and loaded `$id`
  (`CodeCreator.php:234-243`). Without it, `new Model(5)` silently loads nothing.
- **`GetControl()`**: there's no default, and `AdminCrudObject::GetField()`
  (`Features/CrudObject/AdminCrudObject.php:168-171`) calls it for every belongs_to field. A model
  without it is a fatal error in the CRUD form.

**Solution.** On `MagratheaModel`:
- `public function __construct($id = 0)`: runs `$this->MagratheaStart()`, then, if `!empty($id)`,
  sets the PK and calls `GetById($id)`, exactly as the generated constructor did. Subclass
  constructors still override it.
- `public function MagratheaStart() {}`: a no-op hook. Models set `$dbTable`, `$dbPk` and
  `$dbValues` there, or as property defaults.
- `protected $autoload = null;`, replacing `$autoLoad` (removed in item 5).
- `public $created_at = null, $updated_at = null;`. They must be `public`: API responses
  `json_encode()` model objects, which serializes only public properties. New models don't list
  them in `$dbValues`, because `Insert()` and `Update()` handle both by name (items 10 and 11).
  Subclasses that redeclare them as `public`, or list them in `$dbValues` (generated models), keep
  working.
- `public function GetControl()`: returns an instance of the class named `static::class."Control"`,
  the convention `AdminCrudObject::SetObject()` already uses. If that class doesn't exist, it throws
  `MagratheaModelException` naming the class and saying to declare `GetControl()`. Generated Base
  classes keep their own.
- **`MagratheaModel` doesn't implement `iMagratheaModel`.** The interface declares
  `__construct($id)`, so implementing it would force that signature on every subclass constructor:
  a hand-written `__construct()` would be a fatal error at class load. Nothing in `src/` checks
  `instanceof iMagratheaModel`. The interface gets `@deprecated 3.0` (removed in 4.0): nothing in
  the framework implements it any more, and it stays only so 2.x generated Base classes, which
  declare `implements iMagratheaModel`, still load.
- The framework models `AdminUser`, `AppConfig` and `AdminLog` rename `Start()` to
  `MagratheaStart()` and drop their constructors (`AdminUser.php:29-36`, `AppConfig.php:29-36`,
  `AdminLog.php:29-36`), their `$created_at`/`$updated_at`/`$autoload` declarations and
  `implements iMagratheaModel`: they're the reference models, so they declare only what the base
  class doesn't provide.

**Tests** (new `modelDefaultsTest.php`, with a recording database):
- a model with only properties and `MagratheaStart()` (no constructor, `$autoload`, timestamps or
  `GetControl()`) loads by id, and `ToJson()` works;
- `GetControl()` returns an instance of `<FQCN>Control`; a missing control throws with its name in
  the message;
- the legacy shape (its own constructor, `$autoload`, timestamps and `GetControl()`) behaves as
  before;
- the framework models still load.

**Impact** (M6).
- A model class with no constructor in its hierarchy that's instantiated with an argument
  (`new X($v)`) now loads row `$v`; before, the argument was ignored.
- A model without `GetControl()` gets `<FQCN>Control`, or an exception naming it, instead of
  "undefined method".
- Some redeclarations in a subclass are now fatal errors at class load (all verified):
  `$created_at`/`$updated_at` as `protected` or `private`; `$autoload` as `private`; any of the
  three with a type (`public ?string $created_at`: PHP forbids adding a type to a property the
  parent declares untyped); `MagratheaStart()` or `GetControl()` as non-public or `static`. That's
  why the base declarations stay untyped: typing them would break every generated model.

### 7. Field types: one list, unknown types throw
- [ ] done

**Error.**
- `GetDataTypeFromField()` (`MagratheaModel.php:553-573`) has no `default`. An unknown type returns
  `null`, and `PrepareAndExecute()` binds it as a string.
- The framework's own `AdminUser` declares `"active" => "bool"` (`AdminUser.php:47`), which isn't a
  type. It works only because MySQL converts the string.
- Without the generator's select, agents type these strings by hand, and the only list
  (`magrathea_getTypesArr()`, deleted in item 3) lacked `uuid`.

**Solution.**
- `const TYPES = ["int", "boolean", "string", "text", "float", "decimal", "datetime", "date", "uuid"];`
  on `MagratheaModel`. The aliases `integer` and `double` stay accepted: `GetDataTypeFromField()` and
  `CoerceValue()` already handle them.
- `GetDataTypeFromField()` gets a `default` that throws
  `MagratheaModelException("Unknown field type [x]: use one of …")`. `Insert()`, `Update()` and
  `Delete()` let it propagate, and the drift check (item 15) reports it with the model and field.
- `AdminUser`: `"active" => "boolean"`.
- Tests: an unknown type throws on `Insert()` and `Update()`; the aliases work; every framework
  model's `$dbValues` uses only `TYPES`.

**Impact** (M7). A model with an unknown type (`"bool"`, `"varchar"`, `"tinyint"`, `"json"`, a
typo) throws on its first write.

### 8. Controls: `$model` and a derived `$dbTable`
- [ ] done

**Error.** A Control repeats what its model already knows: `$modelNamespace`, `$modelName` and
`$dbTable` (`MagratheaModelControl.php:27-29`). The generator kept them in sync; an agent has to
remember to. A Control whose `$dbTable` differs from its model's makes `GetAll()`, `GetWhere()` and
`GetListPage()` read a different table than `GetById()`.

**Solution.**
- `protected static $model;`, a class name (`Article::class`). `GetModelName()` returns it when it's
  set, else `$modelNamespace."\\".$modelName` as today.
- A new `public static function GetDbTable()`: `static::$dbTable` when it's set, else the table of
  `new (static::GetModelName())()` (no id, so no query), cached per `static::class`. Every internal
  read of `static::$dbTable` goes through it (`:214`, `:224`, `:247`, `:262`, `:277`, `:290`).
- Tests: a Control with only `$model` queries the model's table; the three-property form is
  unchanged; an explicit `$dbTable` wins.

**Impact.** None: it's additive.

### 9. `$strictTypes` defaults to `true`
- [ ] done

**Error.** With `$strictTypes = false` (`MagratheaModel.php:45`), every `int`, `boolean` and `float`
field comes back as a string. Code and tests written by agents assume native types, and the docs
already recommend `true` for new models.

**Solution.**
- `protected $strictTypes = true;`.
- Audit the admin for string comparisons of model fields (`=== "1"`, `== "0"`) and fix them: the
  framework models become strict.
- Tests: the strict-types tests flip their default case.

**Impact ⚠** (M9). Every model that doesn't declare `$strictTypes` returns typed values
(`"42"` → `42`, `"1"` → `true`), which changes API JSON and `===` comparisons. The migration pins
`protected $strictTypes = false;` on every existing model, then diffs API snapshots to catch a
missed one.

---

## Group C — Timestamps owned by the database

The database owns `created_at` and `updated_at`: one clock, MySQL's. The framework never sends
PHP's `now()` and never invents a value on the object. After a write, the object holds either a
value it sent itself or `null`, which means "not read: reload to get the database's value".

The model never reads the row back after a write, since a `SELECT` per write is too much for bulk
saves. Only `MagratheaApiControl::Create()` and `Update()` reload, so API responses carry the real
values.

`Insert()` and `Update()` handle both columns by name, whether or not a model lists them in
`$dbValues`. Generated models list them; framework and new models don't.

### 10. `Insert()`: assigned timestamps are sent as they are
- [ ] done

**Error.**
- `CreateInsertQuery()` (`MagratheaModel.php:318-355`) replaces an assigned `created_at` or
  `updated_at` with PHP's `now()` (`:336-337`), so `InsertWithPk()` imports lose their dates. A
  model that doesn't list the columns in `$dbValues` never sends them at all.
- It then stamps both with `now()` on the object (`:348-349`). That's wrong whenever MySQL's clock,
  zone or default differs from PHP's.
- A `uuid` primary key is generated (`:322-326`) but then skipped as if it were auto-increment
  (`:328`), and `Insert()` overwrites it with `PrepareAndExecute()`'s return value (`:369-370`),
  which is `true` when there's no insert id. So `Insert()`, and `Save()`, on a uuid-PK model send no
  PK and leave `$id === true`. Only `InsertWithPk()` works.

**Solution.**
- Timestamps: the `$dbValues` loop skips `created_at` and `updated_at`. Afterwards, each of the two
  that's non-null on the object is sent as assigned (bound as a date), whether or not `$dbValues`
  lists it. A `null` one isn't sent, so the column's `DEFAULT` applies. Never `now()`.
- No read-back: remove `:348-349`. An unset timestamp stays `null` on the object.
- uuid PK: when the PK's type is `uuid`, `CreateInsertQuery()` sends it (generating it when it's
  empty, as today), and `Insert()` keeps it instead of assigning the insert id. `Insert()` returns
  the PK.
- `AppConfig::Insert()` returns `parent::Insert()`: it calls it and discards the result, so it
  returns `null` instead of the id.
- `MagratheaApiControl::Create()`:
  - drops `created_at` and `updated_at` from the request data, as it already drops `id`
    (`MagratheaApiControl.php:363`). Otherwise API clients could set them, now that `Insert()`
    sends assigned values;
  - returns a freshly loaded object, `new $this->model($m->GetID())`, so the create response
    carries the database's timestamps. That's one query, on the API path only.
- Tests (`modelTimestampsTest.php`, with a recording database):
  - assigned values are sent as they are, for a model that lists the columns in `$dbValues` and for
    one that doesn't;
  - unset ones aren't sent and stay `null` on the object, and no `SELECT` runs after the insert;
  - a uuid-PK model sends its PK and keeps it after `Insert()`;
  - `MagratheaApiControl::Create()` ignores posted timestamps, and reloads: a `SELECT` follows the
    insert.

**Impact** (M10).
- Code that assigned a timestamp before `Insert()` used to store `now()`; now it stores what it
  assigned.
- Right after a plain `Insert()`, an unset `$obj->created_at` is `null`, not `now()`. Reload the
  object, or read the API response.
- uuid-PK models can use `Insert()` and `Save()`.

### 11. `Update()`: the database owns `updated_at`
- [ ] done

**Error.**
- For a loaded object, `Update()` (`:397-449`) always includes `updated_at` (`:405`) and writes
  PHP's `now()` into it (`:429-430`), when the model lists it in `$dbValues`. That's two clocks, and
  it always overrides `ON UPDATE CURRENT_TIMESTAMP` or a `BEFORE UPDATE` trigger.
- The `now()` goes only into the query, so the object keeps reporting the stale loaded value.
- An unchanged loaded object still runs an `UPDATE` that sets only `updated_at`. Without
  `updated_at`, partial updates would produce an empty `SET`, which is a SQL error.

**Solution.**
- `Update()` never writes `created_at` or `updated_at`, whether the object is loaded or not, and
  whether `$dbValues` lists them or not. `ON UPDATE CURRENT_TIMESTAMP` sets `updated_at`; explicit
  values go through `Query::Update()`. `Set()` and `Assign()` keep ignoring both (`:510`).
- No field to write means no query, and `Update()` returns `true`.
- When a query ran, set `$this->updated_at = null` before `SavedState()`, so the object never
  reports the stale value. `created_at` stays as it is (an update doesn't change it). When no query
  ran, `updated_at` stays too: the row didn't change.
- `AppConfig::Update()` returns `parent::Update()` (it returns `null`, as `Insert()` does in item 10).
- Docs: once this item and item 10 are done, `created_at`/`updated_at` are safe to leave out of
  `$dbValues`, so `database/orm-model.md`'s examples drop them (item 6 left them in).
- `MagratheaApiControl::Update()` checks the id with item 0's rule **before** writing (int-PK models),
  so a malformed id fails with 400 instead of updating the row its integer cast points to. After
  the write, it returns a freshly loaded object (`new $this->model($id)`), so the response carries
  the new `updated_at`.
- Tests (`modelPartialUpdateTest.php`, `modelTimestampsTest.php`):
  - an unchanged loaded object runs no query and keeps its `updated_at`;
  - a changed field writes only that field, and timestamps are never in the `SET`;
  - after a write, `updated_at` is `null`, `created_at` is unchanged, and no `SELECT` follows;
  - `MagratheaApiControl::Update()` refuses a malformed id before writing, and reloads after a
    write.

**Impact ⚠** (M11).
- Tables whose `updated_at` has no `ON UPDATE CURRENT_TIMESTAMP` (and no trigger) stop updating it
  after the insert. Item 15's check lists them with the `ALTER` that fixes them, and the migration
  applies them.
- `Save()` on an unchanged object no longer bumps `updated_at`: use `Touch()` (item 12).
- `$obj->updated_at = …; $obj->Save();` no longer writes the assignment.
- Right after a `Save()` that changed something, `$obj->updated_at` is `null`. It used to be the
  stale loaded value.

### 12. `Touch()`
- [ ] done

**Error.** MySQL fires `ON UPDATE` only when a column value actually changes, so after item 11
nothing can bump `updated_at` on purpose ("last seen", "touch the parent").

**Solution.**
- `public function Touch(): bool` runs `UPDATE <table> SET updated_at = CURRENT_TIMESTAMP WHERE
  <pk> = ?` through `PrepareAndExecute()`, binding the PK by its type as `Delete()` does. Then it
  sets `$this->updated_at = null`: MySQL computed the value, so the object has to reload to know it.
- An object without a PK throws `MagratheaModelException`.
- Tests: the SQL and its binding; no PK throws; `updated_at` is `null` afterwards.

**Impact.** None: it's new.

### 13. The timestamp schema
- [ ] done

**Error.**
- Every table a model maps needs `created_at` and `updated_at`, but nothing says so.
  `GetProperties()` (`MagratheaModel.php:69-74`) adds both to every SELECT built from a model
  (`GetById()`, `Query::Obj()`), and `GetAll()`, `GetListPage()` and `GetWhere()` sort by
  `created_at` (`MagratheaModelControl.php:214`, `:224`, `:262`).
- After item 11, `updated_at` also needs `ON UPDATE CURRENT_TIMESTAMP`.
- `src/Admin/database.sql` mixes types: `created_at` is `TIMESTAMP` and `updated_at` is `DATETIME`
  (`:7-8`, `:14-15`, `:29-30`, `:40-41`).
- `_magrathea_users.last_login` is a bare `timestamp` (`:26`). With
  `explicit_defaults_for_timestamp=OFF` (MySQL 5.7's default), a table's first bare `TIMESTAMP`
  column gets `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so `last_login` changes on
  every update of the row.

**Solution.**
- `database.sql`, for new installs:
  - `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`;
  - `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`;
  - `last_login DATETIME NULL`.

  `DATETIME` has no 2038 limit and no session-zone conversion. The session zone is synced to PHP's,
  so `CURRENT_TIMESTAMP` is in PHP's zone. Existing `TIMESTAMP` columns aren't converted: both types
  work.
- Document the table contract in `database/orm-model.md` (and in `skills.MD`, item 30): every table
  a model maps has both columns, with those defaults (`TIMESTAMP` works too).
- Item 15's check finds the tables that break the contract.

**Impact** (M13). None in code. Existing installs whose `_magrathea_users.last_login` picked up
`ON UPDATE` get an optional fix in the migration.

---

## Group D — The service layer

The admin (Group E), the CLI (Group F) and the Setup Wizard (Group G) are thin layers over these
classes. They return arrays and findings (see "Shared conventions") and never print.

### 14. `DB\Schema`: introspection
- [ ] done

**Error.** The admin's table helpers are thin and duplicated: `AdminDatabase::GetTables()` and
`GetTableColumns()` (`AdminDatabase.php:44-60`, `:104-120`), plus a third copy in the Bootstrap's
`show-tables` action. Agents can't get the schema as data.

**Solution.** Complete `Magrathea\DB\Schema` (created in item 3). It's read-only:
- `Tables(bool $withFramework = true)`: rows from `information_schema.TABLES` (name, engine,
  approximate rows, collation, comment). `false` leaves out the framework's `_magrathea_*` tables,
  as `GetTables(false)` did;
- `Columns($table)`: `information_schema.COLUMNS` (name, column type, nullable, default, extra, key);
- `Indexes($table)`: `information_schema.STATISTICS`;
- `CreateStatement($table)`: `SHOW CREATE TABLE`, with the name checked against `Tables()` and
  backtick-quoted;
- `MagratheaType($columnType)` (item 3).

Values in the `information_schema` queries go through `Database::Escape()`. An unknown table throws.
- Remove `AdminDatabase::GetTables()` and `GetTableColumns()`. Their only remaining caller,
  `views/actions/tables-view.php` (the Queries page's table list), switches to `Schema::Tables()`
  in this item.
- Tests: the SQL, through a recording database; the type mapping; an unknown table is rejected.
- Docs: new `docs/mds/database/schema.md` (DocMap `database`; src `DB/Schema.php`,
  `DB/SchemaCheck.php`).

**Impact** (M14). Code calling `AdminDatabase::GetTables()` or `GetTableColumns()` moves to
`Schema`.

### 15. `DB\SchemaCheck`: model discovery, drift and timestamps
- [ ] done

**Error.** The generator was the only thing that kept a model in sync with its table. Without it, a
typo in `$dbValues` surfaces as a SQL error at runtime, or never: a column missing from `$dbValues`
is just not saved.

**Solution.** A new `Magrathea\DB\SchemaCheck`, whose constructor takes an optional `Schema`:
- `FindModels()`:
  - scans each folder in `MagratheaPHP::Instance()->codeFolder` for `*.php`, not recursively, like
    the autoloader;
  - reads the `namespace` and `class` declarations with `token_get_all()`, skipping abstract
    classes;
  - loads each candidate through the autoloader and keeps the subclasses of `MagratheaModel`;
  - skips a class when one of its subclasses was also found. A generated `ArticleBase` and its
    `Article` map the same table, so only `Article` is checked;
  - instantiates each with `new $class()` (no id, so no query) and reads its table, PK and
    `$dbValues` through `GetDbTable()`, `GetPkName()` and `GetDbValues()`;
  - reports a class that fails to load or instantiate as a finding, and never throws;
  - reports a WARNING naming the scanned folders when it finds no model at all, so an empty report
    isn't mistaken for a clean one.
- `CheckModel($class)`:
  - the table is missing: ERROR;
  - a `$dbValues` field is missing from the table: ERROR (writes fail). `created_at` and
    `updated_at` are skipped here, even when `$dbValues` lists them: the timestamp finding below
    owns them, so a legacy model doesn't get two ERRORs for one missing column;
  - a table column is missing from `$dbValues`: INFO (`created_at` and `updated_at` excluded: they
    don't belong in `$dbValues`);
  - `$dbPk` isn't the table's primary key: WARNING;
  - an unknown type (item 7): ERROR;
  - `float` on a `DECIMAL` column: WARNING (it rounds under strict types);
  - any other mismatch with `Schema::MagratheaType()`: WARNING (skipped when it returns `null`);
  - `created_at` or `updated_at` is missing from the table: ERROR (every SELECT built from the model
    fails).
- `CheckTimestamps($table)`, each finding with its fix in `fix`:
  - `created_at` without `DEFAULT CURRENT_TIMESTAMP`: ERROR when it's `NOT NULL` (inserts fail),
    WARNING when it's nullable (it stays `NULL`);
  - `updated_at` without `on update CURRENT_TIMESTAMP` in `EXTRA`: ERROR.

  The fix is an `ALTER TABLE … MODIFY …` that keeps the column's type and nullability and only adds
  `DEFAULT` or `ON UPDATE`.
- `CheckAll()`: `CheckModel()` and `CheckTimestamps()` for every model found, plus an INFO for each
  table that no model maps (except the framework's `_magrathea_*` tables).
- Tests: fixture models in a temporary code folder; fake schema rows; each finding; the
  Base/subclass dedup; the `ALTER` keeps type and nullability.
- Docs: `schema.md`.

**Impact.** None: it's new. The migration runs it (M11).

### 16. `ProjectCheck`: configuration and environment
- [ ] done

**Error.** The structure and configuration checks are scattered. The Bootstrap's `Structurate()`
and `ValidateStructure()` echo HTML (`Bootstrap/Start.php:171-200`), the admin's Structure page
checks folders inline (`views/pages/structure.php:25-78`), and `TimezoneCheck::Run()` stands alone.

**Solution.** A new static `Magrathea\ProjectCheck::Run(bool $withDatabase = true): array`, whose
findings carry a `group`:
- `config`: PHP ≥ 8.1; the `mysqli` extension; `proc_open()` for dumps (INFO when it's missing);
  the config file is readable; `use_environment` names an existing section; the active section has
  `db_host`, `db_name`, `db_user` and a valid `timezone`; `bootstrap_unlocked` set outside a `dev`
  environment: WARNING;
- `folders`: `logs_path`, `cache_path` and `backups_path` exist and are writable; on the web only,
  `configs/` isn't under `$_SERVER["DOCUMENT_ROOT"]` (the CLI can't know the web root);
- `database`: the connection works, and the admin tables exist (`_magrathea_config`);
- `timezone`: `TimezoneCheck::Run()`'s findings;
- `schema`: `SchemaCheck::CheckAll()`.

`$withDatabase = false` skips everything that connects: the `database`, `timezone` and `schema`
groups. The admin's Checks page (item 22), `magrathea check` (item 26) and the wizard (item 28) use
`Run()`.
- Tests: each finding, with `Config::SetConfig()` fixtures, temporary folders and a fake database.
- Docs: new `docs/mds/core/project-check.md` (DocMap `core`; src `ProjectCheck.php`).

**Impact.** None: it's new.

### 17. `DB\Dump` and `DB\SqlSplitter`: export and import
- [ ] done

**Error.**
- Backups are export-only: `AdminDatabase::DoBackup()` runs `mysqldump`, and nothing imports.
- `Database::ImportFile()` (`DB/Database.php:763-770`) passes the whole file to `QueryMulti()`,
  which splits it on `";\n"` (`:703`). A value containing `;\n` breaks its statement, `DELIMITER`
  blocks (triggers, procedures) break, and the whole file and every result stay in memory.
- `Database::Query()` splits multi-statement strings with a private `SplitQueries()` (`:447`) that
  knows quotes, but not comments, backticks or `DELIMITER`.
- Hosts with exec disabled get no dumps at all.

**Solution.**
- A new `Magrathea\DB\SqlSplitter` streams SQL from a file or a string, in chunks, and yields one
  statement at a time. It respects `'…'`, `"…"` and `` `…` `` with backslash escapes, `-- ` and `#`
  line comments, `/* */` blocks (keeping `/*!…*/` conditional comments, which are SQL),
  `DELIMITER`, and CRLF line ends. It replaces both `SplitQueries()` in `Query()` and the
  `explode(";\n")` in `QueryMulti()`.
- A new `Magrathea\DB\Dump` with `Export($file, array $options = [])` and
  `Import($file, array $options = [])`. Files live in `backups_path`, and names go through
  `ValidateBackupFileName()`. Options: `tables` (export only those), `driver` (`binary` or `php`),
  and `continue` (import: don't stop at the first error).
  - Moved here from `AdminDatabase`: `ValidateBackupFileName()`, `GetDefaultFileName()`,
    `GetDefaultsFileContent()` and `RunProcess()`. `RunProcess()` gains an optional stdin file (a
    `proc_open()` descriptor) for imports.
  - **The binary driver** is the default when `proc_open()` exists and `mysqldump`/`mysql` answer
    `--version`:
    - export: `mysqldump --defaults-extra-file=… --single-transaction --routines --triggers
      --default-character-set=utf8mb4 --result-file=… <db> [tables]`. `--defaults-extra-file` must
      stay the first option;
    - import: `mysql --defaults-extra-file=… <db>`, with the file as stdin; `continue` adds
      `--force`.
  - **The PHP driver** is the fallback, or `driver => "php"`:
    - export: a header (`SET NAMES utf8mb4`, `SET time_zone = '+00:00'`,
      `SET FOREIGN_KEY_CHECKS = 0`); then, per table, `SHOW CREATE TABLE` and the rows, streamed
      with an unbuffered query (a new `Database` method using `MYSQLI_USE_RESULT`) as multi-row
      `INSERT`s (`Escape()`, `NULL`, `0x…` for binary columns); views and triggers too. Routines
      are reported as unsupported. The export's own session also uses `+00:00`, so `TIMESTAMP`
      values round-trip exactly; it restores the synced zone afterwards, because the connection is
      shared for the whole request;
    - import: `SqlSplitter` streams the file, and each statement runs on its own through a path
      that doesn't split again (for example `QueryMulti([$statement])`, never `Query()`). Nothing
      accumulates. It stops at the first error, reporting the statement number and MySQL's error,
      unless `continue` is set.
  - `Export()` returns `["file", "size", "driver"]`. `Import()` returns `["driver", "statements"]`
    (the count is known only with the PHP driver), plus the errors when `continue` is set.
- Remove `AdminDatabase::DoBackup()`. The old Backups page has to keep working until item 21
  replaces it, so `Admin/Api/DatabaseApi.php`, `views/pages/backups.php` and
  `views/actions/backup-file.php` switch to `Dump` in this item.
- Tests:
  - `adminBackupsTest.php` (whose `FakeBackupAdminDatabase` overrides `RunProcess()`) is rewritten
    against `Dump`: names, an existing file refused, the argument array without the password, the
    defaults file's content, `0600` mode and deletion, the partial file removed on failure,
    `RunProcess()` without a shell, and the API response;
  - splitter cases: quotes, escapes, comments, `DELIMITER`, conditional comments, CRLF, a statement
    across a chunk boundary; `Query()`/`QueryMulti()` through it; the admin's `database.sql`
    (which `Install::InstallDatabase()` runs) still splits into its statements;
  - the binary driver's command arrays (with the runner overridden);
  - a PHP export of a fake table (`NULL`, quotes, binary data, the header);
  - an import stops at the first error.
- Docs: new `docs/mds/database/dump.md` (DocMap `database`; src `DB/Dump.php`,
  `DB/SqlSplitter.php`), and the splitter in `database/database.md`.

**Impact** (M17). Code calling `AdminDatabase::DoBackup()` moves to `Dump::Export()`. `Query()`,
`QueryMulti()` and `ImportFile()` now split SQL correctly, so statements that broke at a `;` inside
a value or a comment run whole.

---

## Group E — The admin "Database" section

Every page renders data through `Table()` and the form elements, which escape values. Table and
column names are checked against `Schema` before they reach SQL. Page logic lives in small classes
that tests can cover with fake schema rows; the views stay thin.

### 18. Explorer
- [ ] done

**Solution.**
- Tables page: name, approximate rows, engine, the mapped model (from
  `SchemaCheck::FindModels()`) and its drift status.
- Table page: the columns (name, type, null, default, extra, key, suggested Magrathea type), the
  indexes, `SHOW CREATE TABLE` (copyable), the model's findings, and a read-only preview of the
  first 20 rows.
- The logic lives in `Admin\DatabaseExplorer`, tested with fake schema rows.

### 19. Queries
- [ ] done

**Solution.**
- `views/pages/db-query.php` and `views/actions/query-run-card.php` stay: they already escape.
- Results show up to 1000 rows, with a notice when there are more.
- SQL that isn't read-only (see "Shared conventions") asks for confirmation before it runs.
- Tests: the confirmation rule and the row cap.

### 20. Query editor
- [ ] done

**Error.** The admin has no query builder, only raw SQL on the Queries page.

**Solution.** A new page backed by `Admin\QueryEditor::Build(array $spec): array`.
- The form:
  - type: select, insert, update or delete;
  - table, from `Schema`;
  - fields: checkboxes from the columns;
  - where rows: a column, an operator (`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `IS NULL`,
    `IS NOT NULL`, `IN`) and a value;
  - order, limit and page;
  - values, for insert and update.

  Update and delete require at least one where row.
- `Build()` makes a real `Query` through method calls, never `eval`. `=` uses `Where([...])`; the
  other operators use string conditions built from whitelisted column names and `Escape()`d values.
  It returns `["php" => "Query::Select()->Table(\"articles\")->…", "sql" => $query->SQL()]`.
- "Run" posts the spec again. A select shows its rows; a write asks for confirmation, then shows the
  affected rows.
- Tests: `Build()` for every type and operator; unknown tables and columns are rejected; the PHP
  chain and the SQL agree.

### 21. Dumps (replaces Backups)
- [ ] done

**Solution.**
- One page, using `Dump` (item 17):
  - the files in `backups_path`: name, size, date;
  - **Export**: a file name and optional tables; shows the driver used;
  - **Download**: streamed with `Content-Disposition`;
  - **Upload** a `.sql` file: saved under a validated name, never run on upload;
  - **Import**: POST, CSRF (`AdminCsrf`), and a confirmation that names the file and the database;
  - **Delete**: POST.

  Every file name is validated and resolved with `AdminFiles::ResolveInFolder()`.
- Delete `views/pages/backups.php`, `views/actions/backups-list.php`,
  `views/actions/backup-file.php` and `Admin/Api/DatabaseApi.php`.
- `AdminDatabase` keeps only `Initialize()`, `LoadBackupFolder()`, `GetBackupFolder()`,
  `GetSQLFile()` and `GetSQLFileContents()`.
- Tests: the name checks on upload, download and delete; export and import through a fake `Dump`.

### 22. Checks (replaces Structure)
- [ ] done

**Solution.**
- A new page that lists `ProjectCheck::Run()`'s findings by group: configuration, folders (with each
  folder's path), database, timezone, and schema (timestamps with their fix SQL, to copy, and model
  drift).
- Delete `views/pages/structure.php`: its folder checks are now part of this page.
- Tests: findings are grouped and escaped.

### 23. Menu and the Version page
- [ ] done

**Solution.**
- `AdminMenu::GetDatabaseSection()` lists Explorer (`db-explorer`), Queries (`query`), Query editor
  (`query-editor`), Dumps (`dumps`), Checks (`checks`) and Connection (`connect`). The keys
  `backups` (`AdminMenu.php:281`) and `structure` (`:288`) are removed, and `structure` leaves the
  Setup section in `Admin::AddMagratheaMenu()` (`Admin.php:258`).
- The Server page keeps its timezone check. The Version page's "Server Time" card
  (`views/pages/version.php:29-39`) becomes a link to it.
- Tests (`MagratheaAdminTests/adminMenuTest.php`): the section's items; no generator, File Editor,
  `backups` or `structure` items.

---

## Group F — The CLI for agents

### 24. The `bin/magrathea` runner
- [ ] done

**Error.** Agents work in a shell. Without a CLI, everything above is reachable only through a
browser.

**Solution.**
- `bin/magrathea`: an executable PHP script with a `#!/usr/bin/env php` shebang, plus
  `"bin": ["bin/magrathea"]` in `composer.json`, which gives projects `vendor/bin/magrathea`.
- It loads Composer's autoloader from `$GLOBALS['_composer_autoload_path']` (set by the bin proxy of
  Composer 2.2+), else from `__DIR__."/../vendor/autoload.php"` (a checkout of the framework), else
  from `__DIR__."/../../../autoload.php"` (installed in a project).
- It finds the project's bootstrap, in this order:
  1. `--bootstrap=<file>`;
  2. `extra.magrathea.bootstrap` in the nearest `composer.json` above the current folder (a path
     relative to that file);
  3. `app/_inc.php` next to that `composer.json`: the `skills.MD` convention.

  The bootstrap is `require`d and must not print.
- It refuses to run outside the CLI (`PHP_SAPI !== "cli"`).
- Output is text, or with `--json`, a single JSON document on stdout.
- Exit codes: `0` ok; `1` an ERROR finding or a failed command; `2` a usage error.
- The dispatcher is `Magrathea\Cli\Console::Run(array $argv, $out, $err): int`, with streams for
  stdout and stderr, so it's testable in-process. `bin/magrathea` only wires it to `STDOUT` and
  `STDERR` and exits with its code.
- `ErrorManager::DisplayException()` and `DisplayMesage()` (`src/Errors/ErrorManager.php:26-42`)
  print plain text to stderr and exit with code `1` when `PHP_SAPI === "cli"`, instead of the HTML
  page and a `die` with status 0. A missing config then reads as text in a terminal, and scripts
  see the failure.
- Tests: argument parsing, the bootstrap order, `--json`, exit codes, the refusal outside the CLI,
  the CLI error text.
- Docs: new `docs/mds/core/cli.md` (DocMap `core`; src `Cli/Console.php`).

**Impact** (M24). A CLI script (a cron job, a worker) that hits a framework error page now gets
text on stderr and exit code 1, instead of HTML and exit code 0.

### 25. Writes from the CLI need explicit flags
- [ ] done

**Solution.**
- A command that writes refuses to run without its flag, with exit code `2`: `db:query` with SQL
  that isn't read-only needs `--write`, and `db:import` needs `--yes`.
- There are no interactive prompts, since agents can't answer them. The flag is the consent.
- Tests: refused without the flag; runs with it.

### 26. Commands
- [ ] done

**Solution.**

| Command | Uses | Notes |
|---|---|---|
| `check [--no-db]` | `ProjectCheck::Run()` | exit `1` on any ERROR |
| `db:tables` | `Schema::Tables()` | |
| `db:describe <table>` | `Schema` + `SchemaCheck` | columns, indexes, `SHOW CREATE TABLE`, the mapped model and its findings |
| `db:query "<sql>" [--write]` | `Database` | read-only SQL prints its rows; anything else needs `--write` |
| `db:dump [file] [--tables=a,b] [--driver=binary\|php]` | `Dump::Export()` | the default name comes from `GetDefaultFileName()` |
| `db:import <file> --yes [--driver=…] [--continue]` | `Dump::Import()` | |
| `models:verify [Class]` | `SchemaCheck` | exit `1` on any ERROR. Agents run it after writing or changing a model |
| `admin:reset-password <email> [--password=…]` | `AdminUserControl::GetByEmail()` + `SetNewPassword()` | without `--password`, it generates one and prints it once |
| `version` | `MagratheaPHP::Version()`, `AppVersion()` | |

- Tests: each command, against fakes.
- `skills.MD` gets a "Verify your work" section (item 30).

---

## Group G — The Setup Wizard (Bootstrap, rebuilt)

### 27. Entry, modes and the token lock
- [ ] done

**Error.**
- The wizard needs a config just to decide whether it's unlocked (`Start::IsUnlocked()`,
  `Bootstrap/Start.php:69-76`).
- `PreLoad()` (`:265-268`) copies the sample config, which arrives as `dev` with
  `bootstrap_unlocked = true`. So with no config, anyone can open the wizard.
- A WordPress-style wizard for a project with no `magrathea.conf` invites WordPress's takeover
  problem: on a freshly deployed server, whoever reaches the wizard first owns the install. Hence
  the token, which only someone with access to the server's files can read.

**Solution.**
- **Entry file**: `public/setup.php`, part of the project skeleton (`skills.MD` §17, item 30):
  ```php
  <?php
  // Magrathea setup wizard: locked by a token only someone with access to the server's files can read
  require __DIR__."/../vendor/autoload.php";
  Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__."/../app")->Load();
  ```
  The new `Start::AppPath($path)` calls `MagratheaPHP::Instance()->AppPath($path)`, which sets
  `magRoot` to the parent folder. The wizard looks for `<magRoot>/configs/magrathea.conf` itself and
  never calls `MagratheaPHP::Load()`, which dies on a missing config (code 424041).
- **Modes:**
  - setup: there's no config file. It requires the token;
  - maintenance: the config exists. It requires `bootstrap_unlocked = true` in the active
    environment (as today) and the token.

  Anything else gets the locked page (403).
- **The token** (`<magRoot>/configs/.setup-token`):
  - issuing: the file is created when it's missing, on a visit that's allowed to ask for one, with
    mode `0600`. Line 1 is the token, `xxxx-xxxx`: 8 digits from `random_int(0, 99999999)`,
    zero-padded, readable with `cat`. Line 2 counts failed attempts, updated under `flock()`. If
    `configs/` is missing, the wizard tries `mkdir` (`0750`); if it isn't writable, a page explains
    what to create;
  - checking: the form accepts `xxxx-xxxx` or `xxxxxxxx` (spaces and dashes are stripped), compared
    with `hash_equals()`;
  - lockout: 10⁸ tokens is too few without one. At 10 failures, the file is renamed to
    `configs/.setup-locked`, and no token is accepted until the owner deletes that file. The next
    visit then issues a new token. The page says exactly this;
  - session: a correct token runs `session_regenerate_id(true)` and stores `hash("sha256", $token)`
    in the session. Every request compares it with the file, so deleting or replacing the file
    revokes the session;
  - the finish step (item 28) deletes the file.
- Wizard POSTs are checked with `AdminCsrf`. `Start::ACTIONS` (the action whitelist) stays, and
  every action requires a verified session.
- Remove `PreLoad()` and `CreateDefaultConfigFile()` (`:231-252`): only item 28's form writes the
  config, after the token.
- `src/Errors/views/error-magratheaconf.php`, the "config missing" page that `MagratheaPHP::Load()`
  shows, explains how to run the wizard. `Load()` itself doesn't change.
- Tests (`bootstrapTest.php`, rewritten): the token's format and `0600` mode; normalization and
  `hash_equals()`; 10 failures lock it, and deleting the lock issues a new token; setup needs only
  the token, maintenance needs the flag and the token; changing the token file revokes the session;
  an action without a verified session gets 403; POSTs without the CSRF token are refused.
- Docs: new `docs/mds/setup-wizard.md` (DocMap `getting-started`; src `Bootstrap/Start.php`).

**Impact ⚠** (M27). A project with a 2.x wizard entry file (`Bootstrap\Start::Instance()->…->Load()`,
with or without `PreLoad()`) replaces it with the entry above, or deletes it. Using the wizard now
also takes the token.

### 28. Steps
- [ ] done

**Solution.** The server validates each step, and each step's handler refuses a request without a
verified session.
1. **Requirements**: the `ProjectCheck` findings that need no config (PHP, extensions, writable
   folders, `configs/` outside the web root).
2. **Configuration**: writes `configs/magrathea.conf` through `ConfigFile::Save()`, following
   `src/Bootstrap/docs/magrathea.conf.sample`.
   - Fields:
     - the environment name (`[general] use_environment`, default `dev`);
     - `db_host`, `db_port`, `db_name`, `db_user`, `db_pass`;
     - `timezone`: a `<select>` of `timezone_identifiers_list()`, validated against that list;
     - `app_url`;
     - `logs_path`, `cache_path` and `backups_path`, defaulting to folders under `magRoot`;
     - `jwt_key`, defaulting to `bin2hex(random_bytes(32))`;
     - `bootstrap_unlocked`, offered only for `dev`.
   - `ConfigFile::SaveValueOnConfig()` wraps values in `"…"` without escaping
     (`ConfigFile.php:128-132`), so values containing `"` or a line break are rejected.
   - In maintenance mode, only the active section is edited.
3. **Database**: tests the connection with the values entered; runs `TimezoneCheck::Run()` (moved
   here from `config-edit.php`); lists the tables (`Schema`).
4. **Import** (optional): a dump from `backups_path`, or an upload, through `Dump::Import()`. Shows
   the driver, the statement count (PHP driver only) and the first error.
5. **Admin**: `Install::InstallDatabase()` when `_magrathea_config` is missing; then the first super
   admin (an email, and a password of 12 or more characters) when `Admin\Start::CanCreateFirstUser()`
   allows it (`Admin/Start.php:174-180`). The admin's entry file comes with the skeleton (item 30);
   the wizard doesn't write it.
6. **Checks**: `ProjectCheck::Run()`, with the schema's timestamp and drift findings and their fix
   SQL.
7. **Finish**: deletes the token, reminds the user to remove `bootstrap_unlocked` outside `dev`, and
   links to the admin and the docs.

**Removed from the 2.x wizard:**
- the steps `structure`, `config-file`, `config-edit`, `log-and-debug`, `db-connect`, `db-run`,
  `db-query`, `install-admin` and `develop`;
- `tools.php`. Its admin password reset is now `magrathea admin:reset-password` (item 26): shell
  access proves ownership, and a web form doesn't;
- the actions `run-query`, `user-reset`, `users-get`, `show-tables`, `install-admin`,
  `install-admin-preview`, `database-test` and `save-config`, replaced by the steps' handlers;
- `Start::Structurate()` and `ValidateStructure()`;
- the CDN assets in `views/index.php` (Bootstrap from jsdelivr, jQuery from code.jquery.com),
  replaced by the admin's compressed vendor assets.

**Tests:** writing the config into a temporary folder; `"` is rejected; a timezone outside the list
is rejected; `CanCreateFirstUser()` guards the first user; step order; every handler refuses a
request without a verified session.

---

## Group O — CORS origins

### 29. `AllowFromFile()` and the admin's CORS Origins feature
- [ ] done

**Error.**
- `MagratheaApi` only takes origins in code (`Allow([...])`, `MagratheaApi.php:129-135`). Projects
  that edit them at runtime write their own reader and admin page. MagratheaContacts has both:
  `Cors()` in `src/api/api/api.php` reads `GetConfigRoot()."/cors-origins.txt"`, and
  `src/api/magrathea-admin/CorsAdmin.php` edits it.
- `Allow()` doesn't send `Vary: Origin`, so a cache or proxy can serve one origin's
  `Access-Control-Allow-Origin` to another origin.
- `AllowAll()` (`:106-111`) sends `Access-Control-Allow-Credentials: true` with
  `Access-Control-Allow-Origin: *`, a combination browsers reject.
- `AddAcceptHeaders()` (`:143-145`) replaces the accepted-headers list, although its name, its
  docblock and `api/magrathea-api.md` say it appends.

**Solution.**
- `MagratheaApi`:
  - `const CORS_ORIGINS_FILE = "cors-origins.txt"` and static `GetCorsOriginsFile(): string`
    (`MagratheaPHP::Instance()->GetConfigRoot()."/".self::CORS_ORIGINS_FILE`);
  - static `ReadCorsOrigins(?string $file = null): array`: one origin per line, trimmed. Empty lines
    and lines starting with `#` are skipped. A missing or unreadable file returns `[]`;
  - `AllowFromFile(?string $file = null): MagratheaApi` calls `Allow(self::ReadCorsOrigins($file))`.
    A missing file allows no origin. It doesn't touch the accepted headers;
  - `Allow()` also sends `header('Vary: Origin', false)` (`false` appends, so it doesn't replace
    another `Vary`);
  - `AllowAll()` stops sending `Access-Control-Allow-Credentials`;
  - `AddAcceptHeaders(string|array $accept): MagratheaApi` appends, dropping case-insensitive
    duplicates. The new `SetAcceptHeaders(array $headers): MagratheaApi` replaces the list.
    Neither one sends anything; `AcceptHeaders()` still sends the header.
- The admin feature `src/Admin/Features/Cors/` (`AdminFeatureCors.php`, `index.php`, `scripts.js`)
  follows `AdminFeatureCache`'s pattern (`AddJs()`, `SetClassPath()`, `AdminElements::Alert()`
  responses). `featureId`: `AdminFeatureCors`; `featureName`: "CORS Origins".
  - `Index()` shows the file's path ("not created yet" when it's missing), a textarea with the
    file's escaped content, and a Save button that calls
    `callFeature(featureId, "Save", "POST", { origins })`.
  - `Save()` answers 405 to anything but POST (see "Admin dispatch rules"). It splits the posted
    `origins` on `\r\n|\r|\n`, trims each line, drops empty lines and keeps `#` comments. Every
    other line must pass the static `IsValidOrigin(string $origin): bool`:
    `^[a-z][a-z0-9+.-]*://([a-z0-9-]+(\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])(:[0-9]{1,5})?$`. That is
    what browsers send in `Origin`, so `capacitor://localhost` and `http://[::1]:3000` pass, while
    `*`, `null`, `https://a.com/`, `HTTPS://a.com` and anything with a path fail: `Allow()`
    compares exactly, so those lines could never match. If any line fails, nothing is written and
    a danger Alert lists the failing lines, escaped. Otherwise the file is written with
    `file_put_contents(…, LOCK_EX)`; a write failure shows a danger Alert with the path.
  - Errors answer 200 with an Alert, like `AdminFeatureCache::Save()`: the admin's `ajax()` drops
    non-JSON error bodies (`views/javascript/scripts.js:86-93`).
  - Opt-in: the new protected `Admin::LoadCors()` isn't called by `SetFeatures()`. When it's
    loaded, `Admin::AddMagratheaMenu()` adds its item to the Setup section, after AppConfig.
- Tests:
  - `MagratheaTests/apiCorsTest.php`: `ReadCorsOrigins()` (comments, blank lines, surrounding
    whitespace, missing file); `AddAcceptHeaders()` appends and drops duplicates;
    `SetAcceptHeaders()` replaces. The CLI can't read sent headers (`headers_list()` is empty), so
    `Vary: Origin` and `AllowAll()`'s headers go in the final report;
  - `MagratheaTests/adminCorsFeatureTest.php`: an `IsValidOrigin()` data provider. `Save()` answers
    405 to GET, writes valid lines with comments kept, and leaves the file untouched when a line is
    invalid. Failing lines are escaped. The config root points into `test-dump/`;
  - `MagratheaAdminTests/adminFeaturesTest.php` and `adminMenuTest.php`: the feature isn't loaded
    by default, and an `Admin` subclass calling `LoadCors()` gets it in the Setup section.
- Docs:
  - `api/magrathea-api.md`: `AllowFromFile()`, `ReadCorsOrigins()`, `GetCorsOriginsFile()`,
    `Vary: Origin`, `AllowAll()` without credentials, `AddAcceptHeaders()`/`SetAcceptHeaders()`;
  - `admin/admin-features.md`: a "CORS Origins Feature" section after the Cache feature;
  - `admin/admin.md`: a row in the features table ("`$this->LoadCors()` in `SetFeatures()`");
  - `docs/lib/DocMap.php`: add `Admin/Features/Cors/AdminFeatureCors.php` to the `admin-features`
    entry's `src` list, so freshness tracks it. It's not a new page, so there's no new entry;
  - `skills.MD`'s CORS lines (the `AllowAll()` example and "use `Allow([...])` in production"):
    mention `AllowFromFile()`. Items 30 and 31 carry it into the rewrite and `AGENTS.md`.

**Impact** (M29).
- `AddAcceptHeaders()` now appends. Calls that relied on it replacing the list:
  `grep -rn 'AddAcceptHeaders(' --exclude-dir=vendor .` finds them; switch to `SetAcceptHeaders()`.
  A list that already includes the defaults (`Authorization`, `Content-Type`) ends up the same.
- `AllowAll()`: credentialed browser requests never worked with it, so nothing changes for them.
- Projects with their own reader (MagratheaContacts) can replace it with `$this->AllowFromFile()`
  and their CORS admin page with `$this->LoadCors()`: same file, same location. A file that has
  invalid lines is still read; the admin refuses to save it until they're fixed.

---

## Group H — AI-facing docs and packaging

### 30. `skills.MD` for 3.0
- [ ] done

**Error.** In 3.0, `skills.MD` takes the code generator's place: every inaccuracy becomes a bug in
generated code. It still:
- teaches the Base split (§3–4, `:166-404`) and the generator;
- carries 2.x history notes on almost every rule ("since 2.4.0", "requires 2.2.2+");
- catches exceptions in its entry points with `\Magrathea\p_r($ex)` (§1 `:75`, §12 `:956`, §17
  `:1263`), which prints exception details on a production page;
- points to `instructions.MD` (`:13`).

**Solution.** Rewrite it for 3.0 only:
- Frontmatter: `name: magrathea-php`, a description that says it's for projects on MagratheaPHP
  3.x (`platypustechnology/magratheaphp`, namespace `Magrathea`). The frozen 2.x skill keeps
  `magrathea-php2` (see Release).
- Models and controls:
  - single-class models and controls (items 6 and 8), with relations as explicit methods, and no
    `implements iMagratheaModel` (deprecated in item 6);
  - `MagratheaModel::TYPES`;
  - strict types by default;
  - the table contract and the timestamp rules: the database owns `created_at` and `updated_at`,
    which don't go in `$dbValues`; no PHP `now()`; reload to read them; `Touch()`; `Query::Update()`
    for explicit values;
  - `AddFeature()`'s `Base` folder, mentioned only for legacy projects.
- Removed: the generator, the File Editor, `LoadVendor()`, `Clean()`, `ShowAll()` and the `mvc`
  structure.
- New sections: "Setting up a project" (the skeleton, `public/setup.php`, the token); "Verify your
  work" (`vendor/bin/magrathea check`, `models:verify`, `db:describe --json`); dumps.
- Entry points log the exception and answer a generic error instead of calling `p_r($ex)`.
- The §17 skeleton adds `public/setup.php` and an `AGENTS.md` stub: five lines pointing to
  `vendor/platypustechnology/magratheaphp/skills.MD` and `…/docs/mds/`, which ship after item 32.
- "Reference files" points to the shipped `docs/mds/index.md`, not `instructions.MD`.
- The §18 checklist is rewritten for 3.0.

### 31. `AGENTS.md` replaces `instructions.MD`
- [ ] done

**Error.** `instructions.MD` is stale, and it ships in `vendor/`. It teaches `documentation/` and
`config/` paths, a `[section:environment]` syntax that never existed, "always `Query::Clean()`",
"call `LoadVendor()` first", `ToJson()` for API responses (contradicting `skills.MD`), `{param}`
routes (they're `:name`), `AdminCrudObject` properties that don't exist, and PHP 8.0+.

**Solution.**
- Delete `instructions.MD`.
- Write a new root `AGENTS.md`: a guide for agents working **on the framework itself**, not on
  projects that use it:
  - an accurate source map;
  - how to run the suite, and its limits (no `mysqli`; `DatabaseSimulate`, `MockClass()`, the fake
    connection, the runner override);
  - the conventions (tabs, PHPDoc, changelog lines);
  - the doc-sync rules from `CLAUDE.md` (the Reflector, DocMap, the narrative docs, `skills.MD`);
  - `future-plans/` and the per-release migration guide.
- It doesn't ship (item 32). Pointing `CLAUDE.md` at it is Paulo's call: ask, and don't edit
  `CLAUDE.md`.

### 32. Packaging (`.gitattributes`)
- [ ] done

**Error.** Only `/docs` is export-ignored (`.gitattributes:1`), so every project's `vendor/` gets
`CLAUDE.md` (Paulo's personal agent rules, which agents read when they open files in that folder),
`future-plans/`, `phpunit.phar` (5 MB), `scripts/` and the logos. And it doesn't get the migration
guides, which agents need there.

**Solution.**
- Replace the rule with `export-ignore` entries for:
  - `/docs/*.php`, `/docs/lib`, `/docs/views`, `/docs/assets`, `/docs/data`, `/docs/cache`,
    `/docs/CLAUDE.md`;
  - `/future-plans`, `/phpunit.phar`, `/scripts`;
  - `/CLAUDE.md`, `/AGENTS.md`;
  - `/assets`, the brand source files (item 35). The old root logos, `/magrathea_logo.png` and
    `/magrathea_logo.svg`, are already deleted.
- What ships: `src/` (including `Tests/`, which the admin's Tests page uses), `bin/`, `docs/mds/`,
  `skills.MD`, `README.md`, `LICENSE` and `composer.json`.
- Check it without committing, with `git check-attr export-ignore -- <path>…`. Pass each ignored
  path itself (`docs/lib`, `future-plans`, `CLAUDE.md`, a `docs/*.php` file, …), which must come back
  `set`: a pattern that matches a folder doesn't apply to the files inside it (`git archive` skips
  the whole folder), so a file under `docs/lib` reports `unspecified` even when it's excluded. Then
  `docs/mds`, `bin`, `src` and `skills.MD` must come back `unspecified`. The release repeats the
  check on a real archive.

**Impact.** None at runtime.

### 33. Narrative docs pass and DocMap
- [ ] done

**Solution.**
- Most pages are updated item by item. This pass rereads every page that mentions something removed
  or changed:
  - `getting-started.md`, `index.md`;
  - `admin/admin.md`, `admin/admin-features.md`, `admin/admin-manager.md`;
  - `api/api-controller.md`, `api/authentication.md`;
  - `core/config.md`, `core/magrathea-php.md`, `core/global-functions.md`, `core/helper.md`,
    `core/timezone.md`;
  - `database/database.md`, `database/orm-model.md`, `database/orm-control.md`,
    `database/query-builder.md`;
  - `advanced/patterns.md`, `advanced/testing.md`;
  - `utilities/debugger.md`, `utilities/compressors.md`;
  - `docs/data/examples.php` and `README.md`.

  `migrations/migrate-2.4.0.md` stays as it is: it documents 2.x, old names included.
- Check that every new page has its DocMap entry and `index.md` row: `database/schema.md` and
  `database/dump.md` (database), `core/project-check.md` and `core/cli.md` (core),
  `setup-wizard.md` (getting-started). The admin's Database section is documented in
  `admin/admin.md`; add `Admin/DatabaseExplorer.php` and `Admin/QueryEditor.php` to that page's
  src list.

### 34. The migration guide
- [ ] done

**Solution.**
- Move `future-plans/migrate-3.0.md` to `docs/mds/migrations/migrate-3.0.md`, which ships after item
  32, and remove its draft note.
- Add the DocMap `migrations` entry
  `"migrate-3.0" => ["title" => "3.0", "md" => "migrations/migrate-3.0.md", "src" => []]`, and its
  `index.md` row.
- The first line of the `### 3.0.0` changelog section points to it: the vendor path,
  `vendor/platypustechnology/magratheaphp/docs/mds/migrations/migrate-3.0.md`, and the docs URL,
  https://www.platypusweb.com.br/magratheaphp3/docs/?p=migrations/migrate-3.0 .
- `skills.MD`'s "Upgrading" line does the same.

### 35. The new logo
- [ ] done

**Error.** 3.0 has a new logo, but every copy in the repository is still the old one: a 750×750
base64 PNG wrapped in an SVG (~126 KB each). The copies are:
- `src/Admin/views/logo.svg`: the admin logo (`Admin::$adminLogo`, `Admin.php:29`), printed by
  `AdminManager::PrintLogo()` on the login, setup, message and home pages and in the side menu;
- `src/Admin/views/magrathea_logo.svg`: the admin favicon (`Admin::$favicon`, `Admin.php:30`),
  inlined as a data URI by `AdminManager::GetFaviconTag()`;
- `src/Bootstrap/views/logo.svg`: the wizard header, through `include()`
  (`Bootstrap/views/sections/header.php:12`);
- `docs/assets/img/logo.svg`: the docs topbar, the home page and the favicon
  (`docs/views/layout.php:8,15`, `docs/views/home.php:6`);
- `src/magrathea.svg`, which nothing references.

The wizard has no favicon at all.

**The new files** are in `/assets`, which doesn't ship (item 32):
- `magrathea3_logo.svg`: the full-color logo;
- `magrathea3_logo_unicolor.svg`: the single-color (`#f79914`) version;
- `favicons/`: `favicon.svg` (the unicolor shape in a square viewBox, `0 -53.5 544 544`),
  `favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`,
  `android-chrome-192x192.png`, `android-chrome-512x512.png` and `site.webmanifest`.

**Constraints.**
- The admin and the wizard run from `vendor/`, which the web server doesn't serve, so they can't
  link to image files. Their favicon has to stay an inline data URI of an SVG. The PNG/ICO set and
  the manifest are for the docs site only.
- The new logo isn't square (viewBox `544×437`) and its `<svg>` has no `width`/`height`.
  `PrintLogo()` sizes the SVG by replacing the literal `<?=$logoSize?>` in it
  (`AdminManager.php:58-61`), and the wizard header relies on the file's own `width="50"
  height="50"`.
- The new SVGs start with an `<?xml …?>` declaration and a `<!DOCTYPE>`. The wizard `include()`s
  its logo as PHP, so with `short_open_tag=On` `<?xml` is a parse error, and `PrintLogo()` echoes
  both lines into the HTML.

**Solution.**
- In every copy that goes into `src/`, drop the `<?xml …?>` line and the `<!DOCTYPE>` so the file
  starts with `<svg`. Change nothing else in the drawing.
- Admin logo: replace `src/Admin/views/logo.svg` with `magrathea3_logo.svg`, and add
  `width="<?=$logoSize?>" height="<?=$logoSize?>"` to its `<svg>` element so `PrintLogo()` keeps
  sizing it. The default `preserveAspectRatio` (`xMidYMid meet`) centers the 544×437 drawing in the
  square box: don't stretch it.
- Admin favicon: replace `src/Admin/views/magrathea_logo.svg` with `favicons/favicon.svg` (the
  unicolor shape, already square). `GetFaviconTag()` doesn't change.
- Wizard logo: replace `src/Bootstrap/views/logo.svg` with `magrathea3_logo.svg`, with
  `width="50" height="50"` on its `<svg>`.
- Wizard favicon: add an inline `<link rel="icon" href="data:image/svg+xml;base64,…"
  type="image/svg+xml">` to the `<head>` of `Bootstrap/views/index.php` and `locked.php`, built the
  way `GetFaviconTag()` builds it, from a new `src/Bootstrap/views/favicon.svg` (a copy of
  `favicons/favicon.svg`). Don't make the wizard depend on `Admin\AdminManager` for this.
- Delete `src/magrathea.svg`.
- Docs site:
  - replace `docs/assets/img/logo.svg` with `magrathea3_logo.svg` (the topbar and the home page keep
    using it);
  - copy `assets/favicons/` to `docs/assets/favicons/` as it is: `site.webmanifest` uses relative
    paths;
  - in `docs/views/layout.php`, replace the `logo.svg` icon line with `favicon.svg`
    (`type="image/svg+xml"`), `favicon.ico` (`sizes="any"`), the 32 and 16 PNGs, `apple-touch-icon`
    and `site.webmanifest`, all under `<?= $baseUrl ?>/assets/favicons/`.
- Don't touch `docs/assets/img/platypus-logo.svg`: it's the company logo, not Magrathea's.
- Tests: none for the images. Run the full suite (the `PrintLogo()` and favicon paths must still
  render), then check by hand that no file in `src/` starts with `<?xml`:
  `grep -rl '^<?xml' src/`.
- Docs: `admin/admin.md` and `admin/admin-manager.md` mention the logo and favicon properties.
  Update them only if what they say about the defaults is no longer true.
- No migration section: nothing a project calls or configures changes. A project that set its own
  logo with `SetAdminLogo()` keeps it.
- Changelog: `**note:**` new MagratheaPHP 3 logo and favicons in the admin, the setup wizard and
  the docs.

**Impact.** Visual only. Each logo copy drops from ~126 KB to ~31 KB, and the admin favicon to
~4 KB. That's smaller inline SVG on every admin page.

---

## Release (after every item is done)

- [ ] `src/version` → `3.0.0` (Paulo).
- [ ] The `### 3.0.0` changelog section has its date line, the migration `**note:**` first, and every
      `**removed:**` line.
- [ ] Remove the version line from the `####` file banners in `src/` (`####    v. 2.0`, one
      `v. 1.0`, tab or spaces after `####`): nobody keeps those numbers up to date, and they'd be
      wrong again from 3.1. Leave the rest of each banner. This is a release step, not an item,
      because deleting lines would break the line numbers the items cite, and a new item would
      shift the item and **M\<n\>** numbering. Check:
      `grep -rnE "^####[[:space:]]+v\. " src` finds nothing. No changelog line.
- [ ] Full suite green; record the counts here.
- [ ] After Paulo commits: `git archive HEAD | tar -t` matches item 32.
- [ ] Split the local skill in two:
  - `~/.claude/skills/magrathea-php2/` stays the frozen 2.x cookbook. Only its frontmatter
    `description` changes, to say it's for projects on 2.x (`platypustechnology/magratheaphp2`);
  - the 3.0 `skills.MD` (`name: magrathea-php`, item 30) is copied to
    `~/.claude/skills/magrathea-php/SKILL.md`, byte for byte (check with `cmp`).
- [ ] Paulo's infrastructure steps:
  - create `PlatypusTechnology/MagratheaPHP3` and push the existing history into it (the
    `version/3.0` branch, not a squashed snapshot, so `git blame` keeps working). Leave the 2.x
    tags out: they stay in `MagratheaPHP2`, which keeps releasing 2.x (2.4.0 included);
  - submit `platypustechnology/magratheaphp` to Packagist from the new repository. Keep
    `platypustechnology/magratheaphp2` active while 2.x is maintained; when 2.x support ends, mark
    it abandoned, pointing to `platypustechnology/magratheaphp`;
  - publish the docs at `https://www.platypusweb.com.br/magratheaphp3/docs/`. Don't redirect
    `/magratheaphp2/`: 2.x projects still need it. Give it a banner pointing to 3.0 instead.
- [ ] Final report:
  - the files changed, and the test counts;
  - what needs a real MySQL:
    - `ON UPDATE CURRENT_TIMESTAMP` bumping `updated_at`, and the reloaded values after an API
      create or update;
    - `information_schema` output on MySQL vs MariaDB;
    - real `mysqldump`/`mysql` runs;
    - a PHP-driver dump round trip with triggers, views and binary data;
    - `TIMESTAMP` values across zones;
  - the wizard in a browser;
  - the CLI in a real project.
