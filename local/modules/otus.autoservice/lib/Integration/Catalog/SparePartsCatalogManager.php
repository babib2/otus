<?php

/**
 * Управляет инфраструктурой штатного CRM-каталога и отдельным заполнением демонстрационных запчастей.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Catalog;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\MeasureRatioTable;
use Bitrix\Catalog\Model\Product;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreDocumentElementTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Otus\Autoservice\Service\ModuleConfiguration;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Управляет принадлежащими модулю объектами внутри штатного товарного каталога CRM.
 *
 * Сам CRM-каталог не считается собственностью модуля и никогда не удаляется. Принадлежность
 * склада, свойства и демонстрационных товаров определяется по длинным стабильным XML_ID,
 * поэтому повторные настройка и заполнение восстанавливают объекты без создания дубликатов.
 */
final class SparePartsCatalogManager
{
    /** Внешний идентификатор демонстрационного склада, однозначно принадлежащего модулю. */
    public const STORE_XML_ID = 'OTUS_AUTOSERVICE_DEMO_STORE';

    /** Символьный код демонстрационного склада для административного интерфейса. */
    public const STORE_CODE = 'OTUS_AUTOSERVICE_DEMO';

    /** Код строкового свойства, в котором хранится человекочитаемый артикул запчасти. */
    public const ARTICLE_PROPERTY_CODE = 'OTUS_AUTOSERVICE_ARTICLE';

    /** Внешний идентификатор свойства артикула для безопасного определения владельца. */
    public const ARTICLE_PROPERTY_XML_ID = 'OTUS_AUTOSERVICE_ARTICLE_PROPERTY';

    /** Имя блокировки СУБД, сериализующей создание и откат объектов каталога. */
    private const OPERATION_LOCK_NAME = 'otus.autoservice.spare_parts_catalog';

    /** Максимальное время ожидания операции настройки каталога в секундах. */
    private const OPERATION_LOCK_TIMEOUT = 30;

    /** Настройки ID инфраструктуры, которые сохраняются только после успешной подготовки объектов. */
    private const CONFIGURATION_OPTION_NAMES = [
        ModuleConfiguration::OPTION_SPARE_PARTS_CATALOG_ID,
        ModuleConfiguration::OPTION_SPARE_PARTS_ARTICLE_PROPERTY_ID,
        ModuleConfiguration::OPTION_SPARE_PARTS_STORE_ID,
    ];

    /**
     * Стабильные описания демонстрационных товаров.
     *
     * Начальные количества применяются только при выключенном складском учёте. Если складской
     * учёт включён, новые товары создаются с нулём: ненулевой приход должен оформляться складским
     * документом на этапе синхронизации, а не прямой записью из демонстрационного сценария.
     *
     * @var array<int, array{xml_id: string, code: string, article: string, name_message: string, sort: int, initial_quantity: float}>
     */
    private const DEMO_PARTS = [
        [
            'xml_id' => 'OTUS_AUTOSERVICE_PART_OIL_FILTER',
            'code' => 'otus-autoservice-oil-filter',
            'article' => 'OTUS-OF-001',
            'name_message' => 'OTUS_AUTOSERVICE_SPARE_PART_OIL_FILTER',
            'sort' => 100,
            'initial_quantity' => 6.0,
        ],
        [
            'xml_id' => 'OTUS_AUTOSERVICE_PART_BRAKE_PADS',
            'code' => 'otus-autoservice-brake-pads',
            'article' => 'OTUS-BP-001',
            'name_message' => 'OTUS_AUTOSERVICE_SPARE_PART_BRAKE_PADS',
            'sort' => 200,
            'initial_quantity' => 0.0,
        ],
        [
            'xml_id' => 'OTUS_AUTOSERVICE_PART_SPARK_PLUG',
            'code' => 'otus-autoservice-spark-plug',
            'article' => 'OTUS-SP-001',
            'name_message' => 'OTUS_AUTOSERVICE_SPARE_PART_SPARK_PLUG',
            'sort' => 300,
            'initial_quantity' => 12.0,
        ],
        [
            'xml_id' => 'OTUS_AUTOSERVICE_PART_ENGINE_OIL',
            'code' => 'otus-autoservice-engine-oil',
            'article' => 'OTUS-EO-530',
            'name_message' => 'OTUS_AUTOSERVICE_SPARE_PART_ENGINE_OIL',
            'sort' => 400,
            'initial_quantity' => 4.0,
        ],
    ];

    /**
     * Выбирает CRM-каталог и создаёт либо восстанавливает обязательную инфраструктуру этапа.
     *
     * Демонстрационные товары намеренно не создаются установщиком. Их можно добавить отдельно
     * методом seedDemoProducts(), который вызывается только из явно запущенного CLI-сценария.
     *
     * @return array{catalog_id: int, article_property_id: int, store_id: int, inventory_management: bool}
     * Итоговая инфраструктура для диагностики и последующих сервисов.
     */
    public function ensureExists(): array
    {
        $this->includeRequiredModules();

        /** @var \Bitrix\Main\DB\Connection $connection Соединение текущей установки Bitrix. */
        $connection = Application::getConnection();
        if (!$connection->lock(self::OPERATION_LOCK_NAME, self::OPERATION_LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_LOCK_TIMEOUT')
            );
        }

        try {
            return $this->ensureInfrastructureUnlocked();
        } finally {
            $connection->unlock(self::OPERATION_LOCK_NAME);
        }
    }

    /**
     * Явно создаёт или восстанавливает демонстрационные запчасти после подготовки инфраструктуры.
     *
     * @return array{
     *     catalog_id: int,
     *     article_property_id: int,
     *     store_id: int,
     *     product_ids: array<string, int>,
     *     inventory_management: bool
     * } Итоговые ID инфраструктуры и созданных демонстрационных товаров.
     */
    public function seedDemoProducts(): array
    {
        $this->includeRequiredModules();

        /** @var \Bitrix\Main\DB\Connection $connection Соединение для атомарного заполнения каталога. */
        $connection = Application::getConnection();
        if (!$connection->lock(self::OPERATION_LOCK_NAME, self::OPERATION_LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_LOCK_TIMEOUT')
            );
        }

        try {
            /** @var array{catalog_id: int, article_property_id: int, store_id: int, inventory_management: bool} $configuration Актуальная обязательная инфраструктура каталога. */
            $configuration = $this->ensureInfrastructureUnlocked();
            /** @var array<string, int> $productIds Соответствие внешних ID демонстрационным товарам. */
            $productIds = $this->ensureDemoProducts(
                $configuration['catalog_id'],
                $configuration['article_property_id'],
                $configuration['store_id'],
                $configuration['inventory_management']
            );

            return $configuration + ['product_ids' => $productIds];
        } finally {
            $connection->unlock(self::OPERATION_LOCK_NAME);
        }
    }

    /** Проверяет обязательные каталог, склад и свойство артикула без демонстрационных данных. */
    public function isReady(): bool
    {
        if (!$this->tryIncludeRequiredModules()) {
            return false;
        }

        /** @var int|null $catalogId Настроенный ID CRM-каталога. */
        $catalogId = ModuleConfiguration::getSparePartsCatalogId();
        /** @var int|null $articlePropertyId Настроенный ID свойства артикула. */
        $articlePropertyId = ModuleConfiguration::getSparePartsArticlePropertyId();
        /** @var int|null $storeId Настроенный ID демонстрационного склада. */
        $storeId = ModuleConfiguration::getSparePartsStoreId();

        if ($catalogId === null || $articlePropertyId === null || $storeId === null) {
            return false;
        }
        if ((int)\CCrmCatalog::GetDefaultID() !== $catalogId || !$this->isCatalogValid($catalogId)) {
            return false;
        }

        /** @var array<string, mixed>|null $property Проверяемое свойство артикула. */
        $property = $this->findArticleProperty($catalogId);
        if (
            $property === null
            || (int)$property['ID'] !== $articlePropertyId
            || !$this->isArticlePropertyReady($property)
            || !$this->isArticlePropertyCodeAvailable($catalogId, $articlePropertyId)
        ) {
            return false;
        }

        /** @var array<string, mixed>|null $store Проверяемый демонстрационный склад. */
        $store = $this->findManagedStore();
        if (
            $store === null
            || (int)$store['ID'] !== $storeId
            || (string)$store['ACTIVE'] !== 'Y'
            || (string)$store['CODE'] !== self::STORE_CODE
        ) {
            return false;
        }

        return true;
    }

    /**
     * Проверяет явно добавленный демонстрационный набор и согласованность его остатков.
     *
     * Метод возвращает false, если хотя бы один тестовый товар отсутствует или повреждён.
     */
    public function areDemoProductsReady(): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        /** @var int $catalogId Проверенный ID штатного CRM-каталога. */
        $catalogId = (int)ModuleConfiguration::getSparePartsCatalogId();
        /** @var int $articlePropertyId Проверенный ID свойства уникального артикула. */
        $articlePropertyId = (int)ModuleConfiguration::getSparePartsArticlePropertyId();
        /** @var int $storeId Проверенный ID демонстрационного склада. */
        $storeId = (int)ModuleConfiguration::getSparePartsStoreId();
        /** @var array<string, mixed> $definition Ожидаемое описание очередной тестовой запчасти. */
        foreach (self::getDemoPartDefinitions() as $definition) {
            /** @var array<string, mixed>|null $element Элемент инфоблока по стабильному XML_ID. */
            $element = $this->findManagedProduct((string)$definition['xml_id']);
            if (
                $element === null
                || (int)$element['IBLOCK_ID'] !== $catalogId
                || (string)$element['CODE'] !== (string)$definition['code']
                || (string)$element['ACTIVE'] !== 'Y'
            ) {
                return false;
            }

            /** @var int $productId Общий ID элемента инфоблока и товарной записи каталога. */
            $productId = (int)$element['ID'];
            /** @var array<string, mixed>|null $product Товарные параметры и доступное количество. */
            $product = ProductTable::getByPrimary(
                $productId,
                [
                    'select' => [
                        'ID',
                        'TYPE',
                        'QUANTITY',
                        'QUANTITY_RESERVED',
                        'QUANTITY_TRACE',
                        'CAN_BUY_ZERO',
                    ],
                ]
            )->fetch() ?: null;
            /** @var array<string, mixed>|null $storeProduct Количество товара на демонстрационном складе. */
            $storeProduct = $this->getStoreProductRow($storeId, $productId);
            if (
                $product === null
                || $storeProduct === null
                || (int)$product['TYPE'] !== ProductTable::TYPE_PRODUCT
                || (string)$product['QUANTITY_TRACE'] !== ProductTable::STATUS_YES
                || (string)$product['CAN_BUY_ZERO'] !== ProductTable::STATUS_NO
                || (float)$product['QUANTITY'] < 0
                || (float)$storeProduct['AMOUNT'] < 0
                || (float)$storeProduct['QUANTITY_RESERVED'] < 0
                || (float)$storeProduct['QUANTITY_RESERVED'] > (float)$storeProduct['AMOUNT']
                || !$this->isProductQuantityConsistentWithValue(
                    $productId,
                    (float)$product['QUANTITY'],
                    (float)$product['QUANTITY_RESERVED']
                )
                || $this->getArticleValue($catalogId, $productId) !== (string)$definition['article']
                || !$this->isArticleValueAvailable(
                    $catalogId,
                    $articlePropertyId,
                    (string)$definition['article'],
                    $productId
                )
                || !$this->hasDefaultMeasureRatio($productId)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет соответствие доступного количества товара активным складским остаткам.
     *
     * Bitrix хранит в поле товара QUANTITY доступное количество и вычисляет складской эквивалент
     * как сумму AMOUNT минус QUANTITY_RESERVED только по активным складам. При включённом складском
     * учёте дополнительно сверяется QUANTITY_RESERVED товара с суммой резервов активных складов.
     * Одинаковая проверка используется демонстрационным заполнением и CLI-диагностикой, чтобы их
     * правила не расходились.
     */
    public function isProductQuantityConsistent(int $productId): bool
    {
        if ($productId <= 0 || !$this->tryIncludeRequiredModules()) {
            return false;
        }

        /** @var array<string, mixed>|false $product Текущее доступное количество товарной записи. */
        $product = ProductTable::getByPrimary(
            $productId,
            ['select' => ['ID', 'QUANTITY', 'QUANTITY_RESERVED']]
        )->fetch();
        if ($product === false) {
            return false;
        }

        return $this->isProductQuantityConsistentWithValue(
            $productId,
            (float)$product['QUANTITY'],
            (float)$product['QUANTITY_RESERVED']
        );
    }

    /**
     * Удаляет только принадлежащие модулю и не используемые в CRM или складских документах объекты.
     *
     * Запчасть, уже добавленная в товарные позиции CRM или складской документ, сохраняется.
     * Склад сохраняется, если на нём остались товарные строки или ссылки складских документов,
     * а свойство — если им начал пользоваться хотя бы один оставшийся элемент каталога.
     * Штатный CRM-каталог не удаляется.
     */
    public function removeIfOwned(): void
    {
        $this->includeRequiredModules();

        /** @var \Bitrix\Main\DB\Connection $connection Соединение для блокировки безопасного отката. */
        $connection = Application::getConnection();
        if (!$connection->lock(self::OPERATION_LOCK_NAME, self::OPERATION_LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_LOCK_TIMEOUT')
            );
        }

        try {
            /** @var int|null $catalogId Последний каталог, выбранный миграцией. */
            $catalogId = ModuleConfiguration::getSparePartsCatalogId();
            /** @var int|null $storeId Последний демонстрационный склад миграции. */
            $storeId = ModuleConfiguration::getSparePartsStoreId();

            if ($catalogId !== null && $this->isCatalogValid($catalogId)) {
                /** @var array<string, mixed> $definition Описание удаляемой при возможности запчасти. */
                foreach (self::getDemoPartDefinitions() as $definition) {
                    /** @var array<string, mixed>|null $element Точный элемент по XML_ID модуля. */
                    $element = $this->findManagedProduct((string)$definition['xml_id']);
                    if (
                        $element === null
                        || (int)$element['IBLOCK_ID'] !== $catalogId
                        || ProductRowTable::getCount(['=PRODUCT_ID' => (int)$element['ID']]) > 0
                        || StoreDocumentElementTable::getCount(
                            ['=ELEMENT_ID' => (int)$element['ID']]
                        ) > 0
                    ) {
                        continue;
                    }

                    if (!\CIBlockElement::Delete((int)$element['ID'])) {
                        throw new RuntimeException(
                            (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_DELETE_FAILED')
                        );
                    }
                }
            }

            if ($storeId !== null) {
                $this->removeStoreIfEmpty($storeId);
            }
            if ($catalogId !== null) {
                $this->removeArticlePropertyIfUnused($catalogId);
            }

            $this->clearConfiguration();
        } finally {
            $connection->unlock(self::OPERATION_LOCK_NAME);
        }
    }

    /**
     * Возвращает локализованные стабильные определения демонстрационных запчастей.
     *
     * @return array<int, array{xml_id: string, code: string, article: string, name: string, sort: int, initial_quantity: float}>
     */
    public static function getDemoPartDefinitions(): array
    {
        /** @var array<int, array<string, mixed>> $definitions Итоговые данные без внутренних ключей локализации. */
        $definitions = [];

        /** @var array<string, mixed> $definition Статическое описание одной запчасти. */
        foreach (self::DEMO_PARTS as $definition) {
            $definitions[] = [
                'xml_id' => (string)$definition['xml_id'],
                'code' => (string)$definition['code'],
                'article' => (string)$definition['article'],
                'name' => (string)Loc::getMessage((string)$definition['name_message']),
                'sort' => (int)$definition['sort'],
                'initial_quantity' => (float)$definition['initial_quantity'],
            ];
        }

        return $definitions;
    }

    /** Проверяет наличие всех модулей, непосредственно используемых менеджером. */
    private function includeRequiredModules(): void
    {
        if (!$this->tryIncludeRequiredModules()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_MODULES_REQUIRED')
            );
        }
    }

    /** Возвращает true, только если CRM, инфоблоки и каталог успешно подключены. */
    private function tryIncludeRequiredModules(): bool
    {
        return Loader::includeModule('crm')
            && Loader::includeModule('iblock')
            && Loader::includeModule('catalog');
    }

    /**
     * Восстанавливает обязательные объекты при уже подключённых модулях и удерживаемой блокировке.
     *
     * @return array{catalog_id: int, article_property_id: int, store_id: int, inventory_management: bool}
     * Актуальные ID объектов и режим складского учёта портала.
     */
    private function ensureInfrastructureUnlocked(): array
    {
        /** @var array<string, string|null> $configurationSnapshot Исходные общие настройки этапа. */
        $configurationSnapshot = $this->captureConfigurationSnapshot();
        /** @var int|null $catalogId Выбранный каталог либо null до успешного выбора. */
        $catalogId = null;
        /** @var int|null $createdArticlePropertyId Свойство, созданное только текущим вызовом. */
        $createdArticlePropertyId = null;
        /** @var int|null $createdStoreId Склад, созданный только текущим вызовом. */
        $createdStoreId = null;
        /** @var bool $configurationWriteStarted Началась ли неатомарная запись трёх настроек. */
        $configurationWriteStarted = false;

        try {
            $catalogId = $this->ensureCrmCatalog();

            /** @var array{id: int, created: bool} $articlePropertyResult Итог подготовки свойства. */
            $articlePropertyResult = $this->ensureArticleProperty($catalogId);
            /** @var int $articlePropertyId ID принадлежащего модулю свойства артикула. */
            $articlePropertyId = $articlePropertyResult['id'];
            if ($articlePropertyResult['created']) {
                $createdArticlePropertyId = $articlePropertyId;
            }

            /** @var array{id: int, created: bool} $storeResult Итог подготовки склада. */
            $storeResult = $this->ensureStore();
            /** @var int $storeId ID демонстрационного склада без товарных данных. */
            $storeId = $storeResult['id'];
            if ($storeResult['created']) {
                $createdStoreId = $storeId;
            }

            /** @var bool $inventoryManagementEnabled Фактический режим складского учёта портала. */
            $inventoryManagementEnabled = State::isUsedInventoryManagement();

            $configurationWriteStarted = true;
            $this->saveConfiguration($catalogId, $articlePropertyId, $storeId);

            return [
                'catalog_id' => $catalogId,
                'article_property_id' => $articlePropertyId,
                'store_id' => $storeId,
                'inventory_management' => $inventoryManagementEnabled,
            ];
        } catch (\Throwable $exception) {
            /** @var string[] $cleanupErrors Ошибки компенсации частично выполненной подготовки. */
            $cleanupErrors = $this->cleanupFailedInfrastructure(
                $catalogId,
                $createdArticlePropertyId,
                $createdStoreId,
                $configurationSnapshot,
                $configurationWriteStarted
            );
            if ($cleanupErrors !== []) {
                throw new RuntimeException(
                    (string)Loc::getMessage(
                        'OTUS_AUTOSERVICE_SPARE_PARTS_PARTIAL_ROLLBACK_FAILED',
                        [
                            '#ORIGINAL#' => $exception->getMessage(),
                            '#CLEANUP#' => implode('; ', $cleanupErrors),
                        ]
                    ),
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    /** Находит или создаёт штатный CRM-каталог и проверяет его тип. */
    private function ensureCrmCatalog(): int
    {
        /** @var int $catalogId Текущий каталог товарных позиций CRM. */
        $catalogId = (int)\CCrmCatalog::GetDefaultID();
        if ($catalogId <= 0) {
            $catalogId = (int)\CCrmCatalog::EnsureDefaultExists();
        }

        if ($catalogId <= 0 || !$this->isCatalogValid($catalogId)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_CATALOG_REQUIRED')
            );
        }

        return $catalogId;
    }

    /** Проверяет, что инфоблок активен и зарегистрирован именно как простой товарный каталог. */
    private function isCatalogValid(int $catalogId): bool
    {
        /** @var array<string, mixed>|false $iblock Активный инфоблок выбранного каталога. */
        $iblock = IblockTable::getByPrimary(
            $catalogId,
            ['select' => ['ID', 'ACTIVE']]
        )->fetch();
        if ($iblock === false || (string)$iblock['ACTIVE'] !== 'Y') {
            return false;
        }

        /** @var array<string, mixed>|false $catalog Регистрация инфоблока в модуле catalog. */
        $catalog = CatalogIblockTable::getByPrimary(
            $catalogId,
            ['select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID']]
        )->fetch();

        return $catalog !== false && (int)$catalog['PRODUCT_IBLOCK_ID'] === 0;
    }

    /**
     * Создаёт либо проверяет строковое свойство артикула в выбранном CRM-каталоге.
     *
     * @return array{id: int, created: bool} ID свойства и признак создания текущим вызовом.
     */
    private function ensureArticleProperty(int $catalogId): array
    {
        /** @var array<string, mixed>|null $property Свойство с внешним ID модуля. */
        $property = $this->findArticleProperty($catalogId);
        if ($property !== null) {
            if (!$this->isArticlePropertyCompatible($property)) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_CONFLICT')
                );
            }

            /** @var int $propertyId ID проверенного свойства, принадлежащего модулю. */
            $propertyId = (int)$property['ID'];
            if (!$this->isArticlePropertyCodeAvailable($catalogId, $propertyId)) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_CONFLICT')
                );
            }

            /** @var \CIBlockProperty $propertyManager Штатный API восстановления полей свойства. */
            $propertyManager = new \CIBlockProperty();
            if (
                !$propertyManager->Update(
                    $propertyId,
                    [
                        'NAME' => (string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_NAME'
                        ),
                        'ACTIVE' => 'Y',
                        'SORT' => 500,
                        'CODE' => self::ARTICLE_PROPERTY_CODE,
                        'XML_ID' => self::ARTICLE_PROPERTY_XML_ID,
                        'PROPERTY_TYPE' => 'S',
                        'MULTIPLE' => 'N',
                        'IS_REQUIRED' => 'N',
                        'USER_TYPE' => '',
                        'USER_TYPE_SETTINGS' => false,
                    ]
                )
            ) {
                throw new RuntimeException(
                    trim((string)$propertyManager->LAST_ERROR) !== ''
                        ? (string)$propertyManager->LAST_ERROR
                        : (string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_UPDATE_FAILED'
                        )
                );
            }

            return ['id' => $propertyId, 'created' => false];
        }

        /** @var array<string, mixed>|false $codeConflict Чужое свойство с зарезервированным кодом. */
        $codeConflict = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $catalogId, 'CODE' => self::ARTICLE_PROPERTY_CODE]
        )->Fetch();
        if ($codeConflict !== false) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_CONFLICT')
            );
        }

        /** @var \CIBlockProperty $propertyManager Штатный API создания свойства инфоблока. */
        $propertyManager = new \CIBlockProperty();
        /** @var int $propertyId ID нового свойства либо ноль при ошибке legacy API. */
        $propertyId = (int)$propertyManager->Add(
            [
                'IBLOCK_ID' => $catalogId,
                'NAME' => (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_NAME'),
                'ACTIVE' => 'Y',
                'SORT' => 500,
                'CODE' => self::ARTICLE_PROPERTY_CODE,
                'XML_ID' => self::ARTICLE_PROPERTY_XML_ID,
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'USER_TYPE' => '',
                'USER_TYPE_SETTINGS' => false,
            ]
        );
        if ($propertyId <= 0) {
            throw new RuntimeException(
                trim((string)$propertyManager->LAST_ERROR) !== ''
                    ? (string)$propertyManager->LAST_ERROR
                    : (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_CREATE_FAILED')
            );
        }

        return ['id' => $propertyId, 'created' => true];
    }

    /** Возвращает свойство артикула по точному внешнему идентификатору модуля. */
    private function findArticleProperty(int $catalogId): ?array
    {
        /** @var \CDBResult $properties Результат точного поиска legacy API свойств инфоблока. */
        $properties = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $catalogId, 'XML_ID' => self::ARTICLE_PROPERTY_XML_ID]
        );
        /** @var array<string, mixed>|false $property Первое совпадение по внешнему ID. */
        $property = $properties->Fetch();
        if ($property !== false && $properties->Fetch() !== false) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_CONFLICT')
            );
        }

        return $property === false ? null : $property;
    }

    /** Проверяет неизменяемую структуру найденного по XML_ID свойства перед повторным использованием. */
    private function isArticlePropertyCompatible(array $property): bool
    {
        return (string)($property['PROPERTY_TYPE'] ?? '') === 'S'
            && (string)($property['MULTIPLE'] ?? '') === 'N';
    }

    /** Проверяет все рабочие настройки принадлежащего модулю свойства артикула. */
    private function isArticlePropertyReady(array $property): bool
    {
        return $this->isArticlePropertyCompatible($property)
            && (string)($property['CODE'] ?? '') === self::ARTICLE_PROPERTY_CODE
            && (string)($property['ACTIVE'] ?? '') === 'Y'
            && (string)($property['IS_REQUIRED'] ?? '') === 'N'
            && trim((string)($property['USER_TYPE'] ?? '')) === '';
    }

    /** Проверяет, что требуемый код свободен либо принадлежит только ожидаемому свойству. */
    private function isArticlePropertyCodeAvailable(int $catalogId, int $propertyId): bool
    {
        /** @var \CDBResult $properties Все свойства выбранного каталога с зарезервированным кодом. */
        $properties = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $catalogId, 'CODE' => self::ARTICLE_PROPERTY_CODE]
        );
        /** @var array<string, mixed>|false $property Очередное свойство с зарезервированным кодом. */
        while (($property = $properties->Fetch()) !== false) {
            if ((int)$property['ID'] !== $propertyId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаёт или восстанавливает демонстрационный склад, не заменяя чужой склад по умолчанию.
     *
     * @return array{id: int, created: bool} ID склада и признак создания текущим вызовом.
     */
    private function ensureStore(): array
    {
        /** @var array<string, mixed>|null $store Склад с внешним ID модуля. */
        $store = $this->findManagedStore();
        /** @var array<int, array<string, mixed>> $codeStores Склады с зарезервированным символьным кодом. */
        $codeStores = StoreTable::getList(
            [
                'select' => ['ID'],
                'filter' => ['=CODE' => self::STORE_CODE],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ]
        )->fetchAll();
        /** @var array<string, mixed> $codeStore Очередной склад с зарезервированным кодом. */
        foreach ($codeStores as $codeStore) {
            if ($store === null || (int)$codeStore['ID'] !== (int)$store['ID']) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_CONFLICT')
                );
            }
        }

        /** @var int|null $defaultStoreId Существующий склад по умолчанию, который нельзя перехватывать. */
        $defaultStoreId = StoreTable::getDefaultStoreId();

        /** @var string $siteId Активный сайт, к которому привязывается склад. */
        $siteId = $this->getDefaultSiteId();
        /** @var array<string, mixed> $fields Воспроизводимые поля демонстрационного склада. */
        $fields = [
            'TITLE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_TITLE'),
            'ACTIVE' => 'Y',
            'ADDRESS' => (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_ADDRESS'),
            'DESCRIPTION' => (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_DESCRIPTION'),
            'XML_ID' => self::STORE_XML_ID,
            'CODE' => self::STORE_CODE,
            'SORT' => 500,
            'SITE_ID' => $siteId,
            'ISSUING_CENTER' => 'Y',
            'SHIPPING_CENTER' => 'Y',
            'IS_DEFAULT' => $defaultStoreId === null || (int)($store['ID'] ?? 0) === $defaultStoreId ? 'Y' : 'N',
        ];

        if ($store !== null) {
            /** @var \Bitrix\Main\ORM\Data\UpdateResult $updateResult Результат восстановления полей склада. */
            $updateResult = StoreTable::update((int)$store['ID'], $fields);
            $this->throwOnResultErrors($updateResult->isSuccess(), $updateResult->getErrorMessages());

            return ['id' => (int)$store['ID'], 'created' => false];
        }

        /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат создания склада через D7 ORM. */
        $addResult = StoreTable::add($fields);
        $this->throwOnResultErrors($addResult->isSuccess(), $addResult->getErrorMessages());

        return ['id' => (int)$addResult->getId(), 'created' => true];
    }

    /** Возвращает принадлежащий модулю склад по точному XML_ID. */
    private function findManagedStore(): ?array
    {
        /** @var array<int, array<string, mixed>> $stores Склады с точным внешним ID модуля. */
        $stores = StoreTable::getList(
            [
                'select' => ['ID', 'TITLE', 'ACTIVE', 'ADDRESS', 'XML_ID', 'CODE', 'IS_DEFAULT', 'SITE_ID'],
                'filter' => ['=XML_ID' => self::STORE_XML_ID],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ]
        )->fetchAll();
        if (count($stores) > 1) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_CONFLICT')
            );
        }

        return $stores[0] ?? null;
    }

    /** Выбирает основной активный сайт для обязательного поля склада. */
    private function getDefaultSiteId(): string
    {
        /** @var array<string, mixed>|false $site Основной либо первый активный сайт портала. */
        $site = SiteTable::getList(
            [
                'select' => ['ID'],
                'filter' => ['=ACTIVE' => 'Y'],
                'order' => ['DEF' => 'DESC', 'SORT' => 'ASC', 'ID' => 'ASC'],
                'limit' => 1,
            ]
        )->fetch();
        if ($site === false || trim((string)$site['ID']) === '') {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_SITE_REQUIRED')
            );
        }

        return (string)$site['ID'];
    }

    /**
     * Создаёт тестовые элементы, товарные записи, единичные коэффициенты и остатки склада.
     *
     * @return array<string, int> Соответствие XML_ID созданным или найденным ID товаров.
     */
    private function ensureDemoProducts(
        int $catalogId,
        int $articlePropertyId,
        int $storeId,
        bool $inventoryManagementEnabled
    ): array
    {
        /** @var array<string, int> $productIds Итоговая карта внешних идентификаторов. */
        $productIds = [];

        /** @var array<string, mixed> $definition Локализованное описание очередной запчасти. */
        foreach (self::getDemoPartDefinitions() as $definition) {
            /** @var int $productId ID элемента и товарной записи после восстановления. */
            $productId = $this->ensureProductElement($catalogId, $articlePropertyId, $definition);
            /** @var float $initialQuantity Безопасный начальный остаток для текущего режима портала. */
            $initialQuantity = $inventoryManagementEnabled
                ? 0.0
                : (float)$definition['initial_quantity'];

            /** @var float $catalogQuantity Фактическое количество, сохранённое в товарной записи. */
            $catalogQuantity = $this->ensureCatalogProduct($catalogId, $productId, $initialQuantity);
            /** @var float $storeInitialQuantity Начальное количество только для отсутствующей строки склада. */
            $storeInitialQuantity = 0.0;
            if (!$inventoryManagementEnabled) {
                /** @var float $otherStoresAmount Доступное количество на остальных активных складах. */
                $otherStoresAmount = $this->getAvailableStoreAmount($productId, $storeId);
                if ($otherStoresAmount - $catalogQuantity > 0.00001) {
                    throw new RuntimeException(
                        (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_QUANTITY_CONFLICT')
                    );
                }
                $storeInitialQuantity = max(0.0, $catalogQuantity - $otherStoresAmount);
            }

            $this->ensureMeasureRatio($productId);
            $this->ensureStoreProduct($storeId, $productId, $storeInitialQuantity);
            $productIds[(string)$definition['xml_id']] = $productId;
        }

        return $productIds;
    }

    /** Создаёт либо восстанавливает один элемент CRM-каталога и значение его артикула. */
    private function ensureProductElement(int $catalogId, int $articlePropertyId, array $definition): int
    {
        /** @var array<string, mixed>|null $element Элемент с внешним ID текущего определения. */
        $element = $this->findManagedProduct((string)$definition['xml_id']);
        if ($element !== null && (int)$element['IBLOCK_ID'] !== $catalogId) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_CONFLICT')
            );
        }

        /** @var array<int, array<string, mixed>> $codeElements Элементы с зарезервированным кодом запчасти. */
        $codeElements = ElementTable::getList(
            [
                'select' => ['ID'],
                'filter' => ['=IBLOCK_ID' => $catalogId, '=CODE' => (string)$definition['code']],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ]
        )->fetchAll();
        /** @var array<string, mixed> $codeElement Очередной элемент с зарезервированным кодом. */
        foreach ($codeElements as $codeElement) {
            if ($element === null || (int)$codeElement['ID'] !== (int)$element['ID']) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_CONFLICT')
                );
            }
        }

        /** @var int|null $existingProductId ID текущего товара, исключаемого из проверки артикула. */
        $existingProductId = $element === null ? null : (int)$element['ID'];
        if (
            !$this->isArticleValueAvailable(
                $catalogId,
                $articlePropertyId,
                (string)$definition['article'],
                $existingProductId
            )
        ) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_CONFLICT')
            );
        }

        /** @var \CIBlockElement $elementManager Штатный API элементов инфоблока. */
        $elementManager = new \CIBlockElement();
        /** @var array<string, mixed> $fields Воспроизводимые поля тестовой запчасти. */
        $fields = [
            'IBLOCK_ID' => $catalogId,
            'NAME' => (string)$definition['name'],
            'ACTIVE' => 'Y',
            'XML_ID' => (string)$definition['xml_id'],
            'CODE' => (string)$definition['code'],
            'SORT' => (int)$definition['sort'],
        ];

        if ($element === null) {
            $fields['PROPERTY_VALUES'] = [
                $articlePropertyId => (string)$definition['article'],
            ];
            /** @var int $productId ID созданного элемента либо ноль при ошибке. */
            $productId = (int)$elementManager->Add($fields);
            if ($productId <= 0) {
                throw new RuntimeException(
                    trim((string)$elementManager->LAST_ERROR) !== ''
                        ? (string)$elementManager->LAST_ERROR
                        : (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_CREATE_FAILED')
                );
            }
        } else {
            /** @var int $productId ID восстанавливаемого элемента. */
            $productId = (int)$element['ID'];
            if (!$elementManager->Update($productId, $fields)) {
                throw new RuntimeException(
                    trim((string)$elementManager->LAST_ERROR) !== ''
                        ? (string)$elementManager->LAST_ERROR
                        : (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_UPDATE_FAILED')
                );
            }
            \CIBlockElement::SetPropertyValuesEx(
                $productId,
                $catalogId,
                [self::ARTICLE_PROPERTY_CODE => (string)$definition['article']]
            );
        }

        if ($this->getArticleValue($catalogId, $productId) !== (string)$definition['article']) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_SAVE_FAILED')
            );
        }

        return $productId;
    }

    /** Возвращает единственный товар по глобально стабильному XML_ID или отклоняет дубликаты. */
    private function findManagedProduct(string $xmlId): ?array
    {
        /** @var array<int, array<string, mixed>> $rows Совпадения во всех инфоблоках портала. */
        $rows = ElementTable::getList(
            [
                'select' => ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'XML_ID', 'ACTIVE', 'SORT'],
                'filter' => ['=XML_ID' => $xmlId],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ]
        )->fetchAll();
        if (count($rows) > 1) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_PRODUCT_CONFLICT')
            );
        }

        return $rows[0] ?? null;
    }

    /**
     * Создаёт товарную часть элемента, не сбрасывая остаток при повторном запуске.
     *
     * @return float Фактическое значение поля QUANTITY товара после сохранения.
     */
    private function ensureCatalogProduct(int $catalogId, int $productId, float $initialQuantity): float
    {
        /** @var array<string, mixed>|false $product Существующая запись b_catalog_product. */
        $product = ProductTable::getByPrimary(
            $productId,
            ['select' => ['ID', 'QUANTITY']]
        )->fetch();
        /** @var array<string, mixed> $fields Правила количественного учёта физической запчасти. */
        $fields = [
            'TYPE' => ProductTable::TYPE_PRODUCT,
            'QUANTITY_TRACE' => ProductTable::STATUS_YES,
            'CAN_BUY_ZERO' => ProductTable::STATUS_NO,
        ];

        if ($product === false) {
            $fields['ID'] = $productId;
            $fields['QUANTITY'] = $initialQuantity;
            /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат штатной модели товара. */
            $result = Product::add(
                [
                    'fields' => $fields,
                    'external_fields' => ['IBLOCK_ID' => $catalogId],
                ]
            );
        } else {
            /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат восстановления настроек товара. */
            $result = Product::update(
                $productId,
                [
                    'fields' => $fields,
                    'external_fields' => ['IBLOCK_ID' => $catalogId],
                ]
            );
        }

        $this->throwOnResultErrors($result->isSuccess(), $result->getErrorMessages());

        return $product === false ? $initialQuantity : (float)$product['QUANTITY'];
    }

    /** Обеспечивает единичный коэффициент измерения для товарных позиций CRM. */
    private function ensureMeasureRatio(int $productId): void
    {
        /** @var array<int, array<string, mixed>> $defaultRatios Все коэффициенты по умолчанию товара. */
        $defaultRatios = $this->getDefaultMeasureRatios($productId);
        if ($defaultRatios === []) {
            /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат создания коэффициента 1 штука. */
            $result = MeasureRatioTable::add(
                [
                    'PRODUCT_ID' => $productId,
                    'RATIO' => 1.0,
                    'IS_DEFAULT' => 'Y',
                ]
            );
            $this->throwOnResultErrors($result->isSuccess(), $result->getErrorMessages());

            return;
        }

        /** @var array<string, mixed> $primaryRatio Основной коэффициент с минимальным ID. */
        $primaryRatio = array_shift($defaultRatios);
        if (abs((float)$primaryRatio['RATIO'] - 1.0) > 0.00001) {
            /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат восстановления значения 1. */
            $result = MeasureRatioTable::update((int)$primaryRatio['ID'], ['RATIO' => 1.0]);
            $this->throwOnResultErrors($result->isSuccess(), $result->getErrorMessages());
        }

        /** @var array<string, mixed> $extraRatio Лишний коэффициент с ошибочным признаком по умолчанию. */
        foreach ($defaultRatios as $extraRatio) {
            /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат снятия лишнего признака по умолчанию. */
            $result = MeasureRatioTable::update((int)$extraRatio['ID'], ['IS_DEFAULT' => 'N']);
            $this->throwOnResultErrors($result->isSuccess(), $result->getErrorMessages());
        }
    }

    /** Проверяет наличие единственного коэффициента по умолчанию со значением 1. */
    private function hasDefaultMeasureRatio(int $productId): bool
    {
        /** @var array<int, array<string, mixed>> $defaultRatios Коэффициенты по умолчанию товара. */
        $defaultRatios = $this->getDefaultMeasureRatios($productId);

        return count($defaultRatios) === 1
            && abs((float)$defaultRatios[0]['RATIO'] - 1.0) <= 0.00001;
    }

    /**
     * Возвращает коэффициенты товара с признаком по умолчанию в воспроизводимом порядке.
     *
     * @return array<int, array<string, mixed>> Строки коэффициентов с ID и числовым значением.
     */
    private function getDefaultMeasureRatios(int $productId): array
    {
        return MeasureRatioTable::getList(
            [
                'select' => ['ID', 'RATIO'],
                'filter' => ['=PRODUCT_ID' => $productId, '=IS_DEFAULT' => 'Y'],
                'order' => ['ID' => 'ASC'],
            ]
        )->fetchAll();
    }

    /** Создаёт связь товара со складом, не перезаписывая остаток после будущих синхронизаций. */
    private function ensureStoreProduct(int $storeId, int $productId, float $initialQuantity): void
    {
        if ($this->hasStoreProductRow($storeId, $productId)) {
            return;
        }

        /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат создания складской строки. */
        $result = StoreProductTable::add(
            [
                'STORE_ID' => $storeId,
                'PRODUCT_ID' => $productId,
                'AMOUNT' => $initialQuantity,
                'QUANTITY_RESERVED' => 0.0,
            ]
        );
        $this->throwOnResultErrors($result->isSuccess(), $result->getErrorMessages());
    }

    /** Проверяет наличие единственной связи товара с демонстрационным складом. */
    private function hasStoreProductRow(int $storeId, int $productId): bool
    {
        return $this->getStoreProductRow($storeId, $productId) !== null;
    }

    /** Возвращает количественную строку точного товара на демонстрационном складе. */
    private function getStoreProductRow(int $storeId, int $productId): ?array
    {
        /** @var array<string, mixed>|false $row Найденная складская строка либо false. */
        $row = StoreProductTable::getList(
            [
                'select' => ['ID', 'AMOUNT', 'QUANTITY_RESERVED'],
                'filter' => ['=STORE_ID' => $storeId, '=PRODUCT_ID' => $productId],
                'limit' => 1,
            ]
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Возвращает доступное количество товара на активных складах, при необходимости исключая один.
     *
     * Формула совпадает со штатной диагностикой Bitrix: AMOUNT - QUANTITY_RESERVED. Неактивные
     * склады не участвуют в доступном общем количестве товара.
     */
    private function getAvailableStoreAmount(int $productId, ?int $excludedStoreId = null): float
    {
        return $this->getActiveStoreQuantityTotals($productId, $excludedStoreId)['available'];
    }

    /**
     * Возвращает доступное и зарезервированное количество товара на активных складах.
     *
     * @return array{available: float, reserved: float} Суммы по складским строкам.
     */
    private function getActiveStoreQuantityTotals(int $productId, ?int $excludedStoreId = null): array
    {
        /** @var array<int, int> $activeStoreIds ID активных складов, участвующих в остатке. */
        $activeStoreIds = [];
        /** @var array<string, mixed> $store Очередной активный склад портала. */
        foreach (
            StoreTable::getList(
                [
                    'select' => ['ID'],
                    'filter' => ['=ACTIVE' => 'Y'],
                ]
            ) as $store
        ) {
            /** @var int $storeId Проверенный ID активного склада. */
            $storeId = (int)$store['ID'];
            if ($storeId > 0 && ($excludedStoreId === null || $storeId !== $excludedStoreId)) {
                $activeStoreIds[] = $storeId;
            }
        }

        if ($activeStoreIds === []) {
            return ['available' => 0.0, 'reserved' => 0.0];
        }

        /** @var float $availableAmount Суммарное доступное количество на выбранных складах. */
        $availableAmount = 0.0;
        /** @var float $reservedAmount Суммарный резерв на выбранных складах. */
        $reservedAmount = 0.0;
        /** @var array<string, mixed> $row Очередной остаток активного склада. */
        foreach (
            StoreProductTable::getList(
                [
                    'select' => ['AMOUNT', 'QUANTITY_RESERVED'],
                    'filter' => [
                        '=PRODUCT_ID' => $productId,
                        '@STORE_ID' => $activeStoreIds,
                    ],
                ]
            ) as $row
        ) {
            /** @var float $reservedQuantity Резерв текущей складской строки. */
            $reservedQuantity = (float)$row['QUANTITY_RESERVED'];
            $availableAmount += (float)$row['AMOUNT'] - $reservedQuantity;
            $reservedAmount += $reservedQuantity;
        }

        return ['available' => $availableAmount, 'reserved' => $reservedAmount];
    }

    /** Сравнивает количество и резерв товара с итогами его активных складов. */
    private function isProductQuantityConsistentWithValue(
        int $productId,
        float $productQuantity,
        float $productReservedQuantity
    ): bool
    {
        /** @var array{available: float, reserved: float} $storeTotals Итоги активных складов товара. */
        $storeTotals = $this->getActiveStoreQuantityTotals($productId);
        if (abs($productQuantity - $storeTotals['available']) > 0.00001) {
            return false;
        }

        if (!State::isUsedInventoryManagement()) {
            return true;
        }

        return $productReservedQuantity >= 0
            && abs($productReservedQuantity - $storeTotals['reserved']) <= 0.00001;
    }

    /** Читает строковое значение артикула конкретного элемента. */
    private function getArticleValue(int $catalogId, int $productId): string
    {
        /** @var array<string, mixed>|false $propertyValue Строка значения свойства legacy API. */
        $propertyValue = \CIBlockElement::GetProperty(
            $catalogId,
            $productId,
            [],
            ['CODE' => self::ARTICLE_PROPERTY_CODE]
        )->Fetch();

        return $propertyValue === false ? '' : trim((string)$propertyValue['VALUE']);
    }

    /**
     * Проверяет, что артикул не занят другим элементом выбранного CRM-каталога.
     *
     * Текущий товар исключается при повторном заполнении, поэтому собственное существующее
     * значение не считается конфликтом. Свойство адресуется по неизменяемому ID, а не по коду.
     */
    private function isArticleValueAvailable(
        int $catalogId,
        int $articlePropertyId,
        string $article,
        ?int $excludedProductId = null
    ): bool
    {
        $article = trim($article);
        if ($catalogId <= 0 || $articlePropertyId <= 0 || $article === '') {
            return false;
        }

        /** @var array<string, mixed> $filter Фильтр точного артикула с исключением текущего товара. */
        $filter = [
            'IBLOCK_ID' => $catalogId,
            '=PROPERTY_' . $articlePropertyId => $article,
        ];
        if ($excludedProductId !== null && $excludedProductId > 0) {
            $filter['!ID'] = $excludedProductId;
        }

        /** @var \CIBlockResult $elements Первый другой элемент с тем же артикулом. */
        $elements = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        return $elements->Fetch() === false;
    }

    /** Удаляет точный склад модуля только при отсутствии остатков и складских документов. */
    private function removeStoreIfEmpty(int $storedStoreId): void
    {
        /** @var array<string, mixed>|null $store Фактический склад по метке владельца. */
        $store = $this->findManagedStore();
        if ($store === null || (int)$store['ID'] !== $storedStoreId) {
            return;
        }
        if (StoreProductTable::getCount(['=STORE_ID' => $storedStoreId]) > 0) {
            return;
        }
        if (
            StoreDocumentElementTable::getCount(
                [
                    [
                        'LOGIC' => 'OR',
                        '=STORE_FROM' => $storedStoreId,
                        '=STORE_TO' => $storedStoreId,
                    ],
                ]
            ) > 0
        ) {
            return;
        }

        if (!\CCatalogStore::Delete($storedStoreId)) {
            global $APPLICATION;

            /** @var mixed $applicationException Последняя ошибка штатного legacy API склада. */
            $applicationException = is_object($APPLICATION) && method_exists($APPLICATION, 'GetException')
                ? $APPLICATION->GetException()
                : null;
            /** @var string $errorMessage Подробная ошибка Bitrix либо локализованный резервный текст. */
            $errorMessage = is_object($applicationException) && method_exists($applicationException, 'GetString')
                ? trim((string)$applicationException->GetString())
                : '';

            throw new RuntimeException(
                $errorMessage !== ''
                    ? $errorMessage
                    : (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_STORE_DELETE_FAILED')
            );
        }
    }

    /**
     * Удаляет свойство артикула только после удаления всех использующих его значений.
     *
     * @param int|null $expectedPropertyId Если передан, удаляется только свойство с этим ID.
     */
    private function removeArticlePropertyIfUnused(int $catalogId, ?int $expectedPropertyId = null): void
    {
        /** @var array<string, mixed>|null $property Точное свойство, принадлежащее модулю. */
        $property = $this->findArticleProperty($catalogId);
        if ($property === null || !$this->isArticlePropertyCompatible($property)) {
            return;
        }

        /** @var int $propertyId Неизменяемый ID проверяемого свойства артикула. */
        $propertyId = (int)$property['ID'];
        if ($expectedPropertyId !== null && $propertyId !== $expectedPropertyId) {
            return;
        }
        /** @var \CIBlockResult $elements Элементы с непустым значением точного свойства. */
        $elements = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $catalogId,
                '!PROPERTY_' . $propertyId => false,
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        if ($elements->Fetch() !== false) {
            return;
        }

        if (!\CIBlockProperty::Delete($propertyId)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_ARTICLE_PROPERTY_DELETE_FAILED')
            );
        }
    }

    /**
     * Компенсирует только новые объекты и частично записанные настройки неуспешной подготовки.
     *
     * Существовавшие до вызова свойство и склад не удаляются. Если новый объект уже получил
     * внешние данные, штатные проверки использования также сохраняют его.
     *
     * @param int|null $catalogId Каталог, выбранный до возникновения ошибки.
     * @param int|null $createdArticlePropertyId Свойство, созданное текущим вызовом.
     * @param int|null $createdStoreId Склад, созданный текущим вызовом.
     * @param array<string, string|null> $configurationSnapshot Исходные общие настройки этапа.
     * @param bool $configurationWriteStarted Началась ли запись новой конфигурации.
     *
     * @return string[] Ошибки, которые помешали полностью выполнить компенсацию.
     */
    private function cleanupFailedInfrastructure(
        ?int $catalogId,
        ?int $createdArticlePropertyId,
        ?int $createdStoreId,
        array $configurationSnapshot,
        bool $configurationWriteStarted
    ): array
    {
        /** @var string[] $errors Накопленные ошибки независимых шагов компенсации. */
        $errors = [];

        if ($createdStoreId !== null) {
            try {
                $this->removeStoreIfEmpty($createdStoreId);
            } catch (\Throwable $exception) {
                $errors[] = $this->formatCleanupError($exception);
            }
        }

        if ($catalogId !== null && $createdArticlePropertyId !== null) {
            try {
                $this->removeArticlePropertyIfUnused($catalogId, $createdArticlePropertyId);
            } catch (\Throwable $exception) {
                $errors[] = $this->formatCleanupError($exception);
            }
        }

        if ($configurationWriteStarted) {
            try {
                $this->restoreConfigurationSnapshot($configurationSnapshot);
            } catch (\Throwable $exception) {
                $errors[] = $this->formatCleanupError($exception);
            }
        }

        return $errors;
    }

    /** Возвращает непустой текст ошибки компенсации для итогового исключения миграции. */
    private function formatCleanupError(\Throwable $exception): string
    {
        /** @var string $message Исходное сообщение либо имя класса исключения без сообщения. */
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : get_class($exception);
    }

    /**
     * Сохраняет точные общие значения настроек до начала неатомарной записи конфигурации.
     *
     * @return array<string, string|null> Значение каждой настройки либо null, если записи не было.
     */
    private function captureConfigurationSnapshot(): array
    {
        /** @var array<string, string|null> $snapshot Исходные значения общих настроек модуля. */
        $snapshot = [];
        /** @var string $optionName Очередное имя настройки инфраструктуры каталога. */
        foreach (self::CONFIGURATION_OPTION_NAMES as $optionName) {
            $snapshot[$optionName] = Option::getRealValue(
                ModuleConfiguration::MODULE_ID,
                $optionName,
                ''
            );
        }

        return $snapshot;
    }

    /** Восстанавливает общие настройки этапа в точное состояние до неуспешной записи. */
    private function restoreConfigurationSnapshot(array $snapshot): void
    {
        /** @var string $optionName Очередное имя восстанавливаемой настройки. */
        foreach (self::CONFIGURATION_OPTION_NAMES as $optionName) {
            /** @var string|null $value Исходное значение либо null при отсутствии общей записи. */
            $value = $snapshot[$optionName] ?? null;
            if ($value === null) {
                Option::delete(
                    ModuleConfiguration::MODULE_ID,
                    ['name' => $optionName, 'site_id' => '']
                );
                continue;
            }

            Option::set(ModuleConfiguration::MODULE_ID, $optionName, $value, '');
        }
    }

    /** Сохраняет выбранные ID для последующих сервисов синхронизации и административной страницы. */
    private function saveConfiguration(int $catalogId, int $articlePropertyId, int $storeId): void
    {
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_SPARE_PARTS_CATALOG_ID,
            (string)$catalogId
        );
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_SPARE_PARTS_ARTICLE_PROPERTY_ID,
            (string)$articlePropertyId
        );
        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_SPARE_PARTS_STORE_ID,
            (string)$storeId
        );
    }

    /** Удаляет только технические настройки текущего этапа миграции. */
    private function clearConfiguration(): void
    {
        /** @var string $optionName Очередная принадлежащая этапу настройка b_option. */
        foreach (self::CONFIGURATION_OPTION_NAMES as $optionName) {
            Option::delete(ModuleConfiguration::MODULE_ID, ['name' => $optionName]);
        }
    }

    /** Преобразует ошибки D7 Result в исключение, прерывающее миграцию до сохранения версии. */
    private function throwOnResultErrors(bool $success, array $errorMessages): void
    {
        if ($success) {
            return;
        }

        /** @var string $message Безопасное объединённое описание ошибок штатного API. */
        $message = trim(implode('; ', array_map('strval', $errorMessages)));
        throw new RuntimeException(
            $message !== ''
                ? $message
                : (string)Loc::getMessage('OTUS_AUTOSERVICE_SPARE_PARTS_OPERATION_FAILED')
        );
    }
}
