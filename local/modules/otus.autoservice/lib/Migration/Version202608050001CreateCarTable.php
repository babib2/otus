<?php

/**
 * Создаёт и удаляет таблицу автомобилей вместе с индексами первого релиза модели.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Otus\Autoservice\Model\CarTable;
use Throwable;

/**
 * Первая миграция прикладной схемы модуля — таблица автомобилей клиентов.
 */
final class Version202608050001CreateCarTable implements MigrationInterface
{
    /**
     * Версия миграции в хронологическом формате YYYYMMDDNNNN.
     */
    private const VERSION = '202608050001';

    /**
     * Индексы, ускоряющие поиск по контакту, номеру и списку активных автомобилей.
     *
     * @var array<string, string[]>
     */
    private const INDEXES = [
        'ix_otus_autoservice_car_contact' => ['CONTACT_ID'],
        'ix_otus_autoservice_car_plate' => ['LICENSE_PLATE'],
        'ix_otus_autoservice_car_contact_active' => ['CONTACT_ID', 'ACTIVE'],
    ];

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Создаёт таблицу ORM и недостающие индексы.
     *
     * Операция идемпотентна: повторный запуск не пересоздаёт существующую таблицу
     * и не дублирует уже найденные индексы с тем же набором колонок.
     */
    public function up(): void
    {
        /** @var Connection $connection Активное соединение с базой данных Bitrix. */
        $connection = Application::getConnection();

        /** @var string $tableName Физическое имя таблицы автомобилей. */
        $tableName = CarTable::getTableName();

        /** @var bool $tableCreated Создана ли таблица именно текущим запуском миграции. */
        $tableCreated = false;

        try {
            if (!$connection->isTableExists($tableName)) {
                CarTable::getEntity()->createDbTable();
                $tableCreated = true;
            }

            /** @var string $indexName Уникальное имя создаваемого индекса. */
            /** @var string[] $columns Колонки создаваемого индекса в правильном порядке. */
            foreach (self::INDEXES as $indexName => $columns) {
                if (!$connection->isIndexExists($tableName, $columns)) {
                    $connection->createIndex($tableName, $indexName, $columns);
                }
            }
        } catch (Throwable $exception) {
            // Убираем только новую таблицу; существующие пользовательские данные не трогаем.
            if ($tableCreated && $connection->isTableExists($tableName)) {
                $connection->dropTable($tableName);
            }

            throw $exception;
        }
    }

    /**
     * Полностью удаляет таблицу автомобилей при отказе от сохранения данных.
     */
    public function down(): void
    {
        /** @var Connection $connection Активное соединение с базой данных Bitrix. */
        $connection = Application::getConnection();

        /** @var string $tableName Физическое имя удаляемой таблицы автомобилей. */
        $tableName = CarTable::getTableName();

        if ($connection->isTableExists($tableName)) {
            $connection->dropTable($tableName);
        }
    }
}
