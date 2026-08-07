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
     * Имя настройки с идентификатором направления сервисных CRM-сделок.
     *
     * Пустое значение означает, что администратор ещё не выбрал воронку и
     * обработчики сделок должны завершаться без применения ограничений.
     */
    public const OPTION_SERVICE_DEAL_CATEGORY_ID = 'service_deal_category_id';

    /** ID направления, фактически созданного миграцией, независимо от выбора администратора. */
    public const OPTION_SERVICE_DEAL_CATEGORY_CREATED_ID = 'service_deal_category_created_id';

    /** Код настройки стадии «Приёмка». */
    public const OPTION_SERVICE_STAGE_RECEPTION = 'service_stage_reception';

    /** Код настройки стадии «Диагностика». */
    public const OPTION_SERVICE_STAGE_DIAGNOSTICS = 'service_stage_diagnostics';

    /** Код настройки стадии «Ожидание запчастей». */
    public const OPTION_SERVICE_STAGE_WAITING_PARTS = 'service_stage_waiting_parts';

    /** Код настройки стадии «Ремонт». */
    public const OPTION_SERVICE_STAGE_REPAIR = 'service_stage_repair';

    /** Код настройки стадии «Проверка». */
    public const OPTION_SERVICE_STAGE_QUALITY_CHECK = 'service_stage_quality_check';

    /** Код настройки успешной стадии «Завершено». */
    public const OPTION_SERVICE_STAGE_COMPLETED = 'service_stage_completed';

    /** Код настройки финальной стадии «Закрыто без ремонта». */
    public const OPTION_SERVICE_STAGE_FAILED = 'service_stage_failed';

    /** Код настройки финальной стадии «Отменено клиентом». */
    public const OPTION_SERVICE_STAGE_CANCELLED = 'service_stage_cancelled';

    /**
     * Имя технической настройки с кодом поля автомобиля в CRM-сделке.
     */
    public const OPTION_DEAL_CAR_FIELD_NAME = 'deal_car_field_name';

    /**
     * Имя технической настройки с ID пользовательского поля, созданного модулем.
     */
    public const OPTION_DEAL_CAR_FIELD_ID = 'deal_car_field_id';

    /**
     * Флаг владения пользовательским полем: `Y`, если поле создал модуль.
     */
    public const OPTION_DEAL_CAR_FIELD_OWNED = 'deal_car_field_owned';

    /**
     * Стабильный код поля связи CRM-сделки с автомобилем из ORM-таблицы.
     */
    public const DEFAULT_DEAL_CAR_FIELD_NAME = 'UF_CRM_OTUS_CAR_ID';

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
     * Возвращает выбранное направление сервисных сделок.
     *
     * Нулевой ID является допустимым идентификатором основной воронки Bitrix,
     * поэтому отсутствие настройки представлено именно значением null.
     *
     * @return int|null ID направления либо null, если настройка ещё не задана.
     */
    public static function getServiceDealCategoryId(): ?int
    {
        /** @var string $categoryId Сырое значение настройки направления. */
        $categoryId = Option::get(
            self::MODULE_ID,
            self::OPTION_SERVICE_DEAL_CATEGORY_ID,
            ''
        );

        if ($categoryId === '' || preg_match('/^\d+$/', $categoryId) !== 1) {
            return null;
        }

        return (int)$categoryId;
    }

    /**
     * Возвращает проверенный код поля автомобиля в CRM-сделке.
     *
     * Если техническая настройка повреждена, используется стабильный код по
     * умолчанию. Это не позволяет обработчику обратиться к произвольной колонке.
     */
    public static function getDealCarFieldName(): string
    {
        /** @var string $fieldName Код поля, сохранённый миграцией модуля. */
        $fieldName = Option::get(
            self::MODULE_ID,
            self::OPTION_DEAL_CAR_FIELD_NAME,
            self::DEFAULT_DEAL_CAR_FIELD_NAME
        );

        if (preg_match('/^UF_CRM_[A-Z0-9_]+$/', $fieldName) !== 1) {
            return self::DEFAULT_DEAL_CAR_FIELD_NAME;
        }

        return $fieldName;
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
