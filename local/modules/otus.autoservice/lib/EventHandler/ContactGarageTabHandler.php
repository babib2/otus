<?php

/**
 * Добавляет штатную вкладку «Гараж» в доступную пользователю карточку CRM-контакта.
 */

declare(strict_types=1);

namespace Otus\Autoservice\EventHandler;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Component\ParameterSigner;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Integration\Crm\GarageComponentManager;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Обработчик D7-события инициализации вкладок детальной карточки CRM.
 */
final class ContactGarageTabHandler
{
    /** Постоянный DOM- и программный идентификатор вкладки модуля. */
    private const TAB_ID = 'otus_autoservice_garage';

    /**
     * Добавляет вкладку с компонентом только для существующего доступного контакта.
     *
     * @param Event $event Событие CRM с entityTypeID, entityID, guid и текущими вкладками.
     */
    public static function onTabsInitialized(Event $event): EventResult
    {
        /** @var int $entityTypeId Тип открытой CRM-сущности. */
        $entityTypeId = (int)$event->getParameter('entityTypeID');

        /** @var int $contactId Идентификатор открытого CRM-контакта. */
        $contactId = (int)$event->getParameter('entityID');

        /** @var array<int, array<string, mixed>> $tabs Текущие вкладки карточки CRM. */
        $tabs = (array)$event->getParameter('tabs');

        if (
            !ModuleConfiguration::isEnabled()
            || !Loader::includeModule('crm')
            || $entityTypeId !== \CCrmOwnerType::Contact
            || $contactId <= 0
            || !\CCrmContact::CheckReadPermission($contactId)
            || !GarageComponentManager::isInstalled()
            || self::containsGarageTab($tabs)
        ) {
            return new EventResult(EventResult::SUCCESS, ['tabs' => $tabs]);
        }

        /** @var string $signedParameters Подписанный сервером ID контакта для ленивой AJAX-загрузки. */
        $signedParameters = ParameterSigner::signParameters(
            'otus:autoservice.garage',
            ['CONTACT_ID' => $contactId]
        );

        /** @var string $siteId Идентификатор текущего сайта для корректной инициализации AJAX-пролога Bitrix. */
        $siteId = defined('SITE_ID')
            ? (string)SITE_ID
            : (string)Context::getCurrent()->getSite();

        /** @var string $serviceUrl Публичный обработчик, возвращающий HTML вкладки без JSON-обёртки. */
        $serviceUrl = sprintf(
            '/local/components/otus/autoservice.garage/lazyload.ajax.php?site=%s&%s',
            rawurlencode($siteId),
            bitrix_sessid_get()
        );

        $tabs[] = [
            'id' => self::TAB_ID,
            'name' => (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_TAB_NAME'),
            'loader' => [
                'serviceUrl' => $serviceUrl,
                'componentData' => [
                    'template' => '.default',
                    'signedParameters' => $signedParameters,
                ],
            ],
        ];

        return new EventResult(EventResult::SUCCESS, ['tabs' => $tabs]);
    }

    /**
     * Проверяет, не была ли вкладка уже добавлена другим вызовом обработчика.
     *
     * @param array<int, array<string, mixed>> $tabs Текущий набор вкладок карточки.
     */
    private static function containsGarageTab(array $tabs): bool
    {
        /** @var array<string, mixed> $tab Очередное описание вкладки CRM. */
        foreach ($tabs as $tab) {
            if ((string)($tab['id'] ?? '') === self::TAB_ID) {
                return true;
            }
        }

        return false;
    }
}
