# ephpm/db-laravel

A [Laravel database](https://laravel.com/docs/database) driver over
[ePHPm](https://ephpm.dev)'s in-process DB bridge. The same query
builder, `DB` facade, and Eloquent-compatible connection your app
already uses — but every statement is a direct C function call into the
embedded database (`ephpm_db_query()` / `ephpm_db_execute()`), with no
PDO, no MySQL wire protocol, and no socket even in-process.

```php
// config/database.php
'connections' => [
    'ephpm' => [
        'driver'   => 'ephpm',
        'database' => 'main',
        'prefix'   => '',
    ],
],
```

```php
DB::table('users')->where('active', true)->get();      // stdClass rows
DB::table('users')->insertGetId(['name' => 'Alice']);  // auto-increment id
DB::transaction(fn () => ...);                         // BEGIN/COMMIT via the bridge
```

The bridge runs MySQL-dialect SQL through the **same** litewire session
backend that serves ePHPm's MySQL wire frontend — `SHOW`/`DESCRIBE`
emulation, `SET NAMES` no-ops, and transaction statements all behave
exactly as they do over the wire, without the TCP round trip. Laravel's
MySQL query grammar, MySQL schema grammar, and MySQL processor are used
unchanged, so the SQL this driver emits is exactly what the translator
expects.

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Configuration](#configuration)
- [What works (verified by the test suite)](#what-works-verified-by-the-test-suite)
- [What is not supported](#what-is-not-supported)
- [Error handling](#error-handling)
- [Transactions](#transactions)
- [Testing without ePHPm](#testing-without-ephpm)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **Laravel 10.x, 11.x, or 12.x** (`illuminate/database` constraint is
  `^10.0 || ^11.0 || ^12.0`).
- **ePHPm v0.6.3 or newer** (current release: v0.10.2) — the
  `ephpm_db_query()` / `ephpm_db_execute()` SAPI functions this driver
  calls first shipped in the v0.6.3 release. You need an embedded SQLite
  backend configured:

  ```toml
  # ephpm.toml
  [db.sqlite]
  path = "./data/app.db"
  ```

  Under PHP-FPM, Apache mod_php, or the stock PHP CLI those functions
  don't exist and `SapiDbOps::__construct()` throws on instantiation.
  For development and CI without ePHPm, see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the bridge is present from any PHP file with:

```php
var_dump(function_exists('ephpm_db_query'));   // expect bool(true)
```

---

## Install

ePHPm packages are distributed via their GitHub repositories (not
Packagist). Add the repo as a Composer `vcs` repository, then require
the driver:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ephpm/db-laravel" }
  ],
  "require": {
    "ephpm/db-laravel": "^0.1"
  }
}
```

```bash
composer update ephpm/db-laravel
```

Laravel package discovery picks up `EphpmDbServiceProvider`
automatically — no `config/app.php` edit required. The provider
registers the driver with `Connection::resolverFor('ephpm', …)`, and the
rest is configuration in `config/database.php`.

---

## Configuration

Add a connection to `config/database.php`:

```php
'connections' => [
    'ephpm' => [
        'driver'   => 'ephpm',
        'database' => 'main',
        'prefix'   => '',
    ],
],
```

and select it in `.env`:

```dotenv
DB_CONNECTION=ephpm
```

Config keys:

| Key | Meaning |
|-----|---------|
| `driver` | Must be `ephpm`. |
| `database` | Logical database name reported by `getDatabaseName()`. The actual storage is whatever `[db.sqlite]` points at in `ephpm.toml` — the bridge has exactly one embedded database. |
| `prefix` | Standard Laravel table prefix, applied by the query grammar. |
| `ops` | Optional `DbOpsInterface` instance for tests (see below). Omit in production — the SAPI natives are used. |

`host`, `port`, `username`, `password`, `charset`, and `collation` are
meaningless here (there is no socket and no authentication) and are
ignored if present.

---

## What works (verified by the test suite)

| Feature | Status |
|---------|--------|
| `DB::select` / query builder `get()`, `first()`, `count()`, `pluck()` | yes — arrays of `stdClass`, native int/float/null column types |
| `insert()` | yes — returns `bool` |
| `insertGetId()` | yes — id wired from the bridge's `last_insert_id` |
| `insertOrIgnore()` | yes — the compiled `INSERT IGNORE` is translated to SQLite `INSERT OR IGNORE` |
| `upsert()` | **no** — rejected with a clear error; see [What is not supported](#what-is-not-supported) |
| `update()` / `delete()` affected-row counts | yes |
| `DB::transaction()` commit, and rollback + rethrow on exception | yes |
| Nested transactions (`SAVEPOINT transN`) | yes — see [Transactions](#transactions) |
| `QueryException` with SQL + bindings + MySQL errno | yes |
| Duplicate key → `UniqueConstraintViolationException` (errno 1062) | yes |
| `DateTimeInterface` / `bool` bindings | yes — normalized by `prepareBindings()` |
| Non-scalar bindings | rejected with `InvalidArgumentException` before reaching the bridge |
| `cursor()` | yes, but **not streaming** — see below |
| `unprepared()` | yes |
| `pretend()` / query logging | yes |
| Schema builder / migrations | emits MySQL DDL through the MySQL schema grammar (verified via `pretend()`); execution depends on litewire's DDL translation — see below |

The suite (30 tests) runs the driver against a `pdo_sqlite` polyfill of
the bridge (`SqlitePdoDbOps`) that reproduces the native result
and error shapes documented in ePHPm's `ephpm_wrapper.c`, including
litewire's errno mapping. What the polyfill does **not** reproduce is
litewire's MySQL→SQLite SQL translation itself — so anything that works
here is known to emit the right MySQL-dialect SQL, and the translation
of that SQL is ePHPm's (tested) responsibility.

## What is not supported

- **`upsert()`** — the MySQL query grammar compiles it to
  `INSERT … ON DUPLICATE KEY UPDATE`, which the embedded engine
  (litewire → Turso) rejects. Turso does not honour
  `INSERT … ON CONFLICT … DO UPDATE` either, and rewriting to
  `INSERT OR REPLACE` would silently overwrite columns you did not name in
  the update set (and reassign `AUTO_INCREMENT` ids), so there is no safe
  automatic translation. The driver throws a `RuntimeException` explaining
  this rather than running it wrong. Use `insertOrIgnore()` followed by an
  explicit `update()`, or perform the insert-or-update in application code.
- **`selectResultSets()`** — the bridge stages exactly one result set
  per statement; this method throws `RuntimeException`.
- **`cursor()` is not lazy.** `ephpm_db_query()` buffers the complete
  result set before the generator yields its first row. The generator
  API exists for compatibility, not constant-memory iteration. Don't
  use it to walk millions of rows.
- **Read/write splitting** — the bridge is one per-thread session;
  `read`/`write` config and the `$useReadPdo` flag are ignored.
- **`getPdo()` does not return a `\PDO`.** It returns the internal
  bridge handle (transaction control + `lastInsertId()` + `quote()`
  only). Code that reaches for the raw PDO to prepare statements will
  fail — by design.
- **Schema dumps** (`schema:dump`) — `MySqlSchemaState` shells out to
  `mysqldump`, which has nothing to connect to.
- **Sticky "sticky"/reconnect semantics** — `reconnect()`,
  `disconnect()`, and `reconnectIfMissingConnection()` are no-ops; the
  per-thread session is owned and recycled by the ePHPm runtime.

## Error handling

The bridge throws `\Exception` with the **MySQL errno as the exception
code** and a `SQLSTATE[xxxxx]: <backend message>` message. This driver
lets Laravel wrap those into `Illuminate\Database\QueryException`
(preserving SQL, bindings, and the errno via `getCode()`), and detects
unique-key violations by errno 1062 so
`UniqueConstraintViolationException` and Eloquent's
`createOrFirst()`-style upsert paths work.

The errno mapping is litewire's (deliberately conservative):

| Backend condition | errno | SQLSTATE |
|---|---|---|
| unique / primary key violation | 1062 | 23000 |
| foreign key violation | 1452 | 23000 |
| database locked / busy | 1205 | HY000 |
| read-only database | 1290 | HY000 |
| everything else (incl. syntax errors, missing tables) | 1105 | HY000 |

Note the last row: unlike a real MySQL server, a syntax error is **not**
1064/42000 and a missing table is **not** 1146/42S02 — both surface as
1105/HY000. Code that branches on those specific codes won't match.

## Transactions

`BEGIN` / `COMMIT` / `ROLLBACK` are sent to the bridge as plain SQL —
the per-thread litewire session tracks transaction state exactly as it
does for wire clients. Nested `DB::beginTransaction()` calls compile to
`SAVEPOINT transN` / `ROLLBACK TO SAVEPOINT transN` via Laravel's MySQL
grammar; litewire's translator passes SAVEPOINT statements through to
SQLite (which shares the syntax), and its write-admission layer tracks
savepoint nesting, so nested transactions behave as expected.

Two runtime properties worth knowing (both are ePHPm bridge semantics,
not driver features):

- **Transactions are per-thread and end with the request.** A
  transaction the script leaves open at request end is rolled back by
  the runtime with a warning — it cannot leak into the next request.
- **Transaction attempts don't retry across connections.** There is no
  "lost connection" concept; `DB::transaction($fn, $attempts)` retries
  only help for deadlock-classed errors (1205).

## Testing without ePHPm

`EphpmConnection` takes any `DbOpsInterface`, and the connection config
accepts an `ops` key, so your app's test suite can swap in the bundled
`pdo_sqlite` polyfill and run on plain php-cli:

```php
use Ephpm\Db\Laravel\SqlitePdoDbOps;

// e.g. in a test-only database config
'connections' => [
    'ephpm' => [
        'driver'   => 'ephpm',
        'database' => 'main',
        'prefix'   => '',
        'ops'      => new SqlitePdoDbOps(),   // in-memory SQLite
    ],
],
```

`SqlitePdoDbOps` reproduces the bridge's result shapes, transaction
passthrough, and errno mapping, but **not** litewire's MySQL→SQLite SQL
translation — SQL must be parseable by SQLite itself. Query-builder
output is (SQLite accepts backtick quoting); MySQL-specific DDL like
`AUTO_INCREMENT` is not, so create test tables with SQLite DDL directly
on `$ops->pdo()`. It is for tests only — never use it in production.

## How it works

ePHPm registers `ephpm_db_query()` and `ephpm_db_execute()` into PHP's
global function table. Each call runs MySQL-dialect SQL through a
per-thread litewire session against the embedded SQLite backend — the
same code path the MySQL wire frontend uses, minus the socket and the
protocol parsing.

This package maps `Illuminate\Database\Connection` onto those two
functions:

- `select`/`cursor` → `ephpm_db_query()`, rows cast to `stdClass`.
- `insert`/`update`/`delete`/`statement`/`unprepared` →
  `ephpm_db_execute()`, which returns
  `{affected_rows, last_insert_id}`; the connection caches the last
  non-zero `last_insert_id` so `insertGetId()` works (Laravel inserts
  first, then asks for the id).
- Transaction control from Laravel's `ManagesTransactions` trait is
  intercepted by a PDO-shaped handle (`EphpmPdoHandle`) whose
  `beginTransaction()`/`commit()`/`rollBack()`/`exec()` send plain SQL
  to the bridge — so every Laravel version's trait logic (events,
  after-commit callbacks, savepoint bookkeeping) runs untouched.
- Grammar/processor are Laravel's stock MySQL ones; the driver is
  registered under the name `ephpm` via `Connection::resolverFor()` in
  the auto-discovered service provider.

See [ephpm.dev](https://ephpm.dev) for the runtime architecture.

## License

MIT — see [LICENSE](LICENSE).
