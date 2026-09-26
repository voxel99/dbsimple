<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests;

use Jam\DbSimple\Database;
use PHPUnit\Framework\TestCase;

class PlaceholderTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Создаем анонимный тестовый класс на базе Database для проверки плейсхолдеров
        $this->db = new class extends Database {
            protected function _performQuery($arrayQuery) { return true; }
            protected function _performFetch($result) { return []; }
            protected function _performGetBlobFieldNames($result) { return []; }
            protected function _performGetPlaceholderIgnoreRe() { return '(?!)'; }
            protected function _performTransformQuery(&$query, $how) { return null; }
            protected function _performEscape($value, $isIdent = false)
            {
                if ($isIdent) {
                    return '`' . str_replace('`', '``', $value) . '`';
                }
                return "'" . addslashes((string)$value) . "'";
            }
            protected function _performTransaction($mode = null) { return true; }
            protected function _performCommit() { return true; }
            protected function _performRollback() { return true; }
            protected function _performNewBlob($blob_id = null) { return new \stdClass(); }

            public function testExpand(array $query): string
            {
                $this->_expandPlaceholders($query, false);
                return $query[0];
            }
        };
    }

    public function testScalarAndIntPlaceholders(): void
    {
        $sql = $this->db->testExpand(['SELECT * FROM users WHERE id = ?d AND name = ?', 123, 'John']);
        $this->assertSame("SELECT * FROM users WHERE id = 123 AND name = 'John'", $sql);
    }

    public function testIdentifierPlaceholder(): void
    {
        $sql = $this->db->testExpand(['SELECT ?# FROM ?#', 'user_id', 'users']);
        $this->assertSame("SELECT `user_id` FROM `users`", $sql);
    }

    public function testArrayPlaceholder(): void
    {
        $sql = $this->db->testExpand(['SELECT * FROM users WHERE id IN (?a)', [1, 2, 3]]);
        $this->assertSame("SELECT * FROM users WHERE id IN (1, 2, 3)", $sql);
    }

    public function testOptionalBlocks(): void
    {
        $sqlWithVal = $this->db->testExpand(['SELECT * FROM users WHERE 1=1 { AND status = ? }', 'active']);
        $this->assertSame("SELECT * FROM users WHERE 1=1   AND status = 'active'  ", $sqlWithVal);

        $sqlSkipped = $this->db->testExpand(['SELECT * FROM users WHERE 1=1 { AND status = ? }', DBSIMPLE_SKIP]);
        $this->assertSame("SELECT * FROM users WHERE 1=1 ", $sqlSkipped);
    }
}
