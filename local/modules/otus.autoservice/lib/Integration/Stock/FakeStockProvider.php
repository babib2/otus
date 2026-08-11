<?php

/**
 * Возвращает предсказуемые локальные остатки без сетевых запросов и записи в каталог.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use InvalidArgumentException;
use Otus\Autoservice\Service\ModuleConfiguration;

/**
 * Тестовый поставщик для разработки, диагностики и воспроизводимых сценариев.
 */
final class FakeStockProvider implements StockProviderInterface
{
    /** Остаток для товара, отсутствующего в явной карте значений. */
    private int $defaultQuantity;

    /**
     * Остатки, индексированные внешним ID или артикулом товара.
     *
     * @var array<string, int>
     */
    private array $quantities;

    /**
     * Создаёт поставщика с необязательными значениями для отдельных товаров.
     *
     * Сначала поиск выполняется по внешнему ID, затем по артикулу. Если оба ключа
     * отсутствуют, возвращается единое значение по умолчанию.
     *
     * @param array<string, int> $quantities Остатки по внешним ID или артикулам.
     * @param int                $defaultQuantity Неотрицательный остаток по умолчанию.
     */
    public function __construct(array $quantities = [], int $defaultQuantity = 5)
    {
        if ($defaultQuantity < 0) {
            throw new InvalidArgumentException('Default stock quantity cannot be negative.');
        }

        /** @var array<string, int> $normalizedQuantities Проверенная карта тестовых остатков. */
        $normalizedQuantities = [];
        /** @var string|int $key Исходный внешний ID или артикул из карты. */
        /** @var mixed $quantity Исходное значение остатка из карты. */
        foreach ($quantities as $key => $quantity) {
            /** @var string $normalizedKey Ключ без случайных крайних пробелов. */
            $normalizedKey = trim((string)$key);
            if ($normalizedKey === '' || strlen($normalizedKey) > 255) {
                throw new InvalidArgumentException('Fake stock key must contain from 1 to 255 bytes.');
            }
            if (!is_int($quantity) || $quantity < 0) {
                throw new InvalidArgumentException('Fake stock quantity must be a non-negative integer.');
            }

            $normalizedQuantities[$normalizedKey] = $quantity;
        }

        $this->quantities = $normalizedQuantities;
        $this->defaultQuantity = $defaultQuantity;
    }

    /** Возвращает код, совпадающий со значением административной настройки. */
    public function getCode(): string
    {
        return ModuleConfiguration::STOCK_PROVIDER_FAKE;
    }

    /**
     * Возвращает настроенный абсолютный остаток без побочных эффектов.
     */
    public function getCurrentQuantity(StockItem $item): int
    {
        /** @var string $externalId Стабильный внешний ID проверяемого товара. */
        $externalId = $item->getExternalId();
        if (array_key_exists($externalId, $this->quantities)) {
            return $this->quantities[$externalId];
        }

        /** @var string $article Уникальный артикул проверяемого товара. */
        $article = $item->getArticle();
        if (array_key_exists($article, $this->quantities)) {
            return $this->quantities[$article];
        }

        return $this->defaultQuantity;
    }
}
