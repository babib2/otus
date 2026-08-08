<?php

/**
 * Устанавливает публичные ресурсы и обработчик подключения селектора автомобиля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Otus\Autoservice\EventHandler\EventRegistry;
use Otus\Autoservice\Integration\Crm\DealCarSelectorAssetManager;
use RuntimeException;

/**
 * Четвёртая миграция модуля — интерфейс выбора автомобиля по контакту сделки.
 */
final class Version202608070004InstallDealCarSelector implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608070004';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Копирует ресурсы и регистрирует подключающий их обработчик страницы.
     */
    public function up(): void
    {
        if (!DealCarSelectorAssetManager::install()) {
            DealCarSelectorAssetManager::uninstall();

            throw new RuntimeException('Не удалось установить ресурсы селектора автомобиля.');
        }

        try {
            EventRegistry::installDealCarSelectorAssets();
        } catch (\Throwable $exception) {
            DealCarSelectorAssetManager::uninstall();

            throw $exception;
        }
    }

    /**
     * Удаляет только обработчик и публичные ресурсы, добавленные этой миграцией.
     */
    public function down(): void
    {
        EventRegistry::uninstallDealCarSelectorAssets();
        DealCarSelectorAssetManager::uninstall();
    }
}
