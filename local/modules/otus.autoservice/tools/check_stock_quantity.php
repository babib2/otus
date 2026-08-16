<?php

/**
 * Проверяет штатный применитель абсолютного остатка и безопасно восстанавливает исходные количества.
 */

declare(strict_types=1);

use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreDocumentElementTable;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Repository\SparePartStockRepository;
use Otus\Autoservice\Service\ModuleConfiguration;
use Otus\Autoservice\Service\StockQuantityService;

if (PHP_SAPI !== 'cli') {
    // Сценарий раскрывает технические ID и поэтому никогда не публикуется через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $writeTest Явное разрешение временно изменить и затем восстановить один остаток. */
$writeTest = in_array('--write-test', $argv, true);
/** @var string|null $documentRootArgument Первый позиционный аргумент с корнем портала. */
$documentRootArgument = null;

/** @var string $argument Очередной аргумент CLI до отделения флагов от пути. */
foreach (array_slice($argv, 1) as $argument) {
    /** @var string $normalizedArgument Строковое представление текущего аргумента. */
    $normalizedArgument = (string)$argument;
    if (!str_starts_with($normalizedArgument, '--')) {
        $documentRootArgument = $normalizedArgument;
        break;
    }
}

/** @var string $documentRoot Нормализованный корень портала для CLI-пролога. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

/** @var array<string, string> $MESS Сообщения, доступные до загрузки ядра Bitrix. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/check_stock_quantity.php';

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_ROOT_MISSING'] ?? '')
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
    || !Loader::includeModule('iblock')
    || !Loader::includeModule('catalog')
) {
    fwrite(
        STDERR,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_MODULES_REQUIRED')
        . PHP_EOL
    );
    exit(1);
}

/**
 * Останавливает диагностику с локализованным сообщением при ложном условии.
 */
function assertStockQuantityCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Преобразует ошибки Result в одну строку без трассировки и SQL.
 */
function formatStockQuantityErrors(Bitrix\Main\Result $result): string
{
    /** @var string[] $messages Непустые сообщения штатного результата операции. */
    $messages = array_values(
        array_filter(
            array_map('strval', $result->getErrorMessages()),
            static function (string $message): bool {
                return trim($message) !== '';
            }
        )
    );

    return $messages === []
        ? (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_UNKNOWN_ERROR')
        : implode('; ', $messages);
}

/** @var StockQuantityService|null $service Применитель, используемый также обязательным восстановлением. */
$service = null;
/** @var StockItem|null $stockItem Единственная диагностическая запчасть. */
$stockItem = null;
/** @var int|null $originalStoreQuantity Исходный целочисленный физический остаток. */
$originalStoreQuantity = null;
/** @var float|null $originalProductQuantity Исходное доступное количество товара. */
$originalProductQuantity = null;
/** @var int|null $storeId Настроенный склад проверяемой запчасти. */
$storeId = null;
/** @var bool $restoreRequired Началась ли попытка, после которой нужно безусловное восстановление. */
$restoreRequired = false;
/** @var int $exitCode Итоговый код после основной проверки и восстановления. */
$exitCode = 0;
/** @var string $errorMessage Накопленное безопасное сообщение ошибки. */
$errorMessage = '';

try {
    assertStockQuantityCondition(
        !MigrationManager::hasPendingMigrations(),
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_MIGRATION_REQUIRED')
    );
    assertStockQuantityCondition(
        class_exists(StoreDocumentTable::class)
        && class_exists(StoreDocumentElementTable::class)
        && class_exists('CCatalogDocs')
        && method_exists('CCatalogDocs', 'add')
        && method_exists('CCatalogDocs', 'conductDocument'),
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_DOCUMENT_API_REQUIRED')
    );

    /** @var SparePartStockRepository $repository Источник первой фактической запчасти модуля. */
    $repository = new SparePartStockRepository();
    /** @var array<string, mixed> $batch Первая порция каталога с товарами и курсором. */
    $batch = $repository->fetchBatch(0, 100);
    assertStockQuantityCondition(
        $batch['items'] !== [],
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_PART_REQUIRED')
    );

    /** @var array{product_id: int, external_id: string, article: string} $itemData Первая запчасть для теста. */
    $itemData = $batch['items'][0];
    $stockItem = new StockItem(
        $itemData['product_id'],
        $itemData['external_id'],
        $itemData['article']
    );
    $storeId = ModuleConfiguration::getSparePartsStoreId();
    assertStockQuantityCondition(
        $storeId !== null,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_STORE_REQUIRED')
    );

    /** @var array<string, mixed>|false $storeProduct Исходная строка диагностической запчасти на складе. */
    $storeProduct = StoreProductTable::getList(
        [
            'select' => ['AMOUNT', 'QUANTITY_RESERVED'],
            'filter' => [
                '=STORE_ID' => $storeId,
                '=PRODUCT_ID' => $stockItem->getProductId(),
            ],
            'limit' => 1,
        ]
    )->fetch();
    /** @var array<string, mixed>|false $product Исходное доступное количество диагностического товара. */
    $product = ProductTable::getByPrimary(
        $stockItem->getProductId(),
        ['select' => ['QUANTITY']]
    )->fetch();
    assertStockQuantityCondition(
        $storeProduct !== false
        && $product !== false
        && (new SparePartsCatalogManager())->isProductQuantityConsistent(
            $stockItem->getProductId()
        ),
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_INITIAL_INVALID')
    );

    /** @var float $originalStoreAmount Точное исходное значение до проверки целочисленности. */
    $originalStoreAmount = (float)$storeProduct['AMOUNT'];
    $originalStoreQuantity = (int)round($originalStoreAmount);
    $originalProductQuantity = (float)$product['QUANTITY'];

    fwrite(
        STDOUT,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_READ_OK',
            [
                '#MODE#' => State::isUsedInventoryManagement() ? 'inventory_document' : 'direct_api',
                '#PRODUCT_ID#' => (string)$stockItem->getProductId(),
                '#STORE_ID#' => (string)$storeId,
                '#QUANTITY#' => (string)$originalStoreAmount,
            ]
        ) . PHP_EOL
    );

    if ($writeTest) {
        assertStockQuantityCondition(
            !State::isUsedInventoryManagement(),
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_WRITE_INVENTORY_DENIED')
        );
        assertStockQuantityCondition(
            abs($originalStoreAmount - (float)$originalStoreQuantity) <= 0.00001,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_INTEGER_REQUIRED')
        );

        $service = new StockQuantityService();
        /** @var int $testQuantity Отличающееся абсолютное количество для контрольной записи. */
        $testQuantity = $originalStoreQuantity + 1;
        $restoreRequired = true;
        /** @var Bitrix\Main\Result $applyResult Результат реального штатного изменения. */
        $applyResult = $service->apply($stockItem, $testQuantity);
        assertStockQuantityCondition(
            $applyResult->isSuccess(),
            formatStockQuantityErrors($applyResult)
        );
        /** @var array<string, mixed> $applyData Контрольные данные успешного Result. */
        $applyData = $applyResult->getData();
        assertStockQuantityCondition(
            (int)($applyData['store_id'] ?? 0) === $storeId
            && (string)($applyData['mode'] ?? '') === StockQuantityService::MODE_DIRECT_API
            && abs((float)($applyData['applied_store_quantity'] ?? -1) - $testQuantity) <= 0.00001
            && ($applyData['document_id'] ?? null) === null,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_RESULT_INVALID')
        );

        /** @var bool $expectedCallbackFailureCaught Подтверждает распространение ошибки аудита. */
        $expectedCallbackFailureCaught = false;
        try {
            $service->apply(
                $stockItem,
                $originalStoreQuantity + 2,
                static function (Bitrix\Main\Result $result): void {
                    throw new RuntimeException('Expected transactional callback failure.');
                }
            );
        } catch (RuntimeException $exception) {
            $expectedCallbackFailureCaught = $exception->getMessage()
                === 'Expected transactional callback failure.';
        }
        /** @var array<string, mixed>|false $rolledBackStore Склад после ошибки callback. */
        $rolledBackStore = StoreProductTable::getList(
            [
                'select' => ['AMOUNT'],
                'filter' => [
                    '=STORE_ID' => $storeId,
                    '=PRODUCT_ID' => $stockItem->getProductId(),
                ],
                'limit' => 1,
            ]
        )->fetch();
        assertStockQuantityCondition(
            $expectedCallbackFailureCaught
            && $rolledBackStore !== false
            && abs((float)$rolledBackStore['AMOUNT'] - $testQuantity) <= 0.00001,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_CALLBACK_ROLLBACK_INVALID'
            )
        );
    }
} catch (Throwable $exception) {
    $exitCode = 1;
    $errorMessage = $exception->getMessage();
} finally {
    if (
        $restoreRequired
        && $service instanceof StockQuantityService
        && $stockItem instanceof StockItem
        && $originalStoreQuantity !== null
    ) {
        try {
            /** @var Bitrix\Main\Result $restoreResult Результат возврата точного исходного остатка. */
            $restoreResult = $service->apply($stockItem, $originalStoreQuantity);
            if (!$restoreResult->isSuccess()) {
                throw new RuntimeException(formatStockQuantityErrors($restoreResult));
            }

            /** @var array<string, mixed>|false $restoredStore Контрольная складская строка после возврата. */
            $restoredStore = StoreProductTable::getList(
                [
                    'select' => ['AMOUNT'],
                    'filter' => [
                        '=STORE_ID' => $storeId,
                        '=PRODUCT_ID' => $stockItem->getProductId(),
                    ],
                    'limit' => 1,
                ]
            )->fetch();
            /** @var array<string, mixed>|false $restoredProduct Контрольное количество товара после возврата. */
            $restoredProduct = ProductTable::getByPrimary(
                $stockItem->getProductId(),
                ['select' => ['QUANTITY']]
            )->fetch();
            assertStockQuantityCondition(
                $restoredStore !== false
                && $restoredProduct !== false
                && abs((float)$restoredStore['AMOUNT'] - $originalStoreQuantity) <= 0.00001
                && $originalProductQuantity !== null
                && abs((float)$restoredProduct['QUANTITY'] - $originalProductQuantity) <= 0.00001
                && (new SparePartsCatalogManager())->isProductQuantityConsistent(
                    $stockItem->getProductId()
                ),
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_RESTORE_INVALID')
            );
        } catch (Throwable $restoreException) {
            $exitCode = 1;
            $errorMessage = $errorMessage === ''
                ? $restoreException->getMessage()
                : $errorMessage . '; ' . $restoreException->getMessage();
        }
    }
}

if ($exitCode !== 0) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_FAILED',
            ['#ERROR#' => $errorMessage]
        ) . PHP_EOL
    );
    exit($exitCode);
}

if ($writeTest) {
    fwrite(
        STDOUT,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_WRITE_OK') . PHP_EOL
    );
}

exit(0);
