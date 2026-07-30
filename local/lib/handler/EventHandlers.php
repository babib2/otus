<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/lib/handler/AssetInitFiles.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/lib/handler/EpilogEventHandler.php';

use Bitrix\Main\EventManager;
class EventHandlers
{
    
    public static function addEventHandlers ()
    {
        $em = EventManager::getInstance();

        new EpilogEventHandler();
    }
}



