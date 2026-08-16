<?php

/**
 * Содержит русские сообщения проверки штатного применения абсолютного остатка.
 */

/** @var array<string, string> $MESS Сообщения чтения, записи, восстановления и ошибок. */
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_ROOT_MISSING'] = 'Не найден корень портала Bitrix: #ROOT#';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_MODULES_REQUIRED'] = 'Для проверки требуются модули otus.autoservice, iblock и catalog.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_MIGRATION_REQUIRED'] = 'Перед проверкой примените все миграции модуля.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_DOCUMENT_API_REQUIRED'] = 'Установленная версия catalog не содержит обязательный API складских документов.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_PART_REQUIRED'] = 'В каталоге нет запчасти для контрольной проверки.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_STORE_REQUIRED'] = 'В настройках отсутствует склад запчастей.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_INITIAL_INVALID'] = 'Исходные количества товара и склада не согласованы.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_READ_OK'] = 'Применитель остатков готов: режим=#MODE#, товар=#PRODUCT_ID#, склад=#STORE_ID#, остаток=#QUANTITY#.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_WRITE_INVENTORY_DENIED'] = 'Безопасный write-test не создаёт складские документы при включённом складском учёте; проверьте этот режим на отдельном тестовом портале.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_INTEGER_REQUIRED'] = 'Для обратимого write-test исходный остаток должен быть целым числом.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_RESULT_INVALID'] = 'Успешный Result не содержит ожидаемых контрольных данных.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_CALLBACK_ROLLBACK_INVALID'] = 'Ошибка записи аудита не откатила изменение остатка.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_RESTORE_INVALID'] = 'Не удалось подтвердить точное восстановление исходных количеств.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_UNKNOWN_ERROR'] = 'Штатная операция вернула ошибку без сообщения.';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_FAILED'] = 'Ошибка проверки применения остатка: #ERROR#';
$MESS['OTUS_AUTOSERVICE_CHECK_STOCK_QUANTITY_WRITE_OK'] = 'Реальная запись штатным API проверена; исходные количества товара и склада восстановлены.';
