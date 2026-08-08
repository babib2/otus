<?php

/**
 * Реализует прикладные операции с автомобилями и нормализует входные данные.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Crm\ContactTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Otus\Autoservice\Cache\CarListCache;
use Otus\Autoservice\Logger\ModuleLogger;
use Otus\Autoservice\Repository\CarRepository;

Loc::loadMessages(__FILE__);

/**
 * Сервис управления автомобилями клиентов автосервиса.
 *
 * Все публичные методы принимают названия полей в формате D7 (`CONTACT_ID`,
 * `LICENSE_PLATE` и так далее). Технические поля ID и дат от пользователя
 * игнорируются, чтобы их формировал репозиторий и база данных.
 */
final class CarService
{
    /** Наибольшее значение стандартного знакового INTEGER в таблицах Bitrix. */
    private const MAXIMUM_INTEGER_VALUE = 2147483647;

    /** Наименьшее значение стандартного знакового INTEGER в таблицах Bitrix. */
    private const MINIMUM_INTEGER_VALUE = -2147483648;

    /**
     * Поля автомобиля, разрешённые для массового заполнения извне.
     *
     * @var string[]
     */
    private const WRITABLE_FIELDS = [
        'CONTACT_ID',
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'YEAR',
        'COLOR',
        'MILEAGE',
        'ACTIVE',
    ];

    /**
     * Поля, по которым GRID имеет право передавать сортировку в ORM.
     *
     * @var string[]
     */
    private const SORTABLE_FIELDS = [
        'ID',
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'YEAR',
        'COLOR',
        'MILEAGE',
        'ACTIVE',
    ];

    /** @var CarRepository Репозиторий, выполняющий операции D7 ORM. */
    private $repository;

    /**
     * Принимает репозиторий извне для тестирования или создаёт стандартный.
     */
    public function __construct(?CarRepository $repository = null)
    {
        $this->repository = $repository ?? new CarRepository();
    }

    /**
     * Создаёт автомобиль клиента.
     *
     * @param array<string, mixed> $data   Пользовательские поля автомобиля.
     * @param int                  $userId Пользователь Bitrix, создающий запись.
     * @param string               $originId Инициировавший изменение клиентский экземпляр.
     */
    public function create(array $data, int $userId, string $originId = ''): AddResult
    {
        /** @var Error|null $inputError Ошибка типа пользовательского значения до нормализации. */
        $inputError = $this->validateWritableFieldTypes($data);
        if ($inputError !== null) {
            return $this->createFailedAddResult($inputError);
        }

        /** @var array<string, mixed> $fields Разрешённые и нормализованные поля ORM. */
        $fields = $this->prepareFields($data);
        $fields['CREATED_BY'] = max(0, $userId);
        $fields['UPDATED_BY'] = max(0, $userId);

        /** @var int $contactId Идентификатор владельца создаваемого автомобиля. */
        $contactId = (int)($fields['CONTACT_ID'] ?? 0);
        if (!$this->contactExists($contactId)) {
            /** @var AddResult $invalidContactResult Результат без обращения к ORM-таблице автомобиля. */
            $invalidContactResult = new AddResult();
            $invalidContactResult->addError(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_CONTACT_NOT_FOUND'))
            );

            return $invalidContactResult;
        }

        /** @var AddResult $result Результат создания ORM-записи автомобиля. */
        $result = $this->repository->add($fields);
        if ($result->isSuccess()) {
            $this->afterChange(
                $contactId,
                (int)$result->getId(),
                'create',
                max(0, $userId),
                $originId
            );
        }

        return $result;
    }

    /**
     * Создаёт автомобиль в гараже указанного контакта, игнорируя подмену владельца во входных данных.
     *
     * @param int                  $contactId Владелец автомобиля из контекста карточки CRM.
     * @param array<string, mixed> $data      Поля формы автомобиля.
     * @param int                  $userId    Пользователь, выполняющий действие.
     * @param string               $originId  Инициировавший изменение клиентский экземпляр.
     */
    public function createForContact(
        int $contactId,
        array $data,
        int $userId,
        string $originId = ''
    ): AddResult
    {
        // Новая запись всегда активна; состояние архива не принимается из AJAX-формы.
        unset($data['ACTIVE']);
        $data['CONTACT_ID'] = $contactId;

        return $this->create($data, $userId, $originId);
    }

    /**
     * Изменяет разрешённые поля существующего автомобиля.
     *
     * @param int                  $id     Идентификатор автомобиля.
     * @param array<string, mixed> $data   Изменяемые пользовательские поля.
     * @param int                  $userId Пользователь Bitrix, изменяющий запись.
     * @param string               $originId Инициировавший изменение клиентский экземпляр.
     */
    public function update(int $id, array $data, int $userId, string $originId = ''): UpdateResult
    {
        /** @var Error|null $inputError Ошибка типа пользовательского значения до нормализации. */
        $inputError = $this->validateWritableFieldTypes($data);
        if ($inputError !== null) {
            return $this->createFailedUpdateResult($inputError);
        }

        /** @var array<string, mixed>|null $currentCar Запись до изменения для очистки прежнего кеша. */
        $currentCar = $this->repository->findById($id);

        /** @var array<string, mixed> $fields Разрешённые и нормализованные поля ORM. */
        $fields = $this->prepareFields($data);
        $fields['UPDATED_BY'] = max(0, $userId);

        if (isset($fields['CONTACT_ID']) && !$this->contactExists((int)$fields['CONTACT_ID'])) {
            return $this->createFailedUpdateResult(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_CONTACT_NOT_FOUND')
            );
        }

        /** @var UpdateResult $result Результат изменения ORM-записи автомобиля. */
        $result = $this->repository->update($id, $fields);
        if ($result->isSuccess()) {
            /** @var int $oldContactId Владелец автомобиля до изменения. */
            $oldContactId = (int)($currentCar['CONTACT_ID'] ?? 0);

            /** @var int $newContactId Владелец автомобиля после изменения. */
            $newContactId = isset($fields['CONTACT_ID'])
                ? (int)$fields['CONTACT_ID']
                : $oldContactId;

            $this->afterChange($newContactId, $id, 'update', max(0, $userId), $originId);
            if ($oldContactId > 0 && $oldContactId !== $newContactId) {
                CarListCache::clearByContact($oldContactId);
                CarPullService::publish($oldContactId, $id, 'update', $originId);
            }
        }

        return $result;
    }

    /**
     * Изменяет автомобиль только при его принадлежности контакту открытой карточки.
     *
     * @param int                  $id        Идентификатор изменяемого автомобиля.
     * @param int                  $contactId Ожидаемый владелец из карточки CRM.
     * @param array<string, mixed> $data      Поля формы без разрешения сменить владельца.
     * @param int                  $userId    Пользователь, выполняющий изменение.
     * @param string               $originId  Инициировавший изменение клиентский экземпляр.
     */
    public function updateForContact(
        int $id,
        int $contactId,
        array $data,
        int $userId,
        string $originId = ''
    ): UpdateResult {
        /** @var array<string, mixed>|null $car Проверяемый автомобиль из отдельной ORM-сущности. */
        $car = $this->repository->findById($id);
        if ($car === null || (int)$car['CONTACT_ID'] !== $contactId) {
            return $this->createFailedUpdateResult(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_NOT_FOUND_FOR_CONTACT'))
            );
        }

        unset($data['CONTACT_ID'], $data['ACTIVE']);

        return $this->update($id, $data, $userId, $originId);
    }

    /**
     * Деактивирует автомобиль без физического удаления истории.
     *
     * @param int    $id       Идентификатор автомобиля.
     * @param int    $userId   Пользователь, выполняющий архивирование.
     * @param string $originId Инициировавший изменение клиентский экземпляр.
     */
    public function deactivate(int $id, int $userId, string $originId = ''): UpdateResult
    {
        /** @var array<string, mixed>|null $car Автомобиль до архивирования для адресной очистки кеша. */
        $car = $this->repository->findById($id);

        if (
            $car !== null
            && (string)($car['ACTIVE'] ?? 'N') === 'Y'
            && (new DealOpenOrderService($this->repository))->hasOpenOrderForCar($id)
        ) {
            return $this->createFailedUpdateResult(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_OPEN_ORDER_EXISTS'),
                    'CAR_OPEN_ORDER_EXISTS'
                )
            );
        }

        /** @var UpdateResult $result Результат мягкого удаления автомобиля. */
        $result = $this->repository->deactivate($id, max(0, $userId));
        if ($result->isSuccess() && $car !== null) {
            $this->afterChange(
                (int)$car['CONTACT_ID'],
                $id,
                'archive',
                max(0, $userId),
                $originId
            );
        }

        return $result;
    }

    /**
     * Архивирует автомобиль только в гараже его фактического владельца.
     *
     * @param int $id        Идентификатор автомобиля.
     * @param int $contactId Контакт открытой карточки CRM.
     * @param int $userId    Пользователь, выполняющий архивирование.
     * @param string $originId Инициировавший изменение клиентский экземпляр.
     */
    public function deactivateForContact(
        int $id,
        int $contactId,
        int $userId,
        string $originId = ''
    ): UpdateResult {
        /** @var array<string, mixed>|null $car Проверяемый автомобиль контакта. */
        $car = $this->repository->findById($id);
        if ($car === null || (int)$car['CONTACT_ID'] !== $contactId) {
            return $this->createFailedUpdateResult(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_NOT_FOUND_FOR_CONTACT'))
            );
        }

        return $this->deactivate($id, $userId, $originId);
    }

    /**
     * Возвращает автомобиль по идентификатору.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * Ищет автомобиль по номеру в любом поддерживаемом пользовательском формате.
     *
     * @return array<string, mixed>|null
     */
    public function getByLicensePlate(string $licensePlate): ?array
    {
        return $this->repository->findByLicensePlate(
            $this->normalizeLicensePlate($licensePlate)
        );
    }

    /**
     * Возвращает активные автомобили указанного контакта CRM.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveByContact(int $contactId): array
    {
        return $this->repository->findActiveByContact($contactId);
    }

    /**
     * Возвращает кешируемую страницу автомобилей одного контакта для стандартного GRID.
     *
     * @param int                  $contactId Идентификатор владельца автомобилей.
     * @param array<string, mixed> $filter    Значения стандартного фильтра Bitrix.
     * @param array<string, mixed> $order     Запрошенная пользователем сортировка.
     * @param int                  $limit     Размер страницы.
     * @param int                  $offset    Смещение страницы.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getPageByContact(
        int $contactId,
        array $filter,
        array $order,
        int $limit,
        int $offset
    ): array {
        /** @var array<string, mixed> $ormFilter Безопасный ORM-фильтр с обязательным владельцем. */
        $ormFilter = $this->prepareListFilter($contactId, $filter);

        /** @var array<string, string> $ormOrder Сортировка после применения белого списка. */
        $ormOrder = $this->prepareListOrder($order);

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return CarListCache::remember(
            $contactId,
            [
                'filter' => $ormFilter,
                'order' => $ormOrder,
                'limit' => $limit,
                'offset' => $offset,
            ],
            function () use ($ormFilter, $ormOrder, $limit, $offset): array {
                return [
                    'items' => $this->repository->findPage($ormFilter, $ormOrder, $limit, $offset),
                    'total' => $this->repository->countByFilter($ormFilter),
                ];
            }
        );
    }

    /**
     * Приводит государственный номер к единому виду для хранения и поиска.
     *
     * Удаляются пробелы и дефисы, буквы переводятся в верхний регистр. Другие
     * символы пока сохраняются, чтобы не блокировать иностранные форматы номеров.
     */
    public function normalizeLicensePlate(string $licensePlate): string
    {
        /** @var string $upperCasePlate Номер после удаления внешних пробелов и смены регистра. */
        $upperCasePlate = mb_strtoupper(trim($licensePlate), 'UTF-8');

        /** @var string|null $normalizedPlate Результат удаления внутренних разделителей. */
        $normalizedPlate = preg_replace('/[\s\-]+/u', '', $upperCasePlate);

        return $normalizedPlate ?? $upperCasePlate;
    }

    /**
     * Оставляет разрешённые поля и приводит их к типам ORM.
     *
     * @param array<string, mixed> $data Исходные данные контроллера или REST-метода.
     *
     * @return array<string, mixed> Безопасный набор полей для репозитория.
     */
    private function prepareFields(array $data): array
    {
        /** @var array<string, bool> $allowedFieldMap Карта полей для быстрого фильтра. */
        $allowedFieldMap = array_fill_keys(self::WRITABLE_FIELDS, true);

        /** @var array<string, mixed> $fields Данные без технических и неизвестных полей. */
        $fields = array_intersect_key($data, $allowedFieldMap);

        /** @var string $stringField Очередное текстовое поле, очищаемое от внешних пробелов. */
        foreach (['MAKE', 'MODEL', 'COLOR'] as $stringField) {
            if (array_key_exists($stringField, $fields)) {
                $fields[$stringField] = trim((string)$fields[$stringField]);
            }
        }

        if (array_key_exists('LICENSE_PLATE', $fields)) {
            $fields['LICENSE_PLATE'] = $this->normalizeLicensePlate(
                (string)$fields['LICENSE_PLATE']
            );
        }

        if (array_key_exists('CONTACT_ID', $fields)) {
            $fields['CONTACT_ID'] = $this->normalizeIntegerValue(
                $fields['CONTACT_ID'],
                0
            );
        }

        if (array_key_exists('YEAR', $fields)) {
            $fields['YEAR'] = $fields['YEAR'] === '' || $fields['YEAR'] === null
                ? null
                : $this->normalizeIntegerValue($fields['YEAR'], 0);
        }

        if (array_key_exists('COLOR', $fields) && $fields['COLOR'] === '') {
            $fields['COLOR'] = null;
        }

        if (array_key_exists('MILEAGE', $fields)) {
            $fields['MILEAGE'] = $this->normalizeIntegerValue(
                $fields['MILEAGE'],
                -1
            );
        }

        if (array_key_exists('ACTIVE', $fields)) {
            $fields['ACTIVE'] = in_array($fields['ACTIVE'], ['N', 0, '0', false], true)
                ? 'N'
                : 'Y';
        }

        return $fields;
    }

    /**
     * Отклоняет сложные и неподдерживаемые значения до приведения типов PHP.
     *
     * Без этой проверки массив превращается в строку `Array`, а логическое
     * значение — в `1` или пустую строку, что позволяет сохранить данные,
     * отличающиеся от фактически переданных пользователем.
     *
     * @param array<string, mixed> $data Исходные значения контроллера или REST-метода.
     */
    private function validateWritableFieldTypes(array $data): ?Error
    {
        /** @var array<string, string> $stringFieldTitles Локализованные названия строковых полей. */
        $stringFieldTitles = [
            'MAKE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_MAKE'),
            'MODEL' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_MODEL'),
            'LICENSE_PLATE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_LICENSE_PLATE'),
            'COLOR' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_FIELD_COLOR'),
        ];

        /** @var string $fieldName Имя проверяемого строкового поля. */
        /** @var string $fieldTitle Название поля для безопасного сообщения пользователю. */
        foreach ($stringFieldTitles as $fieldName => $fieldTitle) {
            if (!array_key_exists($fieldName, $data)) {
                continue;
            }

            /** @var mixed $value Значение до любого неявного преобразования PHP. */
            $value = $data[$fieldName];
            if (is_string($value) || ($fieldName === 'COLOR' && $value === null)) {
                continue;
            }

            return new Error(
                (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_CAR_ERROR_STRING_EXPECTED',
                    ['#FIELD#' => $fieldTitle]
                ),
                'CAR_INVALID_FIELD_TYPE'
            );
        }

        if (
            array_key_exists('ACTIVE', $data)
            && !in_array($data['ACTIVE'], ['Y', 'N', 1, 0, '1', '0', true, false], true)
        ) {
            return new Error(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ERROR_ACTIVE_INVALID'),
                'CAR_INVALID_ACTIVE_VALUE'
            );
        }

        return null;
    }

    /**
     * Принимает только настоящее целое число или строку из необязательного минуса и цифр.
     *
     * Значение ошибки специально передаётся валидатору ORM: ноль недопустим для
     * контакта и года, а минус один — для пробега. Благодаря этому дроби,
     * логические значения и строки с посторонними символами не округляются PHP.
     *
     * @param mixed $value        Исходное значение контроллера или будущего REST-метода.
     * @param int   $invalidValue Значение, которое гарантированно отклонит ORM-поле.
     */
    private function normalizeIntegerValue(mixed $value, int $invalidValue): int
    {
        if (is_int($value)) {
            return $value >= self::MINIMUM_INTEGER_VALUE && $value <= self::MAXIMUM_INTEGER_VALUE
                ? $value
                : $invalidValue;
        }

        if (is_string($value)) {
            /** @var string $trimmedValue Строка без внешних пробелов перед строгой проверкой. */
            $trimmedValue = trim($value);
            if (preg_match('/^-?\d+$/D', $trimmedValue) === 1) {
                /** @var int|false $validatedValue Целое число в диапазоне текущей PHP-платформы. */
                $validatedValue = filter_var($trimmedValue, FILTER_VALIDATE_INT);
                if (
                    $validatedValue !== false
                    && $validatedValue >= self::MINIMUM_INTEGER_VALUE
                    && $validatedValue <= self::MAXIMUM_INTEGER_VALUE
                ) {
                    return $validatedValue;
                }
            }
        }

        return $invalidValue;
    }

    /**
     * Преобразует значения UI-фильтра в ограниченный ORM-фильтр одного контакта.
     *
     * @param int                  $contactId Обязательный владелец всех возвращаемых строк.
     * @param array<string, mixed> $filter    Значения компонента main.ui.filter.
     *
     * @return array<string|int, mixed> ORM-фильтр без пользовательских имён колонок.
     */
    private function prepareListFilter(int $contactId, array $filter): array
    {
        /** @var array<string|int, mixed> $ormFilter Начальный фильтр, исключающий чужие автомобили. */
        $ormFilter = ['=CONTACT_ID' => max(0, $contactId)];

        /** @var string $search Общая строка поиска по названию и номеру автомобиля. */
        $search = trim((string)($filter['FIND'] ?? ''));
        if ($search !== '') {
            $ormFilter[] = [
                'LOGIC' => 'OR',
                '%MAKE' => $search,
                '%MODEL' => $search,
                '%LICENSE_PLATE' => $this->normalizeLicensePlate($search),
            ];
        }

        /** @var string $fieldName Имя очередного текстового поля из фиксированного списка. */
        foreach (['MAKE', 'MODEL', 'LICENSE_PLATE', 'COLOR'] as $fieldName) {
            /** @var string $value Очищенное значение конкретного текстового фильтра. */
            $value = trim((string)($filter[$fieldName] ?? ''));
            if ($value !== '') {
                $ormFilter['%' . $fieldName] = $fieldName === 'LICENSE_PLATE'
                    ? $this->normalizeLicensePlate($value)
                    : $value;
            }
        }

        /** @var string $numericField Имя числового поля с поддержкой диапазона. */
        foreach (['YEAR', 'MILEAGE'] as $numericField) {
            if (($filter[$numericField . '_from'] ?? '') !== '') {
                $ormFilter['>=' . $numericField] = (int)$filter[$numericField . '_from'];
            }
            if (($filter[$numericField . '_to'] ?? '') !== '') {
                $ormFilter['<=' . $numericField] = (int)$filter[$numericField . '_to'];
            }
        }

        /** @var string $activeFilter Запрошенное состояние активности автомобиля. */
        $activeFilter = (string)($filter['ACTIVE'] ?? '');
        if (in_array($activeFilter, ['Y', 'N'], true)) {
            $ormFilter['=ACTIVE'] = $activeFilter;
        }

        return $ormFilter;
    }

    /**
     * Оставляет не более одного разрешённого поля сортировки GRID.
     *
     * @param array<string, mixed> $order Сортировка из пользовательских настроек GRID.
     *
     * @return array<string, string> Безопасная сортировка ORM.
     */
    private function prepareListOrder(array $order): array
    {
        /** @var string|int $fieldName Запрошенное имя сортируемой колонки. */
        /** @var mixed $direction Запрошенное направление сортировки. */
        foreach ($order as $fieldName => $direction) {
            $fieldName = (string)$fieldName;
            if (!in_array($fieldName, self::SORTABLE_FIELDS, true)) {
                continue;
            }

            return [
                $fieldName => strtoupper((string)$direction) === 'ASC' ? 'ASC' : 'DESC',
            ];
        }

        return ['ID' => 'DESC'];
    }

    /**
     * Проверяет существование контакта через CRM D7 ORM до создания внешней связи.
     *
     * @param int $contactId Проверяемый идентификатор контакта.
     */
    private function contactExists(int $contactId): bool
    {
        if ($contactId <= 0 || !Loader::includeModule('crm')) {
            return false;
        }

        return ContactTable::getByPrimary(
            $contactId,
            ['select' => ['ID']]
        )->fetch() !== false;
    }

    /**
     * Создаёт AddResult с прикладной ошибкой без изменения базы данных.
     *
     * @param Error $error Готовая локализованная ошибка с машинным кодом.
     */
    private function createFailedAddResult(Error $error): AddResult
    {
        /** @var AddResult $result Ошибочный результат, совместимый с операцией создания. */
        $result = new AddResult();
        $result->addError($error);

        return $result;
    }

    /**
     * Создаёт UpdateResult с прикладной ошибкой без изменения базы данных.
     *
     * @param string|Error $error Локализованный текст либо готовая ошибка с машинным кодом.
     */
    private function createFailedUpdateResult(string|Error $error): UpdateResult
    {
        /** @var UpdateResult $result Ошибочный результат совместимого типа D7. */
        $result = new UpdateResult();
        $result->addError($error instanceof Error ? $error : new Error($error));

        return $result;
    }

    /**
     * Очищает кеш, пишет аудит и уведомляет другие открытые вкладки после успешного изменения.
     *
     * @param int    $contactId Владелец изменённого автомобиля.
     * @param int    $carId     Идентификатор изменённой записи.
     * @param string $operation Машинное имя выполненной операции.
     * @param int    $userId    Пользователь, выполнивший действие.
     * @param string $originId  Инициировавший изменение клиентский экземпляр.
     */
    private function afterChange(
        int $contactId,
        int $carId,
        string $operation,
        int $userId,
        string $originId = ''
    ): void {
        CarListCache::clearByContact($contactId);

        /** @var array<string, string> $auditTypes Соответствие операции типу системного аудита. */
        $auditTypes = [
            'create' => ModuleLogger::AUDIT_CAR_CREATED,
            'update' => ModuleLogger::AUDIT_CAR_UPDATED,
            'archive' => ModuleLogger::AUDIT_CAR_ARCHIVED,
        ];

        ModuleLogger::info(
            $auditTypes[$operation] ?? ModuleLogger::AUDIT_CAR_UPDATED,
            (string)$carId,
            [
                'contact_id' => $contactId,
                'user_id' => $userId,
                'operation' => $operation,
            ]
        );

        CarPullService::publish($contactId, $carId, $operation, $originId);
    }
}
