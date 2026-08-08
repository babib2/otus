<?php

/**
 * Выводит панель управления, стандартный фильтр и GRID вкладки «Гараж».
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;

Loc::loadMessages(__FILE__);

/**
 * @var CMain                   $APPLICATION Глобальное приложение для вложенных компонентов.
 * @var CBitrixComponent        $component   Экземпляр компонента гаража.
 * @var CBitrixComponentTemplate $this       Текущий шаблон компонента.
 * @var array<string, mixed>    $arResult    Подготовленные права, строки, фильтр и навигация.
 */

Extension::load(
    [
        'ui.buttons',
        'ui.forms',
        'ui.dialogs.messagebox',
        'ui.notification',
        'pull.client',
    ]
);
CJSCore::Init(['ajax', 'popup']);

if (isset($arResult['ERROR'])) {
    ?>
    <div class="ui-alert ui-alert-danger">
        <span class="ui-alert-message"><?=htmlspecialcharsbx((string)$arResult['ERROR'])?></span>
    </div>
    <?php

    return;
}

/** @var string $containerId Уникальный контейнер клиентского экземпляра вкладки. */
$containerId = (string)$arResult['CONTAINER_ID'];
?>
<div id="<?=htmlspecialcharsbx($containerId)?>" class="otus-autoservice-garage">
    <div class="otus-autoservice-garage__toolbar">
        <?php if ($arResult['CAN_EDIT']): ?>
            <button
                type="button"
                class="ui-btn ui-btn-primary"
                data-role="garage-add-car"
            ><?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ADD'))?></button>
        <?php endif; ?>

        <div class="otus-autoservice-garage__filter">
            <?php
            $APPLICATION->IncludeComponent(
                'bitrix:main.ui.filter',
                '',
                [
                    'FILTER_ID' => $arResult['GRID_ID'],
                    'GRID_ID' => $arResult['GRID_ID'],
                    'FILTER' => $arResult['FILTER_FIELDS'],
                    'ENABLE_LIVE_SEARCH' => true,
                    'ENABLE_LABEL' => true,
                ],
                $component,
                ['HIDE_ICONS' => 'Y']
            );
            ?>
        </div>
    </div>

    <?php
    $APPLICATION->IncludeComponent(
        'bitrix:main.ui.grid',
        '',
        [
            'GRID_ID' => $arResult['GRID_ID'],
            'COLUMNS' => $arResult['COLUMNS'],
            'ROWS' => $arResult['ROWS'],
            'NAV_OBJECT' => $arResult['NAVIGATION'],
            'TOTAL_ROWS_COUNT' => $arResult['TOTAL_COUNT'],
            'SHOW_ROW_CHECKBOXES' => false,
            'SHOW_CHECK_ALL_CHECKBOXES' => false,
            'SHOW_SELECTED_COUNTER' => false,
            'SHOW_TOTAL_COUNTER' => true,
            'SHOW_PAGINATION' => true,
            'SHOW_PAGESIZE' => true,
            'PAGE_SIZES' => [
                ['NAME' => '10', 'VALUE' => '10'],
                ['NAME' => '20', 'VALUE' => '20'],
                ['NAME' => '50', 'VALUE' => '50'],
                ['NAME' => '100', 'VALUE' => '100'],
            ],
            'ALLOW_COLUMNS_SORT' => true,
            'ALLOW_COLUMNS_RESIZE' => true,
            'ALLOW_HORIZONTAL_SCROLL' => true,
            'ALLOW_SORT' => true,
            'ALLOW_PIN_HEADER' => true,
            'AJAX_MODE' => 'Y',
            'AJAX_OPTION_JUMP' => 'N',
            'AJAX_OPTION_HISTORY' => 'N',
            'AJAX_OPTION_STYLE' => 'Y',
        ],
        $component,
        ['HIDE_ICONS' => 'Y']
    );
    ?>
</div>

<script>
BX.ready(function () {
    BX.Otus.Autoservice.Garage.create(<?=CUtil::PhpToJSObject(
        [
            'containerId' => $containerId,
            'gridId' => (string)$arResult['GRID_ID'],
            'contactId' => (int)$arResult['CONTACT_ID'],
            'canEdit' => (bool)$arResult['CAN_EDIT'],
            'actionPrefix' => 'otus:autoservice.api.Car',
            'gridServiceUrl' => (string)$arResult['GRID_SERVICE_URL'],
            'pullWatchTag' => (string)$arResult['PULL_WATCH_TAG'],
            'pullCommand' => (string)$arResult['PULL_COMMAND'],
            'messages' => [
                'formCreateTitle' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_CREATE_TITLE'),
                'formEditTitle' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_EDIT_TITLE'),
                'make' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_MAKE'),
                'model' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_MODEL'),
                'licensePlate' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_LICENSE_PLATE'),
                'year' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_YEAR'),
                'color' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_COLOR'),
                'mileage' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_FORM_MILEAGE'),
                'save' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_SAVE'),
                'cancel' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_CANCEL'),
                'requiredFields' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_REQUIRED_FIELDS'),
                'invalidYear' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_INVALID_YEAR'),
                'invalidMileage' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_INVALID_MILEAGE'),
                'requestFailed' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_REQUEST_FAILED'),
                'archiveTitle' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ARCHIVE_TITLE'),
                'archiveConfirm' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ARCHIVE_CONFIRM'),
                'archiveButton' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ARCHIVE_BUTTON'),
            ],
        ]
    )?>);
});
</script>
