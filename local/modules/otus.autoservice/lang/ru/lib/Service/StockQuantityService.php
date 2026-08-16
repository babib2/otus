<?php

/**
 * Содержит русские сообщения штатного применения абсолютных складских остатков.
 */

/** @var array<string, string> $MESS Ошибки контекста, API, документа и проверки результата. */
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_CONFIG_REQUIRED'] = 'Не настроен каталог или склад запчастей.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_ITEM_INVALID'] = 'Запчасть, товар или склад не соответствуют активной инфраструктуре модуля.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_BELOW_RESERVED'] = 'Внешний остаток меньше уже зарезервированного количества на складе.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_RESPONSIBLE_REQUIRED'] = 'Для складского документа нужен активный ответственный: запустите синхронизацию от пользователя или задайте его ID в настройках модуля.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_SITE_REQUIRED'] = 'Не найден активный сайт для складского документа.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_CURRENCY_REQUIRED'] = 'Для складского документа не удалось подключить модуль currency.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_PURCHASING_PRICE_REQUIRED'] = 'Положительная корректировка требует корректную закупочную цену и валюту товара; при партионном учёте цена должна быть больше нуля.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_COMMENT'] = 'Автоматическая синхронизация абсолютного остатка модулем otus.autoservice.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_API_FAILED'] = 'Штатный API каталога не применил остаток.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_DOCUMENT_FAILED'] = 'Не удалось создать или провести складской документ.';
$MESS['OTUS_AUTOSERVICE_STOCK_QUANTITY_VERIFY_FAILED'] = 'Контрольное чтение не подтвердило абсолютный остаток и согласованность количества товара.';
