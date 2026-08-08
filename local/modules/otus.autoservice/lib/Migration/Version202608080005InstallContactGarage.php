<?php

/**
 * Публикует компонент и регистрирует вкладку «Гараж» для установленного модуля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\EventHandler\EventRegistry;
use Otus\Autoservice\Integration\Crm\GarageComponentManager;
use RuntimeException;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Пятая миграция модуля — управляемый список автомобилей в карточке CRM-контакта.
 */
final class Version202608080005InstallContactGarage implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608080005';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Копирует компонент и регистрирует обработчик вкладки для существующей установки.
     */
    public function up(): void
    {
        if (!GarageComponentManager::install()) {
            GarageComponentManager::uninstall();

            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MIGRATION_INSTALL_FAILED')
            );
        }

        try {
            EventRegistry::installContactGarageTab();
        } catch (Throwable $exception) {
            GarageComponentManager::uninstall();

            throw $exception;
        }
    }

    /**
     * Удаляет обработчик и опубликованные файлы компонента этой миграции.
     */
    public function down(): void
    {
        EventRegistry::uninstallContactGarageTab();
        GarageComponentManager::uninstall();
    }
}
