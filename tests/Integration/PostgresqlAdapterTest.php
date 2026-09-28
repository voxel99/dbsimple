<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests\Integration;

use Jam\DbSimple\Database;
use Jam\DbSimple\Generic;

/**
 * DBSIMPLE_TEST_PGSQL=postgresql://user@127.0.0.1/dbsimple_test
 */
final class PostgresqlAdapterTest extends AdapterTestCase
{
    protected function createDatabase(): Database
    {
        $dsn = (string) getenv('DBSIMPLE_TEST_PGSQL');
        if ($dsn === '') {
            $this->markTestSkipped('Set DBSIMPLE_TEST_PGSQL to run PostgreSQL adapter tests');
        }
        $db = Generic::connect($dsn);
        $db->query('DROP TABLE IF EXISTS users, tags');
        $db->query('CREATE TABLE users (
            id SERIAL PRIMARY KEY, name VARCHAR(64), email VARCHAR(64), age INT, parent_id INT NULL
        )');
        $db->query('CREATE TABLE tags (id SERIAL PRIMARY KEY, name VARCHAR(64) UNIQUE)');
        return $db;
    }

    /** INSERT в PostgreSQL возвращает OID, а не id: используйте RETURNING id или SELECT lastval() */
    protected function insertReturnsId(): bool
    {
        return false;
    }

    public function testReturningId(): void
    {
        $this->assertEquals(5, $this->db->selectCell('INSERT INTO users (name) VALUES (?) RETURNING id', 'Eve'));
    }
}
