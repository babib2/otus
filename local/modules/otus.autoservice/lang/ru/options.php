<?php

/**
 * Содержит русские подписи, уведомления и варианты настроек модуля.
 */

/**
 * @var array<string, string> $MESS Глобальный словарь формы настроек и её сообщений.
 */
$MESS['OTUS_AUTOSERVICE_OPTIONS_MODULE_NOT_LOADED'] = 'Модуль otus.autoservice не удалось подключить.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_ACCESS_DENIED'] = 'Недостаточно прав для изменения настроек модуля.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SAVED'] = 'Настройки сохранены.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_TAB'] = 'Основные настройки';
$MESS['OTUS_AUTOSERVICE_OPTIONS_TAB_TITLE'] = 'Общие параметры работы модуля';
$MESS['OTUS_AUTOSERVICE_OPTIONS_ENABLED'] = 'Включить прикладную логику модуля';
$MESS['OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL'] = 'Уровень журналирования';
$MESS['OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL_ERROR'] = 'Только ошибки';
$MESS['OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL_WARNING'] = 'Ошибки и предупреждения';
$MESS['OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL_INFO'] = 'Информационные сообщения';
$MESS['OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL_DEBUG'] = 'Отладочная информация';
$MESS['OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER'] = 'Источник внешних остатков';
$MESS['OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_RANDOM_ORG'] = 'Random.org — демонстрационный HTTP-сервис';
$MESS['OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_FAKE'] = 'Fake — предсказуемые локальные значения';
$MESS['OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_HELP'] = 'Поставщик только получает абсолютное количество. Запись остатков в каталог выполняется отдельным сервисом синхронизации.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_INVALID'] = 'Выбран неизвестный источник внешних остатков.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY'] = 'Воронка сервисного обслуживания';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_NOT_SELECTED'] = 'Не выбрана — проверки сделок отключены';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_HELP'] = 'Ограничение одного открытого заказа применяется только к выбранной воронке.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_INVALID'] = 'Выбрано несуществующее направление CRM-сделок.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_DEAL_CAR_FIELD'] = 'Код поля автомобиля в сделке';
$MESS['OTUS_AUTOSERVICE_OPTIONS_DEAL_CAR_FIELD_HELP'] = 'Поле создаётся глобально для сущности сделки, но проверяется только в выбранной сервисной воронке.';
$MESS['OTUS_AUTOSERVICE_OPTIONS_SAVE'] = 'Сохранить';
$MESS['OTUS_AUTOSERVICE_OPTIONS_APPLY'] = 'Применить';
$MESS['OTUS_AUTOSERVICE_OPTIONS_DEFAULTS'] = 'Вернуть значения по умолчанию';
$MESS['OTUS_AUTOSERVICE_OPTIONS_DEFAULTS_CONFIRM'] = 'Сбросить все настройки модуля?';
