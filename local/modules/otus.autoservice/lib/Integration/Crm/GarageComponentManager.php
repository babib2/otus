<?php

/**
 * Устанавливает и удаляет публичный компонент вкладки «Гараж» из local/components.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Crm;

use Bitrix\Main\Application;

/**
 * Единый менеджер файлов компонента для установщика и миграции обновления.
 */
final class GarageComponentManager
{
    /** Путь компонента относительно корня сайта. */
    private const DESTINATION_PATH = '/local/components/otus/autoservice.garage';

    /**
     * Копирует компонент с заменой его предыдущей версии.
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
     * Удаляет только те файлы компонента, которые входят в поставку модуля.
     */
    public static function uninstall(): void
    {
        DeleteDirFiles(
            self::getSourceDirectory(),
            self::getDestinationDirectory()
        );
    }

    /**
     * Проверяет наличие основной точки входа опубликованного компонента.
     */
    public static function isInstalled(): bool
    {
        return is_file(self::getDestinationDirectory() . '/class.php');
    }

    /**
     * Возвращает абсолютный путь исходников компонента внутри модуля.
     */
    private static function getSourceDirectory(): string
    {
        return dirname(__DIR__, 3) . '/install/components/otus/autoservice.garage';
    }

    /**
     * Возвращает абсолютный путь опубликованного компонента сайта.
     */
    private static function getDestinationDirectory(): string
    {
        return Application::getDocumentRoot() . self::DESTINATION_PATH;
    }
}
