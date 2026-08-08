<?php

/**
 * Безопасно загружает HTML вкладки «Гараж» отдельным AJAX-запросом карточки CRM-контакта.
 */

declare(strict_types=1);

use Bitrix\Main\Component\ParameterSigner;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\BadSignatureException;

const NO_KEEP_STATISTIC = true;
const NO_AGENT_STATISTIC = true;
const NO_AGENT_CHECK = true;
const PUBLIC_AJAX_MODE = true;
const DisableEventsCheck = true;

/** @var mixed $requestedSiteId Значение сайта из URL до подключения пролога Bitrix. */
$requestedSiteId = $_REQUEST['site'] ?? '';
if (
    !is_string($requestedSiteId)
    || preg_match('/^[a-z][a-z0-9_]$/i', $requestedSiteId) !== 1
    || (defined('SITE_ID') && (string)SITE_ID !== $requestedSiteId)
) {
    die();
}

if (!defined('SITE_ID')) {
    define('SITE_ID', $requestedSiteId);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (
    !defined('B_PROLOG_INCLUDED')
    || B_PROLOG_INCLUDED !== true
    || !Loader::includeModule('crm')
    || !Loader::includeModule('otus.autoservice')
    || !\CCrmSecurityHelper::IsAuthorized()
    || !check_bitrix_sessid()
) {
    die();
}

/** @var array<string, mixed> $componentData Переданные CRM-загрузчиком шаблон и подписанные параметры. */
$componentData = isset($_REQUEST['PARAMS']) && is_array($_REQUEST['PARAMS'])
    ? $_REQUEST['PARAMS']
    : [];

/** @var mixed $signedParameters Серверная подпись параметров компонента без доверия к открытому POST. */
$signedParameters = $componentData['signedParameters'] ?? null;
if (!is_string($signedParameters) || $signedParameters === '') {
    die();
}

try {
    /** @var array<string, mixed> $componentParameters Проверенные параметры, восстановленные из подписи Bitrix. */
    $componentParameters = ParameterSigner::unsignParameters(
        'otus:autoservice.garage',
        $signedParameters
    );
} catch (BadSignatureException | \Bitrix\Main\ArgumentTypeException) {
    die();
}

/** @var int $contactId Идентификатор контакта из подписанных, а не пользовательских параметров. */
$contactId = max(0, (int)($componentParameters['CONTACT_ID'] ?? 0));
if ($contactId <= 0 || !\CCrmContact::CheckReadPermission($contactId)) {
    die();
}

/** @global CMain $APPLICATION Приложение Bitrix для подключения ресурсов и компонента вкладки. */
global $APPLICATION;

header('Content-Type: text/html; charset=' . LANG_CHARSET);
$APPLICATION->ShowAjaxHead();

/** @var string $gridServiceUrl Подписанный URL для сортировки, фильтрации и пагинации GRID. */
$gridServiceUrl = '/local/components/otus/autoservice.garage/lazyload.ajax.php?'
    . http_build_query(
        [
            'site' => (string)SITE_ID,
            'PARAMS' => ['signedParameters' => $signedParameters],
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

$componentParameters['CONTACT_ID'] = $contactId;
$componentParameters['GRID_SERVICE_URL'] = $gridServiceUrl;

$APPLICATION->IncludeComponent(
    'otus:autoservice.garage',
    '.default',
    $componentParameters,
    false,
    [
        'HIDE_ICONS' => 'Y',
        'ACTIVE_COMPONENT' => 'Y',
    ]
);

\CMain::FinalActions();
