<?php

/**
 * Управляет очередностью миграций и сохраняет текущую версию схемы модуля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Config\Option;
use LogicException;

/**
 * Последовательно применяет и откатывает зарегистрированные миграции.
 */
final class MigrationManager
{
    /**
     * Имя настройки Bitrix, в которой хранится версия последней успешной миграции.
     */
    public const OPTION_SCHEMA_VERSION = 'schema_version';

    /**
     * Полные имена классов миграций, составляющих историю схемы модуля.
     * Классы добавляются в список в порядке разработки.
     * Перед выполнением список дополнительно сортируется по версии.
     *
     * @var class-string<MigrationInterface>[]
     */
    private const MIGRATION_CLASSES = [];

    /**
     * Применяет все миграции новее сохранённой версии схемы.
     *
     * Версия записывается только после успешного завершения up(). Если миграция
     * выбросит исключение, установка прервётся и сохранённая версия не изменится.
     */
    public static function migrate(): void
    {
        /** @var string $currentVersion Последняя успешно применённая версия схемы. */
        $currentVersion = self::getCurrentVersion();

        /** @var MigrationInterface $migration Очередная миграция в прямом порядке. */
        foreach (self::createMigrations() as $migration) {
            if (version_compare($migration->getVersion(), $currentVersion, '<=')) {
                continue;
            }

            $migration->up();
            self::setCurrentVersion($migration->getVersion());
            $currentVersion = $migration->getVersion();
        }
    }

    /**
     * Откатывает все применённые миграции в обратном порядке.
     *
     * Метод предназначен для полного удаления данных. При обычном удалении модуля
     * с сохранением данных он не вызывается.
     */
    public static function rollbackAll(): void
    {
        /** @var string $currentVersion Версия схемы, достигнутая перед началом отката. */
        $currentVersion = self::getCurrentVersion();

        /** @var MigrationInterface[] $migrations Все миграции, отсортированные по возрастанию. */
        $migrations = self::createMigrations();

        /** @var int $index Индекс миграции; уменьшается для соблюдения обратного порядка. */
        for ($index = count($migrations) - 1; $index >= 0; --$index) {
            /** @var MigrationInterface $migration Миграция, откатываемая на текущем шаге. */
            $migration = $migrations[$index];
            if (version_compare($migration->getVersion(), $currentVersion, '>')) {
                continue;
            }

            $migration->down();
            /** @var string $previousVersion Версия схемы после успешного отката текущего шага. */
            $previousVersion = $index > 0
                ? $migrations[$index - 1]->getVersion()
                : '0';
            self::setCurrentVersion($previousVersion);
            $currentVersion = $previousVersion;
        }

        Option::delete(
            'otus.autoservice',
            ['name' => self::OPTION_SCHEMA_VERSION]
        );
    }

    /**
     * Возвращает текущую версию схемы данных модуля.
     *
     * @return string Сохранённая версия либо `0`, если миграции ещё не выполнялись.
     */
    public static function getCurrentVersion(): string
    {
        return Option::get(
            'otus.autoservice',
            self::OPTION_SCHEMA_VERSION,
            '0'
        );
    }

    /**
     * Создаёт и сортирует объекты зарегистрированных миграций.
     *
     * @return MigrationInterface[]
     */
    private static function createMigrations(): array
    {
        /** @var MigrationInterface[] $migrations Созданные и проверенные объекты миграций. */
        $migrations = [];

        /** @var class-string<MigrationInterface> $migrationClass Имя создаваемого класса миграции. */
        foreach (self::MIGRATION_CLASSES as $migrationClass) {
            /** @var object $migration Экземпляр, проверяемый на соответствие контракту. */
            $migration = new $migrationClass();
            if (!$migration instanceof MigrationInterface) {
                throw new LogicException(
                    sprintf(
                        'Migration %s must implement %s.',
                        $migrationClass,
                        MigrationInterface::class
                    )
                );
            }

            $migrations[] = $migration;
        }

        usort(
            $migrations,
            static function (MigrationInterface $left, MigrationInterface $right): int {
                return version_compare($left->getVersion(), $right->getVersion());
            }
        );

        return $migrations;
    }

    /**
     * Сохраняет версию последней успешно применённой миграции.
     *
     * @param string $version Версия, которая станет новой точкой продолжения миграций.
     */
    private static function setCurrentVersion(string $version): void
    {
        Option::set(
            'otus.autoservice',
            self::OPTION_SCHEMA_VERSION,
            $version
        );
    }
}
