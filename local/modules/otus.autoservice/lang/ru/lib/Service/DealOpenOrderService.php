<?php

/**
 * Содержит сообщения серверной проверки сервисных сделок и незакрытых заказов.
 */

/** @var array<string, string> $MESS Локализованные бизнес-ошибки CRM-сделки. */
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CRM_REQUIRED'] = 'Модуль CRM недоступен для проверки сервисной сделки.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CAR_REQUIRED'] = 'Для сделки сервисного обслуживания необходимо выбрать автомобиль.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CONTACT_REQUIRED'] = 'Для сервисной сделки необходимо выбрать основного контакта клиента.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CAR_NOT_FOUND'] = 'Выбранный автомобиль не найден в справочнике автосервиса.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CAR_INACTIVE'] = 'Нельзя открыть заказ по архивному автомобилю.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_CAR_CONTACT_MISMATCH'] = 'Выбранный автомобиль не принадлежит основному контакту сделки.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_OPEN_ORDER_GENERIC'] = 'По выбранному автомобилю уже существует незакрытый заказ. Закройте предыдущую сделку перед созданием новой.';
$MESS['OTUS_AUTOSERVICE_DEAL_ERROR_OPEN_ORDER_DETAILS'] = 'По автомобилю уже открыт заказ №#ID# «#TITLE#». Закройте его перед созданием нового: #URL#';
