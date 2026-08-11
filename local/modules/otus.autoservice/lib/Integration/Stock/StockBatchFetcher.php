<?php

/**
 * Получает остатки списка запчастей и изолирует ожидаемую ошибку каждого товара.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use InvalidArgumentException;

/**
 * Формирует поштучные результаты, не прекращая пакет после ошибки поставщика.
 *
 * Неожиданные программные исключения намеренно не скрываются: продолжение касается
 * только типизированных ошибок внешней интеграции StockProviderException.
 */
final class StockBatchFetcher
{
    /** Источник внешних абсолютных остатков для всех товаров одного пакета. */
    private StockProviderInterface $provider;

    /** Сохраняет явно выбранного поставщика без обращения к глобальному контейнеру. */
    public function __construct(StockProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Последовательно получает остатки, сохраняя успех или ошибку каждого товара.
     *
     * @param iterable<StockItem> $items Проверенные товары одной порции синхронизации.
     *
     * @return StockFetchResult[] Результаты в исходном порядке без пропущенных товаров.
     */
    public function fetch(iterable $items): array
    {
        /** @var StockFetchResult[] $results Накопленные поштучные результаты текущей порции. */
        $results = [];

        /** @var mixed $item Очередное значение входного итерируемого набора. */
        foreach ($items as $item) {
            if (!$item instanceof StockItem) {
                throw new InvalidArgumentException('Stock batch can contain only StockItem objects.');
            }

            try {
                /** @var int $quantity Абсолютный остаток успешно обработанного товара. */
                $quantity = $this->provider->getCurrentQuantity($item);
                $results[] = StockFetchResult::success($item, $quantity);
            } catch (StockProviderException $exception) {
                $results[] = StockFetchResult::failure($item, $exception);
            }
        }

        return $results;
    }
}
