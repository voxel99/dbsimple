<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests;

use Jam\DbSimple\Adapter\Sqlite;
use Jam\DbSimple\Connect;
use Jam\DbSimple\DatabaseException;
use Jam\DbSimple\DatabaseInterface;
use Jam\DbSimple\Generic;
use PHPUnit\Framework\TestCase;

final class ConnectTest extends TestCase
{
    public function testConstantsAreAvailableWithoutLoadingClasses(): void
    {
        // Константы подключаются автозагрузчиком Composer (autoload.files)
        $this->assertTrue(defined('DBSIMPLE_SKIP'));
        $this->assertSame('ARRAY_KEY', DBSIMPLE_ARRAY_KEY);
        $this->assertSame('PARENT_KEY', DBSIMPLE_PARENT_KEY);
    }

    public function testConnectIsLazyAndImplementsInterface(): void
    {
        $db = new Connect('sqlite::memory:');
        $this->assertInstanceOf(DatabaseInterface::class, $db);
        $this->assertFalse($db->isConnected());
        $this->assertSame('Sqlite', $db->getShema());

        $this->assertEquals(2, $db->selectCell('SELECT 1 + ?d', 1));
        $this->assertTrue($db->isConnected());
        $this->assertInstanceOf(Sqlite::class, $db->getDatabase());
    }

    public function testInitQueriesRunAfterConnect(): void
    {
        $db = new Connect('sqlite::memory:');
        $db->addInit('CREATE TABLE t (v INTEGER)');
        $db->addInit('INSERT INTO t (v) VALUES (?d)', 7);
        $this->assertFalse($db->isConnected());
        $this->assertEquals(7, $db->selectCell('SELECT v FROM t'));
    }

    public function testSelectPageThroughConnect(): void
    {
        $db = new Connect('sqlite::memory:');
        $db->query('CREATE TABLE t (v INTEGER)');
        $db->query('INSERT INTO t (v) VALUES (?a)', [[1], [2], [3]]);
        $total = 0;
        $rows = $db->selectPage($total, 'SELECT v FROM t ORDER BY v LIMIT ?d', 2);
        $this->assertSame(3, (int) $total);
        $this->assertCount(2, $rows);
    }

    public function testPrefixLoggerAndErrorHandlerSetBeforeConnect(): void
    {
        $db = new Connect('sqlite::memory:');
        $db->setIdentPrefix('app_');
        $log = [];
        $db->setLogger(function ($db, $sql) use (&$log) {
            $log[] = $sql;
        });
        $db->setErrorHandler(false);

        $db->query('CREATE TABLE app_users (id INTEGER)');
        $this->assertEquals(0, $db->selectCell('SELECT COUNT(*) FROM ?_users'));
        $this->assertContains('SELECT COUNT(*) FROM app_users', $log);
        $this->assertFalse($db->select('SELECT * FROM missing'));
        $this->assertNotEmpty($db->errmsg);
    }

    public function testQueryErrorThrowsByDefault(): void
    {
        $db = new Connect('sqlite::memory:');
        $this->expectException(DatabaseException::class);
        $db->select('SELECT * FROM missing');
    }

    public function testConnectionErrorThrowsWithoutPassword(): void
    {
        $db = new Connect('mypdo://user:secret@127.0.0.1:1/nope');
        try {
            $db->selectCell('SELECT 1');
            $this->fail('DatabaseException expected');
        } catch (DatabaseException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testConnectionErrorGoesToCustomHandler(): void
    {
        $db = new Connect('mypdo://user:secret@127.0.0.1:1/nope');
        $messages = [];
        $db->setErrorHandler(function (string $message) use (&$messages) {
            $messages[] = $message;
        });
        $db->selectCell('SELECT 1');
        $this->assertNotEmpty($messages);
    }

    public function testUnknownDriver(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unknown database driver: nosuchdb');
        (new Connect('nosuchdb://localhost/db'))->selectCell('SELECT 1');
    }

    public function testRegisterAdapter(): void
    {
        Generic::registerAdapter('memory', Sqlite::class);
        $db = new Connect('memory:');
        $this->assertEquals(1, $db->selectCell('SELECT 1'));
    }

    public function testParseDsn(): void
    {
        $this->assertSame(
            ['scheme' => 'mypdo', 'host' => '127.0.0.1', 'port' => 3306, 'user' => 'u', 'pass' => 'p', 'path' => '/app', 'query' => 'enc=utf8mb4', 'enc' => 'utf8mb4'],
            array_diff_key(Generic::parseDSN('mypdo://u:p@127.0.0.1:3306/app?enc=utf8mb4'), ['dsn' => 1])
        );
        $this->assertSame('/var/db/app.sqlite', Generic::parseDSN('sqlite:///var/db/app.sqlite')['path']);
        $this->assertSame(':memory:', Generic::parseDSN('sqlite::memory:')['path']);

        $socket = Generic::parseDSN('mypdo:unix_socket=/tmp/mysql.sock;dbname=app;charset=utf8mb4');
        $this->assertSame(['/tmp/mysql.sock', 'app', 'utf8mb4'], [$socket['socket'], $socket['path'], $socket['enc']]);
    }

    public function testPoolReturnsSameInstance(): void
    {
        $this->assertSame(Connect::get('sqlite::memory:'), Connect::get('sqlite::memory:'));
    }
}
