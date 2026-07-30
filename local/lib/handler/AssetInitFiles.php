<?php

use Bitrix\Main\Page\Asset;


class AssetInitFiles
{
    static string $page;
    static Asset $asset;

    static function run ()
    {
        global $APPLICATION;
        static::$asset = Asset::getInstance();
    }

    private static function C ($arr)
    {
        return Str::contains(static::$page, $arr);
    }
}
