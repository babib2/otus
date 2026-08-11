<?php

/**
 * Создаёт и удаляет ORM-таблицы журналов синхронизации внешних остатков.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Otus\Autoservice\Model\SyncItemTable;
use Otus\Autoservice\Model\SyncRunTable;
use Throwable;

/**
 * Добавляет воспроизводимую схему запусков и поштучных результатов синхронизации.
 */
final class Version202608110009CreateStockSyncTables implements MigrationInterface
{
    /** Хронологическая версия миграции журналов синхронизации. */
    private const VERSION = '202608110009';

    /**
     * Индексы таблицы запусков для последнего запуска и контроля heartbeat.
     *
     * @var array<string, string[]>
     */
    private const RUN_INDEXES = [
        'ix_otus_auto_sync_run_started' => ['STARTED_AT'],
        'ix_otus_auto_sync_run_status_heartbeat' => ['STATUS', 'HEARTBEAT_AT'],
    ];

    /**
     * Индексы элементов для просмотра запуска и истории отдельного товара.
     *
     * @var array<string, string[]>
     */
    private const ITEM_INDEXES = [
        'ix_otus_auto_sync_item_run' => ['RUN_ID'],
        'ix_otus_auto_sync_item_product' => ['PRODUCT_ID'],
        'ix_otus_auto_sync_item_run_status' => ['RUN_ID', 'STATUS'],
    ];

    /** Возвращает уникальную версию изменения схемы. */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Идемпотентно создаёт обе таблицы и недостающие индексы.
     */
    public function up(): void
    {
        /** @var Connection $connection Активное соединение портала с СУБД. */
        $connection = Application::getConnection();
        /** @var string $runTableName Физическое имя журнала запусков. */
        $runTableName = SyncRunTable::getTableName();
        /** @var string $itemTableName Физическое имя журнала товаров. */
        $itemTableName = SyncItemTable::getTableName();
        /** @var bool $runTableCreated Создана ли таблица запусков текущей попыткой. */
        $runTableCreated = false;
        /** @var bool $itemTableCreated Создана ли таблица товаров текущей попыткой. */
        $itemTableCreated = false;

        try {
            if (!$connection->isTableExists($runTableName)) {
                SyncRunTable::getEntity()->createDbTable();
                $runTableCreated = true;
            }

            if (!$connection->isTableExists($itemTableName)) {
                SyncItemTable::getEntity()->createDbTable();
                $itemTableCreated = true;
            }

            $this->ensureIndexes($connection, $runTableName, self::RUN_INDEXES);
            $this->ensureIndexes($connection, $itemTableName, self::ITEM_INDEXES);
        } catch (Throwable $exception) {
            if ($itemTableCreated && $connection->isTableExists($itemTableName)) {
                $connection->dropTable($itemTableName);
            }
            if ($runTableCreated && $connection->isTableExists($runTableName)) {
                $connection->dropTable($runTableName);
            }

            throw $exception;
        }
    }

    /** Удаляет сначала дочерний журнал товаров, затем журнал запусков. */
    public function down(): void
    {
        /** @var Connection $connection Активное соединение портала с СУБД. */
        $connection = Application::getConnection();
        /** @var string $itemTableName Физическое имя журнала товаров. */
        $itemTableName = SyncItemTable::getTableName();
        /** @var string $runTableName Физическое имя журнала запусков. */
        $runTableName = SyncRunTable::getTableName();

        if ($connection->isTableExists($itemTableName)) {
            $connection->dropTable($itemTableName);
        }
        if ($connection->isTableExists($runTableName)) {
            $connection->dropTable($runTableName);
        }
    }

    /**
     * Создаёт недостающие индексы по точным наборам колонок.
     *
     * @param Connection                 $connection Соединение, выполняющее DDL.
     * @param string                     $tableName Физическое имя индексируемой таблицы.
     * @param array<string, string[]>    $indexes Карта имени индекса к колонкам.
     */
    private function ensureIndexes(Connection $connection, string $tableName, array $indexes): void
    {
        /** @var string $indexName Стабильное имя очередного индекса. */
        /** @var string[] $columns Упорядоченный набор индексируемых колонок. */
        foreach ($indexes as $indexName => $columns) {
            if (!$connection->isIndexExists($tableName, $columns)) {
                $connection->createIndex($tableName, $indexName, $columns);
            }
        }
    }
}
