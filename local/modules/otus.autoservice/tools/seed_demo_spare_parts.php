<?php

/**
 * Явно добавляет демонстрационные запчасти в подготовленный штатный CRM-каталог.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;

if (PHP_SAPI !== 'cli') {
    // Изменяющий данные сценарий нельзя запускать через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $applyRequested Передан ли обязательный защитный флаг изменения данных. */
$applyRequested = in_array('--apply', $argv, true);

/** @var string|null $documentRootArgument Первый аргумент пути, не являющийся флагом. */
$documentRootArgument = null;
/** @var string $argument Очередной пользовательский аргумент CLI. */
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with((string)$argument, '--')) {
        $documentRootArgument = (string)$argument;
        break;
    }
}

/** @var string $documentRoot Нормализованный корень сайта Bitrix. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

/** @var array<string, string> $MESS Предварительно загруженные сообщения для ошибок до пролога. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/seed_demo_spare_parts.php';

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_SEED_PARTS_DOCUMENT_ROOT_MISSING'] ?? '')
        ) . PHP_EOL
    );
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
$_SERVER['REQUEST_METHOD'] = 'CLI';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);
define('CHK_EVENT', false);

require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

Loc::loadMessages(__FILE__);

if (!$applyRequested) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_SEED_PARTS_USAGE') . PHP_EOL);
    exit(2);
}

if (!Loader::includeModule('otus.autoservice')) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_SEED_PARTS_MODULE_REQUIRED') . PHP_EOL);
    exit(1);
}

try {
    /** @var SparePartsCatalogManager $manager Менеджер явного заполнения демонстрационных данных. */
    $manager = new SparePartsCatalogManager();
    /** @var array{catalog_id: int, article_property_id: int, store_id: int, product_ids: array<string, int>, inventory_management: bool} $configuration Созданная и проверяемая конфигурация. */
    $configuration = $manager->seedDemoProducts();

    if (!$manager->areDemoProductsReady()) {
        throw new RuntimeException(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_SEED_PARTS_VALIDATION_FAILED')
        );
    }

    echo (string)Loc::getMessage(
        'OTUS_AUTOSERVICE_SEED_PARTS_SUCCESS',
        [
            '#CATALOG_ID#' => (string)$configuration['catalog_id'],
            '#STORE_ID#' => (string)$configuration['store_id'],
            '#COUNT#' => (string)count($configuration['product_ids']),
        ]
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_SEED_PARTS_ERROR',
            ['#ERROR#' => $exception->getMessage()]
        ) . PHP_EOL
    );
    exit(1);
}
