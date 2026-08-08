<?php

/**
 * Проверяет поле автомобиля, сервисную воронку, CRM-обработчики и установку селектора без записи данных.
 */

declare(strict_types=1);

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Otus\Autoservice\EventHandler\DealCarSelectorAssetHandler;
use Otus\Autoservice\EventHandler\DealValidationHandler;
use Otus\Autoservice\Integration\Crm\DealCarFieldManager;
use Otus\Autoservice\Integration\Crm\DealCarSelectorAssetManager;
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

if (
    !Loader::includeModule('otus.autoservice')
    || !Loader::includeModule('crm')
    || !Loader::includeModule('ui')
) {
    fwrite(STDERR, 'Modules otus.autoservice, crm and ui must be installed.' . PHP_EOL);
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
 * @param string $fromModuleId Модуль-источник события.
 * @param string $eventName    Название события.
 * @param string $handlerClass Полное имя класса обработчика.
 * @param string $method       Статический метод обработчика.
 */
$hasHandler = static function (
    string $fromModuleId,
    string $eventName,
    string $handlerClass,
    string $method
) use ($eventManager): bool {
    /** @var array<string, mixed> $handler Очередной обработчик события из ядра. */
    foreach ($eventManager->findEventHandlers($fromModuleId, $eventName) as $handler) {
        if (
            (string)($handler['TO_MODULE_ID'] ?? '') === ModuleConfiguration::MODULE_ID
            && (string)($handler['TO_CLASS'] ?? '') === $handlerClass
            && (string)($handler['TO_METHOD'] ?? '') === $method
        ) {
            return true;
        }
    }

    return false;
};

/** @var bool $addHandlerExists Зарегистрирована ли проверка создания сделки. */
$addHandlerExists = $hasHandler(
    'crm',
    'OnBeforeCrmDealAdd',
    DealValidationHandler::class,
    'onBeforeAdd'
);

/** @var bool $updateHandlerExists Зарегистрирована ли проверка обновления сделки. */
$updateHandlerExists = $hasHandler(
    'crm',
    'OnBeforeCrmDealUpdate',
    DealValidationHandler::class,
    'onBeforeUpdate'
);

/** @var bool $selectorHandlerExists Зарегистрировано ли подключение селектора на OnProlog. */
$selectorHandlerExists = $hasHandler(
    'main',
    'OnProlog',
    DealCarSelectorAssetHandler::class,
    'onProlog'
);

/** @var bool $selectorAssetsExist Опубликованы ли оба клиентских файла селектора. */
$selectorAssetsExist = is_file($documentRoot . DealCarSelectorAssetManager::PUBLIC_JS_PATH)
    && is_file($documentRoot . DealCarSelectorAssetManager::PUBLIC_CSS_PATH);

/** @var array<string, array<string, mixed>> $selectorEntities Зарегистрированные сущности UI Entity Selector. */
$selectorEntities = \Bitrix\UI\EntitySelector\Configuration::getEntities();

/** @var bool $selectorProviderExists Зарегистрирован ли серверный провайдер автомобилей. */
$selectorProviderExists = isset($selectorEntities['otus_autoservice_car']);

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
printf(
    "Deal car selector provider: %s%s",
    $selectorProviderExists ? 'OK' : 'NOT FOUND',
    PHP_EOL
);
printf(
    "Deal car selector OnProlog handler: %s%s",
    $selectorHandlerExists ? 'OK' : 'NOT FOUND',
    PHP_EOL
);
printf(
    "Deal car selector assets: %s%s",
    $selectorAssetsExist ? 'OK' : 'NOT FOUND',
    PHP_EOL
);

if (
    !$fieldExists
    || !$serviceCategoryExists
    || !$pipelineReady
    || !$addHandlerExists
    || !$updateHandlerExists
    || !$selectorProviderExists
    || !$selectorHandlerExists
    || !$selectorAssetsExist
) {
    exit(1);
}

exit(0);
