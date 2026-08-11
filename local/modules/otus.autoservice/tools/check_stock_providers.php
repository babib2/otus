<?php

/**
 * Проверяет контракт, фабрику и реализации поставщиков внешних остатков без изменения данных.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Stock\FakeStockProvider;
use Otus\Autoservice\Integration\Stock\RandomOrgStockProvider;
use Otus\Autoservice\Integration\Stock\StockBatchFetcher;
use Otus\Autoservice\Integration\Stock\StockFetchResult;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Integration\Stock\StockProviderException;
use Otus\Autoservice\Integration\Stock\StockProviderFactory;
use Otus\Autoservice\Integration\Stock\StockProviderInterface;
use Otus\Autoservice\Service\ModuleConfiguration;

if (PHP_SAPI !== 'cli') {
    // Диагностика раскрывает техническую конфигурацию и поэтому недоступна через HTTP.
    http_response_code(404);
    exit(1);
}

/** @var bool $performLiveRequest Выполнять ли один явно запрошенный сетевой вызов Random.org. */
$performLiveRequest = in_array('--live', $argv, true);

/** @var string|null $documentRootArgument Первый аргумент пути, не являющийся флагом. */
$documentRootArgument = null;
/** @var string $argument Очередной пользовательский аргумент CLI. */
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with((string)$argument, '--')) {
        $documentRootArgument = (string)$argument;
        break;
    }
}

/** @var string $documentRoot Нормализованный корень портала для подключения пролога. */
$documentRoot = $documentRootArgument !== null
    ? rtrim(str_replace('\\', '/', $documentRootArgument), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

/** @var array<string, string> $MESS Предварительно загруженные сообщения ошибок до пролога. */
$MESS = [];
require dirname(__DIR__) . '/lang/ru/tools/check_stock_providers.php';

if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(
        STDERR,
        str_replace(
            '#ROOT#',
            $documentRoot,
            (string)($MESS['OTUS_AUTOSERVICE_CHECK_STOCK_DOCUMENT_ROOT_MISSING'] ?? '')
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

if (!Loader::includeModule('otus.autoservice')) {
    fwrite(STDERR, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_MODULE_REQUIRED') . PHP_EOL);
    exit(1);
}

/**
 * Немедленно завершает проверку при нарушении ожидаемого условия.
 *
 * @param bool   $condition Фактический результат проверяемого условия.
 * @param string $caseName Краткое безопасное название сценария.
 */
function otusAutoserviceAssertStockCheck(bool $condition, string $caseName): void
{
    if ($condition) {
        return;
    }

    fwrite(
        STDERR,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_ASSERTION_FAILED',
            ['#CASE#' => $caseName]
        ) . PHP_EOL
    );
    exit(1);
}

/** @var string[] $requiredClasses Полный набор классов текущего этапа, ожидаемый в автозагрузчике. */
$requiredClasses = [
    StockItem::class,
    StockProviderException::class,
    StockFetchResult::class,
    StockBatchFetcher::class,
    FakeStockProvider::class,
    RandomOrgStockProvider::class,
    StockProviderFactory::class,
];
/** @var string $requiredClass Очередной класс для проверки автозагрузки. */
foreach ($requiredClasses as $requiredClass) {
    otusAutoserviceAssertStockCheck(
        class_exists($requiredClass),
        'autoload:' . $requiredClass
    );
}
otusAutoserviceAssertStockCheck(
    interface_exists(StockProviderInterface::class),
    'autoload:' . StockProviderInterface::class
);

/** @var StockItem $zeroItem Товар с явно настроенным нулевым остатком. */
$zeroItem = new StockItem(101, 'OTUS-TEST-ZERO', 'TEST-ZERO');
/** @var StockItem $articleItem Товар, находящий значение по артикулу. */
$articleItem = new StockItem(102, 'OTUS-TEST-ARTICLE-XML', 'TEST-ARTICLE');
/** @var StockItem $defaultItem Товар, для которого применяется остаток по умолчанию. */
$defaultItem = new StockItem(103, 'OTUS-TEST-DEFAULT', 'TEST-DEFAULT');

/** @var FakeStockProvider $fakeProvider Предсказуемый поставщик трёх тестовых сценариев. */
$fakeProvider = new FakeStockProvider(
    [
        $zeroItem->getExternalId() => 0,
        $articleItem->getArticle() => 7,
    ],
    5
);
otusAutoserviceAssertStockCheck($fakeProvider->getCurrentQuantity($zeroItem) === 0, 'fake-zero');
otusAutoserviceAssertStockCheck($fakeProvider->getCurrentQuantity($articleItem) === 7, 'fake-article');
otusAutoserviceAssertStockCheck($fakeProvider->getCurrentQuantity($defaultItem) === 5, 'fake-default');

/** @var StockProviderFactory $factory Штатная фабрика со встроенными реализациями. */
$factory = new StockProviderFactory();
/** @var string[] $factoryCodes Машинные коды встроенных реализаций. */
$factoryCodes = $factory->getAvailableCodes();
sort($factoryCodes);
/** @var string[] $expectedCodes Ожидаемые коды из безопасной конфигурации. */
$expectedCodes = ModuleConfiguration::getAllowedStockProviderCodes();
sort($expectedCodes);
otusAutoserviceAssertStockCheck($factoryCodes === $expectedCodes, 'factory-codes');

/** @var StockProviderInterface $configuredProvider Реализация, выбранная текущей настройкой b_option. */
$configuredProvider = $factory->create();
otusAutoserviceAssertStockCheck(
    $configuredProvider->getCode() === ModuleConfiguration::getStockProviderCode(),
    'factory-configured-provider'
);

/** @var StockProviderFactory $injectedFactory Фабрика с единственной явно внедрённой реализацией. */
$injectedFactory = new StockProviderFactory([$fakeProvider]);
otusAutoserviceAssertStockCheck(
    $injectedFactory->getAvailableCodes() === [ModuleConfiguration::STOCK_PROVIDER_FAKE]
    && $injectedFactory->create(ModuleConfiguration::STOCK_PROVIDER_FAKE) === $fakeProvider,
    'factory-dependency-injection'
);

/** @var RandomOrgStockProvider $successfulRandomProvider Поставщик с корректным тестовым HTTP-ответом. */
$successfulRandomProvider = new RandomOrgStockProvider(
    1,
    1,
    1,
    0,
    static function (): array {
        return [
            'status' => 200,
            'transport_ok' => true,
            'body' => "6\n",
            'content_type' => 'text/plain; charset=UTF-8',
        ];
    }
);
otusAutoserviceAssertStockCheck(
    $successfulRandomProvider->getCurrentQuantity($defaultItem) === 6,
    'random-success'
);

/** @var int $retryAttempts Фактическое количество обращений к временному сбойному транспорту. */
$retryAttempts = 0;
/** @var RandomOrgStockProvider $retryingRandomProvider Поставщик со сбоем 503 и успешным повтором. */
$retryingRandomProvider = new RandomOrgStockProvider(
    2,
    1,
    1,
    0,
    static function () use (&$retryAttempts): array {
        $retryAttempts++;
        if ($retryAttempts === 1) {
            return [
                'status' => 503,
                'transport_ok' => true,
                'body' => 'Error: temporary',
                'content_type' => 'text/plain',
            ];
        }

        return [
            'status' => 200,
            'transport_ok' => true,
            'body' => '4',
            'content_type' => 'text/plain',
        ];
    }
);
otusAutoserviceAssertStockCheck(
    $retryingRandomProvider->getCurrentQuantity($defaultItem) === 4 && $retryAttempts === 2,
    'random-retry'
);

/** @var int $transportAttempts Количество обращений при постоянной транспортной ошибке. */
$transportAttempts = 0;
/** @var RandomOrgStockProvider $transportFailureProvider Поставщик с двумя исчерпанными транспортными попытками. */
$transportFailureProvider = new RandomOrgStockProvider(
    2,
    1,
    1,
    0,
    static function () use (&$transportAttempts): array {
        $transportAttempts++;

        return [
            'transport_ok' => false,
            'status' => 0,
            'body' => '',
            'content_type' => '',
        ];
    }
);
/** @var StockProviderException|null $transportException Итоговая типизированная ошибка транспорта. */
$transportException = null;
try {
    $transportFailureProvider->getCurrentQuantity($defaultItem);
} catch (StockProviderException $exception) {
    $transportException = $exception;
}
otusAutoserviceAssertStockCheck(
    $transportException instanceof StockProviderException
    && $transportException->isRetryable()
    && $transportException->getErrorType() === StockProviderException::TRANSPORT_ERROR
    && $transportAttempts === 2,
    'random-transport-classification'
);

/** @var int $permanentAttempts Количество обращений при постоянной HTTP-ошибке. */
$permanentAttempts = 0;
/** @var RandomOrgStockProvider $permanentFailureProvider Поставщик с неповторяемым статусом 400. */
$permanentFailureProvider = new RandomOrgStockProvider(
    3,
    1,
    1,
    0,
    static function () use (&$permanentAttempts): array {
        $permanentAttempts++;

        return [
            'status' => 400,
            'transport_ok' => true,
            'body' => '',
            'content_type' => 'text/plain',
        ];
    }
);

/** @var StockProviderException|null $permanentException Ошибка постоянного HTTP-статуса. */
$permanentException = null;
try {
    $permanentFailureProvider->getCurrentQuantity($defaultItem);
} catch (StockProviderException $exception) {
    $permanentException = $exception;
}
otusAutoserviceAssertStockCheck(
    $permanentException instanceof StockProviderException
    && !$permanentException->isRetryable()
    && $permanentException->getErrorType() === StockProviderException::HTTP_STATUS_ERROR
    && $permanentAttempts === 1,
    'random-no-retry-for-400'
);

/** @var RandomOrgStockProvider $invalidBodyProvider Поставщик с двумя числами вместо одного. */
$invalidBodyProvider = new RandomOrgStockProvider(
    1,
    1,
    1,
    0,
    static function (): array {
        return [
            'status' => 200,
            'transport_ok' => true,
            'body' => "3\n4\n",
            'content_type' => 'text/plain',
        ];
    }
);
/** @var StockProviderException|null $invalidBodyException Ошибка строгой проверки формата. */
$invalidBodyException = null;
try {
    $invalidBodyProvider->getCurrentQuantity($defaultItem);
} catch (StockProviderException $exception) {
    $invalidBodyException = $exception;
}
otusAutoserviceAssertStockCheck(
    $invalidBodyException instanceof StockProviderException
    && !$invalidBodyException->isRetryable()
    && $invalidBodyException->getErrorType() === StockProviderException::RESPONSE_FORMAT_ERROR,
    'random-invalid-body'
);

/** @var RandomOrgStockProvider $invalidContentTypeProvider Поставщик с HTML вместо plain text. */
$invalidContentTypeProvider = new RandomOrgStockProvider(
    1,
    1,
    1,
    0,
    static function (): array {
        return [
            'transport_ok' => true,
            'status' => 200,
            'body' => '3',
            'content_type' => 'text/html',
        ];
    }
);
/** @var StockProviderException|null $invalidContentTypeException Ошибка строгой проверки MIME-типа. */
$invalidContentTypeException = null;
try {
    $invalidContentTypeProvider->getCurrentQuantity($defaultItem);
} catch (StockProviderException $exception) {
    $invalidContentTypeException = $exception;
}
otusAutoserviceAssertStockCheck(
    $invalidContentTypeException instanceof StockProviderException
    && !$invalidContentTypeException->isRetryable()
    && $invalidContentTypeException->getErrorType() === StockProviderException::RESPONSE_FORMAT_ERROR,
    'random-invalid-content-type'
);

/** @var RandomOrgStockProvider $serviceErrorProvider Поставщик с безопасно скрываемым Error-ответом. */
$serviceErrorProvider = new RandomOrgStockProvider(
    1,
    1,
    1,
    0,
    static function (): array {
        return [
            'status' => 200,
            'transport_ok' => true,
            'body' => 'Error: secret diagnostic body',
            'content_type' => 'text/plain',
        ];
    }
);
/** @var StockProviderException|null $serviceErrorException Ошибка, не содержащая исходное тело ответа. */
$serviceErrorException = null;
try {
    $serviceErrorProvider->getCurrentQuantity($defaultItem);
} catch (StockProviderException $exception) {
    $serviceErrorException = $exception;
}
otusAutoserviceAssertStockCheck(
    $serviceErrorException instanceof StockProviderException
    && $serviceErrorException->isRetryable()
    && !str_contains($serviceErrorException->getMessage(), 'secret diagnostic body'),
    'random-safe-error-message'
);

/** @var StockProviderInterface $partiallyFailingProvider Тестовый источник с ошибкой только второго товара. */
$partiallyFailingProvider = new class implements StockProviderInterface {
    /** Возвращает отдельный машинный код только для пакетной диагностики. */
    public function getCode(): string
    {
        return 'batch_test';
    }

    /** Имитирует временную ошибку одного товара, не затрагивая соседние. */
    public function getCurrentQuantity(StockItem $item): int
    {
        if ($item->getProductId() === 102) {
            throw new StockProviderException(
                'Temporary test failure.',
                StockProviderException::TRANSPORT_ERROR,
                true
            );
        }

        return $item->getProductId() === 101 ? 0 : 9;
    }
};
/** @var StockFetchResult[] $batchResults Результаты пакета с успехом до и после ошибки. */
$batchResults = (new StockBatchFetcher($partiallyFailingProvider))->fetch(
    [$zeroItem, $articleItem, $defaultItem]
);
otusAutoserviceAssertStockCheck(
    count($batchResults) === 3
    && $batchResults[0]->isSuccess()
    && $batchResults[0]->getQuantity() === 0
    && !$batchResults[1]->isSuccess()
    && $batchResults[1]->getItem()->getProductId() === 102
    && $batchResults[1]->getErrorType() === StockProviderException::TRANSPORT_ERROR
    && $batchResults[1]->isRetryable()
    && $batchResults[2]->isSuccess()
    && $batchResults[2]->getQuantity() === 9,
    'batch-continues-after-item-error'
);

fwrite(
    STDOUT,
    (string)Loc::getMessage(
        'OTUS_AUTOSERVICE_CHECK_STOCK_CONFIGURED_PROVIDER',
        ['#CODE#' => $configuredProvider->getCode()]
    ) . PHP_EOL
);

if ($performLiveRequest) {
    try {
        /** @var int $liveQuantity Одно реальное значение от Random.org, запрошенное явным флагом. */
        $liveQuantity = (new RandomOrgStockProvider())->getCurrentQuantity($defaultItem);
    } catch (StockProviderException $exception) {
        fwrite(
            STDERR,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CHECK_STOCK_LIVE_FAILED',
                [
                    '#TYPE#' => $exception->getErrorType(),
                    '#ERROR#' => $exception->getMessage(),
                ]
            ) . PHP_EOL
        );
        exit(1);
    }

    fwrite(
        STDOUT,
        (string)Loc::getMessage(
            'OTUS_AUTOSERVICE_CHECK_STOCK_LIVE_QUANTITY',
            ['#QUANTITY#' => (string)$liveQuantity]
        ) . PHP_EOL
    );
}

fwrite(STDOUT, (string)Loc::getMessage('OTUS_AUTOSERVICE_CHECK_STOCK_OK') . PHP_EOL);
