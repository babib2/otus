<?php

/**
 * Проверяет поле автомобиля, настройку сервисной воронки и регистрацию CRM-обработчиков без записи данных.
 */

declare(strict_types=1);

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Otus\Autoservice\EventHandler\DealValidationHandler;
use Otus\Autoservice\Integration\Crm\DealCarFieldManager;
use Otus\Autoservice\Integration\Crm\ServiceDealPipelineManager;
use Otus\Autoservice\Service\ModuleConfiguration;

if (PHP_SAPI !== 'cli') {
    // Сценарий раскрывает техническую конфигурацию и поэтому недоступен через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var string|null $documentRootArgument Переданный пользователем корень сайта. */
$documentRootArgument = isset($argv[1]) ? (string)$argv[1] : null;

/** @var string $documentRoot Нормализованный абсолютный путь к сайту Bitrix. */
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

/** @var DealCarFieldManager $fieldManager Менеджер проверяемого пользовательского поля. */
$fieldManager = new DealCarFieldManager();

/** @var bool $fieldExists Признак совместимого поля автомобиля в CRM. */
$fieldExists = $fieldManager->exists();

/** @var int|null $categoryId Настроенное направление сервисных сделок. */
$categoryId = ModuleConfiguration::getServiceDealCategoryId();

/**
 * @var bool $serviceCategoryExists
 * Выбрано ли существующее направление; нулевой ID обозначает основную воронку Bitrix.
 */
$serviceCategoryExists = $categoryId !== null
    && ($categoryId === 0 || DealCategory::exists($categoryId));

/** @var ServiceDealPipelineManager $pipelineManager Диагностика воронки миграции. */
$pipelineManager = new ServiceDealPipelineManager();

/** @var int|null $managedCategoryId Направление, однозначно созданное миграцией. */
$managedCategoryId = $pipelineManager->getManagedCategoryId();

/** @var bool $pipelineReady Созданы ли направление и полный набор стадий. */
$pipelineReady = $pipelineManager->isReady();

/** @var EventManager $eventManager Реестр постоянных обработчиков Bitrix. */
$eventManager = EventManager::getInstance();

/**
 * Проверяет точную регистрацию метода нашего модуля среди обработчиков события.
 *
 * @param string $eventName Название CRM-события.
 * @param string $method    Метод DealValidationHandler.
 */
$hasHandler = static function (string $eventName, string $method) use ($eventManager): bool {
    /** @var array<string, mixed> $handler Очередной обработчик события из ядра. */
    foreach ($eventManager->findEventHandlers('crm', $eventName) as $handler) {
        if (
            (string)($handler['TO_MODULE_ID'] ?? '') === ModuleConfiguration::MODULE_ID
            && (string)($handler['TO_CLASS'] ?? '') === DealValidationHandler::class
            && (string)($handler['TO_METHOD'] ?? '') === $method
        ) {
            return true;
        }
    }

    return false;
};

/** @var bool $addHandlerExists Зарегистрирована ли проверка создания сделки. */
$addHandlerExists = $hasHandler('OnBeforeCrmDealAdd', 'onBeforeAdd');

/** @var bool $updateHandlerExists Зарегистрирована ли проверка обновления сделки. */
$updateHandlerExists = $hasHandler('OnBeforeCrmDealUpdate', 'onBeforeUpdate');

printf(
    "Deal car field %s: %s%s",
    ModuleConfiguration::getDealCarFieldName(),
    $fieldExists ? 'OK' : 'NOT FOUND',
    PHP_EOL
);
printf(
    "Service deal category: %s%s",
    $categoryId === null
        ? 'NOT CONFIGURED'
        : (string)$categoryId . ($serviceCategoryExists ? '' : ' (NOT FOUND)'),
    PHP_EOL
);
printf(
    "Managed service pipeline category: %s; stages: %s%s",
    $managedCategoryId === null ? 'NOT FOUND' : (string)$managedCategoryId,
    $pipelineReady ? 'OK' : 'NOT READY',
    PHP_EOL
);
printf(
    "OnBeforeCrmDealAdd handler: %s%s",
    $addHandlerExists ? 'OK' : 'NOT FOUND',
    PHP_EOL
);
printf(
    "OnBeforeCrmDealUpdate handler: %s%s",
    $updateHandlerExists ? 'OK' : 'NOT FOUND',
    PHP_EOL
);

if (
    !$fieldExists
    || !$serviceCategoryExists
    || !$pipelineReady
    || !$addHandlerExists
    || !$updateHandlerExists
) {
    exit(1);
}

exit(0);
