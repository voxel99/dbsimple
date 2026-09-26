# Jam DbSimple

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License: LGPL 2.1](https://img.shields.io/badge/License-LGPL_2.1-blue.svg)](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)

A modernized, high-performance database abstraction layer (DBAL) for PHP 8.1+, based on Dmitry Koterov's acclaimed **DbSimple** library.

It preserves the simplicity and expressiveness of DbSimple's macro-placeholder syntax and conditional query blocks while bringing full modern PHP support (strict types, union types, namespaces, PSR-4 autoloading, and PHPUnit test suite).

---

## Features

- **Macro-placeholders**:
  - `?` — auto-escaped scalar / value.
  - `?d` — integer casting.
  - `?f` — float casting.
  - `?#` — identifier escaping (table or column names).
  - `?a` — array expansion for `IN (...)` or batch inserts.
  - `?|` / `?&` — array expansion into `AND` / `OR` condition blocks.
  - `?s` — subquery insertion via `SubQuery` objects without query execution overhead.
- **Conditional SQL blocks `{ ... }`**:
  - Blocks are included only if all placeholder values inside them are not `DBSIMPLE_SKIP`.
- **Result Transformations**:
  - Built-in formatting using `ARRAY_KEY` and `PARENT_KEY` to construct indexed hashes or tree hierarchies directly from query results.
- **Query composition**:
  - First-class support for subqueries (`$db->subquery(...)`).
- **Flexible Debugging & Profiling**:
  - Multi-level SQL debugging (echo SQL, die, trace caller, row counts, or custom callable callback).
- **Multiple Database Adapters**:
  - MySQL (`Mypdo`) via PDO.
  - PostgreSQL (`Postgresql`).
  - SQLite (`Sqlite`).

---

## Installation

```bash
composer require jam/dbsimple
```

---

## Quick Start

### Connection

Connect via DSN string using `Connect` (lazy connection):

```php
use Jam\DbSimple\Connect;

$db = new Connect('mypdo://user:password@127.0.0.1/dbname?enc=utf8mb4');
```

Or instantiate an adapter directly:

```php
use Jam\DbSimple\Adapter\Mypdo;

$db = new Mypdo([
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => 'secret',
    'path' => 'my_database',
    'enc'  => 'utf8mb4',
]);
```

### Basic Queries

```php
// Fetch all rows
$users = $db->select('SELECT * FROM users WHERE status = ?', 'active');

// Fetch single row
$user = $db->selectRow('SELECT * FROM users WHERE id = ?d', $userId);

// Fetch single column
$emails = $db->selectCol('SELECT email FROM users WHERE role = ?', 'admin');

// Fetch single cell
$total = $db->selectCell('SELECT COUNT(*) FROM users');

// Paginated query with total count
$users = $db->selectPage($totalCount, 'SELECT * FROM users LIMIT ?d, ?d', 0, 20);

// INSERT / UPDATE / DELETE
$insertedId = $db->query('INSERT INTO users (name, email) VALUES (?, ?)', 'Alice', 'alice@example.com');
```

### Conditional Blocks

```php
$status = $_GET['status'] ?? null;

$query = $db->select(
    'SELECT * FROM users WHERE 1=1 { AND status = ? } ORDER BY id DESC',
    $status !== null ? $status : DBSIMPLE_SKIP
);
```

### Subqueries

```php
$sub = $db->subquery('WHERE role = ? AND active = ?d', 'editor', 1);

$rows = $db->select('SELECT * FROM users ?s', $sub);
```

---

## Authors & Credits

- **Dmitry Koterov** ([Dk Lab](http://en.dklab.ru)) — Original author and creator of DbSimple.
- **Ivan Borzenkov**, **Konstantin Zhinko** — Original contributors.
- **Jam Team** — PHP 8.x modernization, refactoring, and maintenance.

## License

This library is licensed under the [GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).
