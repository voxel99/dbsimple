<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests\Integration;

use Jam\DbSimple\Database;
use Jam\DbSimple\Generic;

/**
 * DBSIMPLE_TEST_MYSQL=mypdo://user:pass@127.0.0.1/dbsimple_test
 */
final class MypdoAdapterTest extends AdapterTestCase
{
    protected function createDatabase(): Database
    {
        $dsn = (string) getenv('DBSIMPLE_TEST_MYSQL');
        if ($dsn === '') {
            $this->markTestSkipped('Set DBSIMPLE_TEST_MYSQL to run MySQL adapter tests');
        }
        $db = Generic::connect($dsn);
        $db->query('DROP TABLE IF EXISTS users, tags');
        $db->query('CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64), email VARCHAR(64), age INT, parent_id INT NULL
        )');
        $db->query('CREATE TABLE tags (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) UNIQUE)');
        return $db;
    }

    public function testInsertIgnoreAndOnDuplicateKey(): void
    {
        $this->db->query('INSERT INTO tags (name) VALUES (?)', 'php');
        $this->db->query('INSERT IGNORE INTO tags (name) VALUES (?)', 'php');
        $id = $this->db->query('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', 'php');
        $this->assertEquals(1, $id);
        $this->assertEquals(1, $this->db->selectCell('SELECT COUNT(*) FROM tags'));
    }
}
