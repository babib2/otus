<?php

/**
 * Проверяет защищённую историю автомобиля и пагинацию без изменения данных CRM и модуля.
 */

declare(strict_types=1);

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Otus\Autoservice\Model\CarTable;
use Otus\Autoservice\Service\CarHistoryService;

if (PHP_SAPI !== 'cli') {
    // Диагностика может раскрывать состояние установки и поэтому запрещена через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var string|null $documentRootArgument Необязательный корень сайта из первого CLI-аргумента. */
$documentRootArgument = isset($argv[1]) ? (string)$argv[1] : null;

/** @var string $documentRoot Нормализованный корень текущей установки Bitrix. */
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

if (!Loader::includeModule('otus.autoservice') || !Loader::includeModule('crm')) {
    fwrite(STDERR, 'Modules otus.autoservice and crm must be installed.' . PHP_EOL);
    exit(1);
}

if (!class_exists(CarHistoryService::class) || !method_exists(CarHistoryService::class, 'getPage')) {
    fwrite(STDERR, 'Car history service is not available.' . PHP_EOL);
    exit(1);
}

/** @var CarHistoryService $service Проверяемый прикладной сервис истории. */
$service = new CarHistoryService();

/**
 * @var \Bitrix\Main\Result $anonymousResult
 * Проверка обязательного запрета неавторизованного чтения до поиска фикстуры.
 */
$anonymousResult = $service->getPage(1, 1, 0, 1, 5);

/** @var string[] $anonymousErrorCodes Машинные коды ошибок запроса без пользователя. */
$anonymousErrorCodes = array_map(
    static fn(Error $error): string => (string)$error->getCode(),
    $anonymousResult->getErrors()
);
if (
    $anonymousResult->isSuccess()
    || !in_array(CarHistoryService::ERROR_ACCESS_DENIED, $anonymousErrorCodes, true)
) {
    fwrite(STDERR, 'Anonymous car history request was not rejected.' . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed>|false $car Первая запись для необязательной интеграционной проверки чтения. */
$car = CarTable::getList(
    [
        'select' => ['ID', 'CONTACT_ID'],
        'order' => ['ID' => 'ASC'],
        'limit' => 1,
    ]
)->fetch();

if ($car === false) {
    echo 'Car history service and anonymous access: OK; no car fixture for data query.' . PHP_EOL;
    exit(0);
}

/** @var int $carId Проверяемый автомобиль без вывода его реквизитов. */
$carId = (int)$car['ID'];

/** @var int $contactId Фактический владелец проверяемого автомобиля. */
$contactId = (int)$car['CONTACT_ID'];

/** @var \Bitrix\Main\Result $pageResult Страница истории с CRM-правами администратора ID 1. */
$pageResult = $service->getPage($carId, $contactId, 1, 1, 5);
if (!$pageResult->isSuccess()) {
    fwrite(STDERR, implode('; ', $pageResult->getErrorMessages()) . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed> $data Данные страницы, проверяемые без раскрытия содержимого в консоль. */
$data = $pageResult->getData();
if (
    (int)($data['carId'] ?? 0) !== $carId
    || (int)($data['contactId'] ?? 0) !== $contactId
    || !is_string($data['title'] ?? null)
    || !is_array($data['items'] ?? null)
    || !is_array($data['pagination'] ?? null)
    || (int)($data['pagination']['page'] ?? 0) !== 1
    || (int)($data['pagination']['pageSize'] ?? 0) !== 5
) {
    fwrite(STDERR, 'Car history page has an invalid response shape.' . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed> $item Очередная сделка, структура которой проверяется без вывода значений. */
foreach ($data['items'] as $item) {
    if (
        (int)($item['id'] ?? 0) <= 0
        || !is_string($item['title'] ?? null)
        || !is_string($item['stageName'] ?? null)
        || !is_array($item['assignedBy'] ?? null)
        || !is_array($item['products'] ?? null)
    ) {
        fwrite(STDERR, 'Car history contains an invalid deal item.' . PHP_EOL);
        exit(1);
    }
}

printf(
    "Car history service: OK; accessible deals on checked page: %d%s",
    count($data['items']),
    PHP_EOL
);

exit(0);
