<?php

/**
 * Проверяет связь сервисной CRM-сделки с автомобилем и наличие другого открытого заказа.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Otus\Autoservice\Repository\CarRepository;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Реализует серверный инвариант «один открытый заказ на один автомобиль».
 *
 * Единственным источником открытых заказов являются сделки CRM. Сервис не
 * создаёт таблицу блокировок: он ищет одну сделку сервисного направления с тем
 * же автомобилем и семантикой стадии PROCESS. При обновлении текущая сделка
 * исключается из поиска по ID.
 */
final class DealOpenOrderService
{
    /** Код ошибки отсутствующего автомобиля в сервисной сделке. */
    public const ERROR_CAR_REQUIRED = 'CAR_REQUIRED';

    /** Код ошибки отсутствующего основного контакта сделки. */
    public const ERROR_CONTACT_REQUIRED = 'CONTACT_REQUIRED';

    /** Код ошибки ссылки на несуществующий автомобиль. */
    public const ERROR_CAR_NOT_FOUND = 'CAR_NOT_FOUND';

    /** Код ошибки использования архивного автомобиля в открытом заказе. */
    public const ERROR_CAR_INACTIVE = 'CAR_INACTIVE';

    /** Код ошибки принадлежности автомобиля другому контакту. */
    public const ERROR_CAR_CONTACT_MISMATCH = 'CAR_CONTACT_MISMATCH';

    /** Код ошибки уже существующего незакрытого заказа. */
    public const ERROR_OPEN_ORDER_EXISTS = 'OPEN_ORDER_EXISTS';

    /** @var CarRepository Репозиторий чтения автомобилей без изменения записей. */
    private $carRepository;

    /**
     * Принимает репозиторий извне для изолированного тестирования бизнес-правила.
     */
    public function __construct(?CarRepository $carRepository = null)
    {
        $this->carRepository = $carRepository ?? new CarRepository();
    }

    /**
     * Проверяет данные новой сделки непосредственно перед добавлением в CRM.
     *
     * @param array<string, mixed> $fields Поля, уже подготовленные штатным API CRM.
     * @param int                  $userId Пользователь, от имени которого выполняется операция.
     */
    public function validateCreate(array $fields, int $userId): Result
    {
        return $this->validate($fields, null, max(0, $userId));
    }

    /**
     * Проверяет итоговое состояние существующей сделки перед обновлением.
     *
     * Метод сам получает текущие поля, потому что событие OnBeforeCrmDealUpdate
     * содержит преимущественно изменяемые значения, а не полную карточку сделки.
     *
     * @param array<string, mixed> $changedFields Изменяемые поля, включая ID сделки.
     * @param int                  $userId       Пользователь, выполняющий изменение.
     */
    public function validateUpdate(array $changedFields, int $userId): Result
    {
        /** @var int $dealId Идентификатор изменяемой CRM-сделки. */
        $dealId = (int)($changedFields['ID'] ?? 0);
        if ($dealId <= 0) {
            return new Result();
        }

        /** @var array<string, mixed>|null $currentFields Текущее состояние сделки из CRM. */
        $currentFields = $this->findDealById($dealId);
        if ($currentFields === null) {
            // Штатный CRM API самостоятельно сообщит, что изменяемая сделка не найдена.
            return new Result();
        }

        /** @var array<string, mixed> $candidateFields Полное состояние после предполагаемого обновления. */
        $candidateFields = array_merge($currentFields, $changedFields);

        if (!$this->shouldValidateUpdate($changedFields, $currentFields, $candidateFields)) {
            return new Result();
        }

        return $this->validate($candidateFields, $currentFields, max(0, $userId));
    }

    /**
     * Проверяет наличие незакрытого сервисного заказа по автомобилю перед его архивированием.
     *
     * Метод не возвращает реквизиты найденной сделки, чтобы вызывающий код не мог
     * раскрыть данные заказа пользователю без отдельной проверки CRM-прав.
     *
     * @param int $carId Идентификатор архивируемого автомобиля.
     */
    public function hasOpenOrderForCar(int $carId): bool
    {
        if ($carId <= 0) {
            return false;
        }

        /** @var int|null $serviceCategoryId Настроенное направление сервисного обслуживания. */
        $serviceCategoryId = ModuleConfiguration::getServiceDealCategoryId();
        if ($serviceCategoryId === null) {
            return false;
        }

        if (!Loader::includeModule('crm')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CRM_REQUIRED')
            );
        }

        return $this->findOpenDeal(
            $serviceCategoryId,
            ModuleConfiguration::getDealCarFieldName(),
            $carId,
            0
        ) !== null;
    }

    /**
     * Определяет, затрагивает ли обновление поля, способные изменить блокировку автомобиля.
     *
     * Обычное изменение открытой сделки повторно не проверяет активность уже
     * привязанного автомобиля. Это позволяет завершить ранее начатый ремонт и
     * одновременно сохраняет обязательную проверку при смене автомобиля, контакта,
     * направления или возврате сделки из финальной стадии.
     *
     * @param array<string, mixed> $changedFields   Поля из события обновления CRM.
     * @param array<string, mixed> $currentFields   Состояние сделки до обновления.
     * @param array<string, mixed> $candidateFields Состояние сделки после обновления.
     */
    private function shouldValidateUpdate(
        array $changedFields,
        array $currentFields,
        array $candidateFields
    ): bool {
        /** @var int|null $serviceCategoryId Настроенное сервисное направление. */
        $serviceCategoryId = ModuleConfiguration::getServiceDealCategoryId();
        if ($serviceCategoryId === null) {
            return false;
        }

        /** @var int $currentCategoryId Направление сделки до обновления. */
        $currentCategoryId = (int)($currentFields['CATEGORY_ID'] ?? 0);

        /** @var int $candidateCategoryId Направление сделки после обновления. */
        $candidateCategoryId = (int)($candidateFields['CATEGORY_ID'] ?? 0);

        if (
            $currentCategoryId !== $serviceCategoryId
            && $candidateCategoryId !== $serviceCategoryId
        ) {
            return false;
        }

        if ($currentCategoryId !== $candidateCategoryId) {
            return true;
        }

        /** @var string $carFieldName Код пользовательского поля автомобиля. */
        $carFieldName = ModuleConfiguration::getDealCarFieldName();
        if (
            array_key_exists($carFieldName, $changedFields)
            && (int)($currentFields[$carFieldName] ?? 0) !== (int)($candidateFields[$carFieldName] ?? 0)
        ) {
            return true;
        }

        if (
            array_key_exists('CONTACT_ID', $changedFields)
            && (int)($currentFields['CONTACT_ID'] ?? 0) !== (int)($candidateFields['CONTACT_ID'] ?? 0)
        ) {
            return true;
        }

        if (!array_key_exists('STAGE_ID', $changedFields)) {
            return false;
        }

        /** @var string $currentStageId Стадия сделки до обновления. */
        $currentStageId = (string)($currentFields['STAGE_ID'] ?? '');

        /** @var string $candidateStageId Стадия сделки после обновления. */
        $candidateStageId = (string)($candidateFields['STAGE_ID'] ?? '');

        /** @var string $currentSemantic Семантика стадии до обновления. */
        $currentSemantic = (string)($currentFields['STAGE_SEMANTIC_ID'] ?? '');
        if ($currentSemantic === '' && $currentStageId !== '') {
            $currentSemantic = (string)\CCrmDeal::GetSemanticID($currentStageId, $currentCategoryId);
        }

        /** @var string $candidateSemantic Семантика стадии после обновления. */
        $candidateSemantic = $candidateStageId === ''
            ? ''
            : (string)\CCrmDeal::GetSemanticID($candidateStageId, $candidateCategoryId);

        return $currentSemantic !== PhaseSemantics::PROCESS
            && $candidateSemantic === PhaseSemantics::PROCESS;
    }

    /**
     * Проверяет полное предполагаемое состояние сервисной сделки.
     *
     * @param array<string, mixed>      $candidateFields Поля сделки после операции.
     * @param array<string, mixed>|null $currentFields   Поля до обновления либо null при создании.
     * @param int                       $userId           Автор текущей CRM-операции.
     */
    private function validate(array $candidateFields, ?array $currentFields, int $userId): Result
    {
        /** @var Result $result Накопленный результат проверки без исключений для бизнес-ошибок. */
        $result = new Result();

        /** @var int|null $serviceCategoryId Настроенное направление сервисного обслуживания. */
        $serviceCategoryId = ModuleConfiguration::getServiceDealCategoryId();
        if ($serviceCategoryId === null) {
            // До явного выбора воронки модуль не должен ограничивать чужие сделки.
            return $result;
        }

        /** @var int $candidateCategoryId Направление сделки после операции. */
        $candidateCategoryId = (int)($candidateFields['CATEGORY_ID'] ?? 0);
        if ($candidateCategoryId !== $serviceCategoryId) {
            return $result;
        }

        if (!Loader::includeModule('crm')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CRM_REQUIRED')
            );
        }

        /** @var string $carFieldName Код пользовательского поля автомобиля. */
        $carFieldName = ModuleConfiguration::getDealCarFieldName();

        /** @var int $carId Выбранный идентификатор автомобиля. */
        $carId = (int)($candidateFields[$carFieldName] ?? 0);
        if ($carId <= 0) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CAR_REQUIRED'),
                    self::ERROR_CAR_REQUIRED
                )
            );

            return $result;
        }

        /** @var int $contactId Основной контакт CRM, которому должен принадлежать автомобиль. */
        $contactId = (int)($candidateFields['CONTACT_ID'] ?? 0);
        if ($contactId <= 0) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CONTACT_REQUIRED'),
                    self::ERROR_CONTACT_REQUIRED
                )
            );

            return $result;
        }

        /** @var array<string, mixed>|null $car Автомобиль из собственной ORM-таблицы модуля. */
        $car = $this->carRepository->findById($carId);
        if ($car === null) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CAR_NOT_FOUND'),
                    self::ERROR_CAR_NOT_FOUND
                )
            );

            return $result;
        }

        if ((int)$car['CONTACT_ID'] !== $contactId) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CAR_CONTACT_MISMATCH'),
                    self::ERROR_CAR_CONTACT_MISMATCH
                )
            );

            return $result;
        }

        /** @var string $stageId Итоговая стадия; при отсутствии используется начальная стадия направления. */
        $stageId = (string)($candidateFields['STAGE_ID'] ?? '');
        if ($stageId === '') {
            $stageId = (string)\CCrmDeal::GetStartStageID($candidateCategoryId);
        }

        /** @var string $stageSemantic Семантика стадии, вычисленная CRM, а не её отображаемое название. */
        $stageSemantic = (string)\CCrmDeal::GetSemanticID($stageId, $candidateCategoryId);

        if ((string)$car['ACTIVE'] !== 'Y' && $stageSemantic === PhaseSemantics::PROCESS) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_CAR_INACTIVE'),
                    self::ERROR_CAR_INACTIVE
                )
            );

            return $result;
        }

        // Финальная сделка не является открытым заказом и не должна создавать блокировку.
        if ($stageSemantic !== PhaseSemantics::PROCESS) {
            return $result;
        }

        /** @var int $currentDealId ID обновляемой сделки, исключаемый из поиска совпадений. */
        $currentDealId = (int)($currentFields['ID'] ?? 0);

        /** @var array<string, mixed>|null $openDeal Другая незакрытая сделка по автомобилю. */
        $openDeal = $this->findOpenDeal(
            $candidateCategoryId,
            $carFieldName,
            $carId,
            $currentDealId
        );
        if ($openDeal === null) {
            return $result;
        }

        /** @var bool $canReadOpenDeal Может ли инициатор видеть реквизиты найденной сделки. */
        $canReadOpenDeal = $this->canReadDeal(
            (int)$openDeal['ID'],
            $candidateCategoryId,
            $userId
        );

        /** @var string $errorMessage Безопасное сообщение, учитывающее права пользователя. */
        $errorMessage = $canReadOpenDeal
            ? (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_DEAL_ERROR_OPEN_ORDER_DETAILS',
                [
                    '#ID#' => (string)$openDeal['ID'],
                    '#TITLE#' => $this->preparePlainText((string)$openDeal['TITLE']),
                    '#URL#' => \CCrmOwnerType::GetEntityShowPath(
                        \CCrmOwnerType::Deal,
                        (int)$openDeal['ID'],
                        false
                    ),
                ]
            )
            : (string)Loc::getMessage('OTUS_AUTOSERVICE_DEAL_ERROR_OPEN_ORDER_GENERIC');

        $result->addError(new Error($errorMessage, self::ERROR_OPEN_ORDER_EXISTS));
        $result->setData(
            [
                'CAR_ID' => $carId,
                'CATEGORY_ID' => $candidateCategoryId,
                'ASSIGNED_BY_ID' => (int)($candidateFields['ASSIGNED_BY_ID'] ?? 0),
                'OPEN_DEAL' => $openDeal,
                'CAN_READ_OPEN_DEAL' => $canReadOpenDeal,
            ]
        );

        return $result;
    }

    /**
     * Возвращает только поля текущей сделки, необходимые для построения кандидата.
     *
     * @return array<string, mixed>|null Найденная сделка либо null.
     */
    private function findDealById(int $dealId): ?array
    {
        /** @var string $carFieldName Код поля автомобиля для динамического select. */
        $carFieldName = ModuleConfiguration::getDealCarFieldName();

        /** @var \CDBResult $queryResult Результат точечной выборки без пользовательского фильтра прав. */
        $queryResult = \CCrmDeal::GetListEx(
            [],
            [
                '=ID' => $dealId,
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            ['nTopCount' => 1],
            [
                'ID',
                'CATEGORY_ID',
                'STAGE_ID',
                'STAGE_SEMANTIC_ID',
                'CONTACT_ID',
                'ASSIGNED_BY_ID',
                $carFieldName,
            ]
        );

        /** @var array<string, mixed>|false $deal Текущие поля сделки. */
        $deal = $queryResult->Fetch();

        return $deal === false ? null : $deal;
    }

    /**
     * Ищет не более одной другой сделки в нефинальной стадии.
     *
     * @return array<string, mixed>|null Минимальные реквизиты блокирующей сделки.
     */
    private function findOpenDeal(
        int $categoryId,
        string $carFieldName,
        int $carId,
        int $excludedDealId
    ): ?array {
        /** @var array<string, mixed> $filter Индексируемые условия серверной проверки. */
        $filter = [
            '=CATEGORY_ID' => $categoryId,
            '=' . $carFieldName => $carId,
            '=STAGE_SEMANTIC_ID' => PhaseSemantics::PROCESS,
            'CHECK_PERMISSIONS' => 'N',
        ];

        if ($excludedDealId > 0) {
            $filter['!ID'] = $excludedDealId;
        }

        /** @var \CDBResult $queryResult Ограниченная одним элементом выборка CRM. */
        $queryResult = \CCrmDeal::GetListEx(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID', 'TITLE', 'STAGE_ID', 'ASSIGNED_BY_ID']
        );

        /** @var array<string, mixed>|false $deal Первая блокирующая сделка. */
        $deal = $queryResult->Fetch();

        return $deal === false ? null : $deal;
    }

    /**
     * Проверяет право конкретного пользователя читать найденную сделку.
     */
    private function canReadDeal(int $dealId, int $categoryId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        /** @var \CCrmPerms $permissions Объект прав инициатора операции. */
        $permissions = \CCrmPerms::GetUserPermissions($userId);

        return (bool)\CCrmDeal::CheckReadPermission($dealId, $permissions, $categoryId);
    }

    /**
     * Удаляет управляющие символы и HTML из заголовка перед включением в ошибку.
     */
    private function preparePlainText(string $value): string
    {
        /** @var string $plainText Заголовок без HTML-тегов и переводов строк. */
        $plainText = strip_tags($value);
        $plainText = str_replace(["\r", "\n", "\t"], ' ', $plainText);

        return trim($plainText);
    }
}
