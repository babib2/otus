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
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Integration\Stock\StockBatchFetcher;
use Otus\Autoservice\Integration\Stock\StockFetchResult;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Integration\Stock\StockProviderFactory;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\SyncItemTable;
use Otus\Autoservice\Model\SyncRunTable;
use Otus\Autoservice\Repository\SparePartStockRepository;
use RuntimeException;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Выполняет один последовательный запуск без изменения реальных остатков Bitrix.
 *
 * Внешние запросы выполняются вне транзакций. Короткая транзакция охватывает только
 * массовую запись результатов порции и обновление счётчиков запуска. Именованная
 * блокировка СУБД не хранит отдельную сущность и исключает параллельный cron.
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

    /** Фабрика настроенного либо явно внедрённого источника остатков. */
    private StockProviderFactory $providerFactory;

    /** Необязательный внедрённый репозиторий; null означает текущую конфигурацию каталога. */
    private ?SparePartStockRepository $repository;

    /**
     * Создаёт сервис с возможностью заменить поставщика и репозиторий в диагностике.
     */
    public function __construct(
        ?StockProviderFactory $providerFactory = null,
        ?SparePartStockRepository $repository = null
    ) {
        $this->providerFactory = $providerFactory ?? new StockProviderFactory();
        $this->repository = $repository;
    }

    /**
     * Получает остатки всех запчастей и сохраняет поштучные результаты запуска.
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

        /** @var \Otus\Autoservice\Integration\Stock\StockProviderInterface $provider Выбранный источник абсолютных остатков. */
        $provider = $this->providerFactory->create($providerCode);
        /** @var SparePartStockRepository $repository Пакетный источник запчастей CRM-каталога. */
        $repository = $this->repository ?? new SparePartStockRepository();
        /** @var StockBatchFetcher $fetcher Изолирует ожидаемые ошибки отдельных товаров. */
        $fetcher = new StockBatchFetcher($provider);
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
        /** @var int $successItems Число успешных ответов поставщика. */
        $successItems = 0;
        /** @var int $failedItems Число ошибок поставщика или идентификаторов. */
        $failedItems = 0;

        try {
            $this->recoverStaleRunsUnlocked(self::STALE_AFTER_SECONDS);
            $runId = $this->createRun($provider->getCode(), $initiator);

            /** @var int $afterProductId Курсор последнего просканированного товара каталога. */
            $afterProductId = 0;

            while (true) {
                /** @var array{items: array<int, array{product_id: int, external_id: string, article: string}>, last_product_id: int, scanned_count: int} $batch Порция каталога и новый курсор. */
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
                /** @var int $batchTotalItems Число запчастей текущей ещё не сохранённой порции. */
                $batchTotalItems = 0;
                /** @var int $batchSuccessItems Успешные результаты текущей ещё не сохранённой порции. */
                $batchSuccessItems = 0;
                /** @var int $batchFailedItems Ошибки текущей ещё не сохранённой порции. */
                $batchFailedItems = 0;

                /** @var array{product_id: int, external_id: string, article: string} $candidate Очередная запчасть из репозитория. */
                foreach ($batch['items'] as $candidate) {
                    $batchTotalItems++;
                    try {
                        $validItems[] = new StockItem(
                            $candidate['product_id'],
                            $candidate['external_id'],
                            $candidate['article']
                        );
                    } catch (InvalidArgumentException) {
                        $batchFailedItems++;
                        $invalidItemFields[] = $this->buildInvalidItemFields($runId, $candidate);
                    }
                }

                /** @var StockFetchResult[] $fetchResults Поштучные ответы и ожидаемые ошибки поставщика. */
                $fetchResults = $fetcher->fetch($validItems);
                /** @var array<int, array<string, mixed>> $itemFields Все строки текущей транзакции. */
                $itemFields = $invalidItemFields;

                /** @var StockFetchResult $fetchResult Очередной ответ поставщика. */
                foreach ($fetchResults as $fetchResult) {
                    $itemFields[] = $this->buildFetchResultFields($runId, $fetchResult);
                    if ($fetchResult->isSuccess()) {
                        $batchSuccessItems++;
                    } else {
                        $batchFailedItems++;
                    }
                }

                /** @var int $newTotalItems Итоговый счётчик после возможного коммита порции. */
                $newTotalItems = $totalItems + $batchTotalItems;
                /** @var int $newSuccessItems Итоговый успешный счётчик после возможного коммита. */
                $newSuccessItems = $successItems + $batchSuccessItems;
                /** @var int $newFailedItems Итоговый ошибочный счётчик после возможного коммита. */
                $newFailedItems = $failedItems + $batchFailedItems;

                $this->persistBatch(
                    $connection,
                    $runId,
                    $itemFields,
                    $newTotalItems,
                    $newSuccessItems,
                    $newFailedItems
                );
                // Локальные счётчики меняются только после успешного коммита строк и прогресса запуска.
                $totalItems = $newTotalItems;
                $successItems = $newSuccessItems;
                $failedItems = $newFailedItems;
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
     * Атомарно сохраняет поштучные результаты и согласованные счётчики порции.
     *
     * @param array<int, array<string, mixed>> $itemFields Строки SyncItemTable.
     */
    private function persistBatch(
        Connection $connection,
        int $runId,
        array $itemFields,
        int $totalItems,
        int $successItems,
        int $failedItems
    ): void {
        $connection->startTransaction();
        try {
            if ($itemFields !== []) {
                /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат одной массовой вставки порции. */
                $addResult = SyncItemTable::addMulti($itemFields);
                if (!$addResult->isSuccess()) {
                    throw new RuntimeException($this->formatOrmError($addResult->getErrorMessages()));
                }
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
            return [
                'RUN_ID' => $runId,
                'PRODUCT_ID' => $item->getProductId(),
                'EXTERNAL_ID' => $item->getExternalId(),
                'ARTICLE' => $item->getArticle(),
                'STATUS' => SyncItemTable::STATUS_SUCCESS,
                'EXTERNAL_QUANTITY' => $result->getQuantity(),
                'ERROR_TYPE' => null,
                'ERROR_MESSAGE' => null,
                'RETRYABLE' => 'N',
            ];
        }

        return [
            'RUN_ID' => $runId,
            'PRODUCT_ID' => $item->getProductId(),
            'EXTERNAL_ID' => $item->getExternalId(),
            'ARTICLE' => $item->getArticle(),
            'STATUS' => SyncItemTable::STATUS_FAILED,
            'EXTERNAL_QUANTITY' => null,
            'ERROR_TYPE' => $result->getErrorType(),
            'ERROR_MESSAGE' => $result->getErrorMessage(),
            'RETRYABLE' => $result->isRetryable() ? 'Y' : 'N',
        ];
    }

    /**
     * Формирует безопасную ошибочную строку для повреждённого элемента каталога.
     *
     * @param array{product_id: int, external_id: string, article: string} $candidate Сырые идентификаторы запчасти.
     */
    private function buildInvalidItemFields(int $runId, array $candidate): array
    {
        return [
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
        ];
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

    /** Объединяет сообщения ORM, оставляя запасной безопасный текст. */
    private function formatOrmError(array $messages): string
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
            return (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_SYNC_ORM_ERROR');
        }

        return implode('; ', $normalizedMessages);
    }
}
