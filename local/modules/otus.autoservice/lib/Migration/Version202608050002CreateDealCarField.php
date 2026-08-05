<?php

/**
 * Создаёт поле автомобиля в CRM-сделке и регистрирует серверные обработчики контроля заказов.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Otus\Autoservice\EventHandler\EventRegistry;
use Otus\Autoservice\Integration\Crm\DealCarFieldManager;
use Throwable;

/**
 * Вторая миграция модуля — связь автомобиля со сделкой и события её проверки.
 */
final class Version202608050002CreateDealCarField implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608050002';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Создаёт или принимает совместимое поле и подключает обработчики CRM.
     *
     * При сбое регистрации удаляется только поле, созданное текущим модулем.
     * Существующее до установки совместимое поле никогда не удаляется.
     */
    public function up(): void
    {
        /** @var DealCarFieldManager $fieldManager Менеджер метаданных пользовательского поля. */
        $fieldManager = new DealCarFieldManager();
        $fieldManager->ensureExists();

        try {
            EventRegistry::install();
        } catch (Throwable $exception) {
            $fieldManager->removeIfOwned();
            throw $exception;
        }
    }

    /**
     * Удаляет обработчики и принадлежащее модулю поле при полном удалении данных.
     */
    public function down(): void
    {
        EventRegistry::uninstall();

        /** @var DealCarFieldManager $fieldManager Менеджер безопасного удаления поля. */
        $fieldManager = new DealCarFieldManager();
        $fieldManager->removeIfOwned();
    }
}
