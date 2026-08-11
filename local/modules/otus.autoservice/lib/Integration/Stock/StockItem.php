<?php

/**
 * Описывает одну запчасть, для которой поставщик должен вернуть внешний остаток.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use InvalidArgumentException;

/**
 * Неизменяемый набор безопасных идентификаторов товара каталога.
 *
 * Объект не содержит цены, остатка или произвольных полей инфоблока. Благодаря
 * этому поставщик получает только данные, необходимые для идентификации запчасти,
 * и не может случайно изменить состояние каталога.
 */
final class StockItem
{
    /** Положительный ID элемента штатного CRM-каталога. */
    private int $productId;

    /** Стабильный внешний идентификатор элемента каталога. */
    private string $externalId;

    /** Уникальный артикул запчасти из свойства каталога модуля. */
    private string $article;

    /**
     * Создаёт проверенное описание товара для запроса остатка.
     *
     * @param int    $productId Положительный ID элемента каталога.
     * @param string $externalId Непустой внешний ID длиной не более 255 байт.
     * @param string $article Непустой артикул длиной не более 255 байт.
     */
    public function __construct(int $productId, string $externalId, string $article)
    {
        /** @var string $normalizedExternalId Внешний ID без случайных крайних пробелов. */
        $normalizedExternalId = trim($externalId);
        /** @var string $normalizedArticle Артикул без случайных крайних пробелов. */
        $normalizedArticle = trim($article);

        if ($productId <= 0) {
            throw new InvalidArgumentException('Product ID must be a positive integer.');
        }
        if ($normalizedExternalId === '' || strlen($normalizedExternalId) > 255) {
            throw new InvalidArgumentException('External ID must contain from 1 to 255 bytes.');
        }
        if ($normalizedArticle === '' || strlen($normalizedArticle) > 255) {
            throw new InvalidArgumentException('Article must contain from 1 to 255 bytes.');
        }

        $this->productId = $productId;
        $this->externalId = $normalizedExternalId;
        $this->article = $normalizedArticle;
    }

    /** Возвращает ID элемента каталога без дополнительных запросов к базе. */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /** Возвращает стабильный внешний ID для интеграционного сопоставления. */
    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /** Возвращает человекочитаемый уникальный артикул запчасти. */
    public function getArticle(): string
    {
        return $this->article;
    }
}
