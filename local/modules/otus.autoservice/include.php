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
        'Otus\\Autoservice\\EventHandler\\ContactGarageTabHandler' => 'lib/EventHandler/ContactGarageTabHandler.php',
        'Otus\\Autoservice\\EventHandler\\DealCarSelectorAssetHandler' => 'lib/EventHandler/DealCarSelectorAssetHandler.php',
        'Otus\\Autoservice\\EventHandler\\DealValidationHandler' => 'lib/EventHandler/DealValidationHandler.php',
        'Otus\\Autoservice\\Integration\\Crm\\DealCarFieldManager' => 'lib/Integration/Crm/DealCarFieldManager.php',
        'Otus\\Autoservice\\Integration\\Crm\\DealCarSelectorAssetManager' => 'lib/Integration/Crm/DealCarSelectorAssetManager.php',
        'Otus\\Autoservice\\Integration\\Crm\\DealNotificationService' => 'lib/Integration/Crm/DealNotificationService.php',
        'Otus\\Autoservice\\Integration\\Crm\\GarageComponentManager' => 'lib/Integration/Crm/GarageComponentManager.php',
        'Otus\\Autoservice\\Integration\\Crm\\ServiceDealPipelineManager' => 'lib/Integration/Crm/ServiceDealPipelineManager.php',
        'Otus\\Autoservice\\Integration\\Catalog\\SparePartsCatalogManager' => 'lib/Integration/Catalog/SparePartsCatalogManager.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockItem' => 'lib/Integration/Stock/StockItem.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockProviderInterface' => 'lib/Integration/Stock/StockProviderInterface.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockProviderException' => 'lib/Integration/Stock/StockProviderException.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockFetchResult' => 'lib/Integration/Stock/StockFetchResult.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockBatchFetcher' => 'lib/Integration/Stock/StockBatchFetcher.php',
        'Otus\\Autoservice\\Integration\\Stock\\FakeStockProvider' => 'lib/Integration/Stock/FakeStockProvider.php',
        'Otus\\Autoservice\\Integration\\Stock\\RandomOrgStockProvider' => 'lib/Integration/Stock/RandomOrgStockProvider.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockProviderFactory' => 'lib/Integration/Stock/StockProviderFactory.php',
        'Otus\\Autoservice\\Integration\\Stock\\StockQuantityUpdaterInterface' => 'lib/Integration/Stock/StockQuantityUpdaterInterface.php',
        'Otus\\Autoservice\\Integration\\UI\\EntitySelector\\CarProvider' => 'lib/Integration/UI/EntitySelector/CarProvider.php',
        'Otus\\Autoservice\\Integration\\Rest\\CarRestService' => 'lib/Integration/Rest/CarRestService.php',
        'Otus\\Autoservice\\Logger\\ModuleLogger' => 'lib/Logger/ModuleLogger.php',
        'Otus\\Autoservice\\Cache\\CarListCache' => 'lib/Cache/CarListCache.php',
        'Otus\\Autoservice\\Controller\\Car' => 'lib/Controller/Car.php',
        'Otus\\Autoservice\\Migration\\MigrationInterface' => 'lib/Migration/MigrationInterface.php',
        'Otus\\Autoservice\\Migration\\MigrationManager' => 'lib/Migration/MigrationManager.php',
        'Otus\\Autoservice\\Migration\\Version202608050001CreateCarTable' => 'lib/Migration/Version202608050001CreateCarTable.php',
        'Otus\\Autoservice\\Migration\\Version202608050002CreateDealCarField' => 'lib/Migration/Version202608050002CreateDealCarField.php',
        'Otus\\Autoservice\\Migration\\Version202608050003CreateServiceDealPipeline' => 'lib/Migration/Version202608050003CreateServiceDealPipeline.php',
        'Otus\\Autoservice\\Migration\\Version202608070004InstallDealCarSelector' => 'lib/Migration/Version202608070004InstallDealCarSelector.php',
        'Otus\\Autoservice\\Migration\\Version202608080005InstallContactGarage' => 'lib/Migration/Version202608080005InstallContactGarage.php',
        'Otus\\Autoservice\\Migration\\Version202608090006PublishCarHistory' => 'lib/Migration/Version202608090006PublishCarHistory.php',
        'Otus\\Autoservice\\Migration\\Version202608090007RegisterCarRestApi' => 'lib/Migration/Version202608090007RegisterCarRestApi.php',
        'Otus\\Autoservice\\Migration\\Version202608090008CreateSparePartsCatalog' => 'lib/Migration/Version202608090008CreateSparePartsCatalog.php',
        'Otus\\Autoservice\\Migration\\Version202608110009CreateStockSyncTables' => 'lib/Migration/Version202608110009CreateStockSyncTables.php',
        'Otus\\Autoservice\\Migration\\Version202608110010ExtendStockSyncItems' => 'lib/Migration/Version202608110010ExtendStockSyncItems.php',
        'Otus\\Autoservice\\Model\\CarTable' => 'lib/Model/CarTable.php',
        'Otus\\Autoservice\\Model\\SyncRunTable' => 'lib/Model/SyncRunTable.php',
        'Otus\\Autoservice\\Model\\SyncItemTable' => 'lib/Model/SyncItemTable.php',
        'Otus\\Autoservice\\Repository\\CarRepository' => 'lib/Repository/CarRepository.php',
        'Otus\\Autoservice\\Repository\\SparePartStockRepository' => 'lib/Repository/SparePartStockRepository.php',
        'Otus\\Autoservice\\Service\\CarHistoryService' => 'lib/Service/CarHistoryService.php',
        'Otus\\Autoservice\\Service\\CarService' => 'lib/Service/CarService.php',
        'Otus\\Autoservice\\Service\\CarPullService' => 'lib/Service/CarPullService.php',
        'Otus\\Autoservice\\Service\\DealOpenOrderService' => 'lib/Service/DealOpenOrderService.php',
        'Otus\\Autoservice\\Service\\StockSyncService' => 'lib/Service/StockSyncService.php',
        'Otus\\Autoservice\\Service\\StockQuantityService' => 'lib/Service/StockQuantityService.php',
        'Otus\\Autoservice\\Service\\ModuleConfiguration' => 'lib/Service/ModuleConfiguration.php',
        'Otus\\Autoservice\\Service\\ModuleRequirements' => 'lib/Service/ModuleRequirements.php',
    ]
);
