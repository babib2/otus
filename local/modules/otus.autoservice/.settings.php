<?php

/**
 * Регистрирует серверные точки расширения модуля, которые читает конфигурация ядра Bitrix.
 *
 * Провайдер `otus_autoservice_car` используется стандартным UI Entity Selector
 * для безопасной загрузки автомобилей выбранного контакта CRM.
 */

declare(strict_types=1);

return [
    'controllers' => [
        'value' => [
            'defaultNamespace' => '\\Otus\\Autoservice\\Controller',
            'namespaces' => [
                '\\Otus\\Autoservice\\Controller' => 'api',
            ],
        ],
        'readonly' => true,
    ],
    'ui.entity-selector' => [
        'value' => [
            'entities' => [
                [
                    'entityId' => 'otus_autoservice_car',
                    'provider' => [
                        'moduleId' => 'otus.autoservice',
                        'className' => '\\Otus\\Autoservice\\Integration\\UI\\EntitySelector\\CarProvider',
                    ],
                ],
            ],
        ],
        'readonly' => true,
    ],
];
