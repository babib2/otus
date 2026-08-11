<?php

/**
 * Описывает ORM-журнал запусков синхронизации внешних остатков запчастей.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Model;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Хранит жизненный цикл одного cron- или административного запуска.
 */
final class SyncRunTable extends DataManager
{
    /** Запуск создан и ещё обрабатывает товары. */
    public const STATUS_RUNNING = 'running';

    /** Все выбранные товары успешно получили внешний остаток. */
    public const STATUS_COMPLETED = 'completed';

    /** Запуск завершён, но у части товаров сохранены ожидаемые ошибки. */
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    /** Запуск прерван общей инфраструктурной или программной ошибкой. */
    public const STATUS_FAILED = 'failed';

    /** Запуск инициирован планировщиком или прямой CLI-командой. */
    public const INITIATOR_CLI = 'cli';

    /** Запуск инициирован пользователем административной страницы. */
    public const INITIATOR_ADMIN = 'admin';

    /** Возвращает физическое имя таблицы запусков синхронизации. */
    public static function getTableName(): string
    {
        return 'b_otus_autoservice_sync_run';
    }

    /**
     * Возвращает допустимые итоговые и промежуточные статусы запуска.
     *
     * @return string[]
     */
    public static function getAllowedStatuses(): array
    {
        return [
            self::STATUS_RUNNING,
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_ERRORS,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Возвращает допустимые источники ручного или планового запуска.
     *
     * @return string[]
     */
    public static function getAllowedInitiators(): array
    {
        return [self::INITIATOR_CLI, self::INITIATOR_ADMIN];
    }

    /**
     * Описывает колонки запуска, счётчики, heartbeat и безопасную общую ошибку.
     *
     * @return array<int, \Bitrix\Main\ORM\Fields\Field>
     */
    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_ID'))
                ->configurePrimary()
                ->configureAutocomplete(),

            (new StringField('PROVIDER_CODE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_PROVIDER_CODE'))
                ->configureRequired()
                ->configureSize(64)
                ->addValidator(new LengthValidator(2, 64)),

            (new StringField('INITIATOR'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_INITIATOR'))
                ->configureRequired()
                ->configureSize(16)
                ->addValidator(
                    static function ($value) {
                        return in_array((string)$value, self::getAllowedInitiators(), true)
                            ? true
                            : Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_ERROR_INITIATOR');
                    }
                ),

            (new StringField('STATUS'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_STATUS'))
                ->configureRequired()
                ->configureSize(32)
                ->addValidator(
                    static function ($value) {
                        return in_array((string)$value, self::getAllowedStatuses(), true)
                            ? true
                            : Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_ERROR_STATUS');
                    }
                ),

            (new IntegerField('TOTAL_ITEMS'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_TOTAL_ITEMS'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new IntegerField('SUCCESS_ITEMS'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_SUCCESS_ITEMS'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new IntegerField('FAILED_ITEMS'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_FAILED_ITEMS'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new DatetimeField('STARTED_AT'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_STARTED_AT'))
                ->configureRequired()
                ->configureDefaultValue(
                    static function (): DateTime {
                        return new DateTime();
                    }
                ),

            (new DatetimeField('HEARTBEAT_AT'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_HEARTBEAT_AT'))
                ->configureRequired()
                ->configureDefaultValue(
                    static function (): DateTime {
                        return new DateTime();
                    }
                ),

            (new DatetimeField('FINISHED_AT'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_FINISHED_AT'))
                ->configureNullable(),

            (new TextField('ERROR_MESSAGE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_RUN_FIELD_ERROR_MESSAGE'))
                ->configureNullable(),
        ];
    }
}
