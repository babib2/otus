<?php

/**
 * Описывает компонент «Гараж» в визуальном каталоге компонентов Bitrix.
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentDescription = [
    'NAME' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_COMPONENT_NAME'),
    'DESCRIPTION' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_COMPONENT_DESCRIPTION'),
    'PATH' => [
        'ID' => 'otus',
        'NAME' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_COMPONENT_SECTION'),
    ],
];
