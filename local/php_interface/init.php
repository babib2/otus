<?php
if (file_exists(__DIR__ . '/autoload.php')) {
    require_once __DIR__ . '/autoload.php';
}
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Bitrix\Crm\Service\Container;


require_once $_SERVER['DOCUMENT_ROOT'] . '/local/lib/handler/EventHandlers.php';
\Bitrix\Main\Loader::includeModule("crm");

spl_autoload_register(function($sClassName)
{
    $sClassFile = __DIR__.'/classes';

    if ( file_exists($sClassFile.'/'.str_replace('\\', '/', $sClassName).'.php') )
    {
        require_once($sClassFile.'/'.str_replace('\\', '/', $sClassName).'.php');
        return;
    }

    $arClass = explode('\\', strtolower($sClassName));
    foreach($arClass as $sPath )
    {
        $sClassFile .= '/'.ucfirst($sPath);
    }
    $sClassFile .= '.php';
    if (file_exists($sClassFile))
    {
        require_once($sClassFile);
    }

});
/**
 * Project bootstrap files
 */
foreach( [
    /**
     * File for other kernel data:
     *    Service local integration
     *    Env file with local variables
     *        external service credentials
     *        feature enable flags
     */
    __DIR__.'/kernel.php',

    /**
     * Events subscribe
     */
    __DIR__.'/events.php',

    /**
     * Include composer libraries
     */
    __DIR__.'/vendor/autoload.php',

    /**
     * Include old legacy code
     *   constant initiation etc
     */
    __DIR__.'/legacy.php',

    /**
     * Обработчик Смарт-процессов
     */
    __DIR__.'/handlers.php',

     /**
     * константы
     */
    __DIR__.'/const.php',

    /**
     * функции
     */
    __DIR__.'/functions/functions.php',

    /**
     * Сервисы
     */
    __DIR__.'/services/services.php',
    /**
     * Вкладки на элементах
     * */
    __DIR__.'/classes/Company/Tabs/EventHandlers.php',

    ]
    as $filePath )
{
    if ( file_exists($filePath) )
    {
        require_once($filePath);
    }
}
unset($filePath);
EventHandlers::addEventHandlers();
Services::addInstancesLazy();
