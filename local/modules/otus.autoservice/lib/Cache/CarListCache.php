<?php

/**
 * Кеширует страницы списка автомобилей и адресно очищает их после изменений.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Cache;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Throwable;

/**
 * Обёртка над стандартным Bitrix cache с отдельным тегом для каждого контакта.
 */
final class CarListCache
{
    /** Время жизни кеша списка автомобилей в секундах. */
    private const TTL = 300;

    /** Базовый каталог кеша модуля. */
    private const CACHE_DIRECTORY = '/otus.autoservice/garage';

    /** Префикс тега, позволяющий очистить все варианты списка одного контакта. */
    private const TAG_PREFIX = 'otus.autoservice.garage.contact.';

    /**
     * Возвращает закешированный результат либо выполняет переданную загрузку.
     *
     * @param int      $contactId Идентификатор контакта, изолирующий данные владельца.
     * @param array<string, mixed> $parameters Нормализованные фильтр, сортировка и навигация.
     * @param callable(): array{items: array<int, array<string, mixed>>, total: int} $loader
     *        Функция одной загрузки списка и общего количества из ORM.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function remember(int $contactId, array $parameters, callable $loader): array
    {
        /** @var Cache $cache Стандартный экземпляр файлового или настроенного кеша Bitrix. */
        $cache = Cache::createInstance();

        /** @var string $cacheDirectory Каталог кеша конкретного контакта. */
        $cacheDirectory = self::CACHE_DIRECTORY . '/' . $contactId;

        /** @var string $cacheId Ключ конкретного сочетания фильтра, сортировки и страницы. */
        $cacheId = 'page_' . md5(serialize($parameters));

        if ($cache->initCache(self::TTL, $cacheId, $cacheDirectory)) {
            /** @var mixed $cachedData Содержимое ранее сохранённого кеша. */
            $cachedData = $cache->getVars();
            if (is_array($cachedData) && isset($cachedData['items'], $cachedData['total'])) {
                return [
                    'items' => is_array($cachedData['items']) ? $cachedData['items'] : [],
                    'total' => (int)$cachedData['total'],
                ];
            }
        }

        if (!$cache->startDataCache()) {
            return $loader();
        }

        /** @var \Bitrix\Main\Data\TaggedCache $taggedCache Менеджер тегов текущего кеша. */
        $taggedCache = Application::getInstance()->getTaggedCache();
        $taggedCache->startTagCache($cacheDirectory);

        try {
            /** @var array{items: array<int, array<string, mixed>>, total: int} $data Свежие данные ORM. */
            $data = $loader();
            $taggedCache->registerTag(self::getTag($contactId));
            $taggedCache->endTagCache();
            $cache->endDataCache($data);

            return $data;
        } catch (Throwable $exception) {
            $taggedCache->abortTagCache();
            $cache->abortDataCache();

            throw $exception;
        }
    }

    /**
     * Очищает все страницы, фильтры и сортировки гаража одного контакта.
     *
     * @param int $contactId Идентификатор контакта, автомобили которого изменились.
     */
    public static function clearByContact(int $contactId): void
    {
        if ($contactId <= 0) {
            return;
        }

        Application::getInstance()->getTaggedCache()->clearByTag(self::getTag($contactId));
    }

    /**
     * Формирует стабильный тег кеша контакта.
     *
     * @param int $contactId Положительный идентификатор контакта CRM.
     */
    private static function getTag(int $contactId): string
    {
        return self::TAG_PREFIX . $contactId;
    }
}
