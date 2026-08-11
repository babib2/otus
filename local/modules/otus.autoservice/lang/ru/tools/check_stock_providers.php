<?php

/**
 * Содержит русские сообщения CLI-проверки поставщиков внешних остатков.
 */

/** @var array<string, string> $MESS Словарь ошибок и итогов диагностического сценария. */
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_DOCUMENT_ROOT_MISSING'] = 'Не найден пролог Bitrix в корне #ROOT#.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_MODULE_REQUIRED'] = 'Модуль otus.autoservice не установлен или не подключён.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_ASSERTION_FAILED'] = 'Проверка поставщиков не пройдена: #CASE#.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_CONFIGURED_PROVIDER'] = 'Настроенный поставщик внешних остатков: #CODE#';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_LIVE_QUANTITY'] = 'Тестовый ответ Random.org: #QUANTITY#';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_LIVE_FAILED'] = 'Реальный запрос поставщика завершился ошибкой #TYPE#: #ERROR#';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_OK'] = 'Поставщики внешних остатков: OK';
