<?php

/**
 * Проверяет инфраструктуру синхронизации остатков и, по явному флагу, её полный цикл записи.
 */

declare(strict_types=1);

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Integration\Stock\StockProviderException;
use Otus\Autoservice\Integration\Stock\StockProviderFactory;
use Otus\Autoservice\Integration\Stock\StockProviderInterface;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\SyncItemTable;
use Otus\Autoservice\Model\SyncRunTable;
use Otus\Autoservice\Repository\SparePartStockRepository;
use Otus\Autoservice\Service\ModuleConfiguration;
use Otus\Autoservice\Service\StockSyncService;

if (PHP_SAPI !== 'cli') {
    // Диагностика раскрывает технические ID и поэтому никогда не публикуется через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $writeTest Разрешено ли создавать и затем удалять диагностические строки журналов. */
$writeTest = in_array('--write-test', $argv, true);
/** @var string|null $documentRootArgument Первый позиционный аргумент с корнем портала. */
$documentRootArgument = null;

/** @var string $argument Очередной аргумент командной строки. */
foreach (array_slice($argv, 1) as $argument) {
    /** @var string $normalizedArgument Строковое представление аргумента. */
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

/** @var array<string, string> $MESS Предварительные сообщения для ошибок до загрузки ядра. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/check_stock_sync.php';

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_DOCUMENT_ROOT_MISSING'] ?? '')
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
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_MODULES_REQUIRED') . PHP_EOL
    );
    exit(1);
}

/**
 * Выбрасывает исключение, если проверяемое условие не выполнено.
 *
 * @param bool   $condition Фактический результат проверки.
 * @param string $message Безопасное описание нарушенного условия.
 */
function assertStockSyncCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Возвращает количества товаров каталога, чтобы доказать отсутствие складских изменений.
 *
 * @param int[] $productIds Проверяемые ID запчастей.
 *
 * @return array<int, float> Карта ID к абсолютному количеству ProductTable.
 */
function loadCatalogQuantities(array $productIds): array
{
    if ($productIds === []) {
        return [];
    }

    /** @var array<int, float> $quantities Стабильно отсортированные количества товаров. */
    $quantities = [];
    /** @var array<string, mixed> $row Очередная строка товара каталога. */
    foreach (
        ProductTable::getList(
            [
                'select' => ['ID', 'QUANTITY'],
                'filter' => ['@ID' => $productIds],
                'order' => ['ID' => 'ASC'],
            ]
        ) as $row
    ) {
        $quantities[(int)$row['ID']] = (float)$row['QUANTITY'];
    }

    return $quantities;
}

/**
 * Удаляет только строки запусков с точными ID или уникальными кодами текущей диагностики.
 *
 * @param int[] $runIds Точные ID временных запусков в порядке их создания.
 * @param string[] $providerCodes Случайные коды поставщиков конкретного диагностического процесса.
 */
function deleteDiagnosticRuns(array $runIds, array $providerCodes): void
{
    if ($providerCodes !== []) {
        /** @var array<string, mixed> $runRow Очередной запуск с уникальным диагностическим кодом. */
        foreach (
            SyncRunTable::getList(
                [
                    'select' => ['ID'],
                    'filter' => ['@PROVIDER_CODE' => $providerCodes],
                ]
            ) as $runRow
        ) {
            $runIds[] = (int)$runRow['ID'];
        }
    }

    /** @var int[] $runIds Уникальные положительные ID, включая не возвращённые из-за исключения сервиса. */
    $runIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $runIds),
                static function (int $runId): bool {
                    return $runId > 0;
                }
            )
        )
    );
    if ($runIds === []) {
        return;
    }

    /** @var array<string, mixed> $itemRow Очередной дочерний результат временного запуска. */
    foreach (
        SyncItemTable::getList(
            [
                'select' => ['ID'],
                'filter' => ['@RUN_ID' => $runIds],
            ]
        ) as $itemRow
    ) {
        /** @var \Bitrix\Main\ORM\Data\DeleteResult $deleteItemResult Результат удаления одной временной строки. */
        $deleteItemResult = SyncItemTable::delete((int)$itemRow['ID']);
        if (!$deleteItemResult->isSuccess()) {
            throw new RuntimeException(implode('; ', $deleteItemResult->getErrorMessages()));
        }
    }

    /** @var int $runId Точный ID временного запуска. */
    foreach (array_reverse($runIds) as $runId) {
        /** @var \Bitrix\Main\ORM\Data\DeleteResult $deleteRunResult Результат удаления временного запуска. */
        $deleteRunResult = SyncRunTable::delete($runId);
        if (!$deleteRunResult->isSuccess()) {
            throw new RuntimeException(implode('; ', $deleteRunResult->getErrorMessages()));
        }
    }
}

/**
 * Проверяет итоговую строку успешного запуска и все его поштучные результаты.
 *
 * @param int $runId ID диагностического запуска.
 * @param int $expectedItems Ожидаемое число запчастей.
 * @param int $expectedQuantity Ожидаемый остаток каждой запчасти.
 */
function assertSuccessfulRun(int $runId, int $expectedItems, int $expectedQuantity): void
{
    /** @var array<string, mixed>|false $run Итоговая строка проверяемого запуска. */
    $run = SyncRunTable::getByPrimary($runId)->fetch();
    assertStockSyncCondition(
        $run !== false
        && (string)$run['STATUS'] === SyncRunTable::STATUS_COMPLETED
        && (int)$run['TOTAL_ITEMS'] === $expectedItems
        && (int)$run['SUCCESS_ITEMS'] === $expectedItems
        && (int)$run['FAILED_ITEMS'] === 0,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_RUN_INVALID')
    );

    /** @var int $matchedItems Число корректных успешных результатов. */
    $matchedItems = SyncItemTable::getCount(
        [
            '=RUN_ID' => $runId,
            '=STATUS' => SyncItemTable::STATUS_SUCCESS,
            '=EXTERNAL_QUANTITY' => $expectedQuantity,
            '=RETRYABLE' => 'N',
        ]
    );
    assertStockSyncCondition(
        $matchedItems === $expectedItems
        && SyncItemTable::getCount(['=RUN_ID' => $runId]) === $expectedItems,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_ITEMS_INVALID')
    );
}

/**
 * Проверяет продолжение обработки после одной ожидаемой ошибки поставщика.
 *
 * @param int $runId ID диагностического запуска.
 * @param int $expectedItems Ожидаемое число запчастей.
 */
function assertPartialRun(int $runId, int $expectedItems): void
{
    /** @var array<string, mixed>|false $run Итоговая строка частично успешного запуска. */
    $run = SyncRunTable::getByPrimary($runId)->fetch();
    assertStockSyncCondition(
        $run !== false
        && (string)$run['STATUS'] === SyncRunTable::STATUS_COMPLETED_WITH_ERRORS
        && (int)$run['TOTAL_ITEMS'] === $expectedItems
        && (int)$run['SUCCESS_ITEMS'] === $expectedItems - 1
        && (int)$run['FAILED_ITEMS'] === 1,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_RUN_INVALID')
    );

    /** @var array<string, mixed>|false $failedItem Единственная ожидаемая ошибочная строка товара. */
    $failedItem = SyncItemTable::getList(
        [
            'select' => ['STATUS', 'EXTERNAL_QUANTITY', 'ERROR_TYPE', 'ERROR_MESSAGE', 'RETRYABLE'],
            'filter' => ['=RUN_ID' => $runId, '=STATUS' => SyncItemTable::STATUS_FAILED],
            'limit' => 2,
        ]
    )->fetch();
    assertStockSyncCondition(
        $failedItem !== false
        && $failedItem['EXTERNAL_QUANTITY'] === null
        && (string)$failedItem['ERROR_TYPE'] === StockProviderException::TRANSPORT_ERROR
        && trim((string)$failedItem['ERROR_MESSAGE']) !== ''
        && (string)$failedItem['RETRYABLE'] === 'Y'
        && SyncItemTable::getCount(
            ['=RUN_ID' => $runId, '=STATUS' => SyncItemTable::STATUS_FAILED]
        ) === 1
        && SyncItemTable::getCount(
            ['=RUN_ID' => $runId, '=STATUS' => SyncItemTable::STATUS_SUCCESS]
        ) === $expectedItems - 1
        && SyncItemTable::getCount(['=RUN_ID' => $runId]) === $expectedItems,
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_ITEM_INVALID')
    );
}

/** @var int[] $createdRunIds Точные ID журналов, создаваемых режимом записи. */
$createdRunIds = [];
/** @var string[] $diagnosticProviderCodes Уникальные коды для поиска запусков даже после исключения. */
$diagnosticProviderCodes = [];
/** @var string|null $originalLastSuccess Исходное глобальное значение настройки в режиме записи. */
$originalLastSuccess = null;
/** @var bool $lastSuccessSnapshotTaken Было ли исходное глобальное значение фактически прочитано. */
$lastSuccessSnapshotTaken = false;
/** @var bool $lastSuccessRestored Восстановлена ли настройка либо восстановление не требовалось. */
$lastSuccessRestored = true;
/** @var int $exitCode Итоговый код процесса после обязательной очистки. */
$exitCode = 0;
/** @var string $errorMessage Безопасное сообщение проваленной проверки. */
$errorMessage = '';

try {
    assertStockSyncCondition(
        !MigrationManager::hasPendingMigrations(),
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_MIGRATION_REQUIRED')
    );

    /** @var \Bitrix\Main\DB\Connection $connection Соединение для проверки физических таблиц. */
    $connection = Application::getConnection();
    assertStockSyncCondition(
        $connection->isTableExists(SyncRunTable::getTableName())
        && $connection->isTableExists(SyncItemTable::getTableName()),
        (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_TABLES_REQUIRED')
    );

    /** @var SparePartStockRepository $repository Порционный читатель модульных запчастей. */
    $repository = new SparePartStockRepository();
    /** @var int $cursor Последний просканированный ID товара каталога. */
    $cursor = 0;
    /** @var int[] $productIds Уникальные ID обнаруженных запчастей. */
    $productIds = [];

    do {
        /** @var array{items: array<int, array{product_id: int, external_id: string, article: string}>, last_product_id: int, scanned_count: int} $batch Очередная порция каталога. */
        $batch = $repository->fetchBatch($cursor, StockSyncService::DEFAULT_BATCH_SIZE);
        $cursor = $batch['last_product_id'];
        /** @var array{product_id: int, external_id: string, article: string} $item Очередная запчасть порции. */
        foreach ($batch['items'] as $item) {
            assertStockSyncCondition(
                $item['product_id'] > 0
                && $item['external_id'] !== ''
                && $item['article'] !== ''
                && !in_array($item['product_id'], $productIds, true),
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_REPOSITORY_INVALID')
            );
            $productIds[] = $item['product_id'];
        }
    } while ($batch['scanned_count'] > 0);

    fwrite(
        STDOUT,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_READ_OK',
            ['#COUNT#' => (string)count($productIds)]
        ) . PHP_EOL
    );

    if ($writeTest) {
        assertStockSyncCondition(
            $productIds !== [],
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTS_REQUIRED')
        );
        assertStockSyncCondition(
            SyncRunTable::getCount(['=STATUS' => SyncRunTable::STATUS_RUNNING]) === 0,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_RUNNING_EXISTS')
        );

        /** @var string $diagnosticToken Случайный маркер только текущего процесса диагностики. */
        $diagnosticToken = bin2hex(random_bytes(8));
        $diagnosticProviderCodes = [
            'diagnostic_success_' . $diagnosticToken,
            'diagnostic_failure_' . $diagnosticToken,
            'diagnostic_stale_' . $diagnosticToken,
            'diagnostic_crash_' . $diagnosticToken,
        ];
        $originalLastSuccess = Option::getRealValue(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
            ''
        );
        $lastSuccessSnapshotTaken = true;
        $lastSuccessRestored = false;

        /** @var array<int, float> $quantitiesBefore Количества каталога до запусков диагностики. */
        $quantitiesBefore = loadCatalogQuantities($productIds);

        /** @var string $successProviderCode Уникальный код успешного поставщика текущего процесса. */
        $successProviderCode = $diagnosticProviderCodes[0];
        /** @var StockProviderInterface $successProvider Полностью успешный локальный поставщик. */
        $successProvider = new class($successProviderCode) implements StockProviderInterface {
            /** Уникальный код для гарантированной аварийной очистки созданного запуска. */
            private string $code;

            /** Сохраняет случайный код текущего процесса диагностики. */
            public function __construct(string $code)
            {
                $this->code = $code;
            }

            /** Возвращает уникальный код поставщика для строки запуска. */
            public function getCode(): string
            {
                return $this->code;
            }

            /** Возвращает предсказуемый абсолютный остаток без обращения к сети. */
            public function getCurrentQuantity(StockItem $item): int
            {
                return 4;
            }
        };
        /** @var StockSyncService $successService Сервис с полностью успешным локальным поставщиком. */
        $successService = new StockSyncService(
            new StockProviderFactory([$successProvider]),
            $repository
        );
        /** @var int $successRunId ID полностью успешного диагностического запуска. */
        $successRunId = $successService->run(
            SyncRunTable::INITIATOR_CLI,
            2,
            $successProviderCode
        );
        $createdRunIds[] = $successRunId;
        assertSuccessfulRun($successRunId, count($productIds), 4);
        /** @var string $successfulTimestamp Дата, которую обязан записать полностью успешный запуск. */
        $successfulTimestamp = Option::get(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
            ''
        );
        assertStockSyncCondition(
            preg_match('/^[1-9][0-9]*$/D', $successfulTimestamp) === 1,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_DATE_INVALID')
        );

        /** @var StockProviderInterface $partialProvider Поставщик с одной ожидаемой временной ошибкой. */
        $partialProvider = new class($diagnosticProviderCodes[1]) implements StockProviderInterface {
            /** Число уже обработанных товаров для единственной ошибки на первом вызове. */
            private int $calls = 0;

            /** Уникальный код для гарантированной аварийной очистки созданного запуска. */
            private string $code;

            /** Сохраняет случайный код текущего процесса диагностики. */
            public function __construct(string $code)
            {
                $this->code = $code;
            }

            /** Возвращает отдельный уникальный код только для диагностического журнала. */
            public function getCode(): string
            {
                return $this->code;
            }

            /** На первом товаре имитирует транспортный сбой, остальные обрабатывает успешно. */
            public function getCurrentQuantity(StockItem $item): int
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new StockProviderException(
                        (string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_EXPECTED_PROVIDER_ERROR'
                        ),
                        StockProviderException::TRANSPORT_ERROR,
                        true
                    );
                }

                return 6;
            }
        };
        /** @var StockSyncService $partialService Сервис для проверки продолжения после ошибки. */
        $partialService = new StockSyncService(
            new StockProviderFactory([$partialProvider]),
            $repository
        );
        /** @var int $partialRunId ID частично успешного диагностического запуска. */
        $partialRunId = $partialService->run(
            SyncRunTable::INITIATOR_CLI,
            2,
            $partialProvider->getCode()
        );
        $createdRunIds[] = $partialRunId;
        assertPartialRun($partialRunId, count($productIds));
        assertStockSyncCondition(
            Option::get(
                ModuleConfiguration::MODULE_ID,
                ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
                ''
            ) === $successfulTimestamp,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_DATE_CHANGED')
        );

        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
            (string)(time() + 86400),
            ''
        );
        assertStockSyncCondition(
            ModuleConfiguration::getStockSyncLastSuccessAt() === null,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_FUTURE_DATE_ACCEPTED')
        );
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
            $successfulTimestamp,
            ''
        );

        /** @var string $crashProviderCode Уникальный код аварийного поставщика текущего процесса. */
        $crashProviderCode = $diagnosticProviderCodes[3];
        /** @var StockProviderInterface $crashProvider Поставщик с неожиданной программной ошибкой. */
        $crashProvider = new class($crashProviderCode) implements StockProviderInterface {
            /** Уникальный код для поиска запуска, ID которого сервис не успеет вернуть. */
            private string $code;

            /** Сохраняет случайный код аварийного диагностического поставщика. */
            public function __construct(string $code)
            {
                $this->code = $code;
            }

            /** Возвращает уникальный код поставщика для строки failed-запуска. */
            public function getCode(): string
            {
                return $this->code;
            }

            /** Имитирует неожиданное исключение, которое не должен скрывать пакетный обработчик. */
            public function getCurrentQuantity(StockItem $item): int
            {
                throw new RuntimeException('Expected diagnostic crash.');
            }
        };
        /** @var bool $expectedCrashCaught Подтверждает распространение неожиданного исключения. */
        $expectedCrashCaught = false;
        try {
            (new StockSyncService(
                new StockProviderFactory([$crashProvider]),
                $repository
            ))->run(SyncRunTable::INITIATOR_CLI, 2, $crashProviderCode);
        } catch (RuntimeException $exception) {
            $expectedCrashCaught = $exception->getMessage() === 'Expected diagnostic crash.';
        }
        assertStockSyncCondition(
            $expectedCrashCaught
            && SyncRunTable::getCount(
                [
                    '=PROVIDER_CODE' => $crashProviderCode,
                    '=STATUS' => SyncRunTable::STATUS_FAILED,
                ]
            ) === 1,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_CRASH_RUN_INVALID')
        );

        /** @var DateTime $staleDate Заведомо устаревшее время тестового heartbeat. */
        $staleDate = (new DateTime())->add('-120 seconds');
        /** @var \Bitrix\Main\ORM\Data\AddResult $staleAddResult Результат создания зависшего запуска. */
        $staleAddResult = SyncRunTable::add(
            [
                'PROVIDER_CODE' => $diagnosticProviderCodes[2],
                'INITIATOR' => SyncRunTable::INITIATOR_CLI,
                'STATUS' => SyncRunTable::STATUS_RUNNING,
                'STARTED_AT' => $staleDate,
                'HEARTBEAT_AT' => $staleDate,
            ]
        );
        assertStockSyncCondition(
            $staleAddResult->isSuccess() && (int)$staleAddResult->getId() > 0,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_STALE_CREATE_FAILED')
        );
        /** @var int $staleRunId ID искусственно зависшего запуска. */
        $staleRunId = (int)$staleAddResult->getId();
        $createdRunIds[] = $staleRunId;

        /** @var int $recoveredRuns Число записей, восстановленных сервисом. */
        $recoveredRuns = $successService->recoverStaleRuns(60);
        /** @var array<string, mixed>|false $recoveredRun Итоговая строка зависшего запуска. */
        $recoveredRun = SyncRunTable::getByPrimary($staleRunId)->fetch();
        assertStockSyncCondition(
            $recoveredRuns === 1
            && $recoveredRun !== false
            && (string)$recoveredRun['STATUS'] === SyncRunTable::STATUS_FAILED
            && $recoveredRun['FINISHED_AT'] instanceof DateTime
            && trim((string)$recoveredRun['ERROR_MESSAGE']) !== '',
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_STALE_INVALID')
        );

        /** @var array<int, float> $quantitiesAfter Количества после всех сервисных запусков. */
        $quantitiesAfter = loadCatalogQuantities($productIds);
        assertStockSyncCondition(
            $quantitiesAfter === $quantitiesBefore,
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_CATALOG_CHANGED')
        );

        fwrite(
            STDOUT,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_WRITE_OK',
                ['#COUNT#' => (string)count($productIds)]
            ) . PHP_EOL
        );
    }
} catch (Throwable $exception) {
    $exitCode = 1;
    $errorMessage = $exception->getMessage();
} finally {
    if ($writeTest) {
        try {
            deleteDiagnosticRuns($createdRunIds, $diagnosticProviderCodes);
        } catch (Throwable $cleanupException) {
            $exitCode = 1;
            $errorMessage = $errorMessage === ''
                ? $cleanupException->getMessage()
                : $errorMessage . '; ' . $cleanupException->getMessage();
        }
    }

    if ($lastSuccessSnapshotTaken) {
        try {
            if ($originalLastSuccess === null) {
                Option::delete(
                    ModuleConfiguration::MODULE_ID,
                    [
                        'name' => ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
                        'site_id' => '',
                    ]
                );
            } else {
                Option::set(
                    ModuleConfiguration::MODULE_ID,
                    ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
                    $originalLastSuccess,
                    ''
                );
            }
            $lastSuccessRestored = true;
        } catch (Throwable $optionRestoreException) {
            $exitCode = 1;
            $errorMessage = $errorMessage === ''
                ? $optionRestoreException->getMessage()
                : $errorMessage . '; ' . $optionRestoreException->getMessage();
        }
    }
}

if ($exitCode !== 0) {
    if (!$lastSuccessRestored) {
        $errorMessage .= '; ' . (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_OPTION_RESTORE_FAILED'
        );
    }
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_ERROR',
            ['#ERROR#' => $errorMessage]
        ) . PHP_EOL
    );
}

exit($exitCode);
