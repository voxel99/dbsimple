<?php

/**
 * DbSimple_Generic: universal database connected by DSN.
 * (C) Dk Lab, http://en.dklab.ru
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * See http://www.gnu.org/copyleft/lesser.html
 *
 * Use static DbSimple_Generic::connect($dsn) call if you don't know
 * database type and parameters, but have its DSN.
 *
 * Additional keys can be added by appending a URI query string to the
 * end of the DSN.
 *
 * The format of the supplied DSN is in its fullest form:
 *   phptype(dbsyntax)://username:password@protocol+hostspec/database?option=8&another=true
 *
 * Most variations are allowed:
 *   phptype://username:password@protocol+hostspec:110//usr/db_file.db?mode=0644
 *   phptype://username:password@hostspec/database_name
 *   phptype://username:password@hostspec
 *   phptype://username@hostspec
 *   phptype://hostspec/database
 *   phptype://hostspec
 *   phptype(dbsyntax)
 *   phptype
 *
 * Parsing code is partially grabbed from PEAR DB class,
 * initial author: Tomas V.V.Cox <cox@idecnet.com>.
 *
 * Contains 3 classes:
 * - DbSimple_Generic: database factory class
 * - DbSimple_Generic_Database: common database methods
 * - DbSimple_Generic_Blob: common BLOB support
 * - DbSimple_Generic_LastError: error reporting and tracking
 *
 * Special result-set fields:
 * - ARRAY_KEY* ("*" means "anything")
 * - PARENT_KEY
 *
 * Transforms:
 * - GET_ATTRIBUTES
 * - CALC_TOTAL
 * - GET_TOTAL
 * - UNIQ_KEY
 *
 * Query attributes:
 * - BLOB_OBJ
 * - CACHE
 *
 * @author Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 * @author Konstantin Zhinko, http://forum.dklab.ru/users/KonstantinGinkoTit/
 *
 * @version 2.x $Id$
 */

namespace Jam\DbSimple;

/**
 * DbSimple factory.
 */
class Generic
{
    /**
     * DbSimple_Generic connect(mixed $dsn)
     *
     * Universal static function to connect ANY database using DSN syntax.
     * Choose database driver according to DSN. Return new instance
     * of this driver.
     *
     * You can connect to MySQL by socket using this new syntax (like PDO DSN):
     * $dsn = 'mysqli:unix_socket=/cloudsql/app:instance;user=root;pass=;dbname=testdb';
     * $dsn = 'mypdo:unix_socket=/cloudsql/app:instance;charset=utf8;user=testuser;pass=mypassword;dbname=testdb';
     *
     * Connection by host also can be made with this syntax.
     * Or you can use old syntax:
     * $dsn = 'mysql://testuser:mypassword@127.0.0.1/testdb';
     *
     */
    public static function connect($dsn)
    {
        $parsed = self::parseDSN($dsn);
        if (!$parsed || !isset($parsed['scheme'])) {
            return null;
        }
        $object = self::createAdapter($parsed);
        if (isset($parsed['ident_prefix'])) {
            $object->setIdentPrefix($parsed['ident_prefix']);
        }
        return $object;
    }

    /** @var array<string, class-string<Database>> Зарегистрированные адаптеры: схема DSN => класс */
    private static array $adapters = [];

    /**
     * Зарегистрировать свой адаптер под схемой DSN:
     *   Generic::registerAdapter('clickhouse', ClickhouseAdapter::class);
     *   new Connect('clickhouse://user:pass@host/db');
     *
     * @param class-string<Database> $class
     */
    public static function registerAdapter(string $scheme, string $class): void
    {
        if (!is_subclass_of($class, Database::class)) {
            throw new DatabaseException(sprintf('Adapter %s must extend %s', $class, Database::class));
        }
        self::$adapters[strtolower($scheme)] = $class;
    }

    /**
     * Класс адаптера по схеме DSN: зарегистрированный или Adapter\{Scheme} ('mypdo' -> Adapter\Mypdo).
     *
     * @return class-string<Database>
     */
    public static function adapterClass(string $scheme): string
    {
        $class = self::$adapters[strtolower($scheme)] ?? 'Jam\\DbSimple\\Adapter\\' . ucfirst($scheme);
        if (!class_exists($class) || !is_subclass_of($class, Database::class)) {
            throw new DatabaseException('Unknown database driver: ' . $scheme);
        }
        return $class;
    }

    /**
     * Создать адаптер. Обработчик ошибок назначается ДО вызова конструктора,
     * потому что ошибка соединения возникает именно в нём.
     *
     * @param array<string, mixed> $parsed Результат parseDSN() (нужен ключ scheme)
     * @param callable|false|null $errorHandler см. LastError::setErrorHandler()
     */
    public static function createAdapter(array $parsed, $errorHandler = null): Database
    {
        $class = self::adapterClass((string) $parsed['scheme']);
        $reflection = new \ReflectionClass($class);
        /** @var Database $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->setErrorHandler($errorHandler);
        $db->__construct($parsed);
        return $db;
    }

    /** DSN без пароля — для сообщений об ошибках */
    public static function maskDsn(string $dsn): string
    {
        return (string) preg_replace('#(://[^:/@]+:)[^@]*@#', '$1***@', $dsn);
    }

    /**
     * Разбор DSN в массив параметров (scheme, host, port, user, pass, path + параметры query string).
     *
     *   mypdo://user:pass@127.0.0.1:3306/app?enc=utf8mb4
     *   mypdo:unix_socket=/tmp/mysql.sock;dbname=app;user=root   (PDO-подобная запись)
     *   postgresql://user:pass@127.0.0.1/app
     *   sqlite:///var/data/app.db   sqlite:/var/data/app.db   sqlite::memory:
     *
     * @param string|array<string, mixed> $dsn
     * @return array<string, mixed>|null
     */
    public static function parseDSN($dsn)
    {
        if (is_array($dsn)) {
            return $dsn;
        }
        // Файловые DSN (sqlite:///path) parse_url() не разбирает — путь берём как есть
        if (preg_match('#^(sqlite):(?://)?([^?]*)(?:\?(.*))?$#i', $dsn, $m)) {
            $parsed = ['scheme' => strtolower($m[1]), 'path' => $m[2] === '' ? ':memory:' : $m[2]];
            if (!empty($m[3])) {
                parse_str($m[3], $params);
                $parsed += $params;
            }
            $parsed['dsn'] = $dsn;
            return $parsed;
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

        // PDO-подобная запись параметров: mypdo:unix_socket=/tmp/mysql.sock;dbname=app
        if (empty($parsed['host']) && empty($parsed['socket']) && str_contains($parsed['path'] ?? '', '=')) {
            $parsedPdo = self::parseDsnPdo($parsed['path']);
            unset($parsed['path']);
            $parsed = array_merge($parsed, $parsedPdo);
        }

        $parsed['dsn'] = $dsn;
        return $parsed;
    }

    /**
     * Parse string as DBO DSN string.
     *
     * @param string $str
     * @return array<string, string> Parsed DSN parameters
     */
    public static function parseDsnPdo($str)
    {
        if (substr($str, 0, strlen('mysql:')) == 'mysql:') {
            $str = substr($str, strlen('mysql:'));
        }

        $arr = explode(';', $str);

        $result = array();
        foreach ($arr as $k => $v) {
            $v = explode('=', $v);
            if (count($v) == 2) {
                $result[$v[0]] = $v[1];
            }
        }

        if (isset($result['unix_socket'])) {
            $result['socket'] = $result['unix_socket'];
            unset($result['unix_socket']);
        }

        if (isset($result['dbname'])) {
            $result['path'] = $result['dbname'];
            unset($result['dbname']);
        }

        if (isset($result['charset'])) {
            $result['enc'] = $result['charset'];
            unset($result['charset']);
        }

        return $result;
    }
}
