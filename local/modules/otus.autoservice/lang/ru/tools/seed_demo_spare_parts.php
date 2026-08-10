<?php

/**
 * Содержит русские сообщения явного CLI-заполнения каталога демонстрационными запчастями.
 */

/** @var array<string, string> $MESS Словарь сообщений CLI-сценария заполнения. */
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_DOCUMENT_ROOT_MISSING'] = 'Не найден корень сайта Bitrix: #ROOT#';
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_USAGE'] = 'Использование: php tools/seed_demo_spare_parts.php --apply [корень-сайта]';
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_MODULE_REQUIRED'] = 'Модуль otus.autoservice должен быть установлен.';
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_VALIDATION_FAILED'] = 'Созданный демонстрационный набор не прошёл итоговую проверку.';
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_SUCCESS'] = 'Демонстрационные запчасти подготовлены: каталог #CATALOG_ID#, склад #STORE_ID#, товаров #COUNT#.';
$MESS['OTUS_AUTOSERVICE_SEED_PARTS_ERROR'] = 'Не удалось подготовить демонстрационные запчасти: #ERROR#';
