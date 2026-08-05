<?php

/**
 * Применяет ожидающие миграции установленного модуля из командной строки.
 */

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Otus\Autoservice\Integration\Crm\DealCarFieldManager;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\CarTable;

if (PHP_SAPI !== 'cli') {
    // Запуск через HTTP запрещён, потому что сценарий изменяет схему базы данных.
    http_response_code(404);
    exit(1);
}

if (!in_array('--apply', $argv, true)) {
    fwrite(STDERR, 'Usage: php tools/migrate.php --apply [document-root]' . PHP_EOL);
    exit(2);
}

/**
 * @var string|null $documentRootArgument Первый аргумент, не являющийся флагом запуска.
 */
$documentRootArgument = null;

/** @var string $argument Очередной аргумент командной строки. */
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--apply') {
        $documentRootArgument = $argument;
        break;
    }
}

/**
 * @var string $documentRoot Абсолютный нормализованный путь к корню сайта Bitrix.
 */
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

try {
    printf(
        "Schema before: %s; latest: %s%s",
        MigrationManager::getCurrentVersion(),
        MigrationManager::getLatestVersion(),
        PHP_EOL
    );

    MigrationManager::migrate();

    /** @var bool $tableExists Создана ли физическая таблица автомобилей. */
    $tableExists = Application::getConnection()->isTableExists(CarTable::getTableName());

    /** @var bool $dealCarFieldExists Создано ли совместимое поле автомобиля в CRM. */
    $dealCarFieldExists = (new DealCarFieldManager())->exists();

    printf(
        "Schema after: %s; car table: %s; deal car field: %s%s",
        MigrationManager::getCurrentVersion(),
        $tableExists ? 'OK' : 'NOT FOUND',
        $dealCarFieldExists ? 'OK' : 'NOT FOUND',
        PHP_EOL
    );

    exit($tableExists && $dealCarFieldExists ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
