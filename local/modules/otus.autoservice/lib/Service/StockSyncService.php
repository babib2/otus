<?php

/**
 * Координирует порционное получение и журналирование внешних остатков запчастей.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Integration\Stock\StockBatchFetcher;
use Otus\Autoservice\Integration\Stock\StockFetchResult;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Integration\Stock\StockProviderInterface;
use Otus\Autoservice\Integration\Stock\StockProviderFactory;
use Otus\Autoservice\Integration\Stock\StockQuantityUpdaterInterface;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\SyncItemTable;
use Otus\Autoservice\Model\SyncRunTable;
use Otus\Autoservice\Repository\SparePartStockRepository;
use RuntimeException;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Выполняет один последовательный запуск с применением полученных абсолютных остатков Bitrix.
 *
 * Внешние запросы выполняются вне транзакций. Успешное изменение одной запчасти и её
 * аудит фиксируются одной транзакцией; ошибки получения и применения сохраняются отдельно.
 * Именованная блокировка СУБД не хранит отдельную сущность и исключает параллельный cron.
 */
final class StockSyncService
{
    /** Имя глобальной блокировки одного активного процесса синхронизации. */
    public const LOCK_NAME = 'otus.autoservice.stock_sync';

    /** Максимальное ожидание уже занятой блокировки в секундах. */
    public const LOCK_TIMEOUT = 1;

    /** Порция по умолчанию ограничивает интервал между обновлениями heartbeat. */
    public const DEFAULT_BATCH_SIZE = 20;

    /** Максимально разрешённая порция одного запроса каталога. */
    public const MAX_BATCH_SIZE = 100;

    /** Запуск без обновления heartbeat дольше двух часов считается зависшим. */
    public const STALE_AFTER_SECONDS = 7200;

    /** Машинный тип повреждённых обязательных идентификаторов запчасти. */
    public const ERROR_INVALID_CATALOG_ITEM = 'invalid_catalog_item';

    /** Допуск определения фактического движения по количествам применителя. */
    private const APPLY_QUANTITY_EPSILON = 0.00001;

    /** Фабрика настроенного либо явно внедрённого источника остатков. */
    private StockProviderFactory $providerFactory;

    /** Необязательный внедрённый репозиторий; null означает текущую конфигурацию каталога. */
    private ?SparePartStockRepository $repository;

    /** Необязательный заменяемый применитель количества; null означает штатный каталог Bitrix. */
    private ?StockQuantityUpdaterInterface $quantityUpdater;

    /**
     * Создаёт сервис с возможностью заменить поставщика, репозиторий и применитель в диагностике.
     */
    public function __construct(
        ?StockProviderFactory $providerFactory = null,
        ?SparePartStockRepository $repository = null,
        ?StockQuantityUpdaterInterface $quantityUpdater = null
    ) {
        $this->providerFactory = $providerFactory ?? new StockProviderFactory();
        $this->repository = $repository;
        $this->quantityUpdater = $quantityUpdater;
    }

    /**
     * Получает, применяет и журналирует абсолютные остатки всех запчастей.
     *
     * @param string      $initiator Код `cli` или `admin` для аудита источника запуска.
     * @param int         $batchSize Число сканируемых товаров каталога в одной порции.
     * @param string|null $providerCode Явный код для теста либо null для настройки модуля.
     *
     * @return int ID созданной записи b_otus_autoservice_sync_run.
     */
    public function run(
        string $initiator = SyncRunTable::INITIATOR_CLI,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?string $providerCode = null
    ): int {
        $this->validateRunArguments($initiator, $batchSize);
        $this->ensureEnvironmentReady();

        /** @var StockProviderInterface $provider Выбранный источник абсолютных остатков. */
        $provider = $this->providerFactory->create($providerCode);
        /** @var SparePartStockRepository $repository Пакетный источник запчастей CRM-каталога. */
        $repository = $this->repository ?? new SparePartStockRepository();
        /** @var StockBatchFetcher $fetcher Изолирует ожидаемые ошибки отдельных товаров. */
        $fetcher = new StockBatchFetcher($provider);
        /**
         * @var StockQuantityUpdaterInterface $quantityUpdater
         * Применяет абсолютный остаток выбранным штатным способом.
         */
        $quantityUpdater = $this->quantityUpdater ?? new StockQuantityService();
        /** @var Connection $connection Соединение для именованной блокировки и транзакций. */
        $connection = Application::getConnection();

        if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_LOCK_TIMEOUT')
            );
        }

        /** @var int|null $runId ID запуска после успешной первой записи. */
        $runId = null;
        /** @var int $totalItems Число фактически найденных запчастей. */
        $totalItems = 0;
        /** @var int $successItems Число успешно полученных, применённых и проверенных остатков. */
        $successItems = 0;
        /** @var int $failedItems Число ошибок идентификаторов, поставщика или применения. */
        $failedItems = 0;

        try {
            $this->recoverStaleRunsUnlocked(self::STALE_AFTER_SECONDS);
            $runId = $this->createRun($provider->getCode(), $initiator);

            /** @var int $afterProductId Курсор последнего просканированного товара каталога. */
            $afterProductId = 0;

            while (true) {
                /** @var array<string, mixed> $batch Порция каталога и новый курсор. */
                $batch = $repository->fetchBatch($afterProductId, $batchSize);
                if ($batch['scanned_count'] === 0) {
                    break;
                }
                $afterProductId = $batch['last_product_id'];

                if ($batch['items'] === []) {
                    $this->updateRunProgress(
                        $runId,
                        $totalItems,
                        $successItems,
                        $failedItems
                    );
                    continue;
                }

                /** @var StockItem[] $validItems Проверенные товары, передаваемые поставщику. */
                $validItems = [];
                /** @var array<int, array<string, mixed>> $invalidItemFields Готовые строки повреждённых товаров. */
                $invalidItemFields = [];

                /** @var array<string, mixed> $candidate Очередная запчасть из репозитория. */
                foreach ($batch['items'] as $candidate) {
                    try {
                        $validItems[] = new StockItem(
                            $candidate['product_id'],
                            $candidate['external_id'],
                            $candidate['article']
                        );
                    } catch (InvalidArgumentException) {
                        $invalidItemFields[] = $this->buildInvalidItemFields($runId, $candidate);
                    }
                }

                /** @var StockFetchResult[] $fetchResults Поштучные ответы и ожидаемые ошибки поставщика. */
                $fetchResults = $fetcher->fetch($validItems);

                /** @var array<string, mixed> $invalidItemField Ошибка идентификаторов без изменения каталога. */
                foreach ($invalidItemFields as $invalidItemField) {
                    $this->persistFailure(
                        $connection,
                        $runId,
                        $invalidItemField,
                        $totalItems + 1,
                        $successItems,
                        $failedItems + 1
                    );
                    $totalItems++;
                    $failedItems++;
                }

                /** @var StockFetchResult $fetchResult Очередной ответ поставщика. */
                foreach ($fetchResults as $fetchResult) {
                    if (!$fetchResult->isSuccess()) {
                        $this->persistFailure(
                            $connection,
                            $runId,
                            $this->buildFetchResultFields($runId, $fetchResult),
                            $totalItems + 1,
                            $successItems,
                            $failedItems + 1
                        );
                        $totalItems++;
                        $failedItems++;
                        continue;
                    }

                    /** @var int $externalQuantity Полученный абсолютный остаток успешного ответа. */
                    $externalQuantity = (int)$fetchResult->getQuantity();
                    /** @var Result|null $committedApplyResult Результат, записанный callback до коммита. */
                    $committedApplyResult = null;
                    /** @var Result $applyResult Штатный результат применения количества к каталогу. */
                    $applyResult = $quantityUpdater->apply(
                        $fetchResult->getItem(),
                        $externalQuantity,
                        function (Result $successfulResult) use (
                            $runId,
                            $fetchResult,
                            $totalItems,
                            $successItems,
                            $failedItems,
                            &$committedApplyResult
                        ): void {
                            if (!$successfulResult->isSuccess() || $committedApplyResult !== null) {
                                throw new RuntimeException(
                                    'Stock updater invoked transactional callback incorrectly.'
                                );
                            }
                            /** @var array<string, mixed> $successFields Проверенная строка применения. */
                            $successFields = $this->buildAppliedResultFields(
                                $runId,
                                $fetchResult,
                                $successfulResult
                            );
                            $this->persistAppliedSuccess(
                                $runId,
                                $successFields,
                                $totalItems + 1,
                                $successItems + 1,
                                $failedItems
                            );
                            $committedApplyResult = $successfulResult;
                        }
                    );
                    if ($applyResult->isSuccess()) {
                        if ($committedApplyResult !== $applyResult) {
                            throw new RuntimeException(
                                'Successful stock updater did not invoke transactional callback.'
                            );
                        }
                        $totalItems++;
                        $successItems++;
                        continue;
                    }
                    if ($committedApplyResult !== null) {
                        throw new RuntimeException(
                            'Stock updater returned failure after committing successful audit.'
                        );
                    }

                    $this->persistFailure(
                        $connection,
                        $runId,
                        $this->buildAppliedResultFields(
                            $runId,
                            $fetchResult,
                            $applyResult
                        ),
                        $totalItems + 1,
                        $successItems,
                        $failedItems + 1
                    );
                    $totalItems++;
                    $failedItems++;
                }
            }

            /** @var string $finalStatus Итоговый статус по наличию поштучных ошибок. */
            $finalStatus = $failedItems === 0
                ? SyncRunTable::STATUS_COMPLETED
                : SyncRunTable::STATUS_COMPLETED_WITH_ERRORS;
            /** @var DateTime $finishedAt Единая дата итогового статуса и heartbeat. */
            $finishedAt = new DateTime();
            $this->updateRunOrThrow(
                $runId,
                [
                    'STATUS' => $finalStatus,
                    'TOTAL_ITEMS' => $totalItems,
                    'SUCCESS_ITEMS' => $successItems,
                    'FAILED_ITEMS' => $failedItems,
                    'HEARTBEAT_AT' => $finishedAt,
                    'FINISHED_AT' => $finishedAt,
                    'ERROR_MESSAGE' => null,
                ]
            );

            if ($finalStatus === SyncRunTable::STATUS_COMPLETED) {
                Option::set(
                    ModuleConfiguration::MODULE_ID,
                    ModuleConfiguration::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
                    (string)time()
                );
            }

            return $runId;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->markRunFailedSafely(
                    $runId,
                    $totalItems,
                    $successItems,
                    $failedItems
                );
            }

            throw $exception;
        } finally {
            $connection->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Помечает потерянные running-запуски ошибочными по устаревшему heartbeat.
     *
     * Метод вызывается только после получения глобальной блокировки новым запуском:
     * живой конкурент в этот момент существовать не может.
     *
     * @return int Количество восстановленных записей.
     */
    public function recoverStaleRuns(int $staleAfterSeconds = self::STALE_AFTER_SECONDS): int
    {
        if ($staleAfterSeconds < 60) {
            throw new InvalidArgumentException('Stale interval must be at least 60 seconds.');
        }

        /** @var Connection $connection Соединение для проверки таблицы и сериализации восстановления. */
        $connection = Application::getConnection();
        if (!$connection->isTableExists(SyncRunTable::getTableName())) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_TABLES_REQUIRED')
            );
        }
        if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_LOCK_TIMEOUT')
            );
        }

        try {
            return $this->recoverStaleRunsUnlocked($staleAfterSeconds);
        } finally {
            $connection->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Восстанавливает зависшие записи, когда вызывающий код уже удерживает блокировку.
     */
    private function recoverStaleRunsUnlocked(int $staleAfterSeconds): int
    {

        /** @var DateTime $cutoff Максимальная дата heartbeat, считаемая зависшей. */
        $cutoff = (new DateTime())->add(sprintf('-%d seconds', $staleAfterSeconds));
        /** @var int[] $staleRunIds ID незавершённых записей старше порога. */
        $staleRunIds = [];
        /** @var array<string, mixed> $row Очередная устаревшая запись. */
        foreach (
            SyncRunTable::getList(
                [
                    'select' => ['ID'],
                    'filter' => [
                        '=STATUS' => SyncRunTable::STATUS_RUNNING,
                        '<HEARTBEAT_AT' => $cutoff,
                    ],
                ]
            ) as $row
        ) {
            $staleRunIds[] = (int)$row['ID'];
        }

        if ($staleRunIds === []) {
            return 0;
        }

        /** @var DateTime $finishedAt Дата обнаружения зависших запусков. */
        $finishedAt = new DateTime();
        /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат массового изменения. */
        $result = SyncRunTable::updateMulti(
            $staleRunIds,
            [
                'STATUS' => SyncRunTable::STATUS_FAILED,
                'HEARTBEAT_AT' => $finishedAt,
                'FINISHED_AT' => $finishedAt,
                'ERROR_MESSAGE' => (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_STOCK_SYNC_STALE_ERROR'
                ),
            ]
        );
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatOrmError($result->getErrorMessages()));
        }

        return count($staleRunIds);
    }

    /** Проверяет аргументы публичного запуска до обращения к инфраструктуре. */
    private function validateRunArguments(string $initiator, int $batchSize): void
    {
        if (!in_array($initiator, SyncRunTable::getAllowedInitiators(), true)) {
            throw new InvalidArgumentException('Unknown stock synchronization initiator.');
        }
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                sprintf('Stock synchronization batch size must be between 1 and %d.', self::MAX_BATCH_SIZE)
            );
        }
    }

    /** Проверяет модули, миграцию, флаг включения и инфраструктуру каталога. */
    private function ensureEnvironmentReady(): void
    {
        if (
            !Loader::includeModule('iblock')
            || !Loader::includeModule('catalog')
        ) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_MODULES_REQUIRED')
            );
        }
        if (!ModuleConfiguration::isEnabled()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_MODULE_DISABLED')
            );
        }
        if (MigrationManager::hasPendingMigrations()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_MIGRATION_REQUIRED')
            );
        }

        /** @var Connection $connection Соединение для проверки физических таблиц. */
        $connection = Application::getConnection();
        if (
            !$connection->isTableExists(SyncRunTable::getTableName())
            || !$connection->isTableExists(SyncItemTable::getTableName())
        ) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_TABLES_REQUIRED')
            );
        }
        if (!(new SparePartsCatalogManager())->isReady()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_CATALOG_REQUIRED')
            );
        }
    }

    /** Создаёт running-запись запуска и возвращает её положительный ID. */
    private function createRun(string $providerCode, string $initiator): int
    {
        /** @var DateTime $startedAt Единая начальная дата и heartbeat. */
        $startedAt = new DateTime();
        /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат добавления запуска. */
        $result = SyncRunTable::add(
            [
                'PROVIDER_CODE' => $providerCode,
                'INITIATOR' => $initiator,
                'STATUS' => SyncRunTable::STATUS_RUNNING,
                'TOTAL_ITEMS' => 0,
                'SUCCESS_ITEMS' => 0,
                'FAILED_ITEMS' => 0,
                'STARTED_AT' => $startedAt,
                'HEARTBEAT_AT' => $startedAt,
            ]
        );
        if (!$result->isSuccess() || (int)$result->getId() <= 0) {
            throw new RuntimeException($this->formatOrmError($result->getErrorMessages()));
        }

        return (int)$result->getId();
    }

    /**
     * Атомарно сохраняет одну поштучную ошибку и согласованные счётчики запуска.
     *
     * @param array<string, mixed> $itemFields Поля единственной ошибочной строки SyncItemTable.
     */
    private function persistFailure(
        Connection $connection,
        int $runId,
        array $itemFields,
        int $totalItems,
        int $successItems,
        int $failedItems
    ): void {
        $connection->startTransaction();
        try {
            /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат записи поштучной ошибки. */
            $addResult = SyncItemTable::add($itemFields);
            if (!$addResult->isSuccess() || (int)$addResult->getId() <= 0) {
                throw new RuntimeException($this->formatOrmError($addResult->getErrorMessages()));
            }

            $this->updateRunOrThrow(
                $runId,
                [
                    'TOTAL_ITEMS' => $totalItems,
                    'SUCCESS_ITEMS' => $successItems,
                    'FAILED_ITEMS' => $failedItems,
                    'HEARTBEAT_AT' => new DateTime(),
                ]
            );
            $connection->commitTransaction();
        } catch (Throwable $exception) {
            $connection->rollbackTransaction();
            throw $exception;
        }
    }

    /**
     * Записывает одну успешную позицию и прогресс внутри транзакции, открытой применителем.
     *
     * @param array<string, mixed> $itemFields Поля единственной успешной строки журнала.
     */
    private function persistAppliedSuccess(
        int $runId,
        array $itemFields,
        int $totalItems,
        int $successItems,
        int $failedItems
    ): void {
        /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат записи аудита позиции. */
        $addResult = SyncItemTable::add($itemFields);
        if (!$addResult->isSuccess() || (int)$addResult->getId() <= 0) {
            throw new RuntimeException($this->formatOrmError($addResult->getErrorMessages()));
        }

        $this->updateRunOrThrow(
            $runId,
            [
                'TOTAL_ITEMS' => $totalItems,
                'SUCCESS_ITEMS' => $successItems,
                'FAILED_ITEMS' => $failedItems,
                'HEARTBEAT_AT' => new DateTime(),
            ]
        );
    }

    /** Обновляет heartbeat при порции без запчастей. */
    private function updateRunProgress(
        int $runId,
        int $totalItems,
        int $successItems,
        int $failedItems
    ): void {
        $this->updateRunOrThrow(
            $runId,
            [
                'TOTAL_ITEMS' => $totalItems,
                'SUCCESS_ITEMS' => $successItems,
                'FAILED_ITEMS' => $failedItems,
                'HEARTBEAT_AT' => new DateTime(),
            ]
        );
    }

    /** Формирует строку журнала для результата внешнего поставщика. */
    private function buildFetchResultFields(int $runId, StockFetchResult $result): array
    {
        /** @var StockItem $item Товар, которому соответствует результат. */
        $item = $result->getItem();
        if ($result->isSuccess()) {
            throw new RuntimeException('Successful fetch result must be applied before journaling.');
        }

        return array_merge(
            [
                'RUN_ID' => $runId,
                'PRODUCT_ID' => $item->getProductId(),
                'EXTERNAL_ID' => $item->getExternalId(),
                'ARTICLE' => $item->getArticle(),
                'STATUS' => SyncItemTable::STATUS_FAILED,
                'EXTERNAL_QUANTITY' => null,
                'ERROR_TYPE' => $result->getErrorType(),
                'ERROR_MESSAGE' => $result->getErrorMessage(),
                'RETRYABLE' => $result->isRetryable() ? 'Y' : 'N',
            ],
            $this->buildEmptyApplyFields()
        );
    }

    /**
     * Формирует строку журнала после попытки применить успешно полученное количество.
     */
    private function buildAppliedResultFields(
        int $runId,
        StockFetchResult $fetchResult,
        Result $applyResult
    ): array {
        /** @var StockItem $item Запчасть успешного ответа внешнего поставщика. */
        $item = $fetchResult->getItem();
        /** @var array<string, mixed> $applyData Исходные и итоговые количества штатного применителя. */
        $applyData = $applyResult->getData();
        /** @var array<string, mixed> $applyFields Нормализованные nullable-поля ORM-журнала. */
        $applyFields = $this->normalizeApplyFields($applyData, $applyResult->isSuccess());

        if ($applyResult->isSuccess()) {
            if (
                abs(
                    (float)$applyFields['APPLIED_STORE_QUANTITY']
                    - (float)$fetchResult->getQuantity()
                ) > 0.00001
            ) {
                throw new RuntimeException(
                    'Successful stock updater did not confirm requested absolute quantity.'
                );
            }

            return array_merge(
                [
                    'RUN_ID' => $runId,
                    'PRODUCT_ID' => $item->getProductId(),
                    'EXTERNAL_ID' => $item->getExternalId(),
                    'ARTICLE' => $item->getArticle(),
                    'STATUS' => SyncItemTable::STATUS_SUCCESS,
                    'EXTERNAL_QUANTITY' => $fetchResult->getQuantity(),
                    'ERROR_TYPE' => null,
                    'ERROR_MESSAGE' => null,
                    'RETRYABLE' => 'N',
                ],
                $applyFields
            );
        }

        /** @var \Bitrix\Main\Error|null $firstError Первая машинная ошибка применения. */
        $firstError = $applyResult->getError();
        /** @var string $errorType Ограниченный машинный код поштучного сбоя. */
        $errorType = $firstError === null ? '' : trim((string)$firstError->getCode());
        if ($errorType === '') {
            $errorType = StockQuantityService::ERROR_API_FAILED;
        }

        return array_merge(
            [
                'RUN_ID' => $runId,
                'PRODUCT_ID' => $item->getProductId(),
                'EXTERNAL_ID' => $item->getExternalId(),
                'ARTICLE' => $item->getArticle(),
                'STATUS' => SyncItemTable::STATUS_FAILED,
                // Полученный остаток сохраняется даже тогда, когда каталог его не применил.
                'EXTERNAL_QUANTITY' => $fetchResult->getQuantity(),
                'ERROR_TYPE' => mb_substr($errorType, 0, 64),
                'ERROR_MESSAGE' => $this->formatOrmError(
                    $applyResult->getErrorMessages(),
                    'OTUS_AUTOSERVICE_STOCK_SYNC_APPLY_ERROR'
                ),
                'RETRYABLE' => 'N',
            ],
            $applyFields
        );
    }

    /**
     * Преобразует данные Result применителя в точные поля SyncItemTable.
     *
     * Успешная реализация интерфейса обязана вернуть полный набор: нарушение контракта
     * является программной ошибкой и останавливает запуск вместо ложного успеха.
     */
    private function normalizeApplyFields(array $data, bool $required): array
    {
        /** @var array<string, mixed> $fields Пустой либо заполненный набор новых колонок. */
        $fields = $this->buildEmptyApplyFields();
        if ($data === []) {
            if ($required) {
                throw new RuntimeException('Successful stock updater returned no journal data.');
            }

            return $fields;
        }

        /** @var int $storeId Проверенный положительный ID склада. */
        $storeId = (int)($data['store_id'] ?? 0);
        /** @var string $mode Непустой ограниченный код способа обновления. */
        $mode = trim((string)($data['mode'] ?? ''));
        /** @var mixed $previousStoreQuantity Исходный физический остаток склада. */
        $previousStoreQuantity = $data['previous_store_quantity'] ?? null;
        /** @var mixed $appliedStoreQuantity Фактически применённый физический остаток. */
        $appliedStoreQuantity = $data['applied_store_quantity'] ?? null;
        /** @var mixed $previousProductQuantity Исходное доступное количество товара. */
        $previousProductQuantity = $data['previous_product_quantity'] ?? null;
        /** @var mixed $appliedProductQuantity Итоговое доступное количество товара. */
        $appliedProductQuantity = $data['applied_product_quantity'] ?? null;
        /** @var bool $documentIdPresent Передал ли применитель обязательное поле документа. */
        $documentIdPresent = array_key_exists('document_id', $data);
        /** @var mixed $documentIdValue ID проведённого документа в исходном контракте. */
        $documentIdValue = $data['document_id'] ?? null;
        /** @var bool $documentIdValueValid Документ представлен null либо положительным целым ID. */
        $documentIdValueValid = $documentIdPresent
            && (
                $documentIdValue === null
                || (is_int($documentIdValue) && $documentIdValue > 0)
            );
        /** @var int $documentId Нормализованный положительный ID либо 0. */
        $documentId = is_int($documentIdValue) && $documentIdValue > 0
            ? $documentIdValue
            : 0;

        if (
            $required
            && (
                $storeId <= 0
                || !in_array(
                    $mode,
                    [
                        StockQuantityService::MODE_DIRECT_API,
                        StockQuantityService::MODE_INVENTORY_DOCUMENT,
                    ],
                    true
                )
                || !is_numeric($previousStoreQuantity)
                || !is_numeric($appliedStoreQuantity)
                || !is_numeric($previousProductQuantity)
                || !is_numeric($appliedProductQuantity)
            )
        ) {
            throw new RuntimeException('Successful stock updater returned incomplete journal data.');
        }

        if ($required) {
            /** @var bool $hasStoreMovement Изменился ли физический остаток склада. */
            $hasStoreMovement = abs(
                (float)$appliedStoreQuantity - (float)$previousStoreQuantity
            ) > self::APPLY_QUANTITY_EPSILON;
            /** @var bool $documentContractValid Соответствует ли документ заявленному режиму. */
            $documentContractValid = $documentIdValueValid
                && (
                    (
                        $mode === StockQuantityService::MODE_DIRECT_API
                        && $documentId === 0
                    )
                    || (
                        $mode === StockQuantityService::MODE_INVENTORY_DOCUMENT
                        && ($hasStoreMovement ? $documentId > 0 : $documentId === 0)
                    )
                );
            if (!$documentContractValid) {
                throw new RuntimeException(
                    'Successful stock updater returned inconsistent document data.'
                );
            }
        }

        $fields['STORE_ID'] = $storeId > 0 ? $storeId : null;
        $fields['APPLY_MODE'] = $mode === '' ? null : mb_substr($mode, 0, 32);
        $fields['PREVIOUS_STORE_QUANTITY'] = is_numeric($previousStoreQuantity)
            ? (float)$previousStoreQuantity
            : null;
        $fields['APPLIED_STORE_QUANTITY'] = is_numeric($appliedStoreQuantity)
            ? (float)$appliedStoreQuantity
            : null;
        $fields['PREVIOUS_PRODUCT_QUANTITY'] = is_numeric($previousProductQuantity)
            ? (float)$previousProductQuantity
            : null;
        $fields['APPLIED_PRODUCT_QUANTITY'] = is_numeric($appliedProductQuantity)
            ? (float)$appliedProductQuantity
            : null;
        $fields['DOCUMENT_ID'] = $documentId > 0 ? $documentId : null;

        return $fields;
    }

    /** Возвращает полный nullable-набор колонок применения для единообразного ORM-аудита. */
    private function buildEmptyApplyFields(): array
    {
        return [
            'STORE_ID' => null,
            'APPLY_MODE' => null,
            'PREVIOUS_STORE_QUANTITY' => null,
            'APPLIED_STORE_QUANTITY' => null,
            'PREVIOUS_PRODUCT_QUANTITY' => null,
            'APPLIED_PRODUCT_QUANTITY' => null,
            'DOCUMENT_ID' => null,
        ];
    }

    /**
     * Формирует безопасную ошибочную строку для повреждённого элемента каталога.
     *
     * @param array{product_id: int, external_id: string, article: string} $candidate Сырые идентификаторы запчасти.
     */
    private function buildInvalidItemFields(int $runId, array $candidate): array
    {
        return array_merge(
            [
                'RUN_ID' => $runId,
                'PRODUCT_ID' => $candidate['product_id'],
                'EXTERNAL_ID' => $candidate['external_id'] === '' ? null : $candidate['external_id'],
                'ARTICLE' => $candidate['article'] === '' ? null : $candidate['article'],
                'STATUS' => SyncItemTable::STATUS_FAILED,
                'EXTERNAL_QUANTITY' => null,
                'ERROR_TYPE' => self::ERROR_INVALID_CATALOG_ITEM,
                'ERROR_MESSAGE' => (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_STOCK_SYNC_INVALID_ITEM'
                ),
                'RETRYABLE' => 'N',
            ],
            $this->buildEmptyApplyFields()
        );
    }

    /** Обновляет одну запись запуска и преобразует ошибки ORM в исключение. */
    private function updateRunOrThrow(int $runId, array $fields): void
    {
        /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат обновления запуска. */
        $result = SyncRunTable::update($runId, $fields);
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatOrmError($result->getErrorMessages()));
        }
    }

    /** Не маскируя исходное исключение, старается зафиксировать общий сбой запуска. */
    private function markRunFailedSafely(
        int $runId,
        int $totalItems,
        int $successItems,
        int $failedItems
    ): void {
        try {
            /** @var DateTime $finishedAt Дата общей ошибки запуска. */
            $finishedAt = new DateTime();
            SyncRunTable::update(
                $runId,
                [
                    'STATUS' => SyncRunTable::STATUS_FAILED,
                    'TOTAL_ITEMS' => $totalItems,
                    'SUCCESS_ITEMS' => $successItems,
                    'FAILED_ITEMS' => $failedItems,
                    'HEARTBEAT_AT' => $finishedAt,
                    'FINISHED_AT' => $finishedAt,
                    'ERROR_MESSAGE' => (string)Loc::getMessage(
                        'OTUS_AUTOSERVICE_STOCK_SYNC_GENERAL_ERROR'
                    ),
                ]
            );
        } catch (Throwable) {
            // Ошибка аварийного журнала не должна заменить исходную причину остановки.
        }
    }

    /** Объединяет сообщения Result, оставляя выбранный запасной безопасный текст. */
    private function formatOrmError(
        array $messages,
        string $fallbackMessageKey = 'OTUS_AUTOSERVICE_STOCK_SYNC_ORM_ERROR'
    ): string
    {
        /** @var string[] $normalizedMessages Непустые скалярные сообщения ORM. */
        $normalizedMessages = array_values(
            array_filter(
                array_map('strval', $messages),
                static function (string $message): bool {
                    return $message !== '';
                }
            )
        );

        if ($normalizedMessages === []) {
            return (string)Loc::getMessage($fallbackMessageKey);
        }

        return implode('; ', $normalizedMessages);
    }
}
