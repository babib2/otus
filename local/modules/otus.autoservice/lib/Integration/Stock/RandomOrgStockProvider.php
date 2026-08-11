<?php

/**
 * Получает демонстрационный абсолютный остаток через integer endpoint Random.org.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Closure;
use InvalidArgumentException;
use Otus\Autoservice\Service\ModuleConfiguration;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Безопасная HTTP-реализация демонстрационного источника остатков от 0 до 10.
 *
 * Поставщик использует фиксированный HTTPS endpoint, ограничивает размер ответа,
 * проверяет MIME-тип, HTTP-статус, единственное целое число и его диапазон. Повторы
 * выполняются только для временных транспортных статусов и не превышают заданный
 * предел. Тело ответа и URL не включаются в тексты исключений.
 */
final class RandomOrgStockProvider implements StockProviderInterface
{
    /** Фиксированный endpoint из технического задания без секретных параметров. */
    public const ENDPOINT = 'https://www.random.org/integers/?num=1&min=0&max=10&col=1&base=10&format=plain&rnd=new';

    /** Минимальное допустимое абсолютное количество. */
    public const MIN_QUANTITY = 0;

    /** Максимальное допустимое абсолютное количество. */
    public const MAX_QUANTITY = 10;

    /** Максимальный размер простого числового ответа с небольшим запасом. */
    private const MAX_RESPONSE_BYTES = 256;

    /** Максимальное число последовательных HTTP-попыток одного запроса. */
    private int $maxAttempts;

    /** Тайм-аут установления соединения в секундах. */
    private int $connectionTimeout;

    /** Тайм-аут чтения ответа в секундах. */
    private int $streamTimeout;

    /** Задержка между временно неуспешными попытками в миллисекундах. */
    private int $retryDelayMilliseconds;

    /**
     * Транспортная функция, возвращающая только проверяемые метаданные HTTP-ответа.
     *
     * @var Closure(): array{transport_ok: bool, status: int, body: string, content_type: string}
     */
    private Closure $transport;

    /**
     * Создаёт HTTP-поставщика с ограниченными тайм-аутами и повторами.
     *
     * Пользовательский транспорт предназначен для автоматических тестов: рабочая
     * фабрика не передаёт его и использует штатный Bitrix HttpClient.
     *
     * @param int          $maxAttempts Общее число попыток от 1 до 3.
     * @param int          $connectionTimeout Тайм-аут соединения от 1 до 60 секунд.
     * @param int          $streamTimeout Тайм-аут чтения от 1 до 60 секунд.
     * @param int          $retryDelayMilliseconds Задержка повтора от 0 до 5000 мс.
     * @param Closure|null $transport Необязательная тестовая функция HTTP-запроса.
     */
    public function __construct(
        int $maxAttempts = 2,
        int $connectionTimeout = 5,
        int $streamTimeout = 10,
        int $retryDelayMilliseconds = 250,
        ?Closure $transport = null
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 3) {
            throw new InvalidArgumentException('Maximum attempts must be between 1 and 3.');
        }
        if ($connectionTimeout < 1 || $connectionTimeout > 60) {
            throw new InvalidArgumentException('Connection timeout must be between 1 and 60 seconds.');
        }
        if ($streamTimeout < 1 || $streamTimeout > 60) {
            throw new InvalidArgumentException('Stream timeout must be between 1 and 60 seconds.');
        }
        if ($retryDelayMilliseconds < 0 || $retryDelayMilliseconds > 5000) {
            throw new InvalidArgumentException('Retry delay must be between 0 and 5000 milliseconds.');
        }

        $this->maxAttempts = $maxAttempts;
        $this->connectionTimeout = $connectionTimeout;
        $this->streamTimeout = $streamTimeout;
        $this->retryDelayMilliseconds = $retryDelayMilliseconds;
        $this->transport = $transport ?? Closure::fromCallable([$this, 'performHttpRequest']);
    }

    /** Возвращает код, совпадающий со значением административной настройки. */
    public function getCode(): string
    {
        return ModuleConfiguration::STOCK_PROVIDER_RANDOM_ORG;
    }

    /**
     * Получает и строго проверяет одно абсолютное значение остатка.
     */
    public function getCurrentQuantity(StockItem $item): int
    {
        // Random.org не принимает идентификатор товара: StockItem нужен для единого заменяемого контракта.

        /** @var StockProviderException|null $lastException Последняя временная ошибка для итогового выброса. */
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                /** @var mixed $rawResponse Сырое значение внедрённого или штатного транспорта. */
                $rawResponse = ($this->transport)();
                /** @var array{transport_ok: bool, status: int, body: string, content_type: string} $response Проверенная структура HTTP-ответа. */
                $response = $this->normalizeTransportResponse($rawResponse);
            } catch (Throwable $exception) {
                $lastException = new StockProviderException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_TRANSPORT_ERROR'),
                    StockProviderException::TRANSPORT_ERROR,
                    true,
                    $exception
                );

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();
                    continue;
                }

                throw $lastException;
            }

            /** @var int $status HTTP-статус текущей попытки. */
            $status = $response['status'];
            if (!$response['transport_ok']) {
                $lastException = new StockProviderException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_TRANSPORT_ERROR'),
                    StockProviderException::TRANSPORT_ERROR,
                    true
                );

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();
                    continue;
                }

                throw $lastException;
            }

            if ($status === 200) {
                try {
                    return $this->parseSuccessfulResponse(
                        $response['body'],
                        $response['content_type']
                    );
                } catch (StockProviderException $exception) {
                    if ($exception->isRetryable() && $attempt < $this->maxAttempts) {
                        $this->pauseBeforeRetry();
                        continue;
                    }

                    throw $exception;
                }
            }

            /** @var bool $retryable Является ли HTTP-статус временным. */
            $retryable = $this->isRetryableStatus($status);
            $lastException = new StockProviderException(
                (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_RANDOM_STOCK_HTTP_STATUS_ERROR',
                    ['#STATUS#' => (string)$status]
                ),
                StockProviderException::HTTP_STATUS_ERROR,
                $retryable
            );

            if ($retryable && $attempt < $this->maxAttempts) {
                $this->pauseBeforeRetry();
                continue;
            }

            throw $lastException;
        }

        // Цикл всегда возвращает количество или выбрасывает исключение; ветка защищает контракт при будущих изменениях.
        throw $lastException ?? new StockProviderException(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_TRANSPORT_ERROR'),
            StockProviderException::TRANSPORT_ERROR,
            false
        );
    }

    /**
     * Выполняет один запрос штатным HTTP-клиентом Bitrix.
     *
     * @return array{transport_ok: bool, status: int, body: string, content_type: string}
     */
    private function performHttpRequest(): array
    {
        /** @var HttpClient $client HTTP-клиент с проверкой TLS и запретом частных адресов. */
        $client = new HttpClient(
            [
                'socketTimeout' => $this->connectionTimeout,
                'streamTimeout' => $this->streamTimeout,
                'redirect' => true,
                'redirectMax' => 2,
                'bodyLengthMax' => self::MAX_RESPONSE_BYTES,
                'privateIp' => false,
                'headers' => [
                    'Accept' => 'text/plain',
                    'User-Agent' => 'otus.autoservice',
                ],
            ]
        );

        /** @var string|false $body Тело ответа либо false при транспортной ошибке. */
        $body = $client->get(self::ENDPOINT);
        return [
            'transport_ok' => $body !== false,
            'status' => (int)$client->getStatus(),
            'body' => $body === false ? '' : $body,
            'content_type' => (string)$client->getContentType(),
        ];
    }

    /**
     * Проверяет структуру ответа внедрённого транспорта до обращения к полям.
     *
     * @param mixed $response Произвольный результат транспортной функции.
     *
     * @return array{transport_ok: bool, status: int, body: string, content_type: string}
     */
    private function normalizeTransportResponse($response): array
    {
        if (
            !is_array($response)
            || !isset($response['transport_ok'], $response['status'], $response['body'], $response['content_type'])
            || !is_bool($response['transport_ok'])
            || !is_int($response['status'])
            || !is_string($response['body'])
            || !is_string($response['content_type'])
        ) {
            throw new InvalidArgumentException('Stock transport returned an invalid response structure.');
        }

        return [
            'transport_ok' => $response['transport_ok'],
            'status' => $response['status'],
            'body' => $response['body'],
            'content_type' => $response['content_type'],
        ];
    }

    /**
     * Преобразует успешный plain-ответ в абсолютное неотрицательное количество.
     */
    private function parseSuccessfulResponse(string $body, string $contentType): int
    {
        /** @var string $normalizedContentType MIME-тип без параметра кодировки. */
        $normalizedContentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($normalizedContentType !== 'text/plain') {
            throw new StockProviderException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_CONTENT_TYPE_ERROR'),
                StockProviderException::RESPONSE_FORMAT_ERROR,
                false
            );
        }

        /** @var string $normalizedBody Ответ без завершающего перевода строки. */
        $normalizedBody = trim($body);
        if (str_starts_with($normalizedBody, 'Error:')) {
            throw new StockProviderException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_SERVICE_ERROR'),
                StockProviderException::RESPONSE_FORMAT_ERROR,
                true
            );
        }
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $normalizedBody) !== 1) {
            throw new StockProviderException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_RESPONSE_FORMAT_ERROR'),
                StockProviderException::RESPONSE_FORMAT_ERROR,
                false
            );
        }

        /** @var int $quantity Проверенное целочисленное значение ответа. */
        $quantity = (int)$normalizedBody;
        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            throw new StockProviderException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_RANDOM_STOCK_RESPONSE_RANGE_ERROR'),
                StockProviderException::RESPONSE_FORMAT_ERROR,
                false
            );
        }

        return $quantity;
    }

    /** Определяет ограниченный набор HTTP-статусов, для которых допустим повтор. */
    private function isRetryableStatus(int $status): bool
    {
        return $status === 0
            || $status === 408
            || $status === 425
            || $status === 429
            || ($status >= 500 && $status <= 599);
    }

    /** Выполняет короткую задержку перед повторной HTTP-попыткой. */
    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }
}
