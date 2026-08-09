<?php

/**
 * Проверяет регистрацию, безопасность, чтение и необязательный очищаемый CRUD-тест REST API автомобилей.
 */

declare(strict_types=1);

use Bitrix\Crm\ContactTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Rest\RestException;
use Bitrix\Rest\Engine\ScopeManager;
use Otus\Autoservice\Integration\Rest\CarRestService;
use Otus\Autoservice\Model\CarTable;
use Otus\Autoservice\Service\ModuleConfiguration;

if (PHP_SAPI !== 'cli') {
    // Диагностика раскрывает состояние установки и поэтому недоступна через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $writeTestEnabled Разрешено ли временное изменение таблицы автомобилей. */
$writeTestEnabled = in_array('--write-test', $argv, true);

/** @var string|null $documentRootArgument Явно переданный корень сайта без имени флага. */
$documentRootArgument = null;

/** @var string $argument Очередной пользовательский аргумент командной строки. */
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--write-test') {
        $documentRootArgument = (string)$argument;
        break;
    }
}

/** @var string $documentRoot Нормализованный абсолютный корень установки Bitrix. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR, "Bitrix document root not found: {$documentRoot}" . PHP_EOL);
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
$_SERVER['REQUEST_METHOD'] = 'CLI';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);
define('CHK_EVENT', false);

require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

if (
    !Loader::includeModule('otus.autoservice')
    || !Loader::includeModule('rest')
    || !Loader::includeModule('crm')
) {
    fwrite(STDERR, 'Modules otus.autoservice, rest and crm must be installed.' . PHP_EOL);
    exit(1);
}

if (!ModuleConfiguration::isEnabled()) {
    fwrite(STDERR, 'Module otus.autoservice is disabled.' . PHP_EOL);
    exit(1);
}

if (!Application::getConnection()->isTableExists(CarTable::getTableName())) {
    fwrite(STDERR, 'Car table is not installed. Apply migrations first.' . PHP_EOL);
    exit(1);
}

/**
 * Тестовый REST-сервер позволяет вызвать callback без реального OAuth-токена.
 *
 * Он используется только внутри CLI-проверки: production-запросы создают
 * CRestServer штатным REST-модулем после проверки токена и доступа приложения.
 */
final class OtusAutoserviceCarRestTestServer extends CRestServer
{
    /**
     * Создаёт минимальный сервер с пустым запросом для прямого вызова обработчика.
     */
    public function __construct()
    {
        parent::__construct(
            [
                'CLASS' => 'CRestProvider',
                'METHOD' => CarRestService::METHOD_LIST,
                'QUERY' => [],
            ]
        );
    }

    /**
     * Устанавливает только те защищённые свойства, которые в рабочем запросе заполняет ядро REST.
     *
     * @param string $method  Проверяемый метод.
     * @param int    $userId  Пользователь токена либо ноль для отрицательного теста.
     * @param string $scope   Разрешение текущего метода и токена.
     * @param string $channel Имитируемый канал: oauth, webhook, webhook_batch или session.
     */
    public function configure(
        string $method,
        int $userId,
        string $scope,
        string $channel = 'oauth'
    ): void {
        $this->method = $method;
        $this->scope = $scope;
        $this->authScope = null;
        $this->authData = ['user_id' => $userId];
        $this->authType = null;
        $this->clientId = null;
        $this->passwordId = null;

        if ($channel === 'oauth') {
            $this->authData['scope'] = $scope;
            $this->authData['auth_type'] = 'oauth';
            $this->authType = 'oauth';
            $this->clientId = 'otus.autoservice.cli-test';

            return;
        }

        if ($channel === 'webhook' || $channel === 'webhook_batch') {
            $this->authData['password_id'] = 1;
            $this->authData['scope'] = $scope;
            $this->authData['auth_type'] = 'apauth';
            $this->authType = 'apauth';
            if ($channel === 'webhook') {
                $this->passwordId = '1';
            }

            return;
        }

        if ($channel === 'session') {
            $this->authData['scope'] = $scope;
            $this->authData['auth_type'] = 'session';
            $this->authType = 'session';

            return;
        }

        throw new InvalidArgumentException("Unknown REST test channel: {$channel}");
    }
}

/** @var EventManager $eventManager Менеджер проверки точной регистрации REST-обработчика. */
$eventManager = EventManager::getInstance();

/** @var bool $handlerRegistered Найден ли обработчик текущего класса и метода. */
$handlerRegistered = false;

/** @var array<string, mixed> $handler Очередной обработчик события REST-модуля. */
foreach ($eventManager->findEventHandlers('rest', 'OnRestServiceBuildDescription') as $handler) {
    if (
        (string)($handler['TO_MODULE_ID'] ?? '') === ModuleConfiguration::MODULE_ID
        && (string)($handler['TO_CLASS'] ?? '') === CarRestService::class
        && (string)($handler['TO_METHOD'] ?? '') === 'onRestServiceBuildDescription'
    ) {
        $handlerRegistered = true;
        break;
    }
}

if (!$handlerRegistered) {
    fwrite(STDERR, 'Car REST event handler is not registered. Apply migrations first.' . PHP_EOL);
    exit(1);
}

/** @var string[] $availableScopes Перечень scope из штатного кешируемого менеджера Bitrix. */
$availableScopes = ScopeManager::getInstance()->listScope();
if (!in_array(CarRestService::SCOPE, $availableScopes, true)) {
    fwrite(STDERR, 'Scope otus.autoservice is absent from the REST scope manager.' . PHP_EOL);
    exit(1);
}

/** @var array<string, array<string, callable>> $description Опубликованный контракт методов. */
$description = CarRestService::onRestServiceBuildDescription();

/** @var string[] $expectedMethods Полный набор методов, обязательный для текущей версии API. */
$expectedMethods = [
    CarRestService::METHOD_LIST,
    CarRestService::METHOD_GET,
    CarRestService::METHOD_ADD,
    CarRestService::METHOD_UPDATE,
    CarRestService::METHOD_DELETE,
];

foreach ($expectedMethods as $method) {
    if (!is_callable($description[CarRestService::SCOPE][$method] ?? null)) {
        fwrite(STDERR, "REST method is absent or not callable: {$method}" . PHP_EOL);
        exit(1);
    }
}

/** @var array<string, array<string, callable|array<string, mixed>>> $providerDescription Итог всех событий REST-модуля. */
$providerDescription = (new CRestProvider())->getDescription();
foreach ($expectedMethods as $method) {
    if (!is_callable($providerDescription[CarRestService::SCOPE][$method] ?? null)) {
        fwrite(STDERR, "REST provider did not publish method: {$method}" . PHP_EOL);
        exit(1);
    }
}

/**
 * Проверяет, что вызов завершился ожидаемой безопасной REST-ошибкой.
 *
 * @param callable $callback      Отрицательный сценарий.
 * @param string   $expectedCode  Машинный код ожидаемой ошибки.
 */
$assertRestError = static function (callable $callback, string $expectedCode): void {
    try {
        $callback();
    } catch (RestException $exception) {
        if ((string)$exception->getErrorCode() === $expectedCode) {
            return;
        }

        throw new RuntimeException(
            "Unexpected REST error {$exception->getErrorCode()}; expected {$expectedCode}."
        );
    }

    throw new RuntimeException("Expected REST error was not raised: {$expectedCode}.");
};

/** @var OtusAutoserviceCarRestTestServer $server Управляемый контекст прямых вызовов. */
$server = new OtusAutoserviceCarRestTestServer();

try {
    $server->configure(CarRestService::METHOD_LIST, 1, 'crm');
    $assertRestError(
        static fn(): array => CarRestService::listCars(['CONTACT_ID' => 1], 0, $server),
        CarRestService::ERROR_SCOPE_DENIED
    );

    $server->configure(CarRestService::METHOD_LIST, 1, CarRestService::SCOPE, 'session');
    $assertRestError(
        static fn(): array => CarRestService::listCars(['CONTACT_ID' => 1], 0, $server),
        CarRestService::ERROR_EXTERNAL_CONTEXT_REQUIRED
    );

    $server->configure(CarRestService::METHOD_LIST, 0, CarRestService::SCOPE);
    $assertRestError(
        static fn(): array => CarRestService::listCars(['CONTACT_ID' => 1], 0, $server),
        CarRestService::ERROR_AUTH_REQUIRED
    );

    /** @var int $contactId Первый контакт, доступный администратору для чтения и изменения. */
    $contactId = 0;

    /** @var \Bitrix\Crm\Service\UserPermissions\Item $adminPermissions CRM-права пользователя ID 1. */
    $adminPermissions = Container::getInstance()->getUserPermissions(1)->item();

    /** @var \Bitrix\Main\ORM\Query\Result $contactRows Ограниченная выборка подходящей фикстуры. */
    $contactRows = ContactTable::getList(
        [
            'select' => ['ID'],
            'order' => ['ID' => 'ASC'],
            'limit' => 100,
        ]
    );

    while ($contact = $contactRows->fetch()) {
        /** @var int $candidateContactId Проверяемый идентификатор контакта. */
        $candidateContactId = (int)$contact['ID'];
        if (
            $adminPermissions->canRead(\CCrmOwnerType::Contact, $candidateContactId)
            && $adminPermissions->canUpdate(\CCrmOwnerType::Contact, $candidateContactId)
        ) {
            $contactId = $candidateContactId;
            break;
        }
    }

    if ($contactId <= 0) {
        echo 'Car REST contract and security context: OK; no accessible contact fixture.' . PHP_EOL;
        exit(0);
    }

    if ($writeTestEnabled) {
        // Проверка отказа создаёт ожидаемую запись аудита и поэтому относится к write-режиму.
        $server->configure(
            CarRestService::METHOD_LIST,
            2147483647,
            CarRestService::SCOPE
        );
        $assertRestError(
            static fn(): array => CarRestService::listCars(['CONTACT_ID' => $contactId], 0, $server),
            CarRestService::ERROR_ACCESS_DENIED
        );
    }

    $server->configure(CarRestService::METHOD_LIST, 1, CarRestService::SCOPE);

    /** @var array<string, mixed> $listResponse Безопасная страница автомобилей контакта. */
    $listResponse = CarRestService::listCars(
        [
            'CONTACT_ID' => $contactId,
            'FILTER' => ['ACTIVE' => 'Y'],
            'ORDER' => ['ID' => 'DESC'],
            'LIMIT' => 5,
        ],
        0,
        $server
    );

    if (!is_array($listResponse['items'] ?? null) || !is_int($listResponse['total'] ?? null)) {
        throw new RuntimeException('Car list has an invalid response shape.');
    }

    /** @var string $webhookChannel Прямой или batch-контекст входящего вебхука APAuth. */
    foreach (['webhook', 'webhook_batch'] as $webhookChannel) {
        $server->configure(
            CarRestService::METHOD_LIST,
            1,
            CarRestService::SCOPE,
            $webhookChannel
        );

        /** @var array<string, mixed> $webhookResponse Страница, полученная в формате APAuth Bitrix. */
        $webhookResponse = CarRestService::listCars(
            ['CONTACT_ID' => $contactId, 'LIMIT' => 5],
            0,
            $server
        );
        if (!is_array($webhookResponse['items'] ?? null) || !is_int($webhookResponse['total'] ?? null)) {
            throw new RuntimeException("Car list rejected {$webhookChannel} context.");
        }
    }

    $server->configure(CarRestService::METHOD_LIST, 1, CarRestService::SCOPE);

    /** @var string[] $publicFields Разрешённый белый список ответа автомобиля. */
    $publicFields = [
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

    /** @var array<string, mixed> $item Очередной элемент, проверяемый на лишние поля. */
    foreach ($listResponse['items'] as $item) {
        if (array_diff(array_keys($item), $publicFields) !== []) {
            throw new RuntimeException('Car list exposes a technical field.');
        }
    }

    $assertRestError(
        static fn(): array => CarRestService::listCars(
            [
                'CONTACT_ID' => $contactId,
                'FILTER' => ['UNSAFE_FIELD' => 'value'],
            ],
            0,
            $server
        ),
        CarRestService::ERROR_INVALID_ARGUMENT
    );

    if (!$writeTestEnabled) {
        echo 'Car REST registration, context, validation and read path: OK' . PHP_EOL;
        exit(0);
    }

    /** @var int $createdCarId Идентификатор временной записи для гарантированной очистки. */
    $createdCarId = 0;

    try {
        /** @var string $testPlate Уникальный номер временного автомобиля. */
        $testPlate = 'REST' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 10));

        $server->configure(CarRestService::METHOD_ADD, 1, CarRestService::SCOPE);
        /** @var array<string, mixed> $createdCar Ответ создания временного автомобиля. */
        $createdCar = CarRestService::addCar(
            [
                'CONTACT_ID' => $contactId,
                'FIELDS' => [
                    'MAKE' => 'OTUS',
                    'MODEL' => 'REST Integration Test',
                    'LICENSE_PLATE' => $testPlate,
                    'YEAR' => (int)date('Y'),
                    'COLOR' => 'Test',
                    'MILEAGE' => 1,
                ],
            ],
            0,
            $server
        );
        $createdCarId = (int)($createdCar['ID'] ?? 0);
        if ($createdCarId <= 0 || (int)($createdCar['CONTACT_ID'] ?? 0) !== $contactId) {
            throw new RuntimeException('REST add returned an invalid car.');
        }

        $server->configure(CarRestService::METHOD_GET, 1, CarRestService::SCOPE);
        /** @var array<string, mixed> $readCar Ответ чтения созданного автомобиля. */
        $readCar = CarRestService::getCar(
            ['CONTACT_ID' => $contactId, 'ID' => $createdCarId],
            0,
            $server
        );
        if ((string)($readCar['LICENSE_PLATE'] ?? '') !== $testPlate) {
            throw new RuntimeException('REST get did not return the created car.');
        }

        $server->configure(CarRestService::METHOD_UPDATE, 1, CarRestService::SCOPE);
        /** @var array<string, mixed> $updatedCar Ответ изменения пробега. */
        $updatedCar = CarRestService::updateCar(
            [
                'CONTACT_ID' => $contactId,
                'ID' => $createdCarId,
                'FIELDS' => ['MILEAGE' => 123],
            ],
            0,
            $server
        );
        if ((int)($updatedCar['MILEAGE'] ?? 0) !== 123) {
            throw new RuntimeException('REST update did not persist mileage.');
        }

        $server->configure(CarRestService::METHOD_DELETE, 1, CarRestService::SCOPE);
        /** @var array{ID: int, ARCHIVED: bool} $deletedCar Ответ мягкого удаления. */
        $deletedCar = CarRestService::deleteCar(
            ['CONTACT_ID' => $contactId, 'ID' => $createdCarId],
            0,
            $server
        );
        if (($deletedCar['ARCHIVED'] ?? false) !== true) {
            throw new RuntimeException('REST delete did not archive the car.');
        }

        /** @var array<string, mixed>|false $archivedCar Фактическая запись после мягкого удаления. */
        $archivedCar = CarTable::getByPrimary($createdCarId)->fetch();
        if ($archivedCar === false || (string)$archivedCar['ACTIVE'] !== 'N') {
            throw new RuntimeException('REST delete did not set ACTIVE=N.');
        }

        echo 'Car REST full CRUD test: OK' . PHP_EOL;
    } finally {
        if ($createdCarId > 0) {
            /** @var \Bitrix\Main\ORM\Data\DeleteResult $cleanupResult Физическое удаление только тестовой записи. */
            $cleanupResult = CarTable::delete($createdCarId);
            if (!$cleanupResult->isSuccess()) {
                throw new RuntimeException(
                    'REST test cleanup failed: ' . implode('; ', $cleanupResult->getErrorMessages())
                );
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Car REST check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
