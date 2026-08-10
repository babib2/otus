<?php

/**
 * Проверяет обязательную инфраструктуру каталога и, при наличии, демонстрационные запчасти.
 */

declare(strict_types=1);

use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Service\ModuleConfiguration;

if (PHP_SAPI !== 'cli') {
    // Диагностика раскрывает технические ID каталога и поэтому недоступна через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $requireDemoParts Требовать ли полный демонстрационный набор при проверке. */
$requireDemoParts = in_array('--require-demo', $argv, true);

/** @var string|null $documentRootArgument Первый аргумент пути, не являющийся флагом. */
$documentRootArgument = null;
/** @var string $argument Очередной пользовательский аргумент CLI. */
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with((string)$argument, '--')) {
        $documentRootArgument = (string)$argument;
        break;
    }
}

/** @var string $documentRoot Нормализованный корень портала для подключения пролога. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

/** @var array<string, string> $MESS Предварительно загруженные сообщения для ошибок до пролога. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/check_spare_parts_catalog.php';

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DOCUMENT_ROOT_MISSING'] ?? '')
        ) . PHP_EOL
    );
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
$_SERVER['REQUEST_METHOD'] = 'CLI';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);
define('CHK_EVENT', false);

require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

Loc::loadMessages(__FILE__);

if (
    !Loader::includeModule('otus.autoservice')
    || !Loader::includeModule('crm')
    || !Loader::includeModule('iblock')
    || !Loader::includeModule('catalog')
) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_MODULES_REQUIRED') . PHP_EOL);
    exit(1);
}

/** @var string $requiredMigration Версия миграции подготовки запчастей. */
$requiredMigration = '202608090008';
/** @var string $currentMigration Текущая сохранённая версия схемы модуля. */
$currentMigration = MigrationManager::getCurrentVersion();
if (strlen($currentMigration) !== strlen($requiredMigration) || strcmp($currentMigration, $requiredMigration) < 0) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_PARTS_MIGRATION_MISSING',
            ['#VERSION#' => $currentMigration]
        ) . PHP_EOL
    );
    exit(1);
}

/** @var int|null $catalogId Настроенный ID штатного CRM-каталога. */
$catalogId = ModuleConfiguration::getSparePartsCatalogId();
/** @var int|null $articlePropertyId Настроенный ID свойства артикула. */
$articlePropertyId = ModuleConfiguration::getSparePartsArticlePropertyId();
/** @var int|null $storeId Настроенный ID демонстрационного склада. */
$storeId = ModuleConfiguration::getSparePartsStoreId();

if ($catalogId === null || $articlePropertyId === null || $storeId === null) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_CONFIG_INCOMPLETE') . PHP_EOL);
    exit(1);
}
if ((int)\CCrmCatalog::GetDefaultID() !== $catalogId) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_CATALOG_NOT_DEFAULT') . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed>|false $store Демонстрационный склад из D7 ORM. */
$store = StoreTable::getByPrimary(
    $storeId,
    ['select' => ['ID', 'TITLE', 'ACTIVE', 'XML_ID', 'CODE', 'IS_DEFAULT']]
)->fetch();
if (
    $store === false
    || (string)$store['XML_ID'] !== SparePartsCatalogManager::STORE_XML_ID
    || (string)$store['CODE'] !== SparePartsCatalogManager::STORE_CODE
    || (string)$store['ACTIVE'] !== 'Y'
) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_STORE_INVALID') . PHP_EOL);
    exit(1);
}

/** @var SparePartsCatalogManager $manager Менеджер итоговой связности объектов этапа. */
$manager = new SparePartsCatalogManager();
try {
    /** @var bool $infrastructureReady Результат безопасной проверки обязательных объектов. */
    $infrastructureReady = $manager->isReady();
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_PARTS_RUNTIME_ERROR',
            ['#ERROR#' => $exception->getMessage()]
        ) . PHP_EOL
    );
    exit(1);
}
if (!$infrastructureReady) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_MANAGER_NOT_READY') . PHP_EOL);
    exit(1);
}

/** @var array<int, array<string, mixed>> $definitions Ожидаемые демонстрационные определения. */
$definitions = SparePartsCatalogManager::getDemoPartDefinitions();
/** @var array<string, bool> $usedArticles Артикулы, уже встреченные в тестовом наборе. */
$usedArticles = [];
/** @var array<string, bool> $usedExternalIds Внешние ID, уже встреченные в тестовом наборе. */
$usedExternalIds = [];
/** @var int $foundDemoParts Количество фактически найденных демонстрационных товаров. */
$foundDemoParts = 0;
/** @var bool $inventoryManagementEnabled Текущий режим складского учёта портала. */
$inventoryManagementEnabled = State::isUsedInventoryManagement();

/** @var array<string, mixed> $definition Ожидаемое описание очередной демонстрационной запчасти. */
foreach ($definitions as $definition) {
    /** @var string $externalId Стабильный внешний ID текущего товара. */
    $externalId = (string)$definition['xml_id'];
    /** @var string $article Уникальный артикул текущего товара. */
    $article = (string)$definition['article'];
    if (isset($usedExternalIds[$externalId]) || isset($usedArticles[$article])) {
        fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_DEFINITION_DUPLICATE') . PHP_EOL);
        exit(1);
    }
    $usedExternalIds[$externalId] = true;
    $usedArticles[$article] = true;

    /** @var array<string, mixed>|false $element Товарный элемент выбранного CRM-каталога. */
    $element = ElementTable::getList(
        [
            'select' => ['ID', 'IBLOCK_ID', 'NAME', 'XML_ID', 'ACTIVE'],
            'filter' => ['=IBLOCK_ID' => $catalogId, '=XML_ID' => $externalId],
            'limit' => 1,
        ]
    )->fetch();
    if ($element === false) {
        continue;
    }
    ++$foundDemoParts;
    if ((string)$element['ACTIVE'] !== 'Y') {
        fwrite(
            STDERR,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_INVALID',
                ['#EXTERNAL_ID#' => $externalId]
            ) . PHP_EOL
        );
        exit(1);
    }

    /** @var int $productId Общий ID элемента инфоблока и товара catalog. */
    $productId = (int)$element['ID'];
    /** @var array<string, mixed>|false $product Количество и правила доступности товара. */
    $product = ProductTable::getByPrimary(
        $productId,
        ['select' => ['ID', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO', 'TYPE']]
    )->fetch();
    /** @var array<string, mixed>|false $storeProduct Остаток товара на демонстрационном складе. */
    $storeProduct = StoreProductTable::getList(
        [
            'select' => ['ID', 'AMOUNT', 'QUANTITY_RESERVED'],
            'filter' => ['=STORE_ID' => $storeId, '=PRODUCT_ID' => $productId],
            'limit' => 1,
        ]
    )->fetch();
    if ($product === false || $storeProduct === false) {
        fwrite(
            STDERR,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_PARTS_PRODUCT_RECORD_MISSING',
                ['#PRODUCT_ID#' => (string)$productId]
            ) . PHP_EOL
        );
        exit(1);
    }
    if (
        (int)$product['TYPE'] !== ProductTable::TYPE_PRODUCT
        || (string)$product['QUANTITY_TRACE'] !== ProductTable::STATUS_YES
        || (string)$product['CAN_BUY_ZERO'] !== ProductTable::STATUS_NO
        || (float)$product['QUANTITY'] < 0
        || (float)$storeProduct['AMOUNT'] < 0
        || (float)$storeProduct['QUANTITY_RESERVED'] < 0
        || (float)$storeProduct['QUANTITY_RESERVED'] > (float)$storeProduct['AMOUNT']
        || !$manager->isProductQuantityConsistent($productId)
    ) {
        fwrite(
            STDERR,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_PARTS_QUANTITY_INVALID',
                ['#PRODUCT_ID#' => (string)$productId]
            ) . PHP_EOL
        );
        exit(1);
    }
}

/** @var int $expectedDemoParts Полный размер фиксированного демонстрационного набора. */
$expectedDemoParts = count($definitions);
if ($foundDemoParts > 0 && $foundDemoParts < $expectedDemoParts) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_INCOMPLETE',
            ['#FOUND#' => (string)$foundDemoParts, '#EXPECTED#' => (string)$expectedDemoParts]
        ) . PHP_EOL
    );
    exit(1);
}
if ($foundDemoParts === $expectedDemoParts) {
    try {
        /** @var bool $demoProductsReady Итог полной проверки демонстрационного набора. */
        $demoProductsReady = $manager->areDemoProductsReady();
    } catch (Throwable $exception) {
        fwrite(
            STDERR,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_PARTS_RUNTIME_ERROR',
                ['#ERROR#' => $exception->getMessage()]
            ) . PHP_EOL
        );
        exit(1);
    }
    if (!$demoProductsReady) {
        fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_NOT_READY') . PHP_EOL);
        exit(1);
    }
}
if ($requireDemoParts && $foundDemoParts !== $expectedDemoParts) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_REQUIRED') . PHP_EOL);
    exit(1);
}

/** @var string $inventoryMode Локализованное описание текущего режима складского учёта. */
$inventoryMode = (string)Loc::getMessage(
    $inventoryManagementEnabled
        ? 'OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_ENABLED'
        : 'OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_DISABLED'
);
/** @var string $demoMode Локализованное состояние необязательного демонстрационного набора. */
$demoMode = (string)Loc::getMessage(
    $foundDemoParts === $expectedDemoParts
        ? 'OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_READY'
        : 'OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_ABSENT'
);

echo (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_CATALOG_ID', ['#ID#' => (string)$catalogId]) . PHP_EOL;
echo (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_PROPERTY_ID', ['#ID#' => (string)$articlePropertyId]) . PHP_EOL;
echo (string)Loc::getMessage(
    'OTUS_AUTOSERVICE_CHECK_PARTS_STORE_ID',
    ['#ID#' => (string)$storeId, '#DEFAULT#' => (string)$store['IS_DEFAULT']]
) . PHP_EOL;
echo (string)Loc::getMessage(
    'OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_STATUS',
    ['#STATUS#' => $demoMode, '#FOUND#' => (string)$foundDemoParts, '#EXPECTED#' => (string)$expectedDemoParts]
) . PHP_EOL;
echo (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_STATUS', ['#STATUS#' => $inventoryMode]) . PHP_EOL;
echo (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_PARTS_OK') . PHP_EOL;
