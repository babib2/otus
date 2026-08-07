<?php

/**
 * Содержит название сервисной CRM-воронки, её стадий и ошибки миграции.
 */

/** @var array<string, string> $MESS Локализация конфигурации направления CRM. */
$MESS['OTUS_AUTOSERVICE_PIPELINE_CRM_REQUIRED'] = 'Модуль CRM недоступен: операция с сервисной воронкой невозможна.';
$MESS['OTUS_AUTOSERVICE_PIPELINE_OPERATION_LOCK_TIMEOUT'] = 'Не удалось получить блокировку изменения сервисной воронки. Повторите операцию.';
$MESS['OTUS_AUTOSERVICE_PIPELINE_NAME'] = 'Сервисное обслуживание';
$MESS['OTUS_AUTOSERVICE_PIPELINE_NAME_CONFLICT'] = 'В CRM уже существует воронка «Сервисное обслуживание», не принадлежащая модулю. Переименуйте её или удалите перед применением миграции.';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_RECEPTION'] = 'Приёмка';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_DIAGNOSTICS'] = 'Диагностика';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_WAITING_PARTS'] = 'Ожидание запчастей';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_REPAIR'] = 'Ремонт';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_QUALITY_CHECK'] = 'Проверка';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_COMPLETED'] = 'Завершено';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_FAILED'] = 'Закрыто без ремонта';
$MESS['OTUS_AUTOSERVICE_PIPELINE_STAGE_CANCELLED'] = 'Отменено клиентом';
