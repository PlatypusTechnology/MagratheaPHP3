# MagratheaPHP

Version 3 of the acclaimed Magrathea PHP Framework — a lightweight PHP framework with a
built-in ORM, database abstraction, REST API tooling, JWT authentication, an admin panel, caching,
mail, logging, and other utilities needed to build web applications without pulling in a large
stack of dependencies. It generates no code: models and controls are small classes written by hand
(or by an agent), and the framework checks them against the database.

## Installation

```bash
composer require platypustechnology/magratheaphp
```

## Features

- **Core** (`MagratheaPHP`) — application bootstrap, autoloader, environments.
- **ORM & database layer** (`DB`, `MagratheaModel`, `MagratheaModelControl`) — models, query
  building, and database control without hand-written SQL for common cases. The database owns
  `created_at`/`updated_at`.
- **Schema and checks** (`DB\Schema`, `DB\SchemaCheck`, `ProjectCheck`) — tables as data, model ↔
  table drift, and the project's configuration, folders and timezone, as findings.
- **Dumps** (`DB\Dump`, `DB\SqlSplitter`) — export and import, with `mysqldump` or in PHP.
- **REST API tooling** (`MagratheaApi`, `MagratheaApiControl`, `MagratheaApiAuth`) — routing,
  CORS, JWT authentication, pagination, caching.
- **Admin panel** (`Admin/`) — users, app config, cache, CORS origins, CRUD features, and a Database
  section: explorer, queries, query editor, dumps, checks.
- **CLI** (`vendor/bin/magrathea`) — checks, schema, queries, dumps and admin users from a shell,
  with `--json` output for agents.
- **Setup Wizard** (`Bootstrap/`, `public/setup.php`) — a token-locked browser wizard for a new
  install.
- **Utilities** — mail (`MagratheaMail`, `MagratheaMailSMTP`), logging (`Logger`), debugging
  (`Debugger`), compressors, and a config system (`Config`, `ConfigApp`, `ConfigFile`).

## For agents

`skills.MD` is the coding cookbook for projects that use MagratheaPHP, and `docs/mds/` the
reference. Both ship with the package: point a project's `AGENTS.md` at
`vendor/platypustechnology/magratheaphp/skills.MD` and `…/docs/mds/index.md`.

## Documentation

Full documentation — class references, method signatures (kept live via PHP Reflection), and
narrative usage guides — is available online:

**https://www.platypusweb.com.br/magratheaphp3/docs/**

The documentation site's source is in this repository under [`docs/`](docs/index.php)
(PHP-rendered; point a web server or `php -S` at that folder to browse it locally), with its
narrative source files in [`docs/mds/`](docs/mds/index.md). The Composer package ships only
`docs/mds/` (at `vendor/platypustechnology/magratheaphp/docs/mds/`), not the site.

## License

MIT
