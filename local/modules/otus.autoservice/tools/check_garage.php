<?php

/**
 * Проверяет миграцию, файлы, обработчик события и серверные классы вкладки «Гараж» без изменения данных.
 */

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Engine\Resolver;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Otus\Autoservice\Controller\Car;
use Otus\Autoservice\EventHandler\ContactGarageTabHandler;
use Otus\Autoservice\Integration\Crm\GarageComponentManager;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\CarTable;
use Otus\Autoservice\Service\CarHistoryService;
use Otus\Autoservice\Service\CarPullService;
use Otus\Autoservice\Service\CarService;
use Otus\Autoservice\Service\DealOpenOrderService;
use Otus\Autoservice\Service\ModuleConfiguration;

if (PHP_SAPI !== 'cli') {
    // Сценарий раскрывает техническое состояние установки и поэтому недоступен через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var string|null $documentRootArgument Переданный пользователем корень сайта. */
$documentRootArgument = isset($argv[1]) ? (string)$argv[1] : null;

/** @var string $documentRoot Нормализованный абсолютный путь к корню сайта Bitrix. */
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

/** @var string $garageMigrationVersion Версия миграции, впервые устанавливающей вкладку. */
$garageMigrationVersion = '202608090006';

/** @var string $currentSchemaVersion Последняя успешно применённая миграция модуля. */
$currentSchemaVersion = MigrationManager::getCurrentVersion();

/** @var bool $migrationApplied Достигнута ли версия схемы с компонентом гаража. */
$migrationApplied = strlen($currentSchemaVersion) === strlen($garageMigrationVersion)
    && strcmp($currentSchemaVersion, $garageMigrationVersion) >= 0;

/** @var bool $carTableExists Доступна ли ORM-таблица, из которой строится GRID. */
$carTableExists = Application::getConnection()->isTableExists(CarTable::getTableName());

/** @var string $componentSourceDirectory Исходники компонента внутри поставки модуля. */
$componentSourceDirectory = dirname(__DIR__) . '/install/components/otus/autoservice.garage';

/** @var string $componentPublicDirectory Опубликованный установщиком компонент сайта. */
$componentPublicDirectory = $documentRoot . '/local/components/otus/autoservice.garage';

/** @var string[] $requiredComponentFiles Минимальный набор серверных и клиентских файлов вкладки. */
$requiredComponentFiles = [
    '/class.php',
    '/lazyload.ajax.php',
    '/templates/.default/template.php',
    '/templates/.default/script.js',
    '/templates/.default/style.css',
    '/templates/.default/lang/ru/template.php',
];

/**
 * Проверяет полный набор файлов относительно одного каталога компонента.
 *
 * @param string   $directory Корневой каталог исходного или опубликованного компонента.
 * @param string[] $files     Относительные пути обязательных файлов.
 */
$hasComponentFiles = static function (string $directory, array $files): bool {
    /** @var string $file Очередной обязательный путь внутри компонента. */
    foreach ($files as $file) {
        if (!is_file($directory . $file)) {
            return false;
        }
    }

    return true;
};

/** @var bool $sourceFilesExist Полна ли версия компонента внутри local/modules. */
$sourceFilesExist = $hasComponentFiles($componentSourceDirectory, $requiredComponentFiles);

/** @var bool $publicFilesExist Полна ли опубликованная версия внутри local/components. */
$publicFilesExist = GarageComponentManager::isInstalled()
    && $hasComponentFiles($componentPublicDirectory, $requiredComponentFiles);

/** @var EventManager $eventManager Реестр постоянных обработчиков событий Bitrix. */
$eventManager = EventManager::getInstance();

/** @var bool $tabHandlerExists Зарегистрирован ли точный обработчик CRM-вкладки модуля. */
$tabHandlerExists = false;

/** @var array<string, mixed> $handler Очередная регистрация события и её вызываемый метод. */
foreach ($eventManager->findEventHandlers('crm', 'onEntityDetailsTabsInitialized') as $handler) {
    if (
        (string)($handler['TO_MODULE_ID'] ?? '') === ModuleConfiguration::MODULE_ID
        && (string)($handler['TO_CLASS'] ?? '') === ContactGarageTabHandler::class
        && (string)($handler['TO_METHOD'] ?? '') === 'onTabsInitialized'
    ) {
        $tabHandlerExists = true;
        break;
    }
}

/** @var bool $serverClassesReady Доступны ли сервис, контроллер и все его публичные CRUD-действия. */
$serverClassesReady = class_exists(CarService::class)
    && class_exists(CarHistoryService::class)
    && class_exists(Car::class)
    && method_exists(Car::class, 'getAction')
    && method_exists(Car::class, 'createAction')
    && method_exists(Car::class, 'updateAction')
    && method_exists(Car::class, 'archiveAction')
    && method_exists(Car::class, 'historyAction')
    && method_exists(DealOpenOrderService::class, 'hasOpenOrderForCar');

/** @var array{0: object, 1: string}|null $controllerRoute Разрешённый ядром маршрут модульного AJAX-контроллера. */
$controllerRoute = Resolver::getControllerAndAction('otus', ModuleConfiguration::MODULE_ID, 'api.Car.create');

/** @var array{0: object, 1: string}|null $historyControllerRoute Маршрут чтения истории тем же контроллером. */
$historyControllerRoute = Resolver::getControllerAndAction(
    'otus',
    ModuleConfiguration::MODULE_ID,
    'api.Car.history'
);

/** @var bool $controllerRouteReady Ведёт ли строка из BX.ajax.runAction к требуемому контроллеру и действию. */
$controllerRouteReady = is_array($controllerRoute)
    && ($controllerRoute[0] ?? null) instanceof Car
    && ($controllerRoute[1] ?? null) === 'create'
    && is_array($historyControllerRoute)
    && ($historyControllerRoute[0] ?? null) instanceof Car
    && ($historyControllerRoute[1] ?? null) === 'history';

/** @var string $firstPullTag Непрогнозируемый тег первого тестового контакта. */
$firstPullTag = CarPullService::getWatchTag(1);

/** @var bool $pullTagReady Стабилен ли тег для контакта и различается ли он для разных контактов. */
$pullTagReady = str_starts_with($firstPullTag, 'otus_autoservice_garage_contact_')
    && $firstPullTag === CarPullService::getWatchTag(1)
    && $firstPullTag !== CarPullService::getWatchTag(2)
    && $firstPullTag !== 'otus_autoservice_garage_contact_1';

printf("Garage migration %s: %s%s", $garageMigrationVersion, $migrationApplied ? 'OK' : 'NOT APPLIED', PHP_EOL);
printf("Car table: %s%s", $carTableExists ? 'OK' : 'NOT FOUND', PHP_EOL);
printf("Garage source files: %s%s", $sourceFilesExist ? 'OK' : 'NOT FOUND', PHP_EOL);
printf("Garage published files: %s%s", $publicFilesExist ? 'OK' : 'NOT FOUND', PHP_EOL);
printf("Garage CRM tab handler: %s%s", $tabHandlerExists ? 'OK' : 'NOT FOUND', PHP_EOL);
printf("Garage server CRUD: %s%s", $serverClassesReady ? 'OK' : 'NOT READY', PHP_EOL);
printf("Garage controller route: %s%s", $controllerRouteReady ? 'OK' : 'NOT READY', PHP_EOL);
printf("Garage Pull watch tag: %s%s", $pullTagReady ? 'OK' : 'NOT READY', PHP_EOL);

if (
    !$migrationApplied
    || !$carTableExists
    || !$sourceFilesExist
    || !$publicFilesExist
    || !$tabHandlerExists
    || !$serverClassesReady
    || !$controllerRouteReady
    || !$pullTagReady
) {
    exit(1);
}

exit(0);
