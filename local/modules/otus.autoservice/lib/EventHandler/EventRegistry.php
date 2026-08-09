<?php

/**
 * Содержит единый реестр событий и управляет регистрацией обработчиков модуля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\EventHandler;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Rest\Engine\ScopeManager;
use Otus\Autoservice\Integration\Rest\CarRestService;

/**
 * Централизованно регистрирует и удаляет обработчики событий модуля.
 */
final class EventRegistry
{
    /**
     * Описание обработчика, публикующего отдельный REST scope и CRUD автомобилей.
     *
     * @var array{from_module: string, event: string, class: class-string, method: string, sort: int}
     */
    private const CAR_REST_SERVICE_EVENT = [
        'from_module' => 'rest',
        'event' => 'OnRestServiceBuildDescription',
        'class' => CarRestService::class,
        'method' => 'onRestServiceBuildDescription',
        'sort' => 100,
    ];

    /**
     * Описание обработчика вкладки «Гараж» в карточке CRM-контакта.
     *
     * @var array{from_module: string, event: string, class: class-string, method: string, sort: int}
     */
    private const CONTACT_GARAGE_TAB_EVENT = [
        'from_module' => 'crm',
        'event' => 'onEntityDetailsTabsInitialized',
        'class' => ContactGarageTabHandler::class,
        'method' => 'onTabsInitialized',
        'sort' => 100,
    ];

    /**
     * Описание обработчика, подключающего селектор автомобиля в карточке сделки.
     * Константа вынесена отдельно, чтобы интерфейсная миграция могла регистрировать
     * и откатывать только свой обработчик, не затрагивая серверную валидацию.
     *
     * @var array{from_module: string, event: string, class: class-string, method: string, sort: int}
     */
    private const DEAL_CAR_SELECTOR_ASSET_EVENT = [
        'from_module' => 'main',
        'event' => 'OnProlog',
        'class' => DealCarSelectorAssetHandler::class,
        'method' => 'onProlog',
        'sort' => 100,
    ];

    /**
     * Новые обработчики добавляются сюда после реализации бизнес-сценариев.
     * Каждый элемент должен содержать идентификатор исходного модуля `from_module`,
     * имя события `event`, полное имя класса `class`, метод `method` и необязательный
     * приоритет выполнения `sort`.
     *
     * @var array<int, array{
     *     from_module: string,
     *     event: string,
     *     class: class-string,
     *     method: string,
     *     sort?: int
     * }>
     */
    private const EVENTS = [
        [
            'from_module' => 'crm',
            'event' => 'OnBeforeCrmDealAdd',
            'class' => DealValidationHandler::class,
            'method' => 'onBeforeAdd',
            'sort' => 50,
        ],
        [
            'from_module' => 'crm',
            'event' => 'OnBeforeCrmDealUpdate',
            'class' => DealValidationHandler::class,
            'method' => 'onBeforeUpdate',
            'sort' => 50,
        ],
        self::DEAL_CAR_SELECTOR_ASSET_EVENT,
        self::CONTACT_GARAGE_TAB_EVENT,
        self::CAR_REST_SERVICE_EVENT,
    ];

    /**
     * Регистрирует все обработчики модуля в Bitrix.
     *
     * Штатный EventManager формирует уникальный ключ регистрации и использует
     * INSERT IGNORE, поэтому метод безопасно вызывается как при новой установке,
     * так и из миграции уже установленного модуля.
     */
    public static function install(): void
    {
        self::registerEvents(self::EVENTS);
        self::clearRestScopeCache();
    }

    /**
     * Удаляет все обработчики модуля из Bitrix.
     *
     * Использует тот же реестр, что и установка, поэтому сигнатуры регистрации
     * и удаления всегда остаются синхронизированными.
     */
    public static function uninstall(): void
    {
        self::unregisterEvents(self::EVENTS);
        self::clearRestScopeCache();
    }

    /**
     * Регистрирует только обработчик интерфейса автомобиля для обновления установленного модуля.
     */
    public static function installDealCarSelectorAssets(): void
    {
        self::registerEvents([self::DEAL_CAR_SELECTOR_ASSET_EVENT]);
    }

    /**
     * Удаляет только обработчик интерфейса автомобиля при откате его миграции.
     */
    public static function uninstallDealCarSelectorAssets(): void
    {
        self::unregisterEvents([self::DEAL_CAR_SELECTOR_ASSET_EVENT]);
    }

    /**
     * Регистрирует только вкладку «Гараж» при обновлении уже установленного модуля.
     */
    public static function installContactGarageTab(): void
    {
        self::registerEvents([self::CONTACT_GARAGE_TAB_EVENT]);
    }

    /**
     * Удаляет только обработчик вкладки «Гараж» при откате её миграции.
     */
    public static function uninstallContactGarageTab(): void
    {
        self::unregisterEvents([self::CONTACT_GARAGE_TAB_EVENT]);
    }

    /**
     * Регистрирует REST API автомобилей при обновлении уже установленного модуля.
     */
    public static function installCarRestApi(): void
    {
        self::registerEvents([self::CAR_REST_SERVICE_EVENT]);
        self::clearRestScopeCache();
    }

    /**
     * Удаляет только обработчик REST API при откате его миграции.
     */
    public static function uninstallCarRestApi(): void
    {
        self::unregisterEvents([self::CAR_REST_SERVICE_EVENT]);
        self::clearRestScopeCache();
    }

    /**
     * Очищает семидневный кеш REST scope после добавления или удаления обработчика.
     *
     * Метод находится в общем реестре, чтобы одинаково работать для миграции,
     * новой установки и обычного удаления модуля с сохранением данных.
     */
    private static function clearRestScopeCache(): void
    {
        if (Loader::includeModule('rest')) {
            ScopeManager::cleanCache();
        }
    }

    /**
     * Регистрирует переданный набор совместимых обработчиков Bitrix.
     *
     * @param array<int, array{
     *     from_module: string,
     *     event: string,
     *     class: class-string,
     *     method: string,
     *     sort?: int
     * }> $events Точный набор добавляемых обработчиков.
     */
    private static function registerEvents(array $events): void
    {
        /** @var EventManager $eventManager Менеджер регистрации событий ядра D7. */
        $eventManager = EventManager::getInstance();

        /** @var array<string, mixed> $event Описание одного регистрируемого обработчика. */
        foreach ($events as $event) {
            $eventManager->registerEventHandlerCompatible(
                $event['from_module'],
                $event['event'],
                'otus.autoservice',
                $event['class'],
                $event['method'],
                isset($event['sort']) ? (int)$event['sort'] : 100
            );
        }
    }

    /**
     * Удаляет переданный набор обработчиков по тем же сигнатурам, что использовались при регистрации.
     *
     * @param array<int, array{
     *     from_module: string,
     *     event: string,
     *     class: class-string,
     *     method: string,
     *     sort?: int
     * }> $events Точный набор удаляемых обработчиков.
     */
    private static function unregisterEvents(array $events): void
    {
        /** @var EventManager $eventManager Менеджер удаления событий ядра D7. */
        $eventManager = EventManager::getInstance();

        /** @var array<string, mixed> $event Описание удаляемого обработчика. */
        foreach ($events as $event) {
            $eventManager->unRegisterEventHandler(
                $event['from_module'],
                $event['event'],
                'otus.autoservice',
                $event['class'],
                $event['method']
            );
        }
    }
}
