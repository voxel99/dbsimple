<?php

namespace Jam\DbSimple\Adapter;

use Jam\DbSimple\AdapterInterface;
use Jam\DbSimple\Database;
use Jam\DbSimple\DatabaseInterface;
use PDO;
use PDOException;

/**
 * SQLite через PDO (расширение pdo_sqlite).
 *
 * DSN: sqlite:///абсолютный/путь.db, sqlite:относительный.db, sqlite::memory:
 * Массив параметров: ['path' => ':memory:']
 *
 * Совместимость с MySQL-диалектом: INSERT IGNORE переводится в INSERT OR IGNORE,
 * идентификаторы экранируются обратными кавычками (SQLite их понимает).
 * ON DUPLICATE KEY UPDATE, LOCK IN SHARE MODE и SQL_CALC_FOUND_ROWS не поддерживаются.
 *
 * Прежняя реализация работала через расширение sqlite2 (sqlite_factory),
 * которого нет в PHP 5.4+.
 */
class Sqlite extends Database implements AdapterInterface, DatabaseInterface
{
    private ?PDO $link = null;

    /**
     * @param array<string, mixed>|string $dsn Результат Generic::parseDSN() или путь к файлу
     */
    public function __construct($dsn = ':memory:')
    {
        $path = is_array($dsn) ? ($dsn['path'] ?? ':memory:') : $dsn;
        try {
            $this->link = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        } catch (PDOException $e) {
            $this->_setLastError($e->getCode(), $e->getMessage(), 'new PDO(sqlite:' . $path . ')');
        }
    }

    /** Нативное соединение PDO (для DDL-скриптов, PRAGMA и т.п.) */
    public function getPdo(): ?PDO
    {
        return $this->link;
    }

    /**
     * Зарегистрировать PHP-функцию для использования в SQL.
     */
    public function createFunction(string $name, callable $callback, int $numArgs = -1): bool
    {
        return $this->link instanceof \Pdo\Sqlite
            ? $this->link->createFunction($name, $callback, $numArgs)
            : $this->link->sqliteCreateFunction($name, $callback, $numArgs);
    }

    public function prepareQuery($query)
    {
        $query[0] = preg_replace('/^\s*INSERT\s+IGNORE\s+/i', 'INSERT OR IGNORE ', $query[0]);
        return $query;
    }

    protected function _performGetPlaceholderIgnoreRe()
    {
        return '
            "   (?> [^"\\\\]+|\\\\"|\\\\)*    "   |
            \'  (?> [^\'\\\\]+|\\\\\'|\\\\)* \'   |
            `   (?> [^`]+ | ``)*              `   |   # backticks
            /\* .*?                          \*/      # comments
        ';
    }

    protected function _performEscape($s, $isIdent = false)
    {
        if (!$isIdent) {
            return $this->link->quote((string) $s);
        }
        return '`' . str_replace('`', '``', (string) $s) . '`';
    }

    protected function _performTransaction($parameters = null)
    {
        return $this->link->beginTransaction();
    }

    protected function _performCommit()
    {
        return $this->link->commit();
    }

    protected function _performRollback()
    {
        return $this->link->rollBack();
    }

    protected function _performQuery($queryMain)
    {
        if (!$this->link) {
            return $this->_setLastError(-1, 'Connection is not established', $queryMain[0]);
        }
        $this->_expandPlaceholders($queryMain, false);
        $p = $this->link->query($queryMain[0]);
        if (!$p) {
            $info = $this->link->errorInfo();
            return $this->_setLastError($info[1], $info[2], $queryMain[0]);
        }
        if (preg_match('/^\s* INSERT \s+/six', $queryMain[0])) {
            return $this->link->lastInsertId();
        }
        if ($p->columnCount() == 0) {
            return $p->rowCount();
        }
        $rows = $p->fetchAll(PDO::FETCH_ASSOC);
        $p->closeCursor();
        return $rows;
    }

    protected function _performTransformQuery(&$queryMain, $how)
    {
        switch ($how) {
            case 'CALC_TOTAL':
                return true;
            case 'GET_TOTAL':
                // Отдельный COUNT(*) по тому же запросу без ORDER BY / LIMIT
                $m = null;
                $re = '/^
                    (?> -- [^\r\n]* | \s+)*
                    (\s* SELECT \s+)                                              #1
                    (.*?)                                                         #2
                    (\s+ FROM \s+ .*?)                                            #3
                    ((?:\s+ ORDER \s+ BY \s+ .*?)?)                               #4
                    ((?:\s+ LIMIT \s+ \S+ \s* (?: (?:,|OFFSET) \s* \S+ \s*)? )?)  #5
                $/six';
                if (preg_match($re, $queryMain[0], $m)) {
                    $queryMain[0] = $m[1] . $this->_fieldList2Count($m[2]) . ' AS C' . $m[3];
                    $skipTail = substr_count($m[4] . $m[5], '?');
                    if ($skipTail) {
                        array_splice($queryMain, -$skipTail);
                    }
                }
                return true;
        }
        return false;
    }

    protected function _performNewBlob($id = null)
    {
        return null;
    }

    protected function _performGetBlobFieldNames($result)
    {
        return [];
    }

    protected function _performFetch($result)
    {
        return $result;
    }
}
