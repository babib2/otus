<?php

/**
 * Публикует защищённые REST-методы CRUD для автомобилей CRM-контактов.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Rest;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Rest\RestException;
use CRestServer;
use Otus\Autoservice\Logger\ModuleLogger;
use Otus\Autoservice\Service\CarService;
use Otus\Autoservice\Service\ModuleConfiguration;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Регистрирует REST-контракт автомобилей и связывает его с существующим CarService.
 *
 * Класс не доверяет идентификаторам, полям и правам внешнего клиента. Каждый
 * метод проверяет отдельный scope, внешний OAuth/webhook-контекст, пользователя
 * токена, CRM-право на контакт и принадлежность автомобиля этому контакту.
 */
final class CarRestService
{
    /** Отдельное REST-разрешение приложения или входящего вебхука. */
    public const SCOPE = 'otus.autoservice';

    /** Имена публичных REST-методов, составляющих контракт интеграции. */
    public const METHOD_LIST = 'otus.autoservice.car.list';
    public const METHOD_GET = 'otus.autoservice.car.get';
    public const METHOD_ADD = 'otus.autoservice.car.add';
    public const METHOD_UPDATE = 'otus.autoservice.car.update';
    public const METHOD_DELETE = 'otus.autoservice.car.delete';

    /** Стабильные коды ошибок, по которым интеграция может выбирать сценарий обработки. */
    public const ERROR_MODULE_DISABLED = 'CAR_REST_MODULE_DISABLED';
    public const ERROR_SCOPE_DENIED = 'CAR_REST_SCOPE_DENIED';
    public const ERROR_EXTERNAL_CONTEXT_REQUIRED = 'CAR_REST_EXTERNAL_CONTEXT_REQUIRED';
    public const ERROR_AUTH_REQUIRED = 'CAR_REST_AUTH_REQUIRED';
    public const ERROR_ACCESS_DENIED = 'CAR_REST_ACCESS_DENIED';
    public const ERROR_INVALID_ARGUMENT = 'CAR_REST_INVALID_ARGUMENT';
    public const ERROR_CAR_NOT_FOUND = 'CAR_REST_CAR_NOT_FOUND';
    public const ERROR_INTERNAL = 'CAR_REST_INTERNAL_ERROR';

    /** HTTP-статус конфликта с незакрытым сервисным заказом. */
    private const STATUS_CONFLICT = '409 Conflict';

    /** Размер страницы по умолчанию и жёсткий предел одной REST-выборки. */
    private const DEFAULT_LIMIT = 50;
    private const MAXIMUM_LIMIT = 100;

    /** Ограничение глубины offset-захода, защищающее базу от чрезмерного сканирования. */
    private const MAXIMUM_OFFSET = 100000;

    /** Наибольшее значение INTEGER, совместимое с идентификаторами и полями ORM. */
    private const MAXIMUM_INTEGER_VALUE = 2147483647;

    /** Поля автомобиля, которые внешний клиент имеет право записывать. */
    private const WRITABLE_FIELDS = [
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'YEAR',
        'COLOR',
        'MILEAGE',
    ];

    /** Фильтры списка, переводимые в закрытый формат CarService. */
    private const FILTER_FIELDS = [
        'FIND',
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'COLOR',
        'YEAR_FROM',
        'YEAR_TO',
        'MILEAGE_FROM',
        'MILEAGE_TO',
        'ACTIVE',
    ];

    /** Поля, по которым разрешена сортировка REST-списка. */
    private const ORDER_FIELDS = [
        'ID',
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'YEAR',
        'COLOR',
        'MILEAGE',
        'ACTIVE',
    ];

    /** Поля ORM, которые разрешено возвращать внешней системе. */
    private const RESPONSE_FIELDS = [
        'ID',
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
     * Возвращает описание scope и методов для события OnRestServiceBuildDescription.
     *
     * @return array<string, array<string, callable>> Описание сервиса в формате REST-модуля Bitrix.
     */
    public static function onRestServiceBuildDescription(): array
    {
        return [
            self::SCOPE => [
                self::METHOD_LIST => [self::class, 'listCars'],
                self::METHOD_GET => [self::class, 'getCar'],
                self::METHOD_ADD => [self::class, 'addCar'],
                self::METHOD_UPDATE => [self::class, 'updateCar'],
                self::METHOD_DELETE => [self::class, 'deleteCar'],
            ],
        ];
    }

    /**
     * Возвращает одну страницу автомобилей выбранного контакта.
     *
     * @param array<string, mixed> $query  Параметры REST-запроса без служебного `start`.
     * @param int                  $start  Смещение, выделенное REST-сервером Bitrix.
     * @param CRestServer          $server Авторизованный контекст приложения или вебхука.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, next?: int}
     */
    public static function listCars(array $query, int $start, CRestServer $server): array
    {
        return self::execute(
            self::METHOD_LIST,
            $server,
            static function (int $userId, string $originId) use ($query, $start): array {
                /** @var array<string, mixed> $parameters Параметры с единым регистром имён. */
                $parameters = self::normalizeParameterKeys($query, 'query');
                self::assertAllowedParameters($parameters, ['CONTACT_ID', 'FILTER', 'ORDER', 'LIMIT']);

                /** @var int $contactId CRM-контакт, ограничивающий всю выборку. */
                $contactId = self::requirePositiveInteger($parameters, 'CONTACT_ID');
                self::ensureContactPermission($contactId, $userId, false);

                /** @var array<string, mixed> $filter Проверенный фильтр прикладного сервиса. */
                $filter = self::prepareFilter($parameters['FILTER'] ?? []);

                /** @var array<string, string> $order Проверенная сортировка не более чем по одному полю. */
                $order = self::prepareOrder($parameters['ORDER'] ?? []);

                /** @var int $limit Размер возвращаемой страницы. */
                $limit = array_key_exists('LIMIT', $parameters)
                    ? self::normalizeBoundedInteger($parameters['LIMIT'], 'LIMIT', 1, self::MAXIMUM_LIMIT)
                    : self::DEFAULT_LIMIT;

                if ($start < 0 || $start > self::MAXIMUM_OFFSET) {
                    self::throwInvalidArgument('START');
                }

                /** @var array{items: array<int, array<string, mixed>>, total: int} $page Страница CarService. */
                $page = (new CarService())->getPageByContact(
                    $contactId,
                    $filter,
                    $order,
                    $limit,
                    $start
                );

                /** @var array<int, array<string, mixed>> $items Безопасные данные без полей аудита и дат. */
                $items = array_map(
                    static fn(array $car): array => self::formatCar($car),
                    $page['items']
                );

                /** @var array{items: array<int, array<string, mixed>>, total: int, next?: int} $response REST-страница. */
                $response = [
                    'items' => $items,
                    'total' => max(0, (int)$page['total']),
                ];

                /** @var int $next Смещение следующей страницы. */
                $next = $start + count($items);
                if ($items !== [] && $next < $response['total']) {
                    $response['next'] = $next;
                }

                return $response;
            }
        );
    }

    /**
     * Возвращает один автомобиль после проверки контакта-владельца.
     *
     * @param array<string, mixed> $query  Параметры CONTACT_ID и ID.
     * @param int                  $start  Неиспользуемое смещение стандартной REST-сигнатуры.
     * @param CRestServer          $server Авторизованный внешний контекст.
     */
    public static function getCar(array $query, int $start, CRestServer $server): array
    {
        return self::execute(
            self::METHOD_GET,
            $server,
            static function (int $userId, string $originId) use ($query): array {
                /** @var array<string, mixed> $parameters Проверенные верхнеуровневые параметры. */
                $parameters = self::normalizeParameterKeys($query, 'query');
                self::assertAllowedParameters($parameters, ['CONTACT_ID', 'ID']);

                /** @var int $contactId Ожидаемый владелец автомобиля. */
                $contactId = self::requirePositiveInteger($parameters, 'CONTACT_ID');
                /** @var int $carId Запрошенный идентификатор автомобиля. */
                $carId = self::requirePositiveInteger($parameters, 'ID');
                self::ensureContactPermission($contactId, $userId, false);

                return self::formatCar(self::requireOwnedCar($carId, $contactId));
            }
        );
    }

    /**
     * Создаёт активный автомобиль в гараже контакта.
     *
     * @param array<string, mixed> $query  Параметры CONTACT_ID и FIELDS.
     * @param int                  $start  Неиспользуемое смещение стандартной REST-сигнатуры.
     * @param CRestServer          $server Авторизованный внешний контекст.
     */
    public static function addCar(array $query, int $start, CRestServer $server): array
    {
        return self::execute(
            self::METHOD_ADD,
            $server,
            static function (int $userId, string $originId) use ($query, $server): array {
                /** @var array<string, mixed> $parameters Проверенные верхнеуровневые параметры. */
                $parameters = self::normalizeParameterKeys($query, 'query');
                self::assertAllowedParameters($parameters, ['CONTACT_ID', 'FIELDS']);

                /** @var int $contactId Контакт, которому всегда назначается новая запись. */
                $contactId = self::requirePositiveInteger($parameters, 'CONTACT_ID');
                self::ensureContactPermission($contactId, $userId, true);

                /** @var array<string, mixed> $fields Разрешённые поля без владельца и технических значений. */
                $fields = self::prepareWritableFields($parameters['FIELDS'] ?? null, false);

                /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат бизнес-валидации и ORM. */
                $result = (new CarService())->createForContact(
                    $contactId,
                    $fields,
                    $userId,
                    $originId
                );
                self::throwResultError($result);

                /** @var int $carId Идентификатор созданной записи. */
                $carId = (int)$result->getId();
                $server->setStatus(CRestServer::STATUS_CREATED);

                return self::formatCar(self::requireOwnedCar($carId, $contactId));
            }
        );
    }

    /**
     * Изменяет разрешённые поля автомобиля без смены владельца и состояния архива.
     *
     * @param array<string, mixed> $query  Параметры CONTACT_ID, ID и FIELDS.
     * @param int                  $start  Неиспользуемое смещение стандартной REST-сигнатуры.
     * @param CRestServer          $server Авторизованный внешний контекст.
     */
    public static function updateCar(array $query, int $start, CRestServer $server): array
    {
        return self::execute(
            self::METHOD_UPDATE,
            $server,
            static function (int $userId, string $originId) use ($query): array {
                /** @var array<string, mixed> $parameters Проверенные верхнеуровневые параметры. */
                $parameters = self::normalizeParameterKeys($query, 'query');
                self::assertAllowedParameters($parameters, ['CONTACT_ID', 'ID', 'FIELDS']);

                /** @var int $contactId Владелец, внутри гаража которого разрешено изменение. */
                $contactId = self::requirePositiveInteger($parameters, 'CONTACT_ID');
                /** @var int $carId Изменяемый автомобиль. */
                $carId = self::requirePositiveInteger($parameters, 'ID');
                self::ensureContactPermission($contactId, $userId, true);
                self::requireOwnedCar($carId, $contactId);

                /** @var array<string, mixed> $fields Непустой набор разрешённых изменений. */
                $fields = self::prepareWritableFields($parameters['FIELDS'] ?? null, true);

                /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат изменения через бизнес-сервис. */
                $result = (new CarService())->updateForContact(
                    $carId,
                    $contactId,
                    $fields,
                    $userId,
                    $originId
                );
                self::throwResultError($result);

                return self::formatCar(self::requireOwnedCar($carId, $contactId));
            }
        );
    }

    /**
     * Мягко удаляет автомобиль, если для него нет незакрытого сервисного заказа.
     *
     * @param array<string, mixed> $query  Параметры CONTACT_ID и ID.
     * @param int                  $start  Неиспользуемое смещение стандартной REST-сигнатуры.
     * @param CRestServer          $server Авторизованный внешний контекст.
     *
     * @return array{ID: int, ARCHIVED: bool}
     */
    public static function deleteCar(array $query, int $start, CRestServer $server): array
    {
        return self::execute(
            self::METHOD_DELETE,
            $server,
            static function (int $userId, string $originId) use ($query): array {
                /** @var array<string, mixed> $parameters Проверенные верхнеуровневые параметры. */
                $parameters = self::normalizeParameterKeys($query, 'query');
                self::assertAllowedParameters($parameters, ['CONTACT_ID', 'ID']);

                /** @var int $contactId Владелец архивируемой записи. */
                $contactId = self::requirePositiveInteger($parameters, 'CONTACT_ID');
                /** @var int $carId Архивируемый автомобиль. */
                $carId = self::requirePositiveInteger($parameters, 'ID');
                self::ensureContactPermission($contactId, $userId, true);
                self::requireOwnedCar($carId, $contactId);

                /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат мягкого удаления. */
                $result = (new CarService())->deactivateForContact(
                    $carId,
                    $contactId,
                    $userId,
                    $originId
                );
                self::throwResultError($result);

                return [
                    'ID' => $carId,
                    'ARCHIVED' => true,
                ];
            }
        );
    }

    /**
     * Выполняет общие проверки и скрывает непредвиденные внутренние исключения.
     *
     * @param string   $method   Имя вызываемого REST-метода для безопасного аудита.
     * @param callable $callback Прикладная операция с параметрами userId и originId.
     *
     * @return array<string, mixed> Результат прикладной REST-операции.
     */
    private static function execute(string $method, CRestServer $server, callable $callback): array
    {
        try {
            /** @var array{user_id: int, origin_id: string} $context Проверенный внешний контекст. */
            $context = self::requireExternalContext($server);

            return $callback($context['user_id'], $context['origin_id']);
        } catch (RestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            ModuleLogger::warning(
                ModuleLogger::AUDIT_CAR_REST_FAILED,
                $method,
                [
                    'method' => $method,
                    'exception_class' => get_class($exception),
                ]
            );

            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_INTERNAL_ERROR'),
                self::ERROR_INTERNAL,
                CRestServer::STATUS_INTERNAL
            );
        }
    }

    /**
     * Проверяет состояние модулей, scope, внешний канал и пользователя токена.
     *
     * @return array{user_id: int, origin_id: string} Безопасный контекст операции.
     */
    private static function requireExternalContext(CRestServer $server): array
    {
        if (!ModuleConfiguration::isEnabled()) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_MODULE_DISABLED'),
                self::ERROR_MODULE_DISABLED,
                CRestServer::STATUS_FORBIDDEN
            );
        }

        if (!Loader::includeModule('rest') || !Loader::includeModule('crm')) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_MODULES_REQUIRED'),
                self::ERROR_INTERNAL,
                CRestServer::STATUS_INTERNAL
            );
        }

        if ($server->getScope() !== self::SCOPE) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_SCOPE_DENIED'),
                self::ERROR_SCOPE_DENIED,
                CRestServer::STATUS_FORBIDDEN
            );
        }

        /** @var array<string, mixed> $authData Проверенные ядром данные авторизации. */
        $authData = $server->getAuthData();

        /** @var string $clientId Идентификатор OAuth-приложения, включая batch-подзапрос. */
        $clientId = trim((string)($server->getClientId() ?? ($authData['client_id'] ?? '')));

        /**
         * @var string $passwordId Идентификатор входящего вебхука.
         *
         * CRestServerBatchItem не переносит passwordId в своё защищённое свойство,
         * но сохраняет проверенное значение в authData родительского batch-запроса.
         */
        $passwordId = trim((string)($server->getPasswordId() ?? ($authData['password_id'] ?? '')));

        if ($clientId === '' && $passwordId === '') {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_EXTERNAL_CONTEXT_REQUIRED'),
                self::ERROR_EXTERNAL_CONTEXT_REQUIRED,
                CRestServer::STATUS_FORBIDDEN
            );
        }

        /** @var string $authScope Проверенные ядром разрешения OAuth-токена или вебхука. */
        $authScope = is_string($authData['scope'] ?? null)
            ? (string)$authData['scope']
            : '';

        /** @var string[] $authScopes Нормализованный набор разрешений внешнего клиента. */
        $authScopes = array_values(
            array_filter(
                array_map('trim', explode(',', $authScope)),
                static fn(string $scope): bool => $scope !== ''
            )
        );
        if (!in_array(self::SCOPE, $authScopes, true)) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_SCOPE_DENIED'),
                self::ERROR_SCOPE_DENIED,
                CRestServer::STATUS_FORBIDDEN
            );
        }

        /** @var int $userId Пользователь Bitrix, от имени которого работает интеграция. */
        $userId = self::normalizeInteger($authData['user_id'] ?? null);
        if ($userId <= 0) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_AUTH_REQUIRED'),
                self::ERROR_AUTH_REQUIRED,
                CRestServer::STATUS_UNAUTHORIZED
            );
        }

        return [
            'user_id' => $userId,
            'origin_id' => 'rest_' . substr(
                hash(
                    'sha256',
                    $clientId !== '' ? 'oauth:' . $clientId : 'webhook:' . $passwordId
                ),
                0,
                24
            ),
        ];
    }

    /**
     * Проверяет штатное CRM-право пользователя токена на конкретный контакт.
     */
    private static function ensureContactPermission(int $contactId, int $userId, bool $update): void
    {
        /** @var \Bitrix\Crm\Service\UserPermissions\Item $permissions Проверка предметных CRM-прав пользователя. */
        $permissions = Container::getInstance()->getUserPermissions($userId)->item();
        /** @var bool $allowed Разрешение чтения либо изменения контакта. */
        $allowed = $update
            ? $permissions->canUpdate(\CCrmOwnerType::Contact, $contactId)
            : $permissions->canRead(\CCrmOwnerType::Contact, $contactId);

        if ($allowed) {
            return;
        }

        ModuleLogger::warning(
            ModuleLogger::AUDIT_CAR_ACCESS_DENIED,
            (string)$contactId,
            [
                'contact_id' => $contactId,
                'user_id' => $userId,
                'channel' => 'rest',
                'required_permission' => $update ? 'update' : 'read',
            ]
        );

        throw new RestException(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_ACCESS_DENIED'),
            self::ERROR_ACCESS_DENIED,
            CRestServer::STATUS_FORBIDDEN
        );
    }

    /**
     * Возвращает автомобиль только при совпадении ожидаемого владельца.
     *
     * Одинаковый ответ для отсутствующей и чужой записи препятствует перебору ID.
     *
     * @return array<string, mixed> Найденная ORM-запись.
     */
    private static function requireOwnedCar(int $carId, int $contactId): array
    {
        /** @var array<string, mixed>|null $car Найденный автомобиль с техническими полями. */
        $car = (new CarService())->getById($carId);
        if ($car === null || (int)$car['CONTACT_ID'] !== $contactId) {
            throw new RestException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_CAR_NOT_FOUND'),
                self::ERROR_CAR_NOT_FOUND,
                CRestServer::STATUS_NOT_FOUND
            );
        }

        return $car;
    }

    /**
     * Проверяет и переводит REST-фильтр в имена, ожидаемые CarService.
     *
     * @param mixed $rawFilter Исходный параметр FILTER.
     *
     * @return array<string, mixed> Фильтр без произвольных ORM-операторов и полей.
     */
    private static function prepareFilter(mixed $rawFilter): array
    {
        if (!is_array($rawFilter)) {
            self::throwInvalidArgument('FILTER');
        }

        /** @var array<string, mixed> $filter Фильтр с единым регистром ключей. */
        $filter = self::normalizeParameterKeys($rawFilter, 'FILTER');
        self::assertAllowedParameters($filter, self::FILTER_FIELDS);

        /** @var array<string, mixed> $prepared Фильтр формата CarService. */
        $prepared = [];
        foreach (['FIND', 'MAKE', 'MODEL', 'LICENSE_PLATE', 'COLOR'] as $field) {
            if (!array_key_exists($field, $filter)) {
                continue;
            }
            if (!is_string($filter[$field])) {
                self::throwInvalidArgument('FILTER.' . $field);
            }
            $prepared[$field] = trim($filter[$field]);
        }

        foreach (
            [
                'YEAR_FROM' => 'YEAR_from',
                'YEAR_TO' => 'YEAR_to',
                'MILEAGE_FROM' => 'MILEAGE_from',
                'MILEAGE_TO' => 'MILEAGE_to',
            ] as $restField => $serviceField
        ) {
            if (array_key_exists($restField, $filter)) {
                $prepared[$serviceField] = self::normalizeBoundedInteger(
                    $filter[$restField],
                    'FILTER.' . $restField,
                    0,
                    self::MAXIMUM_INTEGER_VALUE
                );
            }
        }

        if (
            isset($prepared['YEAR_from'], $prepared['YEAR_to'])
            && $prepared['YEAR_from'] > $prepared['YEAR_to']
        ) {
            self::throwInvalidArgument('FILTER.YEAR_FROM');
        }
        if (
            isset($prepared['MILEAGE_from'], $prepared['MILEAGE_to'])
            && $prepared['MILEAGE_from'] > $prepared['MILEAGE_to']
        ) {
            self::throwInvalidArgument('FILTER.MILEAGE_FROM');
        }

        if (array_key_exists('ACTIVE', $filter)) {
            if (!is_string($filter['ACTIVE'])) {
                self::throwInvalidArgument('FILTER.ACTIVE');
            }
            /** @var string $active Нормализованное состояние записи. */
            $active = strtoupper(trim($filter['ACTIVE']));
            if (!in_array($active, ['Y', 'N'], true)) {
                self::throwInvalidArgument('FILTER.ACTIVE');
            }
            $prepared['ACTIVE'] = $active;
        }

        return $prepared;
    }

    /**
     * Проверяет сортировку: допускается одно поле и направление ASC/DESC.
     *
     * @param mixed $rawOrder Исходный параметр ORDER.
     *
     * @return array<string, string> Безопасная сортировка CarService.
     */
    private static function prepareOrder(mixed $rawOrder): array
    {
        if (!is_array($rawOrder)) {
            self::throwInvalidArgument('ORDER');
        }

        /** @var array<string, mixed> $order Сортировка с единым регистром поля. */
        $order = self::normalizeParameterKeys($rawOrder, 'ORDER');
        if (count($order) > 1) {
            self::throwInvalidArgument('ORDER');
        }
        self::assertAllowedParameters($order, self::ORDER_FIELDS);

        if ($order === []) {
            return ['ID' => 'DESC'];
        }

        /** @var string $field Единственное поле сортировки. */
        $field = (string)array_key_first($order);
        if (!is_string($order[$field])) {
            self::throwInvalidArgument('ORDER.' . $field);
        }

        /** @var string $direction Нормализованное направление сортировки. */
        $direction = strtoupper(trim($order[$field]));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            self::throwInvalidArgument('ORDER.' . $field);
        }

        return [$field => $direction];
    }

    /**
     * Проверяет набор полей создания или изменения автомобиля.
     *
     * @param mixed $rawFields       Исходный параметр FIELDS.
     * @param bool  $requireNonEmpty Требование хотя бы одного изменяемого поля.
     *
     * @return array<string, mixed> Поля, разрешённые бизнес-сервису.
     */
    private static function prepareWritableFields(mixed $rawFields, bool $requireNonEmpty): array
    {
        if (!is_array($rawFields)) {
            self::throwInvalidArgument('FIELDS');
        }

        /** @var array<string, mixed> $fields Поля с единым регистром имён. */
        $fields = self::normalizeParameterKeys($rawFields, 'FIELDS');
        self::assertAllowedParameters($fields, self::WRITABLE_FIELDS);

        if ($requireNonEmpty && $fields === []) {
            self::throwInvalidArgument('FIELDS');
        }

        return $fields;
    }

    /**
     * Приводит ключи ассоциативного параметра к верхнему регистру без коллизий.
     *
     * @param array<mixed, mixed> $parameters Исходный массив REST.
     * @param string              $parameterName Имя массива для локализованной ошибки.
     *
     * @return array<string, mixed> Массив с уникальными строковыми ключами.
     */
    private static function normalizeParameterKeys(array $parameters, string $parameterName): array
    {
        /** @var array<string, mixed> $normalized Значения под нормализованными ключами. */
        $normalized = [];

        /** @var mixed $key Исходный ключ параметра. */
        /** @var mixed $value Исходное значение параметра. */
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                self::throwInvalidArgument($parameterName);
            }

            /** @var string $normalizedKey Имя ключа в контрактном верхнем регистре. */
            $normalizedKey = strtoupper(trim($key));
            if (array_key_exists($normalizedKey, $normalized)) {
                self::throwInvalidArgument($parameterName . '.' . $normalizedKey);
            }
            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    /**
     * Отклоняет неизвестные параметры вместо их молчаливого игнорирования.
     *
     * @param array<string, mixed> $parameters Проверяемый набор.
     * @param string[]             $allowed    Белый список имён.
     */
    private static function assertAllowedParameters(array $parameters, array $allowed): void
    {
        /** @var string $parameter Очередное пользовательское имя. */
        foreach (array_keys($parameters) as $parameter) {
            if (!in_array($parameter, $allowed, true)) {
                self::throwInvalidArgument($parameter);
            }
        }
    }

    /**
     * Читает обязательный положительный INTEGER из набора параметров.
     *
     * @param array<string, mixed> $parameters Проверенные параметры метода.
     */
    private static function requirePositiveInteger(array $parameters, string $name): int
    {
        if (!array_key_exists($name, $parameters)) {
            self::throwInvalidArgument($name);
        }

        return self::normalizeBoundedInteger(
            $parameters[$name],
            $name,
            1,
            self::MAXIMUM_INTEGER_VALUE
        );
    }

    /**
     * Преобразует целое число либо строку цифр в заданных границах.
     */
    private static function normalizeBoundedInteger(mixed $value, string $name, int $minimum, int $maximum): int
    {
        /** @var int $integer Нормализованное значение либо ноль для ошибочного типа. */
        $integer = self::normalizeInteger($value);
        if ($integer < $minimum || $integer > $maximum) {
            self::throwInvalidArgument($name);
        }

        return $integer;
    }

    /**
     * Строго преобразует совместимое с INTEGER значение без округления и частичного разбора.
     */
    private static function normalizeInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) === 1) {
            return (int)$value;
        }

        return -1;
    }

    /**
     * Переводит первую прикладную ошибку D7 в безопасный REST-ответ.
     */
    private static function throwResultError(Result $result): void
    {
        if ($result->isSuccess()) {
            return;
        }

        /** @var Error|null $error Первая локализованная бизнес- или ORM-ошибка. */
        $error = $result->getErrors()[0] ?? null;
        /** @var string $errorCode Машинный код, если сервис его установил. */
        $errorCode = trim((string)($error?->getCode() ?? ''));
        /** @var string $status HTTP-статус ошибки бизнес-операции. */
        $status = $errorCode === 'CAR_OPEN_ORDER_EXISTS'
            ? self::STATUS_CONFLICT
            : CRestServer::STATUS_WRONG_REQUEST;

        /** @var string $publicErrorCode Стабильный код без внутренних кодов ORM. */
        $publicErrorCode = $errorCode === 'CAR_OPEN_ORDER_EXISTS'
            ? 'CAR_OPEN_ORDER_EXISTS'
            : self::ERROR_INVALID_ARGUMENT;

        throw new RestException(
            $error === null
                ? (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_REST_INVALID_ARGUMENT', ['#PARAMETER#' => 'FIELDS'])
                : $error->getMessage(),
            $publicErrorCode,
            $status
        );
    }

    /**
     * Формирует единый ответ без CREATED_BY, UPDATED_BY и технических дат.
     *
     * @param array<string, mixed> $car Исходная запись ORM.
     *
     * @return array<string, mixed> Публичные поля автомобиля.
     */
    private static function formatCar(array $car): array
    {
        /** @var array<string, mixed> $safeCar Результат белого списка полей ответа. */
        $safeCar = array_intersect_key($car, array_fill_keys(self::RESPONSE_FIELDS, true));

        return [
            'ID' => (int)($safeCar['ID'] ?? 0),
            'CONTACT_ID' => (int)($safeCar['CONTACT_ID'] ?? 0),
            'MAKE' => (string)($safeCar['MAKE'] ?? ''),
            'MODEL' => (string)($safeCar['MODEL'] ?? ''),
            'LICENSE_PLATE' => (string)($safeCar['LICENSE_PLATE'] ?? ''),
            'YEAR' => isset($safeCar['YEAR']) ? (int)$safeCar['YEAR'] : null,
            'COLOR' => isset($safeCar['COLOR']) ? (string)$safeCar['COLOR'] : null,
            'MILEAGE' => (int)($safeCar['MILEAGE'] ?? 0),
            'ACTIVE' => (string)($safeCar['ACTIVE'] ?? 'N'),
        ];
    }

    /**
     * Создаёт единообразную ошибку неверного параметра без раскрытия внутреннего кода.
     */
    private static function throwInvalidArgument(string $parameter): never
    {
        throw new RestException(
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CAR_REST_INVALID_ARGUMENT',
                ['#PARAMETER#' => $parameter]
            ),
            self::ERROR_INVALID_ARGUMENT,
            CRestServer::STATUS_WRONG_REQUEST
        );
    }
}
