<?php

/**
 * Описывает ORM-сущность автомобиля клиента и её соответствие таблице базы данных.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Model;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * D7 ORM-модель автомобиля, принадлежащего контакту CRM.
 *
 * Номер автомобиля хранится уже в нормализованном виде. Приведение регистра,
 * удаление пробелов и дефисов выполняет CarService до передачи данных в ORM.
 */
final class CarTable extends DataManager
{
    /**
     * Возвращает физическое имя таблицы автомобилей.
     */
    public static function getTableName(): string
    {
        return 'b_otus_autoservice_car';
    }

    /**
     * Возвращает описание колонок, типов, значений по умолчанию и валидаторов.
     *
     * @return array<int, \Bitrix\Main\ORM\Fields\Field>
     */
    public static function getMap(): array
    {
        /** @var int $maximumCarYear Максимально допустимый модельный год автомобиля. */
        $maximumCarYear = (int)date('Y') + 1;

        return [
            (new IntegerField('ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_ID'))
                ->configurePrimary()
                ->configureAutocomplete(),

            (new IntegerField('CONTACT_ID'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_CONTACT_ID'))
                ->configureRequired()
                ->addValidator(new RangeValidator(1)),

            (new StringField('MAKE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_MAKE'))
                ->configureRequired()
                ->configureSize(100)
                ->addValidator(new LengthValidator(1, 100)),

            (new StringField('MODEL'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_MODEL'))
                ->configureRequired()
                ->configureSize(100)
                ->addValidator(new LengthValidator(1, 100)),

            (new StringField('LICENSE_PLATE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_LICENSE_PLATE'))
                ->configureRequired()
                ->configureSize(20)
                ->addValidator(new LengthValidator(1, 20)),

            (new IntegerField('YEAR'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_YEAR'))
                ->configureNullable()
                ->addValidator(
                    static function ($value) use ($maximumCarYear) {
                        if ($value === null) {
                            return true;
                        }

                        /** @var int $year Проверяемое числовое значение модельного года. */
                        $year = (int)$value;
                        if ($year >= 1886 && $year <= $maximumCarYear) {
                            return true;
                        }

                        return Loc::getMessage(
                            'OTUS_AUTOSERVICE_CAR_ERROR_YEAR_RANGE',
                            ['#MAX_YEAR#' => (string)$maximumCarYear]
                        );
                    }
                ),

            (new StringField('COLOR'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_COLOR'))
                ->configureNullable()
                ->configureSize(50)
                ->addValidator(new LengthValidator(null, 50)),

            (new IntegerField('MILEAGE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_MILEAGE'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new BooleanField('ACTIVE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_ACTIVE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('Y'),

            (new IntegerField('CREATED_BY'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_CREATED_BY'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new IntegerField('UPDATED_BY'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_UPDATED_BY'))
                ->configureDefaultValue(0)
                ->addValidator(new RangeValidator(0)),

            (new DatetimeField('DATE_CREATE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_DATE_CREATE'))
                ->configureRequired()
                ->configureDefaultValue(
                    static function (): DateTime {
                        return new DateTime();
                    }
                ),

            (new DatetimeField('DATE_UPDATE'))
                ->configureTitle(Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_DATE_UPDATE'))
                ->configureRequired()
                ->configureDefaultValue(
                    static function (): DateTime {
                        return new DateTime();
                    }
                ),
        ];
    }
}
