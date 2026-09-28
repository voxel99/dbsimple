<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests\Integration;

use Jam\DbSimple\Adapter\Sqlite;
use Jam\DbSimple\Database;

final class SqliteAdapterTest extends AdapterTestCase
{
    protected function createDatabase(): Database
    {
        $db = new Sqlite(['path' => ':memory:']);
        $db->getPdo()->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64), email VARCHAR(64), age INTEGER, parent_id INTEGER
        )');
        $db->getPdo()->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) UNIQUE)');
        return $db;
    }

    public function testInsertIgnoreIsTranslated(): void
    {
        $this->db->query('INSERT INTO tags (name) VALUES (?)', 'php');
        $this->db->query('INSERT IGNORE INTO tags (name) VALUES (?)', 'php');
        $this->assertEquals(1, $this->db->selectCell('SELECT COUNT(*) FROM tags'));
    }

    public function testCustomFunction(): void
    {
        /** @var Sqlite $db */
        $db = $this->db;
        $db->createFunction('reverse', 'strrev', 1);
        $this->assertSame('ecilA', $db->selectCell('SELECT reverse(name) FROM users WHERE id = 1'));
    }
}
