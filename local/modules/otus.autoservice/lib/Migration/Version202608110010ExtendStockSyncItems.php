<?php

/**
 * Расширяет поштучный журнал данными фактического применения абсолютного остатка.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\ORM\Fields\ScalarField;
use Otus\Autoservice\Model\SyncItemTable;
use RuntimeException;
use Throwable;

/**
 * Добавляет склад, режим, количества до/после и ссылку на складской документ без потери журнала.
 */
final class Version202608110010ExtendStockSyncItems implements MigrationInterface
{
    /** Хронологическая версия расширения журнала применения остатков. */
    private const VERSION = '202608110010';

    /**
     * Новые nullable-колонки позволяют безопасно сохранить исторические строки миграции 009.
     *
     * @var string[]
     */
    private const COLUMN_NAMES = [
        'STORE_ID',
        'APPLY_MODE',
        'PREVIOUS_STORE_QUANTITY',
        'APPLIED_STORE_QUANTITY',
        'PREVIOUS_PRODUCT_QUANTITY',
        'APPLIED_PRODUCT_QUANTITY',
        'DOCUMENT_ID',
    ];

    /** Возвращает уникальную версию изменения схемы. */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Идемпотентно добавляет недостающие колонки с типами из ORM-карты SyncItemTable.
     */
    public function up(): void
    {
        /** @var Connection $connection Соединение, выполняющее DDL собственной таблицы модуля. */
        $connection = Application::getConnection();
        /** @var string $tableName Физическое имя расширяемого поштучного журнала. */
        $tableName = SyncItemTable::getTableName();
        if (!$connection->isTableExists($tableName)) {
            throw new RuntimeException('Stock synchronization item table does not exist.');
        }

        /** @var string[] $addedColumns Колонки, добавленные только текущей попыткой миграции. */
        $addedColumns = [];
        try {
            /** @var string $columnName Очередная колонка ORM-журнала. */
            foreach (self::COLUMN_NAMES as $columnName) {
                if ($this->hasColumn($connection, $tableName, $columnName)) {
                    continue;
                }

                /** @var \Bitrix\Main\ORM\Fields\Field $field Поле из единственного источника схемы ORM. */
                $field = SyncItemTable::getEntity()->getField($columnName);
                if (!$field instanceof ScalarField) {
                    throw new RuntimeException('Stock synchronization column must be scalar.');
                }

                /** @var \Bitrix\Main\DB\SqlHelper $helper Генератор типа и экранирования текущей СУБД. */
                $helper = $connection->getSqlHelper();
                $connection->queryExecute(
                    'ALTER TABLE ' . $helper->quote($tableName)
                    . ' ADD ' . $helper->quote($field->getColumnName())
                    . ' ' . $helper->getColumnTypeByField($field)
                    . ($field->isNullable() ? ' NULL' : ' NOT NULL')
                );
                $connection->clearCaches($tableName);
                $addedColumns[] = $columnName;
            }
        } catch (Throwable $exception) {
            /** @var string $columnName Колонка текущей попытки, удаляемая в обратном порядке. */
            foreach (array_reverse($addedColumns) as $columnName) {
                if ($this->hasColumn($connection, $tableName, $columnName)) {
                    $connection->dropColumn($tableName, $columnName);
                }
            }

            throw $exception;
        }
    }

    /** Удаляет только колонки этого этапа; миграция 009 затем удалит сам журнал. */
    public function down(): void
    {
        /** @var Connection $connection Соединение для обратного DDL. */
        $connection = Application::getConnection();
        /** @var string $tableName Физическое имя поштучного журнала. */
        $tableName = SyncItemTable::getTableName();
        if (!$connection->isTableExists($tableName)) {
            return;
        }

        /** @var string $columnName Удаляемая в обратном порядке колонка этапа. */
        foreach (array_reverse(self::COLUMN_NAMES) as $columnName) {
            if ($this->hasColumn($connection, $tableName, $columnName)) {
                $connection->dropColumn($tableName, $columnName);
            }
        }
    }

    /** Проверяет колонку без зависимости от регистра ключей драйвера СУБД. */
    private function hasColumn(Connection $connection, string $tableName, string $columnName): bool
    {
        /** @var array<string, \Bitrix\Main\ORM\Fields\ScalarField> $fields Физические поля таблицы. */
        $fields = array_change_key_case($connection->getTableFields($tableName), CASE_UPPER);

        return isset($fields[strtoupper($columnName)]);
    }
}
