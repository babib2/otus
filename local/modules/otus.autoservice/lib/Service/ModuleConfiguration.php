<?php

/**
 * Предоставляет единый доступ к настройкам и значениям по умолчанию модуля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Main\Config\Option;

/**
 * Предоставляет типизированный доступ к настройкам модуля.
 */
final class ModuleConfiguration
{
    /**
     * Системный идентификатор модуля, используемый в таблице настроек Bitrix.
     */
    public const MODULE_ID = 'otus.autoservice';

    /**
     * Имя настройки-флага, разрешающего выполнение прикладной логики модуля.
     */
    public const OPTION_ENABLED = 'module_enabled';

    /**
     * Имя настройки с минимальным уровнем записываемых диагностических сообщений.
     */
    public const OPTION_LOG_LEVEL = 'log_level';

    /**
     * Уровень журналирования, используемый до первого сохранения настроек.
     */
    private const DEFAULT_LOG_LEVEL = 'error';

    /**
     * Белый список значений, которые разрешено сохранять через административную форму.
     *
     * @var string[]
     */
    private const ALLOWED_LOG_LEVELS = [
        'error',   // Ошибки, после которых сценарий не может продолжаться штатно.
        'warning', // Некритичные отклонения, требующие внимания администратора.
        'info',    // Основные этапы успешного выполнения бизнес-сценариев.
        'debug',   // Подробные технические данные для локальной диагностики.
    ];

    /**
     * Проверяет, включена ли прикладная логика модуля.
     *
     * @return bool Значение настройки; при её отсутствии модуль считается включённым.
     */
    public static function isEnabled(): bool
    {
        return Option::get(self::MODULE_ID, self::OPTION_ENABLED, 'Y') === 'Y';
    }

    /**
     * Возвращает настроенный уровень журналирования.
     *
     * Некорректное значение из базы не передаётся дальше: вместо него возвращается
     * безопасный уровень по умолчанию.
     *
     * @return string Одно из значений, перечисленных в ALLOWED_LOG_LEVELS.
     */
    public static function getLogLevel(): string
    {
        /** @var string $logLevel Значение уровня журналирования из настроек Bitrix. */
        $logLevel = Option::get(
            self::MODULE_ID,
            self::OPTION_LOG_LEVEL,
            self::DEFAULT_LOG_LEVEL
        );

        if (!in_array($logLevel, self::ALLOWED_LOG_LEVELS, true)) {
            return self::DEFAULT_LOG_LEVEL;
        }

        return $logLevel;
    }

    /**
     * Возвращает допустимые уровни журналирования для проверки и построения формы.
     *
     * @return string[]
     */
    public static function getAllowedLogLevels(): array
    {
        return self::ALLOWED_LOG_LEVELS;
    }
}
