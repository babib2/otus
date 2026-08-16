<?php

/**
 * Содержит русские ошибки сервиса синхронизации внешних остатков.
 */

/** @var array<string, string> $MESS Словарь запуска, блокировки и журналирования синхронизации. */
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_LOCK_TIMEOUT'] = 'Другой процесс синхронизации остатков уже выполняется.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_MODULES_REQUIRED'] = 'Для синхронизации требуются модули iblock и catalog.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_MODULE_DISABLED'] = 'Прикладная логика модуля отключена в настройках.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_MIGRATION_REQUIRED'] = 'Перед синхронизацией необходимо применить миграции модуля.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_TABLES_REQUIRED'] = 'Таблицы журналов синхронизации не созданы.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_CATALOG_REQUIRED'] = 'Инфраструктура каталога запчастей не готова.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_INVALID_ITEM'] = 'У запчасти отсутствует корректный внешний ID или артикул.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_STALE_ERROR'] = 'Запуск помечен ошибочным: heartbeat не обновлялся дольше допустимого времени.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_GENERAL_ERROR'] = 'Запуск прерван общей ошибкой. Подробности доступны в выводе запуска и системной диагностике.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_ORM_ERROR'] = 'Не удалось сохранить журнал синхронизации.';
$MESS['OTUS_AUTOSERVICE_STOCK_SYNC_APPLY_ERROR'] = 'Не удалось применить абсолютный остаток к каталогу.';
