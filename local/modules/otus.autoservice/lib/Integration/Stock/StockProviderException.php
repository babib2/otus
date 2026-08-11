<?php

/**
 * Представляет безопасную типизированную ошибку получения внешнего остатка.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use RuntimeException;
use Throwable;

/**
 * Позволяет синхронизации отличить временный сбой от постоянной ошибки данных.
 */
final class StockProviderException extends RuntimeException
{
    /** Код ошибки транспорта или исключения HTTP-клиента. */
    public const TRANSPORT_ERROR = 'transport_error';

    /** Код неожиданного HTTP-статуса внешнего сервиса. */
    public const HTTP_STATUS_ERROR = 'http_status_error';

    /** Код ответа с неподдерживаемым типом или содержимым. */
    public const RESPONSE_FORMAT_ERROR = 'response_format_error';

    /** Машинный код категории ошибки без текста ответа и секретных данных. */
    private string $errorType;

    /** Можно ли безопасно повторить запрос через ограниченный интервал. */
    private bool $retryable;

    /**
     * Создаёт ошибку, пригодную для решения о повторе и безопасного журнала.
     *
     * @param string         $message Локализованное описание без URL и тела ответа.
     * @param string         $errorType Один из машинных кодов класса.
     * @param bool           $retryable Признак временной ошибки.
     * @param Throwable|null $previous Исходное исключение транспорта для диагностики стека.
     */
    public function __construct(
        string $message,
        string $errorType,
        bool $retryable,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorType = $errorType;
        $this->retryable = $retryable;
    }

    /** Возвращает стабильную категорию ошибки для журнала синхронизации. */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /** Сообщает, допустим ли ограниченный повтор запроса. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
