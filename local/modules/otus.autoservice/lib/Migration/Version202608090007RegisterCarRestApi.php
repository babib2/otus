<?php

/**
 * Регистрирует защищённый REST API автомобилей в уже установленном модуле.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\EventHandler\EventRegistry;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Седьмая миграция модуля — публикация внешних CRUD-методов автомобилей.
 */
final class Version202608090007RegisterCarRestApi implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608090007';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Добавляет обработчик OnRestServiceBuildDescription без изменения CRM-данных.
     */
    public function up(): void
    {
        if (!Loader::includeModule('rest')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_MIGRATION_REST_REQUIRED')
            );
        }

        EventRegistry::installCarRestApi();
    }

    /**
     * Удаляет только обработчик REST-контракта этой миграции.
     */
    public function down(): void
    {
        EventRegistry::uninstallCarRestApi();
    }
}
