<?php

declare(strict_types=1);

namespace Jam\DbSimple\Tests\Integration;

use Jam\DbSimple\Database;
use Jam\DbSimple\DatabaseException;
use PHPUnit\Framework\TestCase;

/**
 * Общий набор сценариев для всех адаптеров. Наследник создаёт соединение и схему.
 *
 * Таблицы: users(id, name, email, age, parent_id), tags(id, name)
 */
abstract class AdapterTestCase extends TestCase
{
    protected Database $db;

    /** @var array<int, string> */
    protected array $log = [];

    /** Создать адаптер с чистой схемой (таблицы users, tags) */
    abstract protected function createDatabase(): Database;

    /** Поддерживает ли адаптер возврат id из INSERT */
    protected function insertReturnsId(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        $this->db = $this->createDatabase();
        $this->db->setLogger(function ($db, $sql): void {
            if (is_string($sql) && !str_starts_with(ltrim($sql), '--')) {
                $this->log[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            }
        });
        foreach ([['Alice', 'alice@x.io', 30, 0], ['Bob', 'bob@x.io', 25, 1], ['Carol', 'carol@x.io', 35, 1], ['Dave', "d'ave@x.io", 40, 3]] as $row) {
            $this->db->query('INSERT INTO ?_users (name, email, age, parent_id) VALUES (?a)', $row);
        }
        $this->log = [];
    }

    public function testSelectMethods(): void
    {
        $rows = $this->db->select('SELECT name, age FROM ?_users WHERE age > ?d ORDER BY id', 26);
        $this->assertEquals([['name' => 'Alice', 'age' => 30], ['name' => 'Carol', 'age' => 35], ['name' => 'Dave', 'age' => 40]], $rows);

        $this->assertEquals(['name' => 'Bob'], $this->db->selectRow('SELECT name FROM ?_users WHERE name = ?', 'Bob'));
        $this->assertSame([], $this->db->selectRow('SELECT name FROM ?_users WHERE name = ?', 'Nobody'));
        $this->assertSame(['Alice', 'Bob'], $this->db->selectCol('SELECT name FROM ?_users WHERE age < ?d ORDER BY id', 35));
        $this->assertEquals(4, $this->db->selectCell('SELECT COUNT(*) FROM ?_users'));
        $this->assertNull($this->db->selectCell('SELECT name FROM ?_users WHERE id = ?d', 999));
    }

    public function testInsertUpdateDelete(): void
    {
        $id = $this->db->query('INSERT INTO ?_users (?#) VALUES (?a)', ['name', 'age'], ['Eve', 22]);
        if ($this->insertReturnsId()) {
            $this->assertEquals(5, $id);
        }
        $this->assertSame(2, (int) $this->db->query('UPDATE ?_users SET ?a WHERE age < ?d', ['age' => 18], 26));
        $this->assertSame(1, (int) $this->db->query('DELETE FROM ?_users WHERE name = ?', 'Eve'));
        $this->assertEquals(1, $this->db->selectCell('SELECT COUNT(*) FROM ?_users WHERE age = 18'));
    }

    public function testMultiRowInsert(): void
    {
        $this->db->query('INSERT INTO ?_tags (name) VALUES (?a)', [['php'], ['sql'], ['orm']]);
        $this->assertSame(['orm', 'php', 'sql'], $this->db->selectCol('SELECT name FROM ?_tags ORDER BY name'));
    }

    public function testEscapingQuotesAndInjection(): void
    {
        $this->assertSame("d'ave@x.io", $this->db->selectCell('SELECT email FROM ?_users WHERE name = ?', 'Dave'));
        $evil = "x' OR '1'='1";
        $this->assertSame([], $this->db->select('SELECT * FROM ?_users WHERE name = ?', $evil));
    }

    public function testArrayPlaceholders(): void
    {
        $this->assertSame(['Alice', 'Carol'], $this->db->selectCol('SELECT name FROM ?_users WHERE id IN (?a) ORDER BY id', [1, 3]));
        // ?| — (a AND b) OR (c AND d): список условий по строкам
        $names = $this->db->selectCol(
            'SELECT name FROM ?_users WHERE (?|) ORDER BY id',
            [['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 99]]
        );
        $this->assertSame(['Alice'], $names);
    }

    public function testOptionalBlocks(): void
    {
        $query = 'SELECT name FROM ?_users WHERE 1 = 1 { AND age > ?d } { AND name <> ? } ORDER BY id';
        $this->assertSame(['Carol', 'Dave'], $this->db->selectCol($query, 30, DBSIMPLE_SKIP));
        $this->assertSame(['Alice', 'Carol', 'Dave'], $this->db->selectCol($query, DBSIMPLE_SKIP, 'Bob'));
        // Альтернативы: первая, у которой все значения заданы
        $alt = 'SELECT name FROM ?_users WHERE { name = ? | age = ?d } ORDER BY id';
        $this->assertSame(['Bob'], $this->db->selectCol($alt, 'Bob', 35));
        $this->assertSame(['Carol'], $this->db->selectCol($alt, DBSIMPLE_SKIP, 35));
    }

    public function testPlaceholderRightAfterBraceInOptionalBlock(): void
    {
        // {?d ...} — это блок, начинающийся с плейсхолдера (а не особый синтаксис)
        $query = 'SELECT name FROM ?_users WHERE age > {?d +} 0 ORDER BY id';
        $this->assertSame(['Dave'], $this->db->selectCol($query, 35));
        $this->assertCount(4, $this->db->selectCol($query, DBSIMPLE_SKIP));
    }

    public function testNullPlaceholders(): void
    {
        $this->db->query('UPDATE ?_users SET email = ? WHERE name = ?', null, 'Bob');
        $this->assertNull($this->db->selectCell('SELECT email FROM ?_users WHERE name = ?', 'Bob'));
        // ?n: пустое значение -> NULL
        $this->db->query('UPDATE ?_users SET parent_id = ?n WHERE name = ?', 0, 'Bob');
        $this->assertNull($this->db->selectCell('SELECT parent_id FROM ?_users WHERE name = ?', 'Bob'));
    }

    public function testArrayKeyTransforms(): void
    {
        $byId = $this->db->select('SELECT id AS ARRAY_KEY, name FROM ?_users ORDER BY id');
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_keys($byId)));
        $this->assertSame('Carol', $byId[3]['name']);

        $grouped = $this->db->select('SELECT parent_id AS ARRAY_KEY_1, id AS ARRAY_KEY_2, name FROM ?_users ORDER BY id');
        $this->assertSame('Carol', $grouped[1][3]['name']);

        $col = $this->db->selectCol('SELECT email AS ARRAY_KEY, name FROM ?_users ORDER BY id');
        $this->assertSame('Bob', $col['bob@x.io']);
    }

    public function testParentKeyBuildsForest(): void
    {
        $tree = $this->db->select('SELECT id AS ARRAY_KEY, parent_id AS PARENT_KEY, name FROM ?_users ORDER BY id');
        $this->assertSame(['Alice'], array_column($tree, 'name'));
        $alice = reset($tree);
        $this->assertSame(['Bob', 'Carol'], array_column($alice['childNodes'], 'name'));
        $this->assertSame('Dave', current($alice['childNodes'][3]['childNodes'])['name']);
    }

    public function testSelectPage(): void
    {
        $total = 0;
        $rows = $this->db->selectPage($total, 'SELECT name FROM ?_users WHERE age > ?d ORDER BY id LIMIT ?d OFFSET ?d', 20, 2, 1);
        $this->assertEquals(4, $total);
        $this->assertSame(['Bob', 'Carol'], array_column($rows, 'name'));
    }

    public function testSubquery(): void
    {
        $sub = $this->db->subquery('SELECT id FROM ?_users WHERE age >= ?d', 35);
        $this->assertSame(['Carol', 'Dave'], $this->db->selectCol('SELECT name FROM ?_users WHERE id IN (?s) ORDER BY id', $sub));
    }

    public function testTransactions(): void
    {
        $this->db->transaction();
        $this->db->query('DELETE FROM ?_users');
        $this->db->rollback();
        $this->assertEquals(4, $this->db->selectCell('SELECT COUNT(*) FROM ?_users'));

        $this->db->transaction();
        $this->db->query('DELETE FROM ?_users WHERE name = ?', 'Dave');
        $this->db->commit();
        $this->assertEquals(3, $this->db->selectCell('SELECT COUNT(*) FROM ?_users'));
    }

    public function testErrorThrowsByDefaultWithQueryAndCallerContext(): void
    {
        try {
            $this->db->select('SELECT no_such_column FROM ?_users WHERE name = ?', 'Bob');
            $this->fail('DatabaseException expected');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString("no_such_column FROM", (string) $e->getQuery());
            $this->assertStringContainsString("'Bob'", (string) $e->getQuery());
            // Место вызова — этот тест, а не файл библиотеки
            $this->assertStringContainsString(basename(static::parentFile()), (string) $e->getContext());
            $this->assertNotNull($this->db->error);
        }
    }

    public function testErrorHandlerCanBeDisabledOrReplaced(): void
    {
        $this->db->setErrorHandler(false);
        $this->assertFalse($this->db->select('SELECT * FROM no_such_table'));
        $this->assertNotEmpty($this->db->errmsg);

        $caught = null;
        $this->db->setErrorHandler(function (string $message, array $info) use (&$caught): void {
            $caught = $info;
        });
        $this->db->select('SELECT * FROM no_such_table');
        $this->assertStringContainsString('no_such_table', (string) $caught['query']);

        // Успешный запрос сбрасывает последнюю ошибку
        $this->db->selectCell('SELECT 1');
        $this->assertNull($this->db->error);
    }

    public function testLoggerReceivesExpandedSql(): void
    {
        $this->db->select('SELECT * FROM ?_users WHERE id = ?d', '2abc');
        $this->assertStringContainsString('WHERE id = 2', $this->log[0]);
    }

    public function testSetClassNameMapsRows(): void
    {
        $rows = $this->db->setClassName(\ArrayObject::class)->select('SELECT name FROM ?_users WHERE id = ?d', 1);
        $this->assertInstanceOf(\ArrayObject::class, $rows[0]);
        $this->assertSame('Alice', $rows[0]['name']);
        // Действует на один запрос
        $this->assertIsArray($this->db->select('SELECT name FROM ?_users WHERE id = ?d', 1)[0]);
    }

    public function testStatistics(): void
    {
        $before = $this->db->getStatistics()['count'];
        $this->db->selectCell('SELECT 1');
        $this->assertSame($before + 1, $this->db->getStatistics()['count']);
    }

    /** Файл, из которого вызывается запрос в тестах (для проверки контекста ошибки) */
    protected static function parentFile(): string
    {
        return __FILE__;
    }
}
