<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

$events = EventManager::getInstance();

/**
 * Обработчик Смарт-процессов
 */
if (Loader::includeModule('crm'))
{
    require_once dirname(__FILE__) . '/handlers/handlerssmart.php';
    new SmartProcessController();
}