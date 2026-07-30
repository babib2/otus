<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Page\Asset;


class EpilogEventHandler
{
    public function __construct()
    {
        $eventManager = EventManager::getInstance();

        $eventManager->addEventHandler(
            'main',
            'OnEpilog',
            [$this, 'OnEpilogHandler']
        );
    }

     function OnEpilogHandler()
    {
        global $APPLICATION;
             
    }
}
