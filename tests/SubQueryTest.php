<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests;

use Jam\DbSimple\Connect;
use Jam\DbSimple\SubQuery;
use PHPUnit\Framework\TestCase;

class SubQueryTest extends TestCase
{
    public function testSubQueryInstantiationAndGet(): void
    {
        $args = ['WHERE status = ?', 'active'];
        $sub = new SubQuery($args);

        $params = [];
        $sql = $sub->get($params);

        $this->assertSame('WHERE status = ?', $sql);
        $this->assertSame(['active'], $params);
    }
}
