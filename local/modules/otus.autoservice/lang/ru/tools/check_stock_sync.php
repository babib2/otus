<?php

/**
 * Содержит русские сообщения диагностики ежедневной синхронизации остатков.
 */

$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_DOCUMENT_ROOT_MISSING'] = 'Не найден пролог Битрикс в корне #ROOT#.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_MODULES_REQUIRED'] = 'Не удалось подключить модули otus.autoservice, iblock и catalog.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_MIGRATION_REQUIRED'] = 'Сначала примените все миграции модуля.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_TABLES_REQUIRED'] = 'Не созданы таблицы журналов синхронизации.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_REPOSITORY_INVALID'] = 'Репозиторий вернул повторную или некорректную запчасть.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_READ_OK'] = 'Чтение инфраструктуры синхронизации выполнено успешно. Найдено запчастей: #COUNT#.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTS_REQUIRED'] = 'Для режима --write-test требуется хотя бы одна запчасть с внешним ID и артикулом.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_RUNNING_EXISTS'] = 'Нельзя запускать тест записи, пока существует активная синхронизация.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_RUN_INVALID'] = 'Итоговые данные успешного запуска некорректны.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_ITEMS_INVALID'] = 'Поштучные данные успешного запуска некорректны.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_SUCCESS_DATE_INVALID'] = 'Полный успех не обновил корректную дату последней синхронизации.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_RUN_INVALID'] = 'Счётчики частично успешного запуска некорректны.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_ITEM_INVALID'] = 'Ожидаемая ошибка поставщика сохранена некорректно.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_APPLY_PARTIAL_INVALID'] = 'Ожидаемая ошибка применения остатка сохранена некорректно.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_CONTRACT_RUN_INVALID'] = 'Нарушение связи режима применения и складского документа не остановило запуск.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_PARTIAL_DATE_CHANGED'] = 'Частично успешный запуск изменил дату последней полной синхронизации.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_FUTURE_DATE_ACCEPTED'] = 'Будущая дата ошибочно принята как последняя успешная синхронизация.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_CRASH_RUN_INVALID'] = 'Аварийный запуск без возвращённого ID зафиксирован некорректно.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_EXPECTED_PROVIDER_ERROR'] = 'Диагностический временный сбой поставщика.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_EXPECTED_APPLY_ERROR'] = 'Диагностический отказ применения остатка.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_STALE_CREATE_FAILED'] = 'Не удалось создать диагностический зависший запуск.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_STALE_INVALID'] = 'Зависший запуск восстановлен некорректно.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_CATALOG_CHANGED'] = 'Синхронизация журнала неожиданно изменила количество товара каталога.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_WRITE_OK'] = 'Цикл записи выполнен успешно для #COUNT# запчастей; реальные остатки не изменены.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_OPTION_RESTORE_FAILED'] = 'не удалось подтвердить восстановление настройки последнего успешного запуска';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_SYNC_ERROR'] = 'Ошибка диагностики синхронизации: #ERROR#';
