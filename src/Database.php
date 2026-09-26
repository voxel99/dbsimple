<?php

/**
 * DbSimple Database — абстрактный базовый класс для всех адаптеров БД.
 *
 * Реализует:
 * - Систему плейсхолдеров (?, ?d, ?f, ?#, ?a, ?s, ?n, ?_, ?|, ?&)
 * - Опциональные блоки {...} с условным включением
 * - Подзапросы (SubQuery) для композиции SQL
 * - Трансформацию результатов (ARRAY_KEY, PARENT_KEY → hash/forest)
 * - Логирование и профилирование запросов
 * - 7 режимов отладки SQL (0-6) + callable
 *
 * Изначально: (C) Dk Lab, http://en.dklab.ru (LGPL 2.1)
 * Форк: Voxel — добавлены debug-режимы, callable debug, комментарии.
 *
 * Формат DSN:
 *   phptype://username:password@hostspec/database?option=value
 *
 * Специальные колонки в результате:
 * - ARRAY_KEY*  — ключ в результирующем хеше
 * - PARENT_KEY  — родительский ключ для древовидных результатов
 *
 * Атрибуты запросов (комментарии в начале SQL):
 * - BLOB_OBJ    — оборачивать BLOB-поля в объекты
 * - CACHE        — ключ кэширования
 *
 * @see \Jam\DbSimple\Connect      Lazy-connect прокси
 * @see \Jam\DbSimple\Adapter\Mypdo MySQL PDO адаптер
 * @see \Jam\DbSimple\SubQuery      Подзапрос без выполнения
 *
 * @author Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 * @author Konstantin Zhinko, http://forum.dklab.ru/users/KonstantinGinkoTit/
 * @author Ivan Borzenkov, http://forum.dklab.ru/users/Ivan1986/
 *
 * @version 2.x
 */

namespace Jam\DbSimple;

/**
 * Use this constant as placeholder value to skip optional SQL block [...].
 */
if (!defined('DBSIMPLE_SKIP')) {
    define('DBSIMPLE_SKIP', log(0));
}

/**
 * Names of special columns in result-set which is used
 * as array key (or karent key in forest-based resultsets) in
 * resulting hash.
 */
if (!defined('DBSIMPLE_ARRAY_KEY')) {
    define('DBSIMPLE_ARRAY_KEY', 'ARRAY_KEY');   // hash-based resultset support
}
if (!defined('DBSIMPLE_PARENT_KEY')) {
    define('DBSIMPLE_PARENT_KEY', 'PARENT_KEY'); // forrest-based resultset support
}

/**
 *
 * Base class for all databases.
 * Can create transactions and new BLOBs, parse DSNs.
 *
 * Logger is COMMON for multiple transactions.
 * Error handler is private for each transaction and database.
 */
abstract class Database extends LastError implements DatabaseInterface
{
    /**
     * Режим отладки: отключён.
     */
    public const DEBUG_SQL_NONE = 0;

    /**
     * Режим отладки: вывод первого SQL-запроса + die.
     */
    public const DEBUG_SQL_DIE = 1;

    /**
     * Режим отладки: echo всех SQL-запросов.
     */
    public const DEBUG_SQL_ECHO = 2;

    /**
     * Режим отладки: echo SQL + return null (без выполнения запроса).
     * Используется в db_return_query().
     */
    public const DEBUG_SQL_ECHO_NOT_EXECUTED = 3;

    /**
     * Режим отладки: echo SQL + traceback вызывающего кода.
     */
    public const DEBUG_SQL_ECHO_WITH_TRACE = 4;

    /**
     * Режим отладки: echo SQL без выполнения (алиас NOT_EXECUTED).
     */
    public const DEBUG_SQL_ECHO_NO_EXEC = 5;

    /**
     * Режим отладки: echo SQL + количество возвращённых строк и время.
     */
    public const DEBUG_SQL_ECHO_AND_COUNT_ROWS = 6;

    /**
     * Текущий режим отладки SQL-запросов.
     *
     * Может быть int (0-6) или callable для пользовательского логирования.
     * Статическая переменная — глобальная для всех экземпляров.
     *
     * @var int|callable
     */
    public static $debugSql = 0;

    /**
     * Установить режим отладки SQL.
     *
     * @param int|callable $debug Режим (0-6) или callback(string $query)
     */
    public function debugSql(int|callable $debug): void
    {
        self::$debugSql = $debug;
    }

    /**
     * Проверить, совпадает ли текущий режим отладки с указанным.
     *
     * @param int|callable $debug Режим для проверки
     * @return bool
     */
    public function isDebugSql(int|callable $debug): bool
    {
        return self::$debugSql === $debug;
    }


    /**
     * Префикс идентификаторов для плейсхолдера ?_
     */
    private string $_identPrefix = '';

    /**
     * Статистика выполненных запросов.
     *
     * @var array{time: float, count: int}
     */
    private array $_statistics = [
        'time'  => 0,
        'count' => 0,
    ];

    /**
     * Имя класса для маппинга строк результата.
     * Сбрасывается после каждого запроса.
     */
    private string $_className = '';

    /**
     * Callback для логирования запросов.
     *
     * @var callable|null
     */
    private $_logger = null;

    /**
     * Стек аргументов для подстановки в плейсхолдеры (обратный порядок, array_pop).
     *
     * @var array|null
     */
    private $_placeholderArgs;

    /**
     * Массив «нативных» аргументов при использовании native placeholders.
     * null = режим не-нативной подстановки.
     *
     * @var array|null
     */
    private $_placeholderNativeArgs;

    /**
     * Флаг: при раскрытии плейсхолдеров было найдено DBSIMPLE_SKIP.
     */
    private $_placeholderNoValueFound;

    /**
     * Разделители для ?|, ?& и ?a плейсхолдеров.
     *
     * @var array<string, array{inner: string, outer: string}>
     */
    public static array $join = [
        '|' => ['inner' => ' AND ', 'outer' => ') OR ('],
        '&' => ['inner' => ' OR ',  'outer' => ') AND ('],
        'a' => ['inner' => ', ',    'outer' => '), ('],
    ];



    /**
     * Максимальная длина строкового представления строки (в символах).
     * Если длина больше — данные строки не логируются.
     */
    private int $MAX_LOG_ROW_LEN = 128;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * object blob($blob_id)
     * Create new blob
     */
    public function blob($blob_id = null)
    {
        $this->_resetLastError();
        return $this->_performNewBlob($blob_id);
    }

    /**
     * void transaction($mode)
     * Create new transaction.
     */
    public function transaction($mode = null)
    {
        $this->_resetLastError();
        $this->_logQuery('-- START TRANSACTION ' . $mode);
        return $this->_performTransaction($mode);
    }

    /**
     * mixed commit()
     * Commit the transaction.
     */
    public function commit()
    {
        $this->_resetLastError();
        $this->_logQuery('-- COMMIT');
        return $this->_performCommit();
    }

    /**
     * mixed rollback()
     * Rollback the transaction.
     */
    public function rollback()
    {
        $this->_resetLastError();
        $this->_logQuery('-- ROLLBACK');
        return $this->_performRollback();
    }

    /**
     * mixed select(string $query [, $arg1] [,$arg2] ...)
     * Execute query and return the result.
     */
    public function select(mixed ...$query)
    {
        $args = func_get_args();
        $total = false;
        return $this->_query($args, $total);
    }

    /**
     * mixed selectPage(int &$total, string $query [, $arg1] [,$arg2] ...)
     * Execute query and return the result.
     * Total number of found rows (independent to LIMIT) is returned in $total
     * (in most cases second query is performed to calculate $total).
     */
    public function selectPage(&$total, ...$query)
    {
        $args = func_get_args();
        array_shift($args);
        $total = true;
        return $this->_query($args, $total);
    }

    /**
     * hash selectRow(string $query [, $arg1] [,$arg2] ...)
     * Return the first row of query result.
     * On errors return false and set last error.
     * If no one row found, return array()! It is useful while debugging,
     * because PHP DOES NOT generates notice on $row['abc'] if $row === null
     * or $row === false (but, if $row is empty array, notice is generated).
     */
    public function selectRow(mixed ...$query)
    {
        $args = func_get_args();
        $total = false;
        $rows = $this->_query($args, $total);
        if (!is_array($rows)) {
            return $rows;
        }
        if (!count($rows)) {
            return array();
        }
        reset($rows);
        return current($rows);
    }

    /**
     * array selectCol(string $query [, $arg1] [,$arg2] ...)
     * Return the first column of query result as array.
     */
    public function selectCol(mixed ...$query)
    {
        $args = func_get_args();
        $total = false;
        $rows = $this->_query($args, $total);
        if (!is_array($rows)) {
            return $rows;
        }
        $this->_shrinkLastArrayDimensionCallback($rows);
        return $rows;
    }

    /**
     * scalar selectCell(string $query [, $arg1] [,$arg2] ...)
     * Return the first cell of the first column of query result.
     * If no one row selected, return null.
     */
    public function selectCell(mixed ...$query)
    {
        $args = func_get_args();
        $total = false;
        $rows = $this->_query($args, $total);
        if (!is_array($rows)) {
            return $rows;
        }
        if (!count($rows)) {
            return null;
        }
        reset($rows);
        $row = current($rows);
        if (!is_array($row)) {
            return $row;
        }
        reset($row);
        return current($row);
    }

    /**
     * mixed query(string $query [, $arg1] [,$arg2] ...)
     * Alias for select(). May be used for INSERT or UPDATE queries.
     */
    public function query(mixed ...$query)
    {
        $args = func_get_args();
        $total = false;
        return $this->_query($args, $total);
    }

    /**
     * string escape(mixed $s, bool $isIdent=false)
     * Enclose the string into database quotes correctly escaping
     * special characters. If $isIdent is true, value quoted as identifier
     * (e.g.: `value` in MySQL, "value" in Firebird, [value] in MSSQL).
     */
    public function escape($s, $isIdent = false)
    {
        if (is_int($s)) {
            // if this is a integer value - not need to escape (as for ?d placeholder)
            return $s;
        } elseif (is_float($s)) {
            // for mysql the point "." is the separator for the decimal point
            // for example, as for "?f" placeholder
            return str_replace(',', '.', (string)$s);
        }

        return $this->_performEscape($s, $isIdent);
    }

    /**
     * DbSimple_SubQuery subquery(string $query [, $arg1] [,$arg2] ...)
     * Выполняет разворачивание плейсхолдеров без коннекта к базе
     * Нужно для сложных запросов, состоящих из кусков, которые полезно сохранить
     *
     */
    public function subquery(mixed ...$query)
    {
        $args = func_get_args();
        $this->_expandPlaceholders($args, $this->_placeholderNativeArgs !== null);
        return new SubQuery($args);
    }

    /**
     * callback setLogger(callback $logger)
     * Set query logger called before each query is executed.
     * Returns previous logger.
     */
    public function setLogger($logger)
    {
        $prev = $this->_logger;
        $this->_logger = $logger;
        return $prev;
    }

    /**
     * string setIdentPrefix($prx)
     * Set identifier prefix used for $_ placeholder.
     */
    public function setIdentPrefix($prx)
    {
        $old = $this->_identPrefix;
        if ($prx !== null) {
            $this->_identPrefix = $prx;
        }
        return $old;
    }

    /**
     * Задает имя класса строки
     *
     * <br>для следующего запроса каждая строка будет
     * заменена классом, конструктору которого передается
     * массив поле=>значение для этой строки
     *
     * @param string $name имя класса
     * @return Database указатель на себя
     */
    public function setClassName($name)
    {
        $this->_className = $name;
        return $this;
    }

    /**
     * array getStatistics()
     * Returns various statistical information.
     */
    public function getStatistics()
    {
        return $this->_statistics;
    }

    /**
     * string _performEscape(mixed $s, bool $isIdent=false)
     */
    abstract protected function _performEscape($s, $isIdent = false);

    /**
     * object _performNewBlob($id)
     *
     * Returns new blob object.
     */
    abstract protected function _performNewBlob($id = null);

    /**
     * list _performGetBlobFieldNames($resultResource)
     * Get list of all BLOB field names in result-set.
     */
    abstract protected function _performGetBlobFieldNames($result);

    /**
     * mixed _performTransformQuery(array &$query, string $how)
     *
     * Transform query different way specified by $how.
     * May return some information about performed transform.
     */
    abstract protected function _performTransformQuery(&$queryMain, $how);

    /**
     * resource _performQuery($arrayQuery)
     * Must return:
     * - For SELECT queries: ID of result-set (PHP resource).
     * - For other  queries: query status (scalar).
     * - For error  queries: false (and call _setLastError()).
     */
    abstract protected function _performQuery($arrayQuery);

    /**
     * mixed _performFetch($resultResource)
     * Fetch ONE NEXT row from result-set.
     * Must return:
     * - For SELECT queries: all the rows of the query (2d arrray).
     * - For INSERT queries: ID of inserted row.
     * - For UPDATE queries: number of updated rows.
     * - For other  queries: query status (scalar).
     * - For error  queries: false (and call _setLastError()).
     */
    abstract protected function _performFetch($result);

    /**
     * mixed _performTransaction($mode)
     * Start new transaction.
     */
    abstract protected function _performTransaction($mode = null);

    /**
     * mixed _performCommit()
     * Commit the transaction.
     */
    abstract protected function _performCommit();

    /**
     * mixed _performRollback()
     * Rollback the transaction.
     */
    abstract protected function _performRollback();

    /**
     * string _performGetPlaceholderIgnoreRe()
     * Return regular expression which matches ignored query parts.
     * This is needed to skip placeholder replacement inside comments, constants etc.
     */
    protected function _performGetPlaceholderIgnoreRe()
    {
        return '';
    }

    /**
     * Returns marker for native database placeholder. E.g. in FireBird it is '?',
     * in PostgreSQL - '$1', '$2' etc.
     *
     * @param int $n Number of native placeholder from the beginning of the query (begins from 0!).
     * @return string String representation of native placeholder marker (by default - '?').
     */
    protected function _performGetNativePlaceholderMarker($n)
    {
        return '?';
    }

    /**
     * array parseDSN(mixed $dsn)
     * Parse a data source name.
     * See parse_url() for details.
     */
    protected function parseDSN($dsn)
    {
        if (is_array($dsn)) {
            return $dsn;
        }
        $parsed = parse_url($dsn);
        if (!$parsed) {
            return null;
        }
        $params = null;
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $parsed += $params;
        }
        $parsed['dsn'] = $dsn;
        return $parsed;
    }

    public function prepareQuery($query)
    {
        return $query;
    }

    /**
     * array _query($query, &$total)
     * See _performQuery().
     */
    private function _query($query, &$total)
    {
        $this->_resetLastError();

        // Fetch query attributes.
        $this->attributes = $this->_transformQuery($query, 'GET_ATTRIBUTES');

        // Modify query if needed for total counting.
        if ($total) {
            $this->_transformQuery($query, 'CALC_TOTAL');
        }

        $this->_logQuery($query);

        $query = $this->prepareQuery($query);

        // Удаляем SQL-комментарии перед выполнением
        $query[0] = preg_replace("#--\s.*$#m", "", $query[0]);

        // Debug-обработка: callable проверяется ПЕРВЫМ, чтобы избежать
        // сравнения Closure с int внутри in_array()
        if (!empty(self::$debugSql)) {
            $this->_expandPlaceholders($query, false);
            $query[0] = preg_replace("#--.*$#m", "", $query[0]);
            $query[0] = preg_replace("#\s+#uis", " ", trim($query[0]));

            if (is_callable(self::$debugSql)) {
                // Пользовательский callback — передаём SQL-строку
                call_user_func(self::$debugSql, $query[0]);
            } elseif (self::$debugSql === self::DEBUG_SQL_ECHO_NOT_EXECUTED
                   || self::$debugSql === self::DEBUG_SQL_ECHO_NO_EXEC) {
                // Вывод SQL без выполнения — return null
                echo $query[0] . "\n";
                return null;
            } elseif (self::$debugSql === self::DEBUG_SQL_DIE) {
                die($query[0]);
            } elseif (self::$debugSql === self::DEBUG_SQL_ECHO
                   || self::$debugSql === self::DEBUG_SQL_ECHO_WITH_TRACE
                   || self::$debugSql === self::DEBUG_SQL_ECHO_AND_COUNT_ROWS) {
                echo $query[0] . "\n";
                if (self::$debugSql === self::DEBUG_SQL_ECHO_WITH_TRACE) {
                    $backtrace = debug_backtrace(2);
                    foreach ($backtrace as $trace) {
                        if (!isset($trace['file'])) {
                            continue;
                        }
                        $file = str_replace("\\", "/", $trace['file']);
                        $enginePath = function_exists('path') ? path('engine') : __DIR__;
                        if ($enginePath && strpos($file, $enginePath) !== false) {
                            continue;
                        }
                        $rootPath = function_exists('path') ? path('root') : '';
                        $displayFile = $rootPath ? str_replace($rootPath, '', $file) : $file;
                        echo sprintf("%s (%d)", $displayFile, $trace['line']) . "\n";
                        break;
                    }
                }
            }
        }

        // Run the query (counting time).
        $qStart = microtime(true);
        $result = $this->_performQuery($query);
        $fetchTime = $firstFetchTime = 0;

        if (is_resource($result) || is_object($result)) {
            $rows = array();
            // Fetch result row by row.
            $fStart = microtime(true);
            $row = $this->_performFetch($result);
            $firstFetchTime = microtime(true) - $fStart;
            if (!empty($row)) {
                $rows[] = $row;
                while ($row = $this->_performFetch($result)) {
                    $rows[] = $row;
                }
            }
            $fetchTime = microtime(true) - $fStart;
        } else {
            $rows = $result;
        }
        $queryTime = microtime(true) - $qStart;

        // Вывод row count — только для int-режима, чтобы не сравнивать Closure с int
        if (is_int(self::$debugSql) && self::$debugSql === self::DEBUG_SQL_ECHO_AND_COUNT_ROWS) {
            echo sprintf("(rows: %d, time: %f s)\n", $rows && is_array($rows) ? count($rows) : 0, $queryTime);
        }

        // Log query statistics.
        $this->_logQueryStat($queryTime, $fetchTime, $firstFetchTime, $rows);

        // Prepare BLOB objects if needed.
        if (is_array($rows) && !empty($this->attributes['BLOB_OBJ'])) {
            $blobFieldNames = $this->_performGetBlobFieldNames($result);
            foreach ($blobFieldNames as $name) {
                for ($r = count($rows) - 1; $r >= 0; $r--) {
                    $rows[$r][$name] = $this->_performNewBlob($rows[$r][$name]);
                }
            }
        }

        // Transform resulting rows.
        $result = $this->_transformResult($rows);

        // Count total number of rows if needed.
        if (is_array($result) && $total) {
            $this->_transformQuery($query, 'GET_TOTAL');
            $total = call_user_func_array(array(&$this, 'selectCell'), $query);
        }

        if ($this->_className) {
            foreach ($result as $k => $v) {
                $result[$k] = new $this->_className($v);
            }
            $this->_className = '';
        }

        return $result;
    }

    /**
     * mixed _transformQuery(array &$query, string $how)
     *
     * Transform query different way specified by $how.
     * May return some information about performed transform.
     */
    private function _transformQuery(&$query, $how)
    {
        // Do overriden transformation.
        $result = $this->_performTransformQuery($query, $how);
        if ($result === true) {
            return $result;
        }
        // Common transformations.
        switch ($how) {
            case 'GET_ATTRIBUTES':
                // Extract query attributes.
                $options = array();
                $q = $query[0];
                $m = null;
                while (preg_match('/^ \s* -- [ \t]+ (\w+): ([^\r\n]+) [\r\n]* /sx', $q, $m)) {
                    $options[$m[1]] = trim($m[2]);
                    $q = substr($q, strlen($m[0]));
                }
                return $options;
            case 'UNIQ_KEY':
                $q = $this->attributes['CACHE'];
                $query = array();
                while (preg_match('/(\w+)\.\w+/sx', $q, $m)) {
                    $query[] = 'SELECT MAX(' . $m[0] . ') AS M, COUNT(*) AS C FROM ' . $m[1];
                    $q = substr($q, strlen($m[0]));
                }
                $query = "  -- UNIQ_KEY\n" .
                    join("\nUNION\n", $query);
                return true;
        }
        // No such transform.
        $this->_setLastError(-1, "No such transform type: $how", $query);
        return null;
    }

    /**
     * void _expandPlaceholders(array &$queryAndArgs, bool $useNative=false)
     * Replace placeholders by quoted values.
     * Modify $queryAndArgs.
     */
    /**
     * Раскрыть плейсхолдеры в запросе.
     *
     * Заменяет ?, ?d, ?f, ?#, ?a, ?s, ?n, ?_, ?|, ?& соответствующими
     * экранированными значениями из $queryAndArgs.
     *
     * @param array $queryAndArgs [0 => SQL-шаблон, 1.. => значения]. Модифицируется по ссылке.
     * @param bool  $useNative    Использовать нативные плейсхолдеры PDO (не используется в MySQL).
     */
    protected function _expandPlaceholders(&$queryAndArgs, $useNative = false)
    {
        if (!is_array($queryAndArgs)) {
            $queryAndArgs = array($queryAndArgs);
        }

        $this->_placeholderNativeArgs = $useNative ? array() : null;
        $this->_placeholderArgs = array_reverse($queryAndArgs);

        $query = array_pop($this->_placeholderArgs); // array_pop is faster than array_shift
        // Do all the work.
        $this->_placeholderNoValueFound = false;
        $query = $this->_expandPlaceholdersFlow($query);

        if ($useNative) {
            array_unshift($this->_placeholderNativeArgs, $query);
            $queryAndArgs = $this->_placeholderNativeArgs;
        } else {
            $queryAndArgs = array($query);
        }
    }

    /**
     * Рекурсивное раскрытие плейсхолдеров в SQL-шаблоне.
     *
     * Обрабатывает:
     * - Игнорируемые фрагменты (комментарии, строковые литералы)
     * - Опциональные блоки {...}
     * - Плейсхолдеры ?, ?d, ?f, ?#, ?a, ?s, ?n, ?_, ?|, ?&
     *
     * Все внутренние переменные (_placeholder*) должны быть инициализированы
     * перед вызовом. Может вызываться рекурсивно.
     *
     * @param string|null $query SQL-шаблон
     * @return string Раскрытый SQL
     */
    private function _expandPlaceholdersFlow($query)
    {
        // Fix: {?s} обрабатывается корректно — добавляем пробел после {
        if (!$query) {
            return $query;
        }
        $query = str_replace('{?', '{ ?', $query);

        $re = '{
            (?>
                # Игнорируемые фрагменты.
                (?>
                    # SQL-комментарии.
                    -- [^\r\n]*
                )
                  |
                (?>
                    # Специфика адаптера (строковые литералы, backticks).
                    ' . trim($this->_performGetPlaceholderIgnoreRe()) . '
                )
            )
              |
            (?>
                # Опциональные блоки {содержимое}
                \{
                    # "+" вместо "*" — иначе вложенные блоки обрабатываются некорректно.
                    ( (?> (?>(\??)[^{}]+)  |  (?R) )* )             #1
                \}
            )
              |
            (?>
                # Плейсхолдеры: ?, ?d, ?f, ?#, ?a, ?s, ?n, ?_, ?|, ?&
                (\?) ( [_dsafn&|\#]? )                           #2 #3
            )
        }sxS';
        $query = preg_replace_callback(
            $re,
            array(&$this, '_expandPlaceholdersCallback'),
            $query
        );
        return $query;
    }

    /**
     * Callback для preg_replace_callback — обработка одного плейсхолдера.
     *
     * Логика:
     * - $m[3] — '?' маркер плейсхолдера, $m[4] — тип (d, s, a, #, n, f, _, |, &)
     * - $m[1] — содержимое опционального блока {...}
     *
     * @param array $m Matches от регулярного выражения
     * @return string Подставленное значение
     */
    private function _expandPlaceholdersCallback($m)
    {
        // Placeholder.
        if (!empty($m[3])) {
            $type = $m[4];

            // Idenifier prefix.
            if ($type == '_') {
                return $this->_identPrefix;
            }

            // Value-based placeholder.
            if (!$this->_placeholderArgs) {
                return 'DBSIMPLE_ERROR_NO_VALUE';
            }
            $value = array_pop($this->_placeholderArgs);

            // Skip this value?
            if ($value === DBSIMPLE_SKIP) {
                $this->_placeholderNoValueFound = true;
                return '';
            }

            // First process guaranteed non-native placeholders.
            switch ($type) {
                case 's':
                    if (!($value instanceof SubQuery)) {
                        return 'DBSIMPLE_ERROR_VALUE_NOT_SUBQUERY';
                    }
                    return $value->get($this->_placeholderNativeArgs);
                case '|':
                case '&':
                case 'a':
                    if (!$value) {
                        $this->_placeholderNoValueFound = true;
                    }
                    if (!is_array($value)) {
                        return 'DBSIMPLE_ERROR_VALUE_NOT_ARRAY';
                    }
                    $parts = array();
                    $multi = array(); //массив для двойной вложенности
                    $mult = $type != 'a' || is_int(key($value)) && is_array(current($value));
                    foreach ($value as $prefix => $field) {
                        //превращаем $value в двумерный нуменованный массив
                        if (!is_array($field)) {
                            $field = array($prefix => $field);
                            $prefix = 0;
                        }
                        $prefix = is_int($prefix) ? '' :
                            $this->escape($this->_addPrefix2Table($prefix), true) . '.';
                        //для мультиинсерта очищаем ключи - их быть не может по синтаксису
                        if ($mult && $type == 'a') {
                            $field = array_values($field);
                        }
                        foreach ($field as $k => $v) {
                            if ($v instanceof SubQuery) {
                                $v = $v->get($this->_placeholderNativeArgs);
                            } else {
                                $v = $v === null ? 'NULL' : $this->escape($v);
                            }
                            if (!is_int($k)) {
                                $k = $this->escape($k, true);
                                $parts[] = "$prefix$k = $v";
                            } else {
                                $parts[] = $v;
                            }
                        }
                        if ($mult) {
                            $multi[] = join(self::$join[$type]['inner'], $parts);
                            $parts = array();
                        }
                    }
                    return $mult ? join(self::$join[$type]['outer'], $multi) : join(', ', $parts);
                case '#':
                    // Identifier.
                    if (!is_array($value)) {
                        if ($value instanceof SubQuery) {
                            return $value->get($this->_placeholderNativeArgs);
                        }
                        return $this->escape($this->_addPrefix2Table($value), true);
                    }
                    $parts = array();
                    foreach ($value as $table => $identifiers) {
                        if (!is_array($identifiers)) {
                            $identifiers = array($identifiers);
                        }
                        $prefix = '';
                        if (!is_int($table)) {
                            $prefix = $this->escape($this->_addPrefix2Table($table), true) . '.';
                        }
                        foreach ($identifiers as $identifier) {
                            if ($identifier instanceof SubQuery) {
                                $parts[] = $identifier->get($this->_placeholderNativeArgs);
                            } elseif (!is_string($identifier)) {
                                return 'DBSIMPLE_ERROR_ARRAY_VALUE_NOT_STRING';
                            } else {
                                $parts[] = $prefix . ($identifier == '*' ? '*' :
                                    $this->escape($this->_addPrefix2Table($identifier), true));
                            }
                        }
                    }
                    return join(', ', $parts);
                case 'n':
                    // NULL-based placeholder.
                    return empty($value) ? 'NULL' : intval($value);
            }

            // Native arguments are not processed.
            if ($this->_placeholderNativeArgs !== null) {
                $this->_placeholderNativeArgs[] = $value;
                return $this->_performGetNativePlaceholderMarker(count($this->_placeholderNativeArgs) - 1);
            }

            // In non-native mode arguments are quoted.
            if ($value === null) {
                return 'NULL';
            }
            switch ($type) {
                case '':
                    if (!is_scalar($value)) {
                        return 'DBSIMPLE_ERROR_VALUE_NOT_SCALAR';
                    }
                    return $this->escape($value);
                case 'd':
                    return intval($value);
                case 'f':
                    return str_replace(',', '.', (string)floatval($value));
            }
            // By default - escape as string.
            return $this->escape($value);
        }

        $prevPh = null;

        // Optional block.
        if (isset($m[1]) && strlen($block = $m[1])) {
            $prev = $this->_placeholderNoValueFound;
            if ($this->_placeholderNativeArgs !== null) {
                $prevPh = $this->_placeholderNativeArgs;
            }

            // Проверка на {?  } - условный блок
            $skip = false;
            if ($m[2] == '?') {
                $skip = array_pop($this->_placeholderArgs) === DBSIMPLE_SKIP;
                $block[0] = ' ';
            }

            $block = $this->_expandOptionalBlock($block);

            if ($skip) {
                $block = '';
            }

            if ($this->_placeholderNativeArgs !== null) {
                if ($this->_placeholderNoValueFound) {
                    $this->_placeholderNativeArgs = $prevPh;
                }
            }
            $this->_placeholderNoValueFound = $prev; // recurrent-safe

            return $block;
        }

        // Default: skipped part of the string.
        return $m[0];
    }

    /**
     * Заменяет ?_ на текущий префикс
     *
     * @param string $table имя таблицы
     * @return string имя таблицы
     */
    private function _addPrefix2Table($table)
    {
        if (substr($table, 0, 2) == '?_') {
            $table = $this->_identPrefix . substr($table, 2);
        }
        return $table;
    }

    /**
     * Разбирает опциональный блок - условие |
     *
     * @param string $block блок, который нужно разобрать
     * @return string что получается в результате разбора блока
     */
    private function _expandOptionalBlock($block)
    {
        $alts = array();
        $alt = '';
        $sub = 0;
        $exp = explode('|', $block);
        // Оптимизация, так как в большинстве случаев | не используется
        if (count($exp) == 1) {
            $alts = $exp;
        } else {
            foreach ($exp as $v) {
                // Реализуем автоматный магазин для нахождения нужной скобки
                // На суммарную парность скобок проверять нет необходимости - об этом заботится регулярка
                $sub += substr_count($v, '{');
                $sub -= substr_count($v, '}');
                if ($sub > 0) {
                    $alt .= $v . '|';
                } else {
                    $alts[] = $alt . $v;
                    $alt = '';
                }
            }
        }
        $r = '';
        foreach ($alts as $block) {
            $this->_placeholderNoValueFound = false;
            $block = $this->_expandPlaceholdersFlow($block);
            // Необходимо пройти все блоки, так как если пропустить оставшиесь,
            // то это нарушит порядок подставляемых значений
            if (empty($this->_placeholderNoValueFound) && $r === '') {
                $r = ' ' . $block . ' ';
            }
        }
        return $r;
    }

    /**
     * void _setLastError($code, $msg, $query)
     * Set last database error context.
     * Aditionally expand placeholders.
     */
    protected function _setLastError($code, $msg, $query)
    {
        if (is_array($query)) {
            $this->_expandPlaceholders($query, false);
            $query = $query[0];
        }
        return parent::_setLastError($code, $msg, $query);
    }

    /**
     * Convert SQL field-list to COUNT(...) clause
     * (e.g. 'DISTINCT a AS aa, b AS bb' -> 'COUNT(DISTINCT a, b)').
     */
    protected function _fieldList2Count($fields)
    {
        $m = null;
        if (preg_match('/^\s* DISTINCT \s* (.*)/sx', $fields, $m)) {
            $fields = $m[1];
            $fields = preg_replace('/\s+ AS \s+ .*? (?=,|$)/sx', '', $fields);
            return "COUNT(DISTINCT $fields)";
        } else {
            return 'COUNT(*)';
        }
    }

    /**
     * array _transformResult(list $rows)
     * Transform resulting rows to various formats.
     */
    private function _transformResult($rows)
    {
        // is not array
        if (!is_array($rows) || !$rows) {
            return $rows;
        }

        // Find ARRAY_KEY* AND PARENT_KEY fields in field list.
        $pk = null;
        $ak = array();
        foreach (array_keys(current($rows)) as $fieldName) {
            if (0 == strncasecmp($fieldName, DBSIMPLE_ARRAY_KEY, strlen(DBSIMPLE_ARRAY_KEY))) {
                $ak[] = $fieldName;
            } elseif (0 == strncasecmp($fieldName, DBSIMPLE_PARENT_KEY, strlen(DBSIMPLE_PARENT_KEY))) {
                $pk = $fieldName;
            }
        }

        if (!$ak) {
            return $rows;
        }

        natsort($ak); // sort ARRAY_KEY* using natural comparision
        // Tree-based array? Fields: ARRAY_KEY, PARENT_KEY
        if ($pk !== null) {
            return $this->_transformResultToForest($rows, $ak[0], $pk);
        }
        // Key-based array? Fields: ARRAY_KEY.
        return $this->_transformResultToHash($rows, $ak);
    }

    /**
     * Converts rowset to key-based array.
     *
     * @param array<int, array<string, mixed>> $rows   Two-dimensional array of resulting rows.
     * @param array<int, string> $arrayKeys List of ARRAY_KEY* field names.
     * @return array<mixed> Transformed array with nested structure based on array keys
     */
    private function _transformResultToHash(array $rows, array $arrayKeys)
    {
        $result = array();
        foreach ($rows as $row) {
            // Iterate over all of ARRAY_KEY* fields and build array dimensions.
            $current = &$result;
            foreach ($arrayKeys as $ak) {
                $key = $row[$ak];
                unset($row[$ak]); // remove ARRAY_KEY* field from result row
                if ($key !== null) {
                    $current = &$current[$key];
                } else {
                    // IF ARRAY_KEY field === null, use array auto-indices.
                    $tmp = array();
                    $current[] = & $tmp;
                    $current = & $tmp;
                    unset($tmp); // we use $tmp, because don't know the value of auto-index
                }
            }
            $current = $row; // save the row in last dimension
        }
        return $result;
    }

    /**
     * Converts rowset to the forest.
     *
     * @param array<int, array<string, mixed>> $rows       Two-dimensional array of resulting rows.
     * @param string $idName    Name of ID field.
     * @param string $pidName   Name of PARENT_ID field.
     * @return array<mixed> Transformed array as tree structure
     */
    private function _transformResultToForest(array $rows, $idName, $pidName)
    {
        $children = array(); // children of each ID
        $ids = array();
        // Collect who are children of whom.
        foreach ($rows as $i => $r) {
            $row = & $rows[$i];
            $id = $row[$idName];
            if ($id === null) {
                // Rows without an ID are totally invalid and makes the result tree to
                // be empty (because PARENT_ID = null means "a root of the tree"). So
                // skip them totally.
                continue;
            }
            $pid = $row[$pidName];
            if ($id == $pid) {
                $pid = null;
            }
            $children[$pid][$id] = & $row;
            if (!isset($children[$id])) {
                $children[$id] = array();
            }
            $row['childNodes'] = & $children[$id];
            $ids[$id] = true;
        }
        // Root elements are elements with non-found PIDs.
        $forest = array();
        foreach ($rows as $i => $r) {
            $row = & $rows[$i];
            $id = $row[$idName];
            $pid = $row[$pidName];
            if ($pid == $id) {
                $pid = null;
            }
            if (!isset($ids[$pid])) {
                $forest[$row[$idName]] = & $row;
            }
            unset($row[$idName]);
            unset($row[$pidName]);
        }
        return $forest;
    }

    /**
     * Replaces the last array in a multi-dimensional array $V by its first value.
     * Used for selectCol(), when we need to transform (N+1)d resulting array
     * to Nd array (column).
     */
    private function _shrinkLastArrayDimensionCallback(&$v)
    {
        if (!$v) {
            return;
        }
        reset($v);
        if (!is_array($firstCell = current($v))) {
            $v = $firstCell;
        } else {
            array_walk($v, array(&$this, '_shrinkLastArrayDimensionCallback'));
        }
    }

    /**
     * void _logQuery($query, $noTrace=false)
     * Must be called on each query.
     * If $noTrace is true, library caller is not solved (speed improvement).
     */
    protected function _logQuery($query, $noTrace = false)
    {
        if (!$this->_logger) {
            return null;
        }
        $this->_expandPlaceholders($query, false);
        $args = array();
        $args[] = & $this;
        $args[] = $query[0];
        $args[] = $noTrace ? null : $this->findLibraryCaller();
        return call_user_func_array($this->_logger, $args);
    }

    /**
     * void _logQueryStat($queryTime, $fetchTime, $firstFetchTime, $rows)
     * Log information about performed query statistics.
     */
    private function _logQueryStat($queryTime, $fetchTime, $firstFetchTime, $rows)
    {
        // Always increment counters.
        $this->_statistics['time'] += $queryTime;
        $this->_statistics['count']++;

        // If no logger, economize CPU resources and actually log nothing.
        if (!$this->_logger) {
            return;
        }

        $dt = round($queryTime * 1000);
        $firstFetchTime = round($firstFetchTime * 1000);
        $tailFetchTime = round($fetchTime * 1000) - $firstFetchTime;
        $log = "  -- ";
        if ($firstFetchTime + $tailFetchTime) {
            $log = sprintf("  -- %d ms = %d+%d" . ($tailFetchTime ? "+%d" : ""), $dt, $dt - $firstFetchTime - $tailFetchTime, $firstFetchTime, $tailFetchTime);
        } else {
            $log = sprintf("  -- %d ms", $dt);
        }
        $log .= "; returned ";

        if (!is_array($rows)) {
            $log .= $this->escape($rows);
        } else {
            $detailed = null;
            if (count($rows) == 1) {
                $len = 0;
                $values = array();
                foreach ($rows[0] as $k => $v) {
                    if ($v) {
                        $len += strlen($v);
                        if ($len > $this->MAX_LOG_ROW_LEN) {
                            break;
                        }
                    }
                    $values[] = $v === null ? 'NULL' : $this->escape($v);
                }
                if ($len <= $this->MAX_LOG_ROW_LEN) {
                    $detailed = "(" . preg_replace("/\r?\n/", "\\n", join(', ', $values)) . ")";
                }
            }
            if ($detailed) {
                $log .= $detailed;
            } else {
                $log .= count($rows) . " row(s)";
            }
        }

        $this->_logQuery($log, true);
    }
}
