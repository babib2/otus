<?php

/**
 * Содержит русские сообщения операционной CLI-команды синхронизации остатков.
 */

/** @var array<string, string> $MESS Словарь запуска cron и его результата. */
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_DOCUMENT_ROOT_MISSING'] = 'Не найден пролог Bitrix в корне #ROOT#.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_INVALID_BATCH_SIZE'] = 'Параметр --batch-size должен быть положительным целым числом.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_UNKNOWN_ARGUMENT'] = 'Неизвестный параметр командной строки: #ARGUMENT#.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_EXTRA_POSITIONAL_ARGUMENT'] = 'Допускается только один позиционный аргумент с корнем портала.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_MODULES_REQUIRED'] = 'Не удалось подключить модули otus.autoservice, iblock и catalog.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_MIGRATION_REQUIRED'] = 'Перед cron-запуском примените миграции модуля.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_RECOVERED'] = 'Проверка зависших запусков завершена: восстановлено #COUNT#.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_RESULT_MISSING'] = 'Итоговая запись запуска не найдена.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_RESULT'] = 'Синхронизация ##ID#: поставщик=#PROVIDER#, статус=#STATUS#, всего=#TOTAL#, успешно=#SUCCESS#, ошибок=#FAILED#.';
$MESS['OTUS_AUTOSERVICE_SYNC_STOCK_ERROR'] = 'Синхронизация не выполнена: #ERROR#';
