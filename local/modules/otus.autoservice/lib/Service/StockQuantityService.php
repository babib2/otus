<?php

/**
 * Применяет абсолютный остаток запчасти публичными API каталога или складским документом Bitrix.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\Model\Product;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UserTable;
use Closure;
use InvalidArgumentException;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Integration\Stock\StockItem;
use Otus\Autoservice\Integration\Stock\StockQuantityUpdaterInterface;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Выбирает способ записи по фактическому режиму складского учёта и проверяет результат чтением.
 *
 * При выключенном складском учёте складская строка и доступное количество товара изменяются
 * одной транзакцией через D7 ORM/модель товара. При включённом режиме штатным legacy API
 * создаётся и проводится документ корректировки либо списания. Таблицы каталога прямым SQL
 * не изменяются.
 */
final class StockQuantityService implements StockQuantityUpdaterInterface
{
    /** Обновление выполнено публичными API товара и складской строки без складского документа. */
    public const MODE_DIRECT_API = 'direct_api';

    /** Обновление выполнено проведённым штатным складским документом. */
    public const MODE_INVENTORY_DOCUMENT = 'inventory_document';

    /** Товар, склад или конфигурация не соответствуют допустимой инфраструктуре модуля. */
    public const ERROR_INVALID_CONTEXT = 'stock_invalid_context';

    /** Целевой физический остаток меньше уже зарезервированного количества склада. */
    public const ERROR_BELOW_RESERVED = 'stock_below_reserved';

    /** Один из публичных D7 API вернул ошибочный Result. */
    public const ERROR_API_FAILED = 'stock_api_failed';

    /** Не удалось создать или провести обязательный складской документ. */
    public const ERROR_DOCUMENT_FAILED = 'stock_document_failed';

    /** Для положительной складской корректировки отсутствует корректная закупочная стоимость. */
    public const ERROR_PURCHASING_PRICE_REQUIRED = 'stock_purchasing_price_required';

    /** Контрольное чтение не подтвердило целевой остаток и согласованность количества. */
    public const ERROR_VERIFICATION_FAILED = 'stock_verification_failed';

    /** Допуск сравнения количеств каталога с плавающей точкой. */
    private const QUANTITY_EPSILON = 0.00001;

    /** Явно внедрённый ответственный складских документов либо null для текущей конфигурации. */
    private ?int $responsibleUserId;

    /**
     * Создаёт сервис с необязательным ответственным для CLI-тестов и фоновых сценариев.
     *
     * @param int|null $responsibleUserId Положительный ID активного пользователя либо null.
     */
    public function __construct(?int $responsibleUserId = null)
    {
        if ($responsibleUserId !== null && $responsibleUserId <= 0) {
            throw new InvalidArgumentException('Responsible user ID must be positive.');
        }

        $this->responsibleUserId = $responsibleUserId;
    }

    /**
     * Применяет абсолютный остаток и возвращает данные, необходимые поштучному журналу.
     *
     * @param StockItem $item Запчасть, ранее выбранная репозиторием модуля.
     * @param int $absoluteQuantity Целевой физический остаток на складе модуля.
     */
    public function apply(
        StockItem $item,
        int $absoluteQuantity,
        ?Closure $transactionalSuccessCallback = null
    ): Result {
        if ($absoluteQuantity < 0) {
            throw new InvalidArgumentException('Absolute stock quantity cannot be negative.');
        }

        /** @var array<string, mixed>|Result $context Проверенный снимок товара, склада и количества. */
        $context = $this->loadContext($item);
        if ($context instanceof Result) {
            return $context;
        }

        /** @var bool $inventoryMode Фактический режим, зафиксированный на всю операцию товара. */
        $inventoryMode = State::isUsedInventoryManagement();

        if ($absoluteQuantity + self::QUANTITY_EPSILON < (float)$context['store_reserved_quantity']) {
            return $this->createFailure(
                self::ERROR_BELOW_RESERVED,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_BELOW_RESERVED'),
                $this->buildResultData($context, $inventoryMode)
            );
        }

        if ($inventoryMode) {
            return $this->applyWithInventoryDocument(
                $context,
                $absoluteQuantity,
                $transactionalSuccessCallback
            );
        }

        return $this->applyWithDirectApi(
            $context,
            $absoluteQuantity,
            $transactionalSuccessCallback
        );
    }

    /**
     * Загружает и проверяет товар, XML_ID, каталог, активный склад и исходные количества.
     *
     * @return array<string, mixed>|Result Проверенный контекст либо ошибочный Result без изменений.
     */
    private function loadContext(StockItem $item): array|Result
    {
        /** @var int|null $catalogId Настроенный ID штатного CRM-каталога. */
        $catalogId = ModuleConfiguration::getSparePartsCatalogId();
        /** @var int|null $storeId Настроенный ID склада запчастей. */
        $storeId = ModuleConfiguration::getSparePartsStoreId();
        /** @var int|null $articlePropertyId ID свойства артикула выбранного каталога. */
        $articlePropertyId = ModuleConfiguration::getSparePartsArticlePropertyId();
        if ($catalogId === null || $storeId === null || $articlePropertyId === null) {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_CONFIG_REQUIRED')
            );
        }

        /** @var array<string, mixed>|false $element Элемент инфоблока, подтверждающий каталог и XML_ID. */
        $element = ElementTable::getByPrimary(
            $item->getProductId(),
            ['select' => ['ID', 'IBLOCK_ID', 'XML_ID', 'ACTIVE']]
        )->fetch();
        /** @var array<string, mixed>|false $product Штатная товарная запись и доступное количество. */
        $product = ProductTable::getByPrimary(
            $item->getProductId(),
            ['select' => ['ID', 'TYPE', 'QUANTITY', 'QUANTITY_RESERVED']]
        )->fetch();
        /** @var array<string, mixed>|false $store Настроенный активный склад модуля. */
        $store = StoreTable::getByPrimary(
            $storeId,
            ['select' => ['ID', 'ACTIVE', 'SITE_ID']]
        )->fetch();
        /** @var string|null $article Текущее нормализованное значение одиночного свойства. */
        $article = $this->loadArticle(
            $catalogId,
            $articlePropertyId,
            $item->getProductId()
        );

        if (
            $element === false
            || $product === false
            || $store === false
            || (int)$element['IBLOCK_ID'] !== $catalogId
            || trim((string)$element['XML_ID']) !== $item->getExternalId()
            || $article !== $item->getArticle()
            || (string)$element['ACTIVE'] !== 'Y'
            || (int)$product['TYPE'] !== ProductTable::TYPE_PRODUCT
            || (string)$store['ACTIVE'] !== 'Y'
        ) {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_ITEM_INVALID')
            );
        }

        /** @var array<string, mixed>|false $storeProduct Текущая строка товара на складе либо false. */
        $storeProduct = StoreProductTable::getList(
            [
                'select' => ['ID', 'AMOUNT', 'QUANTITY_RESERVED'],
                'filter' => [
                    '=STORE_ID' => $storeId,
                    '=PRODUCT_ID' => $item->getProductId(),
                ],
                'limit' => 1,
            ]
        )->fetch();
        if (
            (float)$product['QUANTITY_RESERVED'] < 0
            || (
                $storeProduct !== false
                && (
                    (float)$storeProduct['AMOUNT'] < 0
                    || (float)$storeProduct['QUANTITY_RESERVED'] < 0
                )
            )
        ) {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_ITEM_INVALID')
            );
        }

        return [
            'catalog_id' => $catalogId,
            'store_id' => $storeId,
            'store_site_id' => trim((string)$store['SITE_ID']),
            'product_id' => $item->getProductId(),
            'store_product_id' => $storeProduct === false ? null : (int)$storeProduct['ID'],
            'store_quantity' => $storeProduct === false ? 0.0 : (float)$storeProduct['AMOUNT'],
            'store_reserved_quantity' => $storeProduct === false
                ? 0.0
                : (float)$storeProduct['QUANTITY_RESERVED'],
            'product_quantity' => (float)$product['QUANTITY'],
        ];
    }

    /**
     * Обновляет складскую строку и доступное количество товара одной короткой транзакцией.
     */
    private function applyWithDirectApi(
        array $context,
        int $absoluteQuantity,
        ?Closure $transactionalSuccessCallback
    ): Result {
        /** @var Connection $connection Соединение транзакции двух согласованных D7-операций. */
        $connection = Application::getConnection();
        /** @var Result $result Итог, который будет заполнен данными контрольного чтения. */
        $result = new Result();
        $result->setData($this->buildResultData($context, false));

        /** @var float $newProductQuantity Доступное количество с учётом всех активных складов. */
        $newProductQuantity = $this->calculateAvailableQuantityWithTarget(
            (int)$context['product_id'],
            (int)$context['store_id'],
            (float)$absoluteQuantity,
            (float)$context['store_reserved_quantity']
        );

        $connection->startTransaction();
        try {
            if ($context['store_product_id'] === null) {
                /** @var \Bitrix\Main\ORM\Data\AddResult $storeResult Результат создания складской строки. */
                $storeResult = StoreProductTable::add(
                    [
                        'STORE_ID' => (int)$context['store_id'],
                        'PRODUCT_ID' => (int)$context['product_id'],
                        'AMOUNT' => (float)$absoluteQuantity,
                        'QUANTITY_RESERVED' => 0.0,
                    ]
                );
            } else {
                /**
                 * @var \Bitrix\Main\ORM\Data\UpdateResult $storeResult
                 * Результат изменения абсолютного остатка склада.
                 */
                $storeResult = StoreProductTable::update(
                    (int)$context['store_product_id'],
                    ['AMOUNT' => (float)$absoluteQuantity]
                );
            }
            if (!$storeResult->isSuccess()) {
                $connection->rollbackTransaction();

                return $this->createFailure(
                    self::ERROR_API_FAILED,
                    $this->formatOperationErrors($storeResult->getErrorMessages()),
                    $result->getData()
                );
            }

            /** @var \Bitrix\Main\ORM\Data\UpdateResult $productResult Результат штатной модели товара. */
            $productResult = Product::update(
                (int)$context['product_id'],
                [
                    'fields' => ['QUANTITY' => $newProductQuantity],
                    'external_fields' => ['IBLOCK_ID' => (int)$context['catalog_id']],
                ]
            );
            if (!$productResult->isSuccess()) {
                $connection->rollbackTransaction();
                Product::clearCacheItem((int)$context['product_id']);

                return $this->createFailure(
                    self::ERROR_API_FAILED,
                    $this->formatOperationErrors($productResult->getErrorMessages()),
                    $result->getData()
                );
            }

            /** @var array<string, mixed>|null $verifiedData Контрольные количества после обеих операций. */
            $verifiedData = $this->verifyAppliedQuantity($context, (float)$absoluteQuantity);
            if ($verifiedData === null) {
                $connection->rollbackTransaction();
                Product::clearCacheItem((int)$context['product_id']);

                return $this->createFailure(
                    self::ERROR_VERIFICATION_FAILED,
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_VERIFY_FAILED'),
                    $result->getData()
                );
            }

            $result->setData(
                array_merge(
                    $result->getData(),
                    [
                        'applied_store_quantity' => $verifiedData['store_quantity'],
                        'applied_product_quantity' => $verifiedData['product_quantity'],
                    ]
                )
            );
            $this->invokeTransactionalSuccessCallback($transactionalSuccessCallback, $result);
            $connection->commitTransaction();

            Product::clearCacheItem((int)$context['product_id']);
            return $result;
        } catch (Throwable $exception) {
            $connection->rollbackTransaction();
            Product::clearCacheItem((int)$context['product_id']);

            throw $exception;
        }
    }

    /**
     * Создаёт и проводит корректировку или списание на разницу с абсолютным остатком.
     */
    private function applyWithInventoryDocument(
        array $context,
        int $absoluteQuantity,
        ?Closure $transactionalSuccessCallback
    ): Result {
        /** @var Result $result Заготовка с исходными значениями для журнала. */
        $result = new Result();
        $result->setData($this->buildResultData($context, true));
        /** @var float $delta Разница физического остатка, оформляемая документом. */
        $delta = (float)$absoluteQuantity - (float)$context['store_quantity'];

        if (abs($delta) <= self::QUANTITY_EPSILON) {
            /** @var array<string, mixed>|null $verifiedData Контроль неизменного абсолютного остатка. */
            $verifiedData = $this->verifyAppliedQuantity($context, (float)$absoluteQuantity);
            if ($verifiedData === null) {
                return $this->createFailure(
                    self::ERROR_VERIFICATION_FAILED,
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_VERIFY_FAILED'),
                    $result->getData()
                );
            }

            $result->setData(
                array_merge(
                    $result->getData(),
                    [
                        'applied_store_quantity' => $verifiedData['store_quantity'],
                        'applied_product_quantity' => $verifiedData['product_quantity'],
                    ]
                )
            );
            if ($transactionalSuccessCallback !== null) {
                /** @var Connection $connection Соединение атомарной записи неизменного результата. */
                $connection = Application::getConnection();
                $connection->startTransaction();
                try {
                    $this->invokeTransactionalSuccessCallback(
                        $transactionalSuccessCallback,
                        $result
                    );
                    $connection->commitTransaction();
                } catch (Throwable $exception) {
                    $connection->rollbackTransaction();
                    throw $exception;
                }
            }

            return $result;
        }

        if (!Loader::includeModule('currency')) {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_CURRENCY_REQUIRED'),
                $result->getData()
            );
        }

        /** @var int|null $responsibleUserId Активный пользователь, обязательный для документа. */
        $responsibleUserId = $this->resolveResponsibleUserId();
        if ($responsibleUserId === null) {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_RESPONSIBLE_REQUIRED'),
                $result->getData()
            );
        }

        /** @var string $documentType Корректировка для прихода либо списание для уменьшения. */
        $documentType = $delta > 0
            ? StoreDocumentTable::TYPE_STORE_ADJUSTMENT
            : StoreDocumentTable::TYPE_DEDUCT;
        /** @var string $siteId Проверенный сайт документа и склада. */
        $siteId = $this->resolveSiteId((string)$context['store_site_id']);
        if ($siteId === '') {
            return $this->createFailure(
                self::ERROR_INVALID_CONTEXT,
                (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_SITE_REQUIRED'),
                $result->getData()
            );
        }

        /** @var Connection $connection Соединение общей транзакции создания и проведения документа. */
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            /** @var array<string, mixed> $elementFields Одна позиция с положительной величиной движения. */
            $elementFields = [
                'ELEMENT_ID' => (int)$context['product_id'],
                'AMOUNT' => abs($delta),
            ];
            if ($documentType === StoreDocumentTable::TYPE_STORE_ADJUSTMENT) {
                $elementFields['STORE_TO'] = (int)$context['store_id'];
                /** @var float|null $purchasingPrice Безопасная цена прихода либо null при ошибке. */
                $purchasingPrice = $this->resolvePurchasingPrice($context);
                if ($purchasingPrice === null) {
                    $connection->rollbackTransaction();

                    return $this->createFailure(
                        self::ERROR_PURCHASING_PRICE_REQUIRED,
                        (string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STOCK_QUANTITY_PURCHASING_PRICE_REQUIRED'
                        ),
                        $result->getData()
                    );
                }
                $elementFields['PURCHASING_PRICE'] = $purchasingPrice;
            } else {
                $elementFields['STORE_FROM'] = (int)$context['store_id'];
            }

            /** @var array<string, mixed> $documentFields Поля штатного складского документа. */
            $documentFields = [
                'DOC_TYPE' => $documentType,
                'SITE_ID' => $siteId,
                'CREATED_BY' => $responsibleUserId,
                'MODIFIED_BY' => $responsibleUserId,
                'RESPONSIBLE_ID' => $responsibleUserId,
                'STATUS' => 'N',
                'WAS_CANCELLED' => 'N',
                'COMMENTARY' => (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_COMMENT'
                ),
                'ELEMENT' => [$elementFields],
            ];
            if ($documentType === StoreDocumentTable::TYPE_STORE_ADJUSTMENT) {
                $documentFields['CURRENCY'] = CurrencyManager::getBaseCurrency();
                $documentFields['TOTAL'] = abs($delta) * (float)$elementFields['PURCHASING_PRICE'];
            }

            $this->resetApplicationException();
            /** @var bool|int $createdDocumentId Результат штатного API создания документа и позиции. */
            $createdDocumentId = \CCatalogDocs::add($documentFields);
            if ((int)$createdDocumentId <= 0) {
                /** @var string $legacyError Безопасная ошибка штатного API создания. */
                $legacyError = $this->consumeApplicationException();
                $connection->rollbackTransaction();

                return $this->createFailure(
                    self::ERROR_DOCUMENT_FAILED,
                    $legacyError,
                    $result->getData()
                );
            }

            /** @var int $documentId ID созданного, но ещё не проведённого документа. */
            $documentId = (int)$createdDocumentId;

            $this->resetApplicationException();
            // В установленной версии публичный контроллер Bitrix сам проводит документы этим API.
            /** @var bool|string $conducted Эквивалент штатного Result для проведения документа. */
            $conducted = \CCatalogDocs::conductDocument($documentId, $responsibleUserId);
            if ($conducted !== true) {
                /** @var string $legacyError Безопасное сообщение исключения глобального legacy API. */
                $legacyError = $this->consumeApplicationException();
                $connection->rollbackTransaction();

                return $this->createFailure(
                    self::ERROR_DOCUMENT_FAILED,
                    $legacyError,
                    $result->getData()
                );
            }

            /** @var array<string, mixed>|false $conductedDocument Контроль статуса и типа документа. */
            $conductedDocument = StoreDocumentTable::getByPrimary(
                $documentId,
                ['select' => ['ID', 'DOC_TYPE', 'STATUS']]
            )->fetch();
            if (
                $conductedDocument === false
                || (string)$conductedDocument['DOC_TYPE'] !== $documentType
                || (string)$conductedDocument['STATUS'] !== StoreDocumentTable::STATUS_CONDUCTED
            ) {
                $connection->rollbackTransaction();

                return $this->createFailure(
                    self::ERROR_DOCUMENT_FAILED,
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_FAILED'),
                    $result->getData()
                );
            }

            /** @var array<string, mixed>|null $verifiedData Остатки после штатного проведения. */
            $verifiedData = $this->verifyAppliedQuantity($context, (float)$absoluteQuantity);
            if ($verifiedData === null) {
                $connection->rollbackTransaction();

                return $this->createFailure(
                    self::ERROR_VERIFICATION_FAILED,
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_VERIFY_FAILED'),
                    $result->getData()
                );
            }

            $result->setData(
                array_merge(
                    $result->getData(),
                    [
                        'document_id' => $documentId,
                        'applied_store_quantity' => $verifiedData['store_quantity'],
                        'applied_product_quantity' => $verifiedData['product_quantity'],
                    ]
                )
            );
            $this->invokeTransactionalSuccessCallback($transactionalSuccessCallback, $result);
            $connection->commitTransaction();

            return $result;
        } catch (Throwable $exception) {
            $connection->rollbackTransaction();

            throw $exception;
        }
    }

    /**
     * Вычисляет доступный итог товара после замены физического остатка выбранного склада.
     */
    private function calculateAvailableQuantityWithTarget(
        int $productId,
        int $targetStoreId,
        float $targetAmount,
        float $targetReserved
    ): float {
        /** @var float $availableQuantity Доступный остаток целевого и остальных активных складов. */
        $availableQuantity = $targetAmount - $targetReserved;
        /** @var array<string, mixed> $row Очередной остаток другого активного склада. */
        foreach (
            StoreProductTable::getList(
                [
                    'select' => ['STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED'],
                    'filter' => [
                        '=PRODUCT_ID' => $productId,
                        '=STORE.ACTIVE' => 'Y',
                        '!=STORE_ID' => $targetStoreId,
                    ],
                ]
            ) as $row
        ) {
            $availableQuantity += (float)$row['AMOUNT'] - (float)$row['QUANTITY_RESERVED'];
        }

        return $availableQuantity;
    }

    /** Возвращает текущее одиночное значение артикула тем же API, что и репозиторий. */
    private function loadArticle(int $catalogId, int $articlePropertyId, int $productId): ?string
    {
        /** @var array<int, array<string, mixed>> $propertyRows Контейнер массового API для одного товара. */
        $propertyRows = [$productId => ['ID' => $productId]];
        \CIBlockElement::GetPropertyValuesArray(
            $propertyRows,
            $catalogId,
            ['ID' => [$productId]],
            ['ID' => $articlePropertyId],
            [
                'USE_PROPERTY_ID' => 'Y',
                'GET_RAW_DATA' => 'Y',
                'PROPERTY_FIELDS' => ['ID'],
            ]
        );

        /** @var mixed $rawArticle Сырое значение свойства либо null при отсутствии. */
        $rawArticle = $propertyRows[$productId][$articlePropertyId]['VALUE'] ?? null;
        if (!is_scalar($rawArticle)) {
            return null;
        }

        /** @var string $article Артикул без случайных крайних пробелов. */
        $article = trim((string)$rawArticle);

        return $article === '' ? null : $article;
    }

    /**
     * Повторно читает количества и подтверждает абсолютный остаток и общую согласованность.
     *
     * @return array{store_quantity: float, product_quantity: float}|null Проверенные значения либо null.
     */
    private function verifyAppliedQuantity(array $context, float $absoluteQuantity): ?array
    {
        /** @var array<string, mixed>|false $storeProduct Контрольная строка выбранного склада. */
        $storeProduct = StoreProductTable::getList(
            [
                'select' => ['AMOUNT', 'QUANTITY_RESERVED'],
                'filter' => [
                    '=STORE_ID' => (int)$context['store_id'],
                    '=PRODUCT_ID' => (int)$context['product_id'],
                ],
                'limit' => 1,
            ]
        )->fetch();
        /** @var array<string, mixed>|false $product Контрольное доступное количество товара. */
        $product = ProductTable::getByPrimary(
            (int)$context['product_id'],
            ['select' => ['QUANTITY']]
        )->fetch();
        if (
            $storeProduct === false
            || $product === false
            || abs((float)$storeProduct['AMOUNT'] - $absoluteQuantity) > self::QUANTITY_EPSILON
            || (float)$storeProduct['QUANTITY_RESERVED'] > (float)$storeProduct['AMOUNT'] + self::QUANTITY_EPSILON
            || !(new SparePartsCatalogManager())->isProductQuantityConsistent(
                (int)$context['product_id']
            )
        ) {
            return null;
        }

        return [
            'store_quantity' => (float)$storeProduct['AMOUNT'],
            'product_quantity' => (float)$product['QUANTITY'],
        ];
    }

    /**
     * Выбирает активного ответственного: явный ID, текущего пользователя или настройку cron.
     */
    private function resolveResponsibleUserId(): ?int
    {
        /** @var array<int, int|null> $candidates Приоритетные кандидаты на ответственность. */
        $candidates = [
            $this->responsibleUserId,
            (int)CurrentUser::get()->getId(),
            ModuleConfiguration::getStockDocumentResponsibleUserId(),
        ];
        /** @var int|null $candidate Очередной ID до проверки активности. */
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate <= 0) {
                continue;
            }

            /** @var array<string, mixed>|null $user Активный пользователь для аудита документа. */
            $user = UserTable::getRow(
                [
                    'select' => ['ID'],
                    'filter' => ['=ID' => $candidate, '=ACTIVE' => 'Y'],
                ]
            );
            if ($user !== null) {
                return (int)$user['ID'];
            }
        }

        return null;
    }

    /** Выбирает сайт склада либо основной активный сайт портала. */
    private function resolveSiteId(string $storeSiteId): string
    {
        if ($storeSiteId !== '') {
            /** @var array<string, mixed>|null $storeSite Активный сайт, заданный у склада. */
            $storeSite = SiteTable::getRow(
                [
                    'select' => ['ID'],
                    'filter' => ['=ID' => $storeSiteId, '=ACTIVE' => 'Y'],
                ]
            );
            if ($storeSite !== null) {
                return (string)$storeSite['ID'];
            }
        }

        /** @var array<string, mixed>|null $defaultSite Основной либо первый активный сайт. */
        $defaultSite = SiteTable::getRow(
            [
                'select' => ['ID'],
                'filter' => ['=ACTIVE' => 'Y'],
                'order' => ['DEF' => 'DESC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            ]
        );

        return $defaultSite === null ? '' : trim((string)$defaultSite['ID']);
    }

    /**
     * Возвращает закупочную цену прихода в базовой валюте без ложной нулевой стоимости партии.
     */
    private function resolvePurchasingPrice(array $context): ?float
    {
        /** @var array<string, mixed>|false $product Текущая закупочная цена и валюта товара. */
        $product = ProductTable::getByPrimary(
            (int)$context['product_id'],
            ['select' => ['PURCHASING_PRICE', 'PURCHASING_CURRENCY']]
        )->fetch();
        /** @var float $purchasingPrice Проверенная неотрицательная закупочная цена. */
        $purchasingPrice = $product === false ? 0.0 : (float)$product['PURCHASING_PRICE'];
        /** @var string $purchasingCurrency Валюта закупочной цены товара. */
        $purchasingCurrency = $product === false
            ? ''
            : trim((string)$product['PURCHASING_CURRENCY']);
        /** @var string $baseCurrency Базовая валюта создаваемого документа. */
        $baseCurrency = trim((string)CurrencyManager::getBaseCurrency());
        if ($purchasingPrice <= 0.0) {
            return State::isProductBatchMethodSelected() ? null : 0.0;
        }
        if ($purchasingCurrency === '' || $baseCurrency === '') {
            return null;
        }
        if ($purchasingCurrency !== $baseCurrency) {
            $purchasingPrice = (float)\CCurrencyRates::ConvertCurrency(
                $purchasingPrice,
                $purchasingCurrency,
                $baseCurrency
            );
        }
        if (!is_finite($purchasingPrice) || $purchasingPrice <= 0.0) {
            return null;
        }

        return $purchasingPrice;
    }

    /** Выполняет запись успешного аудита внутри ещё не зафиксированной транзакции каталога. */
    private function invokeTransactionalSuccessCallback(?Closure $callback, Result $result): void
    {
        if ($callback !== null) {
            $callback($result);
        }
    }

    /**
     * Формирует единый набор данных журнала до применения и с пустыми итоговыми значениями.
     *
     * @return array<string, mixed> Данные Result, которые StockSyncService перенесёт в ORM-журнал.
     */
    private function buildResultData(array $context, bool $inventoryMode): array
    {
        return [
            'store_id' => (int)$context['store_id'],
            'mode' => $inventoryMode ? self::MODE_INVENTORY_DOCUMENT : self::MODE_DIRECT_API,
            'previous_store_quantity' => (float)$context['store_quantity'],
            'applied_store_quantity' => null,
            'previous_product_quantity' => (float)$context['product_quantity'],
            'applied_product_quantity' => null,
            'document_id' => null,
        ];
    }

    /** Создаёт ошибочный Result с машинным кодом и необязательными исходными данными. */
    private function createFailure(string $code, string $message, array $data = []): Result
    {
        /** @var Result $result Ошибочный результат поштучной операции. */
        $result = new Result();
        $result->setData($data);
        $result->addError(
            new Error(
                trim($message) === ''
                    ? (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_API_FAILED')
                    : $message,
                $code
            )
        );

        return $result;
    }

    /** Объединяет непустые сообщения API, не подменяя их техническим SQL или исключением. */
    private function formatOperationErrors(array $messages): string
    {
        /** @var string[] $normalizedMessages Непустые скалярные сообщения штатного Result. */
        $normalizedMessages = array_values(
            array_filter(
                array_map('strval', $messages),
                static function (string $message): bool {
                    return trim($message) !== '';
                }
            )
        );

        return $normalizedMessages === []
            ? (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_API_FAILED')
            : implode('; ', $normalizedMessages);
    }

    /** Забирает и сбрасывает ошибку legacy API проведения, возвращая безопасный запасной текст. */
    private function consumeApplicationException(): string
    {
        global $APPLICATION;

        if (!is_object($APPLICATION)) {
            return (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_FAILED');
        }

        /** @var mixed $exception Исключение, сохранённое глобальным приложением Bitrix. */
        $exception = $APPLICATION->GetException();
        if (!is_object($exception)) {
            return (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_FAILED');
        }

        /** @var string $message Текст бизнес-ошибки проведения без SQL и трассировки. */
        $message = trim((string)$exception->GetString());
        $APPLICATION->ResetException();

        return $message === ''
            ? (string)Loc::getMessage('OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_FAILED')
            : $message;
    }

    /** Удаляет возможную устаревшую ошибку перед новым вызовом глобального legacy API. */
    private function resetApplicationException(): void
    {
        global $APPLICATION;

        if (is_object($APPLICATION)) {
            $APPLICATION->ResetException();
        }
    }
}
