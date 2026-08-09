<?php

/**
 * Повторно публикует компонент «Гараж» с окном истории ремонтов автомобиля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Crm\GarageComponentManager;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Шестая миграция модуля — доставка истории ремонтов в уже установленный компонент.
 */
final class Version202608090006PublishCarHistory implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608090006';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Заменяет опубликованную копию компонента актуальными исходниками модуля.
     */
    public function up(): void
    {
        if (!GarageComponentManager::install()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_MIGRATION_INSTALL_FAILED')
            );
        }
    }

    /**
     * Не удаляет общий компонент: его жизненным циклом владеет установочная миграция 0005.
     *
     * При полном откате следующая миграция самостоятельно снимет обработчик вкладки
     * и удалит опубликованные файлы. Отдельной схемы данных этот выпуск не создаёт.
     */
    public function down(): void
    {
    }
}
