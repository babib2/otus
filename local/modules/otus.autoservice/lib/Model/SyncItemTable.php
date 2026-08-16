<?php

/**
 * Описывает ORM-журнал поштучных результатов синхронизации запчастей.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Model;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Хранит внешний абсолютный остаток, фактическое применение либо безопасную ошибку товара.
 */
final class SyncItemTable extends DataManager
{
    /** Внешний остаток товара успешно получен, применён и подтверждён контрольным чтением. */
    public const STATUS_SUCCESS = 'success';

    /** Получение или проверка идентификаторов товара завершились ошибкой. */
    public const STATUS_FAILED = 'failed';

    /** Возвращает физическое имя таблицы поштучных результатов. */
    public static function getTableName(): string
    {
        return 'b_otus_autoservice_sync_item';
    }

    /**
     * Возвращает допустимые статусы результата товара.
     *
     * @return string[]
     */
    public static function getAllowedStatuses(): array
    {
        return [self::STATUS_SUCCESS, self::STATUS_FAILED];
    }

    /**
     * Описывает связь с запуском, идентификаторы, количества до/после, документ и ошибку.
     *
     * EXTERNAL_ID и ARTICLE допускают null, чтобы журналировать повреждённую
     * запчасть, у которой отсутствует один из обязательных идентификаторов.
     *
     * @return array<int, \Bitrix\Main\ORM\Fields\Field>
     */
    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_ID'))
                ->configurePrimary()
                ->configureAutocomplete(),

            (new IntegerField('RUN_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_RUN_ID'))
                ->configureRequired()
                ->addValidator(new RangeValidator(1)),

            (new IntegerField('PRODUCT_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_PRODUCT_ID'))
                ->configureRequired()
                ->addValidator(new RangeValidator(1)),

            (new StringField('EXTERNAL_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_EXTERNAL_ID'))
                ->configureNullable()
                ->configureSize(255)
                ->addValidator(new LengthValidator(null, 255)),

            (new StringField('ARTICLE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_ARTICLE'))
                ->configureNullable()
                ->configureSize(255)
                ->addValidator(new LengthValidator(null, 255)),

            (new StringField('STATUS'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_STATUS'))
                ->configureRequired()
                ->configureSize(16)
                ->addValidator(
                    static function ($value) {
                        return in_array((string)$value, self::getAllowedStatuses(), true)
                            ? true
                            : Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_ERROR_STATUS');
                    }
                ),

            (new IntegerField('EXTERNAL_QUANTITY'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_EXTERNAL_QUANTITY'))
                ->configureNullable()
                ->addValidator(new RangeValidator(0)),

            (new IntegerField('STORE_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_STORE_ID'))
                ->configureNullable(),

            (new StringField('APPLY_MODE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_APPLY_MODE'))
                ->configureNullable()
                ->configureSize(32)
                ->addValidator(new LengthValidator(null, 32)),

            (new FloatField('PREVIOUS_STORE_QUANTITY'))
                ->configureTitle(
                    Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_PREVIOUS_STORE_QUANTITY')
                )
                ->configureNullable(),

            (new FloatField('APPLIED_STORE_QUANTITY'))
                ->configureTitle(
                    Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_APPLIED_STORE_QUANTITY')
                )
                ->configureNullable(),

            (new FloatField('PREVIOUS_PRODUCT_QUANTITY'))
                ->configureTitle(
                    Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_PREVIOUS_PRODUCT_QUANTITY')
                )
                ->configureNullable(),

            (new FloatField('APPLIED_PRODUCT_QUANTITY'))
                ->configureTitle(
                    Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_APPLIED_PRODUCT_QUANTITY')
                )
                ->configureNullable(),

            (new IntegerField('DOCUMENT_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_DOCUMENT_ID'))
                ->configureNullable(),

            (new StringField('ERROR_TYPE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_ERROR_TYPE'))
                ->configureNullable()
                ->configureSize(64)
                ->addValidator(new LengthValidator(null, 64)),

            (new TextField('ERROR_MESSAGE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_ERROR_MESSAGE'))
                ->configureNullable(),

            (new BooleanField('RETRYABLE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_RETRYABLE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('N'),

            (new DatetimeField('DATE_CREATE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_SYNC_ITEM_FIELD_DATE_CREATE'))
                ->configureRequired()
                ->configureDefaultValue(
                    static function (): DateTime {
                        return new DateTime();
                    }
                ),
        ];
    }
}
