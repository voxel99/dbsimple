<?php

namespace Jam\DbSimple;

/**
 * Ленивое соединение: адаптер создаётся и подключается при первом запросе.
 *
 * <code>
 * $db = new Connect('mypdo://user:pass@127.0.0.1/app?enc=utf8mb4');
 * $db->addInit('SET time_zone = ?', '+00:00'); // выполнится сразу после подключения
 * $rows = $db->select('SELECT * FROM users WHERE id IN (?a)', [1, 2]); // здесь происходит подключение
 * </code>
 *
 * Схема DSN выбирает адаптер: mypdo -> Adapter\Mypdo, postgresql -> Adapter\Postgresql,
 * sqlite -> Adapter\Sqlite. Остальные методы адаптера (debugSql(), createFunction() и т.п.)
 * доступны через __call().
 *
 * @method void debugSql(int|callable $debug)
 * @method bool isDebugSql(int|callable $debug)
 */
class Connect implements DatabaseInterface
{
    /** Адаптер (null до первого запроса) */
    protected ?Database $DbSimple = null;

    /** @var string DSN подключения */
    protected $DSN;

    /** @var string Имя адаптера из схемы DSN (Mypdo, Postgresql, Sqlite) */
    protected $shema;

    /** @var array<int, array<int, mixed>> Запросы, выполняемые сразу после подключения */
    protected $init = [];

    /** @var array|null Последняя ошибка (после подключения — ссылка на $error адаптера) */
    public $error = null;

    /** @var string|null Текст последней ошибки */
    public $errmsg = null;

    /** @var callable|false|null См. LastError::$errorHandler */
    private $errorHandler = null;
    private $identPrefix = null;
    private $logger = null;

    /**
     * @param string $dsn DSN строка БД
     */
    public function __construct($dsn)
    {
        $this->DSN = $dsn;
        $this->shema = ucfirst(substr($dsn, 0, (int) strpos($dsn, ':')));
    }

    /**
     * Соединение из пула: один объект на DSN в рамках процесса.
     */
    public static function get($dsn): self
    {
        static $pool = [];
        return $pool[$dsn] ??= new self($dsn);
    }

    /** @return string Имя адаптера (Mypdo, Postgresql, Sqlite) */
    public function getShema()
    {
        return $this->shema;
    }

    /** Подключиться (если ещё не подключены) и вернуть адаптер. */
    public function getDatabase(): Database
    {
        if ($this->DbSimple === null) {
            $this->connect($this->DSN);
        }
        return $this->DbSimple;
    }

    public function isConnected(): bool
    {
        return $this->DbSimple !== null;
    }

    /**
     * Прочие методы адаптера (debugSql(), createFunction() и т.п.)
     */
    public function __call($method, $params)
    {
        return $this->getDatabase()->$method(...$params);
    }

    public function select(...$query)
    {
        return $this->getDatabase()->select(...$query);
    }

    public function selectPage(&$total, ...$query)
    {
        return $this->getDatabase()->selectPage($total, ...$query);
    }

    public function selectRow(...$query)
    {
        return $this->getDatabase()->selectRow(...$query);
    }

    public function selectCol(...$query)
    {
        return $this->getDatabase()->selectCol(...$query);
    }

    public function selectCell(...$query)
    {
        return $this->getDatabase()->selectCell(...$query);
    }

    public function query(...$query)
    {
        return $this->getDatabase()->query(...$query);
    }

    public function subquery(...$query)
    {
        return $this->getDatabase()->subquery(...$query);
    }

    public function escape($s, $isIdent = false)
    {
        return $this->getDatabase()->escape($s, $isIdent);
    }

    public function transaction($mode = null)
    {
        return $this->getDatabase()->transaction($mode);
    }

    public function commit()
    {
        return $this->getDatabase()->commit();
    }

    public function rollback()
    {
        return $this->getDatabase()->rollback();
    }

    public function blob($blob_id = null)
    {
        return $this->getDatabase()->blob($blob_id);
    }

    public function setClassName($name)
    {
        $this->getDatabase()->setClassName($name);
        return $this;
    }

    public function getStatistics()
    {
        return $this->DbSimple ? $this->DbSimple->getStatistics() : ['time' => 0, 'count' => 0];
    }

    /**
     * Подключение к базе данных
     * @param string $dsn DSN строка БД
     */
    public function connect($dsn)
    {
        $parsed = Generic::parseDSN($dsn);
        if (!$parsed || !isset($parsed['scheme'])) {
            throw new DatabaseException('Cannot parse DSN or detect database driver: ' . Generic::maskDsn((string) $dsn));
        }

        $this->shema = ucfirst($parsed['scheme']);
        // Обработчик нужен до конструктора адаптера: ошибка соединения возникает именно там
        $db = Generic::createAdapter($parsed, $this->errorHandler);
        $this->DbSimple = $db;
        $this->errmsg = &$db->errmsg;
        $this->error = &$db->error;

        $prefix = $parsed['prefix'] ?? $this->identPrefix;
        if ($prefix) {
            $db->setIdentPrefix($prefix);
        }
        if ($this->logger) {
            $db->setLogger($this->logger);
        }

        foreach ($this->init as $query) {
            $db->query(...$query);
        }
        $this->init = [];
    }

    /**
     * Обработчик ошибок «по умолчанию» (для совместимости с кодом, который передаёт
     * [$connect, 'errorHandler']): бросает DatabaseException.
     *
     * @param string $msg Сообщение об ошибке
     * @param array<string, mixed>|string $info Подробная информация о контексте ошибки
     */
    public function errorHandler($msg, $info)
    {
        throw new DatabaseException($msg, is_array($info) ? $info : []);
    }

    /**
     * Запрос, выполняемый сразу после подключения (SET NAMES, time_zone и т.п.).
     * Если соединение уже установлено — выполняется немедленно.
     *
     * @param string $query запрос
     * @return mixed
     */
    public function addInit($query, ...$args)
    {
        if ($this->DbSimple !== null) {
            return $this->DbSimple->query($query, ...$args);
        }
        $this->init[] = [$query, ...$args];
        return null;
    }

    /**
     * Установить обработчик ошибок.
     *
     * @param callable|false|null $handler
     *   null — по умолчанию (DatabaseException), false — отключен, callable($message, array $info)
     * @return callable|false|null предыдущий обработчик
     */
    public function setErrorHandler($handler)
    {
        $prev = $this->errorHandler;
        $this->errorHandler = $handler;
        $this->DbSimple?->setErrorHandler($handler);
        return $prev;
    }

    /**
     * Логгер, вызываемый перед каждым запросом: function ($db, string $sql, ?array $caller).
     * Возвращает предыдущий логгер.
     */
    public function setLogger($logger)
    {
        $prev = $this->logger;
        $this->logger = $logger;
        $this->DbSimple?->setLogger($logger);
        return $prev;
    }

    /**
     * Префикс таблиц для плейсхолдера ?_ (SELECT * FROM ?_users -> app_users).
     */
    public function setIdentPrefix($prx)
    {
        $old = $this->identPrefix;
        if ($prx !== null) {
            $this->identPrefix = $prx;
        }
        $this->DbSimple?->setIdentPrefix($prx);
        return $old;
    }
}
