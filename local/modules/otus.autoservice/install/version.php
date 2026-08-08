<?php

/**
 * Хранит текущую версию модуля и дату выпуска для системы обновлений Bitrix.
 *
 * @var array{VERSION: string, VERSION_DATE: string} $arModuleVersion
 * VERSION следует семантическому формату, VERSION_DATE содержит дату сборки
 * в формате `YYYY-MM-DD HH:MM:SS`, ожидаемом штатным установщиком.
 */

$arModuleVersion = [
    'VERSION' => '0.6.0',                       // Семантическая версия кода модуля.
    'VERSION_DATE' => '2026-08-08 00:00:00',   // Дата сборки текущей версии.
];
