<?php

/**
 * Подключает ресурсы и русские сообщения селектора автомобиля на странице CRM-сделки.
 */

declare(strict_types=1);

namespace Otus\Autoservice\EventHandler;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use Otus\Autoservice\Integration\Crm\DealCarSelectorAssetManager;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Ограниченно подключает клиентский селектор только в карточках CRM-сделок.
 */
final class DealCarSelectorAssetHandler
{
    /** Шаблон SEF-адресов создания и просмотра/редактирования CRM-сделки. */
    private const DEAL_DETAILS_PATH_PATTERN = '~^/crm/deal/(?:details|edit|show)/~i';

    /**
     * Обрабатывает `main:OnProlog` до формирования HTML страницы.
     *
     * На списках сделок, административных страницах и остальных разделах
     * дополнительные ресурсы не подключаются.
     */
    public static function onProlog(): void
    {
        if (!ModuleConfiguration::isEnabled()) {
            return;
        }

        /** @var string $requestedPage Нормализованный путь текущего HTTP-запроса. */
        $requestedPage = str_replace(
            '\\',
            '/',
            (string)Application::getInstance()->getContext()->getRequest()->getRequestedPage()
        );
        $requestedPage = '/' . ltrim($requestedPage, '/');

        if (preg_match(self::DEAL_DETAILS_PATH_PATTERN, $requestedPage) !== 1) {
            return;
        }

        Extension::load('ui.entity-selector');

        /** @var array<string, string> $messages Локализованные подписи клиентского интерфейса. */
        $messages = [
            'OTUS_AUTOSERVICE_CAR_SELECTOR_CHOOSE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CHOOSE'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_CHANGE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CHANGE'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_CLEAR' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CLEAR'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_EMPTY' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_EMPTY'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_CURRENT_ID' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CURRENT_ID'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_NO_CONTACT' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_NO_CONTACT'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_CONTACT_CHANGED' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CONTACT_CHANGED'),
            'OTUS_AUTOSERVICE_CAR_SELECTOR_LOAD_ERROR' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_LOAD_ERROR'),
        ];

        /** @var Asset $asset Менеджер очереди CSS, JavaScript и встроенных сообщений страницы. */
        $asset = Asset::getInstance();
        $asset->addString(
            '<script>BX.message(' . json_encode(
                $messages,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) . ');</script>'
        );
        $asset->addCss(DealCarSelectorAssetManager::PUBLIC_CSS_PATH);
        $asset->addJs(DealCarSelectorAssetManager::PUBLIC_JS_PATH);
    }
}
