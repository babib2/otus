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
        'Otus\\Autoservice\\EventHandler\\DealValidationHandler' => 'lib/EventHandler/DealValidationHandler.php',
        'Otus\\Autoservice\\Integration\\Crm\\DealCarFieldManager' => 'lib/Integration/Crm/DealCarFieldManager.php',
        'Otus\\Autoservice\\Integration\\Crm\\DealNotificationService' => 'lib/Integration/Crm/DealNotificationService.php',
        'Otus\\Autoservice\\Integration\\Crm\\ServiceDealPipelineManager' => 'lib/Integration/Crm/ServiceDealPipelineManager.php',
        'Otus\\Autoservice\\Logger\\ModuleLogger' => 'lib/Logger/ModuleLogger.php',
        'Otus\\Autoservice\\Migration\\MigrationInterface' => 'lib/Migration/MigrationInterface.php',
        'Otus\\Autoservice\\Migration\\MigrationManager' => 'lib/Migration/MigrationManager.php',
        'Otus\\Autoservice\\Migration\\Version202608050001CreateCarTable' => 'lib/Migration/Version202608050001CreateCarTable.php',
        'Otus\\Autoservice\\Migration\\Version202608050002CreateDealCarField' => 'lib/Migration/Version202608050002CreateDealCarField.php',
        'Otus\\Autoservice\\Migration\\Version202608050003CreateServiceDealPipeline' => 'lib/Migration/Version202608050003CreateServiceDealPipeline.php',
        'Otus\\Autoservice\\Model\\CarTable' => 'lib/Model/CarTable.php',
        'Otus\\Autoservice\\Repository\\CarRepository' => 'lib/Repository/CarRepository.php',
        'Otus\\Autoservice\\Service\\CarService' => 'lib/Service/CarService.php',
        'Otus\\Autoservice\\Service\\DealOpenOrderService' => 'lib/Service/DealOpenOrderService.php',
        'Otus\\Autoservice\\Service\\ModuleConfiguration' => 'lib/Service/ModuleConfiguration.php',
        'Otus\\Autoservice\\Service\\ModuleRequirements' => 'lib/Service/ModuleRequirements.php',
    ]
);
