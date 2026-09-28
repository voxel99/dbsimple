<?php

namespace Jam\DbSimple;

use RuntimeException;

/**
 * Ошибка базы данных (соединение, синтаксис SQL, нарушение ограничений и т.п.).
 *
 * Бросается обработчиком ошибок по умолчанию. Свой обработчик задаётся через
 * setErrorHandler(callable), отключить исключения — setErrorHandler(false):
 * тогда методы запросов возвращают false, а детали лежат в $db->error / $db->errmsg.
 */
class DatabaseException extends RuntimeException
{
    /**
     * @param array{code: mixed, message: string, query: mixed, context: string} $info
     */
    public function __construct(string $message, private array $info = [])
    {
        parent::__construct($message, is_numeric($info['code'] ?? null) ? (int) $info['code'] : 0);
    }

    /** SQL-запрос (с подставленными значениями), на котором произошла ошибка */
    public function getQuery(): ?string
    {
        $query = $this->info['query'] ?? null;
        return is_array($query) ? (string) ($query[0] ?? '') : $query;
    }

    /** Код ошибки драйвера */
    public function getDbCode(): mixed
    {
        return $this->info['code'] ?? null;
    }

    /** Место в прикладном коде, откуда был выполнен запрос: "file line N" */
    public function getContext(): ?string
    {
        return $this->info['context'] ?? null;
    }

    /** @return array{code: mixed, message: string, query: mixed, context: string} */
    public function getInfo(): array
    {
        return $this->info;
    }
}
