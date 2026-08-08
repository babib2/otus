<?php

/**
 * Копирует и удаляет статические ресурсы селектора автомобиля в публичном каталоге сайта.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Crm;

use Bitrix\Main\Application;

/**
 * Управляет устанавливаемыми JavaScript- и CSS-файлами поля автомобиля.
 *
 * Исходники хранятся внутри модуля, а браузер получает их из `/local/js`.
 * Один менеджер используется установщиком и миграцией обновления, поэтому
 * новая установка и обновление уже работающего проекта дают одинаковый результат.
 */
final class DealCarSelectorAssetManager
{
    /** Публичный URL JavaScript-файла, подключаемого в карточке CRM-сделки. */
    public const PUBLIC_JS_PATH = '/local/js/otus.autoservice/deal-car-selector/deal-car-selector.js';

    /** Публичный URL таблицы стилей селектора автомобиля. */
    public const PUBLIC_CSS_PATH = '/local/js/otus.autoservice/deal-car-selector/deal-car-selector.css';

    /** Путь назначения ресурсов относительно корня сайта. */
    private const DESTINATION_PATH = '/local/js/otus.autoservice/deal-car-selector';

    /**
     * Копирует текущую версию ресурсов с заменой устаревших файлов.
     *
     * @return bool Результат штатной рекурсивной операции CopyDirFiles().
     */
    public static function install(): bool
    {
        return CopyDirFiles(
            self::getSourceDirectory(),
            self::getDestinationDirectory(),
            true,
            true
        );
    }

    /**
     * Удаляет из публичного каталога только файлы, присутствующие в исходном наборе модуля.
     */
    public static function uninstall(): void
    {
        DeleteDirFiles(
            self::getSourceDirectory(),
            self::getDestinationDirectory()
        );
    }

    /**
     * Возвращает абсолютный каталог исходных ресурсов внутри модуля.
     */
    private static function getSourceDirectory(): string
    {
        return dirname(__DIR__, 3) . '/install/assets/deal-car-selector';
    }

    /**
     * Возвращает абсолютный публичный каталог назначения внутри текущего сайта.
     */
    private static function getDestinationDirectory(): string
    {
        return Application::getDocumentRoot() . self::DESTINATION_PATH;
    }
}
