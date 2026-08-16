<?php

/**
 * Описывает и проверяет требования модуля к PHP и установленным модулям Bitrix.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Main\ModuleManager;

/**
 * Описывает минимальные требования модуля к окружению Bitrix.
 */
final class ModuleRequirements
{
    /**
     * Минимальная версия PHP, на которой разрешена установка модуля.
     */
    public const MINIMUM_PHP_VERSION = '8.1.0';

    /**
     * Идентификаторы модулей Bitrix, обязательных для всех заявленных сценариев.
     * Проверяется именно регистрация модулей в системе, а не только наличие файлов.
     *
     * @var string[]
     */
    private const REQUIRED_MODULES = [
        'main',    // Ядро D7, настройки, события и работа с базой данных.
        'ui',      // Стандартные GRID, фильтр, уведомления и Entity Selector.
        'crm',     // Сделки, контакты, воронки и пользовательские поля CRM.
        'iblock',  // Элементы и свойства штатного каталога запчастей.
        'catalog', // Каталог услуг, товары, цены и складские остатки.
        'currency', // Базовая валюта штатного складского документа корректировки.
        'pull',    // Мгновенное обновление интерфейса и push-события.
        'rest',    // Публикация и обработка REST-методов модуля.
        'im',      // Уведомления сотрудникам через внутренний мессенджер.
        'bizproc', // Согласования заявок и другие бизнес-процессы.
    ];

    /**
     * Возвращает идентификаторы обязательных модулей Bitrix.
     *
     * Метод используется установщиком, диагностической страницей и CLI-проверкой,
     * чтобы список зависимостей не дублировался в разных частях проекта.
     *
     * @return string[]
     */
    public static function getRequiredModules(): array
    {
        return self::REQUIRED_MODULES;
    }

    /**
     * Возвращает список обязательных, но не установленных модулей Bitrix.
     *
     * @return string[]
     */
    public static function getMissingModules(): array
    {
        /** @var string[] $missingModules Идентификаторы зависимостей, не зарегистрированных в Bitrix. */
        $missingModules = [];

        /** @var string $moduleId Проверяемый идентификатор обязательного модуля. */
        foreach (self::REQUIRED_MODULES as $moduleId) {
            if (!ModuleManager::isModuleInstalled($moduleId)) {
                $missingModules[] = $moduleId;
            }
        }

        return $missingModules;
    }

    /**
     * Проверяет соответствие текущей версии PHP минимальному требованию.
     *
     * @return bool Возвращает true, если текущий интерпретатор поддерживается.
     */
    public static function isPhpVersionSupported(): bool
    {
        return version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=');
    }
}
