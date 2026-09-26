<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests;

use Jam\DbSimple\Database;
use Jam\DbSimple\SubQuery;
use PHPUnit\Framework\TestCase;

class SubQueryCompositionTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
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

    public function testSubQueryWithPlaceholderS(): void
    {
        $sub = $this->db->subquery('WHERE role = ? AND active = ?d', 'admin', 1);
        $sql = $this->db->testExpand(['SELECT * FROM users ?s', $sub]);

        $this->assertSame("SELECT * FROM users WHERE role = 'admin' AND active = 1", $sql);
    }
}
