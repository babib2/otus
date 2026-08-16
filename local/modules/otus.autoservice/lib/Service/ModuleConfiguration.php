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

    /** ID штатного CRM-каталога, выбранного для хранения запчастей автосервиса. */
    public const OPTION_SPARE_PARTS_CATALOG_ID = 'spare_parts_catalog_id';

    /** ID строкового свойства каталога, в котором хранится артикул запчасти. */
    public const OPTION_SPARE_PARTS_ARTICLE_PROPERTY_ID = 'spare_parts_article_property_id';

    /** ID демонстрационного склада, однозначно помеченного внешним кодом модуля. */
    public const OPTION_SPARE_PARTS_STORE_ID = 'spare_parts_store_id';

    /** Имя пользовательской настройки с кодом источника внешних остатков. */
    public const OPTION_STOCK_PROVIDER = 'stock_provider';

    /** Unix-время последнего запуска, полностью получившего и применившего остатки всех запчастей. */
    public const OPTION_STOCK_SYNC_LAST_SUCCESS_AT = 'stock_sync_last_success_at';

    /** ID активного пользователя, ответственного за складские документы фонового cron-запуска. */
    public const OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID = 'stock_document_responsible_user_id';

    /** Код HTTP-поставщика демонстрационных остатков Random.org. */
    public const STOCK_PROVIDER_RANDOM_ORG = 'random_org';

    /** Код локального предсказуемого поставщика для разработки и тестов. */
    public const STOCK_PROVIDER_FAKE = 'fake';

    /** Поставщик внешних остатков, используемый до первого сохранения настроек. */
    public const DEFAULT_STOCK_PROVIDER = self::STOCK_PROVIDER_RANDOM_ORG;

    /** Допустимое опережение часов сервера при чтении даты полного успеха в секундах. */
    private const STOCK_SYNC_FUTURE_TOLERANCE_SECONDS = 300;

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
     * Белый список встроенных поставщиков, доступных в административной форме.
     *
     * @var string[]
     */
    private const ALLOWED_STOCK_PROVIDERS = [
        self::STOCK_PROVIDER_RANDOM_ORG,
        self::STOCK_PROVIDER_FAKE,
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
     * Возвращает выбранный миграцией штатный каталог запчастей.
     *
     * @return int|null Положительный ID инфоблока-каталога либо null при отсутствии настройки.
     */
    public static function getSparePartsCatalogId(): ?int
    {
        return self::getPositiveIntegerOption(self::OPTION_SPARE_PARTS_CATALOG_ID);
    }

    /**
     * Возвращает созданное модулем свойство артикула в каталоге запчастей.
     *
     * @return int|null Положительный ID свойства инфоблока либо null при отсутствии настройки.
     */
    public static function getSparePartsArticlePropertyId(): ?int
    {
        return self::getPositiveIntegerOption(self::OPTION_SPARE_PARTS_ARTICLE_PROPERTY_ID);
    }

    /**
     * Возвращает демонстрационный склад, используемый последующей синхронизацией остатков.
     *
     * @return int|null Положительный ID склада каталога либо null при отсутствии настройки.
     */
    public static function getSparePartsStoreId(): ?int
    {
        return self::getPositiveIntegerOption(self::OPTION_SPARE_PARTS_STORE_ID);
    }

    /**
     * Возвращает проверенный код поставщика внешних остатков.
     *
     * Повреждённое или устаревшее значение из b_option не передаётся фабрике:
     * вместо него выбирается встроенный HTTP-поставщик по умолчанию.
     *
     * @return string Один из кодов, перечисленных в ALLOWED_STOCK_PROVIDERS.
     */
    public static function getStockProviderCode(): string
    {
        /** @var string $providerCode Сырое значение настройки поставщика из b_option. */
        $providerCode = Option::get(
            self::MODULE_ID,
            self::OPTION_STOCK_PROVIDER,
            self::DEFAULT_STOCK_PROVIDER
        );

        if (!in_array($providerCode, self::ALLOWED_STOCK_PROVIDERS, true)) {
            return self::DEFAULT_STOCK_PROVIDER;
        }

        return $providerCode;
    }

    /**
     * Возвращает настроенного ответственного за автоматически создаваемые складские документы.
     *
     * Метод проверяет только строгий формат положительного ID. Активность пользователя
     * проверяется непосредственно перед созданием документа, чтобы не кешировать устаревший статус.
     *
     * @return int|null Положительный ID либо null для запуска от текущего пользователя.
     */
    public static function getStockDocumentResponsibleUserId(): ?int
    {
        return self::getPositiveIntegerOption(self::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID);
    }

    /**
     * Возвращает коды встроенных поставщиков для проверки и построения формы.
     *
     * @return string[] Новый массив кодов, изменение которого не влияет на конфигурацию класса.
     */
    public static function getAllowedStockProviderCodes(): array
    {
        return self::ALLOWED_STOCK_PROVIDERS;
    }

    /**
     * Возвращает дату последней полностью полученной и применённой синхронизации остатков.
     *
     * Значение хранится как Unix-время, чтобы не зависеть от формата даты и часового
     * пояса сайта. Повреждённое, нереалистичное или существенно будущее значение игнорируется.
     */
    public static function getStockSyncLastSuccessAt(): ?\Bitrix\Main\Type\DateTime
    {
        /** @var string $rawTimestamp Сырое значение Unix-времени из b_option. */
        $rawTimestamp = Option::get(
            self::MODULE_ID,
            self::OPTION_STOCK_SYNC_LAST_SUCCESS_AT,
            ''
        );
        if (preg_match('/^[1-9][0-9]*$/D', $rawTimestamp) !== 1) {
            return null;
        }

        /** @var int $timestamp Проверенное числовое Unix-время. */
        $timestamp = (int)$rawTimestamp;
        if (
            $timestamp <= 0
            || (string)$timestamp !== $rawTimestamp
            || $timestamp > time() + self::STOCK_SYNC_FUTURE_TOLERANCE_SECONDS
        ) {
            return null;
        }

        return \Bitrix\Main\Type\DateTime::createFromTimestamp($timestamp);
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

    /**
     * Строго читает положительный целочисленный идентификатор из настройки модуля.
     *
     * Повреждённое, отрицательное или дробное значение не передаётся сервисам каталога.
     *
     * @param string $optionName Имя технической настройки с идентификатором сущности Bitrix.
     *
     * @return int|null Проверенный положительный ID либо null.
     */
    private static function getPositiveIntegerOption(string $optionName): ?int
    {
        /** @var string $rawValue Сырое строковое значение из b_option. */
        $rawValue = Option::get(self::MODULE_ID, $optionName, '');
        if (preg_match('/^[1-9][0-9]*$/D', $rawValue) !== 1) {
            return null;
        }

        /** @var int $identifier Преобразованный идентификатор в диапазоне PHP integer. */
        $identifier = (int)$rawValue;

        if ($identifier <= 0 || (string)$identifier !== $rawValue) {
            return null;
        }

        return $identifier;
    }
}
