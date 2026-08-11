<?php

/**
 * Хранит результат получения внешнего остатка одной запчасти без потери контекста товара.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use LogicException;

/**
 * Неизменяемый результат успешного запроса либо безопасной ошибки поставщика.
 */
final class StockFetchResult
{
    /** Проверенный товар, к которому относится результат. */
    private StockItem $item;

    /** Абсолютное количество успешного ответа или null при ошибке. */
    private ?int $quantity;

    /** Безопасный машинный тип ошибки или null при успехе. */
    private ?string $errorType;

    /** Безопасное локализованное описание ошибки или null при успехе. */
    private ?string $errorMessage;

    /** Допустимо ли повторить неуспешный запрос позже. */
    private bool $retryable;

    /**
     * Создаёт согласованное состояние результата; вызывается только фабричными методами.
     */
    private function __construct(
        StockItem $item,
        ?int $quantity,
        ?string $errorType,
        ?string $errorMessage,
        bool $retryable
    ) {
        $this->item = $item;
        $this->quantity = $quantity;
        $this->errorType = $errorType;
        $this->errorMessage = $errorMessage;
        $this->retryable = $retryable;
    }

    /** Создаёт успешный результат с неотрицательным абсолютным количеством. */
    public static function success(StockItem $item, int $quantity): self
    {
        if ($quantity < 0) {
            throw new LogicException('Successful stock quantity cannot be negative.');
        }

        return new self($item, $quantity, null, null, false);
    }

    /** Создаёт ошибочный результат только из безопасного исключения поставщика. */
    public static function failure(StockItem $item, StockProviderException $exception): self
    {
        return new self(
            $item,
            null,
            $exception->getErrorType(),
            $exception->getMessage(),
            $exception->isRetryable()
        );
    }

    /** Возвращает товар, даже если получение его остатка завершилось ошибкой. */
    public function getItem(): StockItem
    {
        return $this->item;
    }

    /** Сообщает, содержит ли результат корректное абсолютное количество. */
    public function isSuccess(): bool
    {
        return $this->quantity !== null;
    }

    /**
     * Возвращает успешное количество.
     *
     * @throws LogicException Если вызывающий код не проверил isSuccess().
     */
    public function getQuantity(): int
    {
        if ($this->quantity === null) {
            throw new LogicException('Failed stock result does not contain a quantity.');
        }

        return $this->quantity;
    }

    /** Возвращает машинный тип ошибки либо null для успешного результата. */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /** Возвращает безопасное описание ошибки либо null для успешного результата. */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** Сообщает, допустим ли более поздний повтор неуспешного запроса. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
