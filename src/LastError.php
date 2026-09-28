<?php

namespace Jam\DbSimple;

/**
 * Support for error tracking.
 * Can hold error messages, error queries and build proper stacktraces.
 */
abstract class LastError
{
    /** @var array{code: mixed, message: string, query: mixed, context: string}|null Последняя ошибка */
    public $error = null;
    /** @var string|null Текст последней ошибки с местом вызова */
    public $errmsg = null;

    /**
     * Обработчик ошибок:
     *   null     — по умолчанию: бросить DatabaseException;
     *   false    — ничего не делать (методы вернут false, детали — в $error/$errmsg);
     *   callable — function (string $message, array $info): void.
     * @var callable|false|null
     */
    private $errorHandler = null;

    /** @var array<int, string> Регулярные выражения "Class::method", пропускаемые при поиске места вызова */
    private array $ignoresInTrace = [];

    /**
     * abstract void _logQuery($query)
     * Must be overriden in derived class.
     */
    abstract protected function _logQuery($query);

    /**
     * void _resetLastError()
     * Reset the last error. Must be called on correct queries.
     */
    protected function _resetLastError()
    {
        $this->error = $this->errmsg = null;
    }

    /**
     * void _setLastError(int $code, string $message, string $query)
     * Fill $this->error property with error information. Error context
     * (code initiated the query outside DbSimple) is assigned automatically.
     */
    protected function _setLastError($code, $msg, $query)
    {
        $context = "unknown";
        if (($t = $this->findLibraryCaller())) {
            $context = (isset($t['file']) ? $t['file'] : '?') . ' line ' . (isset($t['line']) ? $t['line'] : '?');
        }
        $this->error = array(
            'code' => $code,
            'message' => rtrim($msg),
            'query' => $query,
            'context' => $context,
        );
        $this->errmsg = rtrim($msg) . ($context ? " at $context" : "");

        $this->_logQuery("  -- error #" . $code . ": " . preg_replace('/(\r?\n)+/s', ' ', $this->errmsg));

        $this->handleError();

        return false;
    }

    private function handleError(): void
    {
        if ($this->errorHandler === null) {
            throw new DatabaseException($this->errmsg, $this->error);
        }
        if (is_callable($this->errorHandler)) {
            call_user_func($this->errorHandler, $this->errmsg, $this->error);
        }
    }

    /**
     * callback setErrorHandler(callback $handler)
     * Set new error handler called on database errors.
     * Handler gets 3 arguments:
     * - error message
     * - full error context information (last query etc.)
     */
    public function setErrorHandler($handler)
    {
        $prev = $this->errorHandler;
        $this->errorHandler = $handler;
        // Ошибка уже есть (например, соединения в конструкторе при отключённом обработчике) —
        // сообщаем о ней новому обработчику сразу
        if ($prev === false && $this->error && is_callable($handler)) {
            call_user_func($handler, $this->errmsg, $this->error);
        }
        return $prev;
    }

    /**
     * Не считать местом вызова методы, подходящие под регулярное выражение по "Class::method"
     * (например, слой моделей поверх DbSimple: 'Jam\\Models\\.*').
     */
    public function addIgnoreInTrace($name)
    {
        $this->ignoresInTrace[] = $name;
    }

    /**
     * Первый кадр стека вне DbSimple: файл и строка прикладного кода, выполнившего запрос.
     * Используется в сообщениях об ошибках и логгере.
     *
     * @return array<string, mixed>|null
     */
    public function findLibraryCaller()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $ignoreRe = $this->ignoresInTrace ? '/^(?:' . implode('|', $this->ignoresInTrace) . ')$/sx' : null;

        foreach ($trace as $i => $frame) {
            if (!isset($frame['file']) || str_starts_with($frame['file'], __DIR__ . DIRECTORY_SEPARATOR)) {
                continue;
            }
            // Функция, внутри которой сделан этот вызов
            $caller = $trace[$i + 1] ?? [];
            $callerClass = $caller['class'] ?? null;
            if ($callerClass && is_a($callerClass, self::class, true)) {
                continue; // адаптер, объявленный вне пакета
            }
            $callerName = ($callerClass ? $callerClass . '::' : '') . ($caller['function'] ?? '');
            if ($ignoreRe && $callerName !== '' && preg_match($ignoreRe, $callerName)) {
                continue;
            }
            return $frame;
        }
        return null;
    }
}
