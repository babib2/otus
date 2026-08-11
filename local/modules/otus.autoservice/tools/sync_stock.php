<?php

/**
 * Запускает синхронизацию внешних остатков из cron или восстанавливает зависшие запуски.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Model\SyncRunTable;
use Otus\Autoservice\Service\StockSyncService;

if (PHP_SAPI !== 'cli') {
    // Операционная команда изменяет журнал и поэтому никогда не публикуется через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var array<string, string> $MESS Предварительные сообщения до загрузки пролога. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/sync_stock.php';

/** @var bool $recoverOnly Выполнить только безопасное восстановление устаревших running-записей. */
$recoverOnly = false;
/** @var int|null $requestedBatchSize Явный размер порции либо null для значения сервиса по умолчанию. */
$requestedBatchSize = null;
/** @var string|null $documentRootArgument Первый позиционный аргумент корня портала. */
$documentRootArgument = null;

/** @var string $argument Очередной аргумент командной строки. */
foreach (array_slice($argv, 1) as $argument) {
    /** @var string $normalizedArgument Строковое представление аргумента. */
    $normalizedArgument = (string)$argument;
    if ($normalizedArgument === '--recover-stale-only') {
        $recoverOnly = true;
        continue;
    }
    if (str_starts_with($normalizedArgument, '--batch-size=')) {
        /** @var string $rawBatchSize Значение после имени параметра. */
        $rawBatchSize = substr($normalizedArgument, strlen('--batch-size='));
        if (preg_match('/^[1-9][0-9]*$/D', $rawBatchSize) !== 1) {
            fwrite(
                STDERR,
                (string)($MESS['OTUS_AUTOSERVICE_SYNC_STOCK_INVALID_BATCH_SIZE'] ?? '') . PHP_EOL
            );
            exit(1);
        }
        $requestedBatchSize = (int)$rawBatchSize;
        continue;
    }
    if (str_starts_with($normalizedArgument, '--')) {
        fwrite(
            STDERR,
            str_replace(
                '#ARGUMENT#',
                $normalizedArgument,
                (string)($MESS['OTUS_AUTOSERVICE_SYNC_STOCK_UNKNOWN_ARGUMENT'] ?? '')
            ) . PHP_EOL
        );
        exit(1);
    }
    if ($documentRootArgument === null) {
        $documentRootArgument = $normalizedArgument;
        continue;
    }

    fwrite(
        STDERR,
        (string)($MESS['OTUS_AUTOSERVICE_SYNC_STOCK_EXTRA_POSITIONAL_ARGUMENT'] ?? '') . PHP_EOL
    );
    exit(1);
}

/** @var string $documentRoot Нормализованный корень портала для пролога Bitrix. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_SYNC_STOCK_DOCUMENT_ROOT_MISSING'] ?? '')
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

if (
    !Loader::includeModule('otus.autoservice')
    || !Loader::includeModule('iblock')
    || !Loader::includeModule('catalog')
) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_SYNC_STOCK_MODULES_REQUIRED') . PHP_EOL);
    exit(1);
}

if (MigrationManager::hasPendingMigrations()) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_SYNC_STOCK_MIGRATION_REQUIRED') . PHP_EOL);
    exit(1);
}

/** @var int $batchSize Итоговый размер порции после загрузки класса сервиса. */
$batchSize = $requestedBatchSize ?? StockSyncService::DEFAULT_BATCH_SIZE;

try {
    /** @var StockSyncService $service Сервис блокировки, пакетной обработки и журналирования. */
    $service = new StockSyncService();
    if ($recoverOnly) {
        /** @var int $recoveredRuns Число устаревших запусков, переведённых в failed. */
        $recoveredRuns = $service->recoverStaleRuns();
        fwrite(
            STDOUT,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_SYNC_STOCK_RECOVERED',
                ['#COUNT#' => (string)$recoveredRuns]
            ) . PHP_EOL
        );
        exit($recoveredRuns > 0 ? 2 : 0);
    }

    /** @var int $runId ID выполненного запуска синхронизации. */
    $runId = $service->run(SyncRunTable::INITIATOR_CLI, $batchSize);
    /** @var array<string, mixed>|false $run Сохранённый итоговый журнал запуска. */
    $run = SyncRunTable::getByPrimary(
        $runId,
        [
            'select' => [
                'ID',
                'PROVIDER_CODE',
                'STATUS',
                'TOTAL_ITEMS',
                'SUCCESS_ITEMS',
                'FAILED_ITEMS',
            ],
        ]
    )->fetch();
    if ($run === false) {
        throw new RuntimeException(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_SYNC_STOCK_RESULT_MISSING')
        );
    }

    fwrite(
        STDOUT,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_SYNC_STOCK_RESULT',
            [
                '#ID#' => (string)$run['ID'],
                '#PROVIDER#' => (string)$run['PROVIDER_CODE'],
                '#STATUS#' => (string)$run['STATUS'],
                '#TOTAL#' => (string)$run['TOTAL_ITEMS'],
                '#SUCCESS#' => (string)$run['SUCCESS_ITEMS'],
                '#FAILED#' => (string)$run['FAILED_ITEMS'],
            ]
        ) . PHP_EOL
    );

    exit(
        (string)$run['STATUS'] === SyncRunTable::STATUS_COMPLETED
            ? 0
            : 2
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_SYNC_STOCK_ERROR',
            ['#ERROR#' => $exception->getMessage()]
        ) . PHP_EOL
    );
    exit(1);
}
