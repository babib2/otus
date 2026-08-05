<?php

/**
 * Создаёт, проверяет и безопасно удаляет пользовательское поле автомобиля в CRM-сделке.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Crm;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Service\ModuleConfiguration;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Управляет метаданными поля, связывающего сделку с записью автомобиля.
 *
 * Поле создаётся как необязательное для CRM в целом, потому что пользовательские
 * поля сделки общие для всех направлений. Условную обязательность только в
 * сервисной воронке обеспечивает серверный обработчик DealValidationHandler.
 */
final class DealCarFieldManager
{
    /**
     * Идентификатор сущности пользовательских полей сделки в Bitrix.
     */
    private const ENTITY_ID = 'CRM_DEAL';

    /**
     * Обеспечивает наличие совместимого поля и возвращает его числовой ID.
     *
     * Существующее поле повторно не создаётся. Если его тип или множественность
     * несовместимы с хранением одного ID автомобиля, миграция прекращается до
     * регистрации обработчиков, чтобы не оставить систему в неоднозначном состоянии.
     */
    public function ensureExists(): int
    {
        if (!Loader::includeModule('crm')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_CRM_REQUIRED')
            );
        }

        /** @var string $fieldName Стабильный код создаваемого поля CRM. */
        $fieldName = ModuleConfiguration::getDealCarFieldName();

        /** @var array<string, mixed>|null $existingField Уже зарегистрированное поле с тем же кодом. */
        $existingField = $this->find();
        if ($existingField !== null) {
            $this->assertCompatible($existingField);
            Option::set(
                ModuleConfiguration::MODULE_ID,
                ModuleConfiguration::OPTION_DEAL_CAR_FIELD_NAME,
                $fieldName
            );

            return (int)$existingField['ID'];
        }

        /** @var \CUserTypeEntity $userFieldEntity Штатный менеджер пользовательских полей Bitrix. */
        $userFieldEntity = new \CUserTypeEntity();

        /** @var int|false $fieldId ID созданного поля либо false при ошибке API. */
        $fieldId = $userFieldEntity->Add(
            [
                'ENTITY_ID' => self::ENTITY_ID,
                'FIELD_NAME' => $fieldName,
                'USER_TYPE_ID' => 'integer',
                'XML_ID' => 'OTUS_AUTOSERVICE_CAR_ID',
                'SORT' => 500,
                'MULTIPLE' => 'N',
                'MANDATORY' => 'N',
                'SHOW_FILTER' => 'I',
                'SHOW_IN_LIST' => 'Y',
                'EDIT_IN_LIST' => 'N',
                'IS_SEARCHABLE' => 'N',
                'SETTINGS' => [
                    'SIZE' => 20,
                    'MIN_VALUE' => 1,
                    'MAX_VALUE' => 0,
                    'DEFAULT_VALUE' => null,
                ],
                'EDIT_FORM_LABEL' => [
                    'ru' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_RU'),
                    'en' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_EN'),
                ],
                'LIST_COLUMN_LABEL' => [
                    'ru' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_RU'),
                    'en' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_EN'),
                ],
                'LIST_FILTER_LABEL' => [
                    'ru' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_RU'),
                    'en' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_LABEL_EN'),
                ],
                'ERROR_MESSAGE' => [
                    'ru' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_ERROR_RU'),
                    'en' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_ERROR_EN'),
                ],
                'HELP_MESSAGE' => [
                    'ru' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_HELP_RU'),
                    'en' => (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_HELP_EN'),
                ],
            ]
        );

        if ($fieldId === false || (int)$fieldId <= 0) {
            /** @var \CApplicationException|null $applicationException Ошибка, установленная старым API Bitrix. */
            $applicationException = isset($GLOBALS['APPLICATION'])
                ? $GLOBALS['APPLICATION']->GetException()
                : null;

            throw new RuntimeException(
                $applicationException !== null
                    ? $applicationException->GetString()
                    : (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_CREATE_FAILED')
            );
        }

        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_DEAL_CAR_FIELD_NAME,
            $fieldName
        );
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_DEAL_CAR_FIELD_ID,
            (string)$fieldId
        );
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_DEAL_CAR_FIELD_OWNED,
            'Y'
        );

        return (int)$fieldId;
    }

    /**
     * Возвращает метаданные поля по его коду.
     *
     * @return array<string, mixed>|null Поле CRM либо null, если миграция ещё не применена.
     */
    public function find(): ?array
    {
        /** @var \CDBResult $queryResult Результат выборки метаданных пользовательского поля. */
        $queryResult = \CUserTypeEntity::GetList(
            [],
            [
                'ENTITY_ID' => self::ENTITY_ID,
                'FIELD_NAME' => ModuleConfiguration::getDealCarFieldName(),
            ]
        );

        /** @var array<string, mixed>|false $field Найденные метаданные поля. */
        $field = $queryResult->Fetch();

        return $field === false ? null : $field;
    }

    /**
     * Проверяет наличие совместимого пользовательского поля без изменения CRM.
     */
    public function exists(): bool
    {
        /** @var array<string, mixed>|null $field Метаданные проверяемого поля. */
        $field = $this->find();
        if ($field === null) {
            return false;
        }

        return (string)$field['USER_TYPE_ID'] === 'integer'
            && (string)$field['MULTIPLE'] !== 'Y';
    }

    /**
     * Удаляет поле только тогда, когда оно было создано именно этим модулем.
     *
     * Совместимое поле, существовавшее до установки, не принадлежит модулю и при
     * деинсталляции сохраняется вместе со всеми пользовательскими значениями.
     */
    public function removeIfOwned(): void
    {
        if (
            Option::get(
                ModuleConfiguration::MODULE_ID,
                ModuleConfiguration::OPTION_DEAL_CAR_FIELD_OWNED,
                'N'
            ) !== 'Y'
        ) {
            return;
        }

        /** @var array<string, mixed>|null $field Поле, которое ранее создал модуль. */
        $field = $this->find();
        if ($field !== null) {
            /** @var \CUserTypeEntity $userFieldEntity Менеджер удаления пользовательского поля. */
            $userFieldEntity = new \CUserTypeEntity();
            if ($userFieldEntity->Delete((int)$field['ID']) === false) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_CAR_FIELD_DELETE_FAILED')
                );
            }
        }

        Option::delete(
            ModuleConfiguration::MODULE_ID,
            ['name' => ModuleConfiguration::OPTION_DEAL_CAR_FIELD_ID]
        );
        Option::delete(
            ModuleConfiguration::MODULE_ID,
            ['name' => ModuleConfiguration::OPTION_DEAL_CAR_FIELD_OWNED]
        );
        Option::delete(
            ModuleConfiguration::MODULE_ID,
            ['name' => ModuleConfiguration::OPTION_DEAL_CAR_FIELD_NAME]
        );
    }

    /**
     * Прерывает миграцию, если занятый код поля имеет несовместимую структуру.
     *
     * @param array<string, mixed> $field Метаданные существующего поля CRM.
     */
    private function assertCompatible(array $field): void
    {
        if (
            (string)$field['USER_TYPE_ID'] !== 'integer'
            || (string)$field['MULTIPLE'] === 'Y'
        ) {
            throw new RuntimeException(
                (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_DEAL_CAR_FIELD_INCOMPATIBLE',
                    ['#FIELD_NAME#' => ModuleConfiguration::getDealCarFieldName()]
                )
            );
        }
    }
}
