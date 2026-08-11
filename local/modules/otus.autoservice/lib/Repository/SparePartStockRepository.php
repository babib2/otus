<?php

/**
 * Пакетно выбирает запчасти из штатного CRM-каталога для синхронизации остатков.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Repository;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Localization\Loc;
use InvalidArgumentException;
use Otus\Autoservice\Service\ModuleConfiguration;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Читает товары и свойство артикула без запросов к каталогу внутри цикла товаров.
 *
 * Непустое значение свойства артикула является признаком запчасти автосервиса.
 * Обычные товары штатного CRM-каталога без этого свойства сканируются для движения
 * курсора, но не передаются внешнему поставщику и не попадают в журнал запуска.
 */
final class SparePartStockRepository
{
    /** ID штатного CRM-инфоблока с товарами и запчастями. */
    private int $catalogId;

    /** ID строкового свойства, хранящего уникальный артикул запчасти. */
    private int $articlePropertyId;

    /**
     * Создаёт репозиторий по текущей конфигурации либо явно переданным ID.
     *
     * Явные значения используются диагностикой; рабочий cron читает настройки,
     * записанные миграцией подготовки каталога.
     */
    public function __construct(?int $catalogId = null, ?int $articlePropertyId = null)
    {
        /** @var int|null $resolvedCatalogId Итоговый ID инфоблока каталога. */
        $resolvedCatalogId = $catalogId ?? ModuleConfiguration::getSparePartsCatalogId();
        /** @var int|null $resolvedPropertyId Итоговый ID свойства артикула. */
        $resolvedPropertyId = $articlePropertyId
            ?? ModuleConfiguration::getSparePartsArticlePropertyId();

        if ($resolvedCatalogId === null || $resolvedPropertyId === null) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_REPOSITORY_CONFIG_REQUIRED')
            );
        }

        $this->catalogId = $resolvedCatalogId;
        $this->articlePropertyId = $resolvedPropertyId;
    }

    /**
     * Выбирает очередную порцию простых активных товаров и оставляет запчасти.
     *
     * Курсор строится по ID товара каталога, поэтому вставка новых товаров не
     * приводит к повторной обработке уже пройденных ID текущего запуска.
     *
     * @param int $afterProductId Последний просканированный ID или 0 для начала.
     * @param int $limit Максимум сканируемых товаров от 1 до 500.
     *
     * @return array{
     *     items: array<int, array{product_id: int, external_id: string, article: string}>,
     *     last_product_id: int,
     *     scanned_count: int
     * }
     */
    public function fetchBatch(int $afterProductId, int $limit): array
    {
        if ($afterProductId < 0) {
            throw new InvalidArgumentException('Product cursor cannot be negative.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Stock repository batch size must be between 1 and 500.');
        }

        /** @var array<int, array<string, mixed>> $productRows Порция товаров с внешним ID элемента. */
        $productRows = ProductTable::getList(
            [
                'select' => [
                    'ID',
                    'ELEMENT_XML_ID' => 'IBLOCK_ELEMENT.XML_ID',
                ],
                'filter' => [
                    '=IBLOCK_ELEMENT.IBLOCK_ID' => $this->catalogId,
                    '=IBLOCK_ELEMENT.ACTIVE' => 'Y',
                    '=TYPE' => ProductTable::TYPE_PRODUCT,
                    '>ID' => $afterProductId,
                ],
                'order' => ['ID' => 'ASC'],
                'limit' => $limit,
            ]
        )->fetchAll();

        if ($productRows === []) {
            return [
                'items' => [],
                'last_product_id' => $afterProductId,
                'scanned_count' => 0,
            ];
        }

        /** @var array<int, array<string, mixed>> $propertyRows Заготовка массовой загрузки свойства по ID товара. */
        $propertyRows = [];
        /** @var array<string, mixed> $productRow Очередной товар выбранной порции. */
        foreach ($productRows as $productRow) {
            /** @var int $productId Положительный ID товара и элемента инфоблока. */
            $productId = (int)$productRow['ID'];
            $propertyRows[$productId] = ['ID' => $productId];
        }

        \CIBlockElement::GetPropertyValuesArray(
            $propertyRows,
            $this->catalogId,
            ['ID' => array_keys($propertyRows)],
            ['ID' => $this->articlePropertyId],
            [
                'USE_PROPERTY_ID' => 'Y',
                'GET_RAW_DATA' => 'Y',
                'PROPERTY_FIELDS' => ['ID'],
            ]
        );

        /** @var array<int, array{product_id: int, external_id: string, article: string}> $items Найденные запчасти порции. */
        $items = [];
        /** @var int $lastProductId Максимальный просканированный ID для следующего запроса. */
        $lastProductId = $afterProductId;

        foreach ($productRows as $productRow) {
            /** @var int $productId ID текущего товара. */
            $productId = (int)$productRow['ID'];
            $lastProductId = max($lastProductId, $productId);

            /** @var mixed $rawArticle Сырое значение одиночного свойства артикула. */
            $rawArticle = $propertyRows[$productId][$this->articlePropertyId]['VALUE'] ?? null;
            /** @var string $article Нормализованный артикул либо пустая строка для обычного товара CRM. */
            $article = is_scalar($rawArticle) ? trim((string)$rawArticle) : '';
            if ($article === '') {
                continue;
            }

            /** @var mixed $rawExternalId Сырое XML_ID элемента каталога. */
            $rawExternalId = $productRow['ELEMENT_XML_ID'] ?? null;
            $items[] = [
                'product_id' => $productId,
                'external_id' => is_scalar($rawExternalId) ? trim((string)$rawExternalId) : '',
                'article' => $article,
            ];
        }

        return [
            'items' => $items,
            'last_product_id' => $lastProductId,
            'scanned_count' => count($productRows),
        ];
    }
}
