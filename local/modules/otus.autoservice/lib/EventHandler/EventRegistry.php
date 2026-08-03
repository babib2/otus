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
    private const EVENTS = [];

    /**
     * Регистрирует все обработчики модуля в Bitrix.
     *
     * Повторное выполнение должно происходить только через штатный установщик,
     * чтобы в таблице зависимостей событий не появлялись лишние записи.
     */
    public static function install(): void
    {
        /** @var EventManager $eventManager Менеджер регистрации событий ядра D7. */
        $eventManager = EventManager::getInstance();

        /** @var array<string, mixed> $event Описание одного обработчика из реестра. */
        foreach (self::EVENTS as $event) {
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
     * Удаляет все обработчики модуля из Bitrix.
     *
     * Использует тот же реестр, что и установка, поэтому сигнатуры регистрации
     * и удаления всегда остаются синхронизированными.
     */
    public static function uninstall(): void
    {
        /** @var EventManager $eventManager Менеджер удаления событий ядра D7. */
        $eventManager = EventManager::getInstance();

        /** @var array<string, mixed> $event Описание удаляемого обработчика. */
        foreach (self::EVENTS as $event) {
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
