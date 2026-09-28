# Jam DbSimple

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License: LGPL 2.1](https://img.shields.io/badge/License-LGPL_2.1-blue.svg)](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)

A modernized fork of Dmitry Koterov's **DbSimple** for PHP 8.1+: plain SQL with expressive
placeholders, optional query blocks, result shaping (hashes and trees) and lazy connections.
It is also the storage layer of [`jam/dbsimple-models`](https://github.com/voxel99/dbsimple-models).

- [Installation](#installation)
- [Connecting](#connecting)
- [Queries](#queries)
- [Placeholders](#placeholders)
- [Optional blocks](#optional-blocks)
- [Shaping results: ARRAY_KEY and PARENT_KEY](#shaping-results-array_key-and-parent_key)
- [Subqueries](#subqueries)
- [Transactions](#transactions)
- [Error handling](#error-handling)
- [Logging, statistics and debugging](#logging-statistics-and-debugging)
- [Adapters](#adapters)
- [Writing an adapter](#writing-an-adapter)
- [Tests](#tests)
- [Upgrade notes](#upgrade-notes)

---

## Installation

```bash
composer require jam/dbsimple
```

Install the PHP extension for your database: `pdo_mysql` (MySQL / MariaDB), `pgsql` (PostgreSQL)
or `pdo_sqlite` (SQLite).

## Connecting

### Lazy connection (recommended)

`Connect` stores the DSN and connects on the first query, so creating it costs nothing:

```php
use Jam\DbSimple\Connect;

$db = new Connect('mypdo://user:password@127.0.0.1/app?enc=utf8mb4');
$db->setIdentPrefix('app_');                  // for ?_ placeholders
$db->addInit('SET time_zone = ?', '+00:00');  // runs right after connecting
$db->setLogger(fn ($db, string $sql) => error_log($sql));

$user = $db->selectRow('SELECT * FROM ?_users WHERE id = ?d', 42); // connects here
```

`Connect::get($dsn)` returns one shared instance per DSN. `$db->getDatabase()` returns the
underlying adapter (connecting if needed), `$db->isConnected()` tells whether it happened.

### DSN formats

| Database | DSN |
|----------|-----|
| MySQL / MariaDB (PDO) | `mypdo://user:pass@host:3306/dbname?enc=utf8mb4` |
| MySQL via socket | `mypdo:unix_socket=/var/run/mysqld/mysqld.sock;dbname=app;user=root;pass=;charset=utf8mb4` |
| PostgreSQL | `postgresql://user:pass@host:5432/dbname` |
| SQLite file | `sqlite:///var/data/app.db` or `sqlite:relative/path.db` |
| SQLite in memory | `sqlite::memory:` |

Extra query-string parameters are passed to the adapter: `enc` (MySQL charset), `persist=1`,
`timeout=5`, `prefix=app_` (table prefix for `?_`).

### Eager connection

```php
use Jam\DbSimple\Generic;
use Jam\DbSimple\Adapter\Mypdo;

$db = Generic::connect('mypdo://user:pass@127.0.0.1/app');       // adapter chosen by the scheme
$db = new Mypdo(['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'path' => 'app', 'enc' => 'utf8mb4']);
```

## Queries

| Method | Returns |
|--------|---------|
| `select($sql, ...$args)` | list of rows (associative arrays) |
| `selectRow($sql, ...$args)` | first row, or `[]` if nothing found |
| `selectCol($sql, ...$args)` | first column as a list |
| `selectCell($sql, ...$args)` | first cell, or `null` |
| `selectPage(&$total, $sql, ...$args)` | rows of the page; `$total` — count without `LIMIT` |
| `query($sql, ...$args)` | `INSERT`: last insert id; `UPDATE`/`DELETE`: affected rows; `SELECT`: rows |

On error, a `DatabaseException` is thrown (see [Error handling](#error-handling)).

```php
$id = $db->query('INSERT INTO users (?#) VALUES (?a)', ['name', 'email'], ['Alice', 'alice@example.com']);
$affected = $db->query('UPDATE users SET ?a WHERE id = ?d', ['name' => 'Alice C.'], $id);

$total = 0;
$page = $db->selectPage($total, 'SELECT * FROM users WHERE active = 1 ORDER BY id LIMIT ?d OFFSET ?d', 20, 40);
```

## Placeholders

Values are always escaped by the adapter — never concatenate them into SQL.

| Placeholder | Meaning | Example → SQL |
|-------------|---------|---------------|
| `?` | escaped scalar (string/number), `null` → `NULL` | `name = ?` + `"O'Neil"` → `name = 'O\'Neil'` |
| `?d` | integer | `id = ?d` + `"42abc"` → `id = 42` |
| `?f` | float | `price > ?f` + `9.5` → `price > 9.5` |
| `?n` | integer, empty value → `NULL` | `parent_id = ?n` + `0` → `parent_id = NULL` |
| `?#` | identifier(s) | `?#` + `'users'` → `` `users` ``; `['id', 'name']` → `` `id`, `name` ``; `['u' => ['id'], 'p' => '*']` → `` `u`.`id`, `p`.* `` |
| `?a` | list | `IN (?a)` + `[1, 2]` → `IN (1, 2)` |
| `?a` | assignments | `SET ?a` + `['name' => 'x', 'n' => null]` → ``SET `name` = 'x', `n` = NULL`` |
| `?a` | multi-row `VALUES` | `VALUES (?a)` + `[[1, 'x'], [2, 'y']]` → `VALUES (1, 'x'), (2, 'y')` |
| `?\|` | OR of AND-groups | `(?\|)` + `[['a' => 1, 'b' => 2], ['a' => 3]]` → ``(`a` = 1 AND `b` = 2) OR (`a` = 3)`` |
| `?&` | AND of OR-groups | `(?&)` + `[['a' => 1, 'b' => 2], ['c' => 3]]` → ``(`a` = 1 OR `b` = 2) AND (`c` = 3)`` |
| `?s` | subquery (`SubQuery` object) | see [Subqueries](#subqueries) |
| `?_` | table prefix (no argument) | `FROM ?_users` → `FROM app_users` |

Notes:

- `?|` and `?&` produce `a) OR (b` — wrap the placeholder in parentheses.
- An empty array in `?a` produces `IN ()`, which is invalid SQL. Put such conditions into an
  [optional block](#optional-blocks): an empty array skips the block.
- Placeholders inside string literals, backtick identifiers and `/* comments */` are not replaced.

## Optional blocks

A block in curly braces is kept only if **none** of its placeholders received `DBSIMPLE_SKIP`:

```php
$rows = $db->select(
    'SELECT * FROM users
     WHERE 1 = 1
       { AND status = ? }
       { AND id IN (?a) }
       { AND created_at >= ? }',
    $filter['status'] ?? DBSIMPLE_SKIP,
    $filter['ids'] ?? [],               // an empty array skips the block too
    $filter['from'] ?? DBSIMPLE_SKIP
);
```

Alternatives separated by `|` — the first alternative with all values present wins (every
alternative consumes its arguments, so the argument order stays stable):

```php
// ORDER BY a user-chosen column, falling back to id
$db->select('SELECT * FROM users ORDER BY { ?# DESC | id DESC }', $sort ?? DBSIMPLE_SKIP);
```

Blocks can be nested.

## Shaping results: ARRAY_KEY and PARENT_KEY

Special column aliases change the shape of the result:

```php
// Hash by id: [1 => ['name' => 'Alice'], 2 => [...]]
$db->select('SELECT id AS ARRAY_KEY, name FROM users');

// Nested hash: [$groupId => [$userId => row]]
$db->select('SELECT group_id AS ARRAY_KEY_1, id AS ARRAY_KEY_2, name FROM users');

// Key => value
$db->selectCol('SELECT email AS ARRAY_KEY, name FROM users');   // ['a@x.io' => 'Alice', ...]

// Tree: roots at the top level, children in 'childNodes' (keyed by ARRAY_KEY)
$tree = $db->select('SELECT id AS ARRAY_KEY, parent_id AS PARENT_KEY, title FROM categories');
```

`setClassName($class)` turns the rows of the next query into objects (`new $class($row)`):

```php
$users = $db->setClassName(UserDto::class)->select('SELECT * FROM users');
```

## Subqueries

`subquery()` expands placeholders without running a query; the result is inserted with `?s`.
Useful for building queries from reusable parts:

```php
$active = $db->subquery('SELECT user_id FROM sessions WHERE seen_at > ?', $since);
$users = $db->select('SELECT * FROM users WHERE id IN (?s) { AND role = ? }', $active, $role ?? DBSIMPLE_SKIP);
```

## Transactions

```php
$db->transaction();
try {
    $db->query('UPDATE accounts SET balance = balance - ?d WHERE id = ?d', 100, $from);
    $db->query('UPDATE accounts SET balance = balance + ?d WHERE id = ?d', 100, $to);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
```

## Error handling

By default every database error (connection, syntax, constraint) throws
`Jam\DbSimple\DatabaseException`:

```php
use Jam\DbSimple\DatabaseException;

try {
    $db->select('SELECT * FROM missing_table WHERE id = ?d', 5);
} catch (DatabaseException $e) {
    $e->getMessage();   // driver message + "at /app/src/UserRepository.php line 42"
    $e->getQuery();     // SQL with values substituted
    $e->getContext();   // the caller's file and line (outside DbSimple)
    $e->getDbCode();    // driver error code
}
```

`setErrorHandler()` changes this:

```php
$db->setErrorHandler(false);             // silent: methods return false, details in $db->error / $db->errmsg
$db->setErrorHandler(function (string $message, array $info) use ($logger) {
    $logger->error($message, $info);     // info: code, message, query, context
    throw new MyAppException($message);
});
$db->setErrorHandler(null);              // back to the default (DatabaseException)
```

If your code wraps DbSimple (a repository or model layer), exclude it from the reported
context: `$db->getDatabase()->addIgnoreInTrace('App\\\\Repository\\\\.*');`

## Logging, statistics and debugging

```php
// Called before every query with the expanded SQL (and after it with a "-- N ms; returned ..." line)
$db->setLogger(function ($db, string $sql, ?array $caller) {
    if (!str_starts_with(ltrim($sql), '--')) {
        error_log($sql);
    }
});

$db->getStatistics();   // ['time' => total seconds, 'count' => number of queries]
```

Quick debugging (global for all connections):

| Mode | Effect |
|------|--------|
| `debugSql(Database::DEBUG_SQL_ECHO)` | print every query |
| `debugSql(Database::DEBUG_SQL_ECHO_WITH_TRACE)` | print the query and the calling file/line |
| `debugSql(Database::DEBUG_SQL_ECHO_AND_COUNT_ROWS)` | print the query, row count and time |
| `debugSql(Database::DEBUG_SQL_ECHO_NOT_EXECUTED)` | print queries without executing them |
| `debugSql(Database::DEBUG_SQL_DIE)` | print the first query and stop |
| `debugSql(fn (string $sql) => ...)` | custom callback |
| `debugSql(Database::DEBUG_SQL_NONE)` | off |

## Adapters

| Adapter | Scheme | Notes |
|---------|--------|-------|
| `Adapter\Mypdo` | `mypdo` | MySQL / MariaDB via PDO. `selectPage()` uses `SQL_CALC_FOUND_ROWS`. |
| `Adapter\Postgresql` | `postgresql` | ext-pgsql, native `$1` placeholders with a prepared-statement cache. `INSERT` returns the OID, not the id: use `INSERT ... RETURNING id` with `selectCell()`. |
| `Adapter\Sqlite` | `sqlite` | SQLite via PDO. Translates `INSERT IGNORE` to `INSERT OR IGNORE`; `createFunction()` registers PHP functions; `getPdo()` gives the raw connection. `ON DUPLICATE KEY UPDATE` and locking clauses are not supported. |

Identifiers are quoted with backticks in MySQL and SQLite and with double quotes in PostgreSQL.

## Writing an adapter

An adapter extends `Jam\DbSimple\Database` and implements a handful of hooks; placeholders,
optional blocks, result shaping, logging and error handling come from the base class:

| Method | Purpose |
|--------|---------|
| `__construct(array $dsn)` | connect; on failure call `$this->_setLastError($code, $message, $context)` |
| `_performQuery(array $query)` | call `$this->_expandPlaceholders($query, false)`, run `$query[0]`; return rows (array), insert id, affected rows, or `$this->_setLastError(...)` |
| `_performEscape($value, $isIdent)` | quote a value or an identifier |
| `_performTransaction()`, `_performCommit()`, `_performRollback()` | transactions |
| `_performTransformQuery(&$query, $how)` | `CALC_TOTAL` / `GET_TOTAL` for `selectPage()`; return `true` if handled |
| `_performGetPlaceholderIgnoreRe()` | regex of fragments where placeholders are not replaced (string literals, comments) |
| `_performFetch($result)`, `_performNewBlob()`, `_performGetBlobFieldNames()` | only for adapters returning cursors / BLOB objects |

`Adapter\Sqlite` is a compact reference implementation. Register a custom adapter under its own
DSN scheme:

```php
Generic::registerAdapter('clickhouse', ClickhouseAdapter::class);
$db = new Connect('clickhouse://user:pass@host/db');
```

## Tests

```bash
composer install
vendor/bin/phpunit                                   # unit tests + SQLite integration tests

DBSIMPLE_TEST_MYSQL=mypdo://user:pass@127.0.0.1/dbsimple_test \
DBSIMPLE_TEST_PGSQL=postgresql://user:pass@127.0.0.1/dbsimple_test \
vendor/bin/phpunit                                   # the same scenarios on MySQL and PostgreSQL
```

The integration suite (`tests/Integration/AdapterTestCase.php`) runs identical scenarios on every
adapter — start there when adding a new one.

## Upgrade notes

- **Errors throw `DatabaseException` by default.** Previously `Connect` printed the error together
  with the SQL and called `exit()`, and adapters created directly silently returned `false`.
  Call `setErrorHandler(false)` to get the old silent behaviour.
- **`Adapter\Sqlite` works on PDO.** The old implementation required the `sqlite2` extension,
  which does not exist in modern PHP.
- The constants `DBSIMPLE_SKIP`, `DBSIMPLE_ARRAY_KEY`, `DBSIMPLE_PARENT_KEY` are defined in
  `src/constants.php`, loaded by Composer's autoloader.
- The undocumented `{?...}` "conditional block" syntax was removed: it had been disabled since
  `{?d ...}` blocks were fixed, and `{?` now always starts a block with a placeholder.
- `Connect` implements `DatabaseInterface`, so it can be type-hinted like an adapter.

## Authors & Credits

- **Dmitry Koterov** ([Dk Lab](http://en.dklab.ru)) — original author of DbSimple.
- **Ivan Borzenkov**, **Konstantin Zhinko** — original contributors.
- **Jam Team** — PHP 8.x modernization, refactoring, and maintenance.

## License

[GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).
