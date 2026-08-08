<?php

/**
 * Содержит единый реестр событий и управляет регистрацией обработчиков модуля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\EventHandler;

use Bitrix\Main\EventManager;

/**
 * Централизованно регистрирует и удаляет обработчики событий модуля.
 */
final class EventRegistry
{
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
