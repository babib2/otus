<?php

/**
 * Выполняет очищаемый интеграционный CRUD-тест хранилища автомобилей через D7.
 */

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Otus\Autoservice\Model\CarTable;
use Otus\Autoservice\Service\CarHistoryService;
use Otus\Autoservice\Service\CarService;

if (PHP_SAPI !== 'cli') {
    // Запуск через HTTP запрещён, потому что тест временно изменяет данные.
    http_response_code(404);
    exit(1);
}

if (!in_array('--write-test', $argv, true)) {
    fwrite(STDERR, 'Usage: php tools/check_car_storage.php --write-test [document-root]' . PHP_EOL);
    exit(2);
}

/** @var string|null $documentRootArgument Явно переданный корень сайта. */
$documentRootArgument = null;

/** @var string $argument Очередной аргумент командной строки. */
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--write-test') {
        $documentRootArgument = $argument;
        break;
    }
}

/** @var string $documentRoot Нормализованный абсолютный путь к корню Bitrix. */
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

if (!Loader::includeModule('otus.autoservice')) {
    fwrite(STDERR, 'Module otus.autoservice is not installed.' . PHP_EOL);
    exit(1);
}

if (!Application::getConnection()->isTableExists(CarTable::getTableName())) {
    fwrite(STDERR, 'Car table is not installed. Apply migrations first.' . PHP_EOL);
    exit(1);
}

/** @var CarService $service Проверяемый прикладной сервис автомобилей. */
$service = new CarService();

/** @var int $createdCarId Идентификатор временной записи для гарантированной очистки. */
$createdCarId = 0;

/** @var int $exitCode Итоговый код завершения теста. */
$exitCode = 0;

try {
    /** @var \Bitrix\Main\ORM\Data\AddResult $invalidResult Проверка отрицательного пробега и года. */
    $invalidResult = $service->create(
        [
            'CONTACT_ID' => 1,
            'MAKE' => 'OTUS',
            'MODEL' => 'Invalid Test',
            'LICENSE_PLATE' => 'OTUS-INVALID',
            'YEAR' => 1800,
            'MILEAGE' => -1,
        ],
        0
    );

    if ($invalidResult->isSuccess()) {
        CarTable::delete((int)$invalidResult->getId());
        throw new RuntimeException('Invalid year or mileage was accepted.');
    }

    /** @var \Bitrix\Main\ORM\Data\AddResult $invalidTypeResult Проверка сложного значения строкового поля. */
    $invalidTypeResult = $service->create(
        [
            'CONTACT_ID' => 1,
            'MAKE' => ['OTUS'],
            'MODEL' => 'Invalid Type Test',
            'LICENSE_PLATE' => 'OTUS-INVALID-TYPE',
            'MILEAGE' => 0,
        ],
        0
    );

    if ($invalidTypeResult->isSuccess()) {
        CarTable::delete((int)$invalidTypeResult->getId());
        throw new RuntimeException('Array value of a string field was accepted.');
    }

    /** @var string $sourcePlate Номер с разделителем и нижним регистром для проверки нормализации. */
    $sourcePlate = 'otus-' . strtolower(substr(hash('sha256', uniqid('', true)), 0, 8));

    /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат создания тестового автомобиля. */
    $addResult = $service->createForContact(
        1,
        [
            'MAKE' => 'OTUS',
            'MODEL' => 'Integration Test',
            'LICENSE_PLATE' => $sourcePlate,
            'YEAR' => (int)date('Y'),
            'COLOR' => 'Test',
            'MILEAGE' => 1,
            'ACTIVE' => 'N',
        ],
        0
    );

    if (!$addResult->isSuccess()) {
        throw new RuntimeException(implode('; ', $addResult->getErrorMessages()));
    }

    $createdCarId = (int)$addResult->getId();

    /** @var array<string, mixed>|null $createdCar Запись, прочитанная сразу после создания. */
    $createdCar = $service->getById($createdCarId);
    if ($createdCar === null) {
        throw new RuntimeException('Created car cannot be read by ID.');
    }

    if ($createdCar['LICENSE_PLATE'] !== $service->normalizeLicensePlate($sourcePlate)) {
        throw new RuntimeException('License plate was not normalized.');
    }

    if ((string)$createdCar['ACTIVE'] !== 'Y') {
        throw new RuntimeException('Garage create action accepted an archived state.');
    }

    /** @var \Bitrix\Main\ORM\Data\UpdateResult $updateResult Результат обновления пробега. */
    $updateResult = $service->update(
        $createdCarId,
        [
            'MILEAGE' => 123,
            'YEAR' => '',
        ],
        0
    );
    if (!$updateResult->isSuccess()) {
        throw new RuntimeException(implode('; ', $updateResult->getErrorMessages()));
    }

    /** @var array<string, mixed>|null $updatedCar Запись после обновления. */
    $updatedCar = $service->getByLicensePlate($sourcePlate);
    if (
        $updatedCar === null
        || (int)$updatedCar['MILEAGE'] !== 123
        || $updatedCar['YEAR'] !== null
    ) {
        throw new RuntimeException('Updated car cannot be found by license plate.');
    }

    /** @var array<int, array<string, mixed>> $activeCars Активные автомобили тестового контакта. */
    $activeCars = $service->getActiveByContact(1);

    /** @var int[] $activeCarIds Идентификаторы активных автомобилей для точной проверки. */
    $activeCarIds = array_map('intval', array_column($activeCars, 'ID'));
    if (!in_array($createdCarId, $activeCarIds, true)) {
        throw new RuntimeException('Created car is absent from active contact cars.');
    }

    /** @var CarHistoryService $historyService Проверка CRM-истории на временном автомобиле без сделок. */
    $historyService = new CarHistoryService();

    /** @var \Bitrix\Main\Result $historyResult Доступная администратору пустая страница истории. */
    $historyResult = $historyService->getPage($createdCarId, 1, 1, 1, 5);
    if (!$historyResult->isSuccess()) {
        throw new RuntimeException(implode('; ', $historyResult->getErrorMessages()));
    }

    /** @var array<string, mixed> $historyData Структура ответа истории временного автомобиля. */
    $historyData = $historyResult->getData();
    if (
        (int)($historyData['carId'] ?? 0) !== $createdCarId
        || !is_array($historyData['items'] ?? null)
        || $historyData['items'] !== []
        || !is_array($historyData['pagination'] ?? null)
    ) {
        throw new RuntimeException('Empty car history response has an invalid shape.');
    }

    /** @var \Bitrix\Main\Result $anonymousHistoryResult Запрещённое чтение истории без пользователя. */
    $anonymousHistoryResult = $historyService->getPage($createdCarId, 1, 0, 1, 5);
    if ($anonymousHistoryResult->isSuccess()) {
        throw new RuntimeException('Anonymous car history request was accepted.');
    }

    /** @var \Bitrix\Main\ORM\Data\UpdateResult $deactivateResult Результат мягкого удаления. */
    $deactivateResult = $service->deactivate($createdCarId, 0);
    if (!$deactivateResult->isSuccess()) {
        throw new RuntimeException(implode('; ', $deactivateResult->getErrorMessages()));
    }

    /** @var array<string, mixed>|null $deactivatedCar Запись после мягкого удаления. */
    $deactivatedCar = $service->getById($createdCarId);
    if ($deactivatedCar === null || $deactivatedCar['ACTIVE'] !== 'N') {
        throw new RuntimeException('Car was not deactivated.');
    }

    echo 'Car storage CRUD test: OK' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Car storage CRUD test failed: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($createdCarId > 0) {
        /** @var \Bitrix\Main\ORM\Data\DeleteResult $deleteResult Очистка временной тестовой записи. */
        $deleteResult = CarTable::delete($createdCarId);
        if (!$deleteResult->isSuccess()) {
            fwrite(STDERR, 'Test cleanup failed: ' . implode('; ', $deleteResult->getErrorMessages()) . PHP_EOL);
            $exitCode = 1;
        }
    }
}

exit($exitCode);
