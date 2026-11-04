# AGENTS.md: working on MagratheaPHP itself

This guide is for agents changing **the framework**: this repository, the package
`platypustechnology/magratheaphp`, namespace `Magrathea`. It isn't meant for projects that *use* the
framework. They get:
- `skills.MD`: the cookbook for writing code with the framework;
- `docs/mds/`: the narrative docs, starting at `docs/mds/index.md`.

Both ship in `vendor/platypustechnology/magratheaphp/`. This file doesn't.

---

## Source map

`composer.json` maps `Magrathea\` to `src/` (PSR-4). It also always loads the three procedural
files (`_EnumTrait.php`, `_Functions.php`, `_FunctionsDebug.php`). PHP 8.1+.

```
src/
├── MagratheaPHP.php            bootstrap singleton: Load() reads the config, Connect(), Dev()/Prod(), StartSession()
├── Config.php                  configs/magrathea.conf (INI): Get() reads the active environment's section
├── ConfigFile.php              INI file reading and writing (Save())
├── ConfigApp.php               key/value settings stored in the database (_magrathea_config), edited by the admin's AppConfig
├── Singleton.php               Instance(), MockClass() (tests swap instances through it)
├── MagratheaModel.php          ORM model: $dbTable, $dbPk, $dbValues, TYPES, strict types, Insert/Update/Save/Reload/Touch
├── MagratheaModelControl.php   static queries for a model ($model, GetDbTable(), GetAll(), GetWhere()…)
├── MagratheaPagination.php     paged result (data, page, count, total, hasMore)
├── MagratheaApi.php            router (Add/Crud with :name params), CORS (Allow/AllowAll/AllowFromFile), Run()
├── MagratheaApiControl.php     controller base (List/Read/Create/Update/Delete), JWT helpers
├── MagratheaApiAuth.php        JWT login/refresh controller (extends MagratheaApiControl)
├── MagratheaCache.php          file cache for API responses
├── MagratheaMail.php / MagratheaMailSMTP.php
├── Logger.php / Debugger.php
├── MagratheaHelper.php         static helpers
├── ProjectCheck.php            the project's health as findings (admin, CLI, Setup Wizard)
├── TimezoneCheck.php           PHP/MySQL clocks and the timezone key, as findings
├── Uuid.php                    Uuid::V7()
├── _Functions.php              global functions (p_r(), now()…); NOT a class (see docs/CLAUDE.md, HasClass())
├── _FunctionsDebug.php / _EnumTrait.php
├── version                     the release number (scripts/release.sh tags it)
├── changelog.md
├── DB/
│   ├── Database.php            mysqli wrapper (singleton): Query/QueryAll/QueryRow/QueryOne/QueryStream, PrepareAndExecute, Transaction
│   ├── DatabaseSimulate.php    the mock Database::Instance()->Mock() installs
│   ├── Query.php, QueryInsert.php, QueryUpdate.php, QueryDelete.php, QueryHelper.php   builders (they don't execute)
│   ├── Schema.php              information_schema as arrays; MagratheaType()
│   ├── SchemaCheck.php         models vs. tables, as findings
│   ├── SqlSplitter.php         streaming SQL statement splitter
│   └── Dump.php                .sql export/import (binary or php driver), RunProcess()
├── Cli/                        `vendor/bin/magrathea`: Console (dispatch), Command, Result, Text, Commands/*
├── Bootstrap/                  the Setup Wizard: Start (modes, session), Steps (each step as arrays), SetupToken, views/, docs/*.sample
├── Admin/
│   ├── Admin.php               a project's admin config (SetFeatures(), Load*() opt-ins, menu)
│   ├── AdminManager.php        admin runtime singleton
│   ├── Start.php               admin request dispatch: install state, login, CSRF, API, features, views
│   ├── Install.php, AdminDatabase.php, database.sql   the admin's own tables
│   ├── AdminFeature.php, AdminMenu.php, AdminElements.php, AdminForm.php, AdminUrls.php, AdminUsers.php, AdminCsrf.php, AdminFiles.php
│   ├── DatabaseExplorer.php, QueryRunner.php, QueryEditor.php, DumpManager.php, CheckReport.php   the Database section, as arrays
│   ├── Api/                    the admin's internal API
│   ├── Features/               AppConfig, Cache, Cors, CrudObject, OpenApi, User, UserLogs, ApiExplorer
│   └── views/                  pages/, actions/, elements/, javascript/, css/
├── Errors/                     ErrorManager, error-codes.md, error views
├── Exceptions/                 MagratheaException and its subclasses (Api, DB, DBConnectionLost, Config, Model)
├── Compressors/                CSS/SCSS and JS minifiers
└── Tests/                      TestsManager (the admin's Tests page), TestsHelper, phpUnitBootstrap.php, the suites
bin/magrathea                   CLI entry: wires Console::Run() to STDOUT/STDERR, nothing else
docs/                           the documentation site (see "Keeping the docs in sync")
skills.MD                       the AI-facing cookbook for projects (downloadable from the site as skill.md)
future-plans/                   release plans and the migration guide in progress
scripts/release.sh              tags src/version and pushes the tag
```

The pattern for anything new: the logic lives in a class that returns arrays (`Schema`,
`SchemaCheck`, `ProjectCheck`, `Dump`, the admin's `DatabaseExplorer`/`QueryRunner`/…,
`Bootstrap\Steps`), and the admin pages, the wizard's views and the CLI commands only render
what it returns. Checks return **findings** shaped like `TimezoneCheck::Run()`'s.

---

## Running the tests

```bash
php phpunit.phar --bootstrap src/Tests/phpUnitBootstrap.php src/Tests
```

`phpunit.phar` is in the repository root. The suite is expected to end green, apart from the
three deprecations that `firebase/php-jwt` raises. Record the counts before your first change, so
you can compare at the end.

Folders:
- `src/Tests/MagratheaTests/`: most tests;
- `src/Tests/MagratheaAdminTests/`: the admin's features and menu;
- `src/Tests/MagratheaTests/test-dump/`: the test config (`magrathea-test.conf`, read with
  `Config::Instance()->SetPath(__DIR__."/test-dump")`) and the place for files tests write.

### Limits, and how to work around them

The development PHP CLI has **no `mysqli`, no MySQL server and no `mysqldump`**. Nothing in the
suite touches a real database. Test database behavior with:
- **`DatabaseSimulate`**: `Database::Instance()->Mock()` installs it;
- **a recording `Database` subclass** installed with `Database::MockClass($db)` (see
  `TimestampsRecordingDatabase` in `modelTimestampsTest.php`). `Singleton` instances live for the
  whole process, so save the previous instance in `setUp()` and put it back in `tearDown()`, or
  later tests run against your mock;
- **a fake mysqli connection** from an overridden `CreateConnection()` (see
  `FakeConnectionDatabase` in `databaseConnectionTest.php`);
- **an overridden process runner**: a `Dump` subclass with its own `RunProcess()` records the
  `mysqldump`/`mysql` commands instead of running them (see `dumpBinaryTest.php`);
- **the CLI in-process**: `Console::Run($argv, $out, $err)` with stream resources, and a
  `Console` subclass that overrides `Sapi()`, `WorkingDirectory()` and `CommandClasses()` (see
  `TestableConsole` in `cliConsoleTest.php`).

Headers can't be read from the CLI either (`headers_list()` is empty). What can only be checked on a
real stack (MySQL vs MariaDB `information_schema`, `ON UPDATE CURRENT_TIMESTAMP`, real `mysqldump`
runs, sent headers, the admin and the wizard in a browser) goes in your final report. Don't claim
you checked it.

Some tests guard conventions rather than behavior. `shortOpenTagTest.php` fails if any file in
`src/` opens with `<?`. `removedApisTest.php` fails if a removed method comes back.

---

## Conventions

- **Tabs** for indentation, in PHP, JS and the docs' PHP alike.
- **`<?php`**, never `<?`. That includes `bin/magrathea` and the skeleton files, which the
  short-tag test doesn't scan.
- **Namespace `Magrathea`** (`use Magrathea\…`, `\Magrathea\p_r()`).
- **PHPDoc** on public methods, aligned with tabs:
  ```php
  /**
  * What it does, and what it refuses.
  * @param 	string 	$file 		file name (see `ValidateBackupFileName()`)
  * @return 	array 				["file" => …, "driver" => …]
  */
  ```
  The docs site reads these docblocks live, so they are the API reference.
- **File banners.** `src/` files open with the `####` banner. Don't put a version line (`v. x.y`)
  in a new banner: the 3.0 release removes them.
- **Admin code**: values are rendered through `AdminElements::Table()` and the form elements,
  which escape them. URLs only reach a feature's own dispatchable methods
  (`AdminFeature::IsDispatchable()`). Writing actions answer 405 to anything but POST
  (`AdminCrudObject::RefuseIfNotPost()`). Forms carry the `AdminCsrf` token.
- **Changelog.** Every change gets a line in `src/changelog.md`, in the newest `### x.y.z` section
  at the top. The line is indented with a tab and starts with one of these prefixes:
  `**new:**`, `**fix:**`, `**note:**`, `**removed:**` (`**security fix:**` for vulnerabilities).
  Name the methods, say what changed for existing callers, and mark a breaking `**note:**`
  with ⚠.
- **Breaking changes** are allowed in a major release, with no compatibility shim. They're
  documented in that release's migration guide (see below), with how to detect each one.
- **Git**: don't commit, push, tag or run `scripts/release.sh` unless asked.

---

## Keeping the docs in sync

Every change to `src/` is reflected in the docs **in the same session**. The site is `docs/`
(architecture in `docs/CLAUDE.md`; serve it with `php -S localhost:8000 -t docs`).

- **Signatures update themselves.** `docs/lib/Reflector.php` reflects `src/` on every page load,
  so methods and classes that are added, removed or renamed need no doc edit.
- **The prose doesn't.** When a class's behavior changes, reread its page in `docs/mds/` and fix
  what's no longer true. `DocMap::Freshness()` flags a page as "may be outdated" when a mapped
  `src/` file's last commit is newer than the page's. That only says *something* changed, never
  *what* is wrong, and it misses pages that were wrong from the start. It's no substitute for
  reading the page.
- **New pages and new src files need `docs/lib/DocMap.php`.** It's a hand-kept map from each page
  to its `src/` files, mirroring `docs/mds/index.md`. A new page needs an entry there and a row in
  `index.md`; a new src file belonging to an existing page goes into that entry's `src` list, so
  freshness tracks it. Nothing is discovered automatically, on purpose: don't automate it, and
  don't change the categories without asking.
- **`skills.MD`** is what agents write project code from, so an error there becomes a bug in
  someone's project. When you touch an area it has a section for, check that section against the
  current source, signatures included.
- **`docs/data/examples.php`** holds the site's code samples. They're curated by hand, so a
  renamed method breaks them silently.

---

## Packaging

`.gitattributes` decides what Composer installs into `vendor/` (`git archive` drops every
`export-ignore` path). Only `src/`, `bin/`, `docs/mds/`, `skills.MD`, `README.md`, `LICENSE` and
`composer.json` ship. A new root file or folder, or a new file or folder in `docs/` outside `mds/`,
ships unless you add it there. Check without committing: `git check-attr export-ignore -- <path>`
must say `set` for the ignored path itself (a file inside an ignored folder says `unspecified`, but
`git archive` skips the folder), or list a real archive with
`git archive --worktree-attributes HEAD | tar -t`.

## Plans and migration guides

- **`future-plans/`** holds the plan for the next release (`plan-<version>.md`): numbered items,
  each with Error, Solution and Impact, a checkbox, and "Decided while implementing" notes. Work
  through items in number order, and tick an item only when its code, tests, docs, changelog and
  migration section are all done. If the code contradicts the plan, stop and report it instead
  of improvising.
- **Each release has a migration guide**, written for a person or an agent upgrading a project.
  It's drafted next to the plan (`future-plans/migrate-<version>.md`) and moves to
  `docs/mds/migrations/` when the release is done (`migrate-2.4.0.md` is the model). Every
  breaking change gets a section: **Applies if** (a `grep` or a command to detect it), **What
  changed**, **What to do**.
