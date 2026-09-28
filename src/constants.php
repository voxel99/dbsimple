<?php

/**
 * Глобальные константы DbSimple.
 *
 * Подключаются автозагрузчиком Composer (секция "files"), поэтому доступны
 * до загрузки любого класса пакета и объявляются ровно один раз.
 */

if (!defined('DBSIMPLE_SKIP')) {
    /**
     * Значение-маркер: плейсхолдер с этим значением «выключает» содержащий его
     * необязательный блок {...}: select('... { AND a = ? }', DBSIMPLE_SKIP)
     */
    define('DBSIMPLE_SKIP', log(0));
}

if (!defined('DBSIMPLE_ARRAY_KEY')) {
    /** Колонка ARRAY_KEY* в результате — ключ результирующего массива (ARRAY_KEY_1, ARRAY_KEY_2 — вложенность) */
    define('DBSIMPLE_ARRAY_KEY', 'ARRAY_KEY');
}

if (!defined('DBSIMPLE_PARENT_KEY')) {
    /** Колонка PARENT_KEY вместе с ARRAY_KEY — результат собирается в дерево (childNodes) */
    define('DBSIMPLE_PARENT_KEY', 'PARENT_KEY');
}
