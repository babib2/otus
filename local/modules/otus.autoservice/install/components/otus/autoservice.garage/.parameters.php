<?php

/**
 * Описывает входной параметр контакта для визуальной настройки компонента «Гараж».
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentParameters = [
    'PARAMETERS' => [
        'CONTACT_ID' => [
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_PARAMETER_CONTACT_ID'),
            'TYPE' => 'STRING',
            'DEFAULT' => '0',
        ],
    ],
];
