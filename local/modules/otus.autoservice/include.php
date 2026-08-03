<?php

/**
 * Регистрирует классы модуля otus.autoservice в автозагрузчике Bitrix D7.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;

/**
 * Карта автозагрузки содержит полное имя класса в ключе и путь относительно
 * корня модуля в значении. Явная карта сохраняет корректный регистр каталогов
 * при переносе проекта с Windows на Linux.
 */
Loader::registerAutoLoadClasses(
    'otus.autoservice',
    [
        'Otus\\Autoservice\\EventHandler\\EventRegistry' => 'lib/EventHandler/EventRegistry.php',
        'Otus\\Autoservice\\Migration\\MigrationInterface' => 'lib/Migration/MigrationInterface.php',
        'Otus\\Autoservice\\Migration\\MigrationManager' => 'lib/Migration/MigrationManager.php',
        'Otus\\Autoservice\\Service\\ModuleConfiguration' => 'lib/Service/ModuleConfiguration.php',
        'Otus\\Autoservice\\Service\\ModuleRequirements' => 'lib/Service/ModuleRequirements.php',
    ]
);
