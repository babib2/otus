<?php

/**
 * Содержит русские сообщения проверки инфраструктуры каталога и демонстрационных запчастей.
 */

/** @var array<string, string> $MESS Словарь ошибок и результатов проверки каталога. */
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DOCUMENT_ROOT_MISSING'] = 'Не найден корень сайта Bitrix: #ROOT#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_MODULES_REQUIRED'] = 'Необходимы установленные модули otus.autoservice, CRM, Инфоблоки и Торговый каталог.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_MIGRATION_MISSING'] = 'Миграция каталога запчастей не применена. Текущая версия: #VERSION#.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_CONFIG_INCOMPLETE'] = 'Настройки инфраструктуры каталога запчастей заполнены не полностью.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_CATALOG_NOT_DEFAULT'] = 'Настроенный каталог запчастей не является основным каталогом CRM.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_STORE_INVALID'] = 'Склад модуля отсутствует или имеет несовместимые параметры.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_MANAGER_NOT_READY'] = 'Менеджер каталога сообщает о незавершённой обязательной инфраструктуре.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_RUNTIME_ERROR'] = 'Проверка каталога завершилась ошибкой: #ERROR#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEFINITION_DUPLICATE'] = 'В определениях демонстрационных товаров повторяется артикул или внешний ID.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_INVALID'] = 'Демонстрационный товар #EXTERNAL_ID# существует, но отключён или повреждён.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_PRODUCT_RECORD_MISSING'] = 'Для товара #PRODUCT_ID# отсутствует товарная или складская запись.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_QUANTITY_INVALID'] = 'Настройки или количества товара #PRODUCT_ID# некорректны либо рассогласованы.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_INCOMPLETE'] = 'Демонстрационный набор заполнен частично: найдено #FOUND# из #EXPECTED# товаров.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_NOT_READY'] = 'Полный демонстрационный набор не прошёл проверку связности.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_REQUIRED'] = 'Демонстрационные товары не добавлены. Запустите seed_demo_spare_parts.php --apply.';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_ENABLED'] = 'включён';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_DISABLED'] = 'выключен';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_READY'] = 'подготовлен';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_ABSENT'] = 'не добавлен';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_CATALOG_ID'] = 'CRM-каталог запчастей: #ID#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_PROPERTY_ID'] = 'Свойство артикула: #ID#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_STORE_ID'] = 'Склад модуля: #ID#; по умолчанию=#DEFAULT#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_DEMO_STATUS'] = 'Демонстрационные товары: #STATUS# (#FOUND#/#EXPECTED#)';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_INVENTORY_STATUS'] = 'Складской учёт: #STATUS#';
$MESS['OTUS_AUTOSERVICE_CHECK_PARTS_OK'] = 'Конфигурация каталога запчастей: OK';
