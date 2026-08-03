<?php

/**
 * Добавляет раздел автосервиса и ссылки на его страницы в административное меню.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
global $APPLICATION;

if (!Loader::includeModule('otus.autoservice')) {
    return false;
}

// Пункт меню доступен пользователям как минимум с правом чтения модуля.
if ($APPLICATION->GetGroupRight('otus.autoservice') < 'R') {
    return false;
}

/**
 * Возвращаемая структура описывает родительский раздел, сортировку, иконки
 * и дочерние ссылки. Bitrix объединяет её с меню административной панели.
 *
 * @return array<string, mixed>
 */
return [
    'parent_menu' => 'global_menu_services', // Родительский раздел «Сервисы».
    'section' => 'otus_autoservice',         // Уникальный код раздела модуля.
    'sort' => 500,                           // Позиция относительно соседних разделов.
    'text' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_TEXT'),   // Видимое название.
    'title' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_TITLE'), // Всплывающая подсказка.
    'icon' => 'sys_menu_icon',               // Иконка раздела в свёрнутом меню.
    'page_icon' => 'sys_page_icon',          // Иконка на административной странице.
    'items_id' => 'menu_otus_autoservice',   // DOM-идентификатор списка дочерних пунктов.
    'items' => [
        [
            'text' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_STATUS'),       // Подпись ссылки.
            'title' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_STATUS_TITLE'), // Подсказка ссылки.
            'url' => 'otus_autoservice_status.php?lang=' . urlencode(LANGUAGE_ID), // Диагностика.
        ],
        [
            'text' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_SETTINGS'),       // Подпись ссылки.
            'title' => Loc::getMessage('OTUS_AUTOSERVICE_MENU_SETTINGS_TITLE'), // Подсказка ссылки.
            'url' => 'settings.php?lang=' . urlencode(LANGUAGE_ID)
                . '&mid=otus.autoservice&mid_menu=1', // Стандартная форма настроек модуля.
        ],
    ],
];
