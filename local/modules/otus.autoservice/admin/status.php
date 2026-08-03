<?php

/**
 * Показывает состояние модуля, версию PHP, настройки и доступность зависимостей.
 */

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Otus\Autoservice\Service\ModuleConfiguration;
use Otus\Autoservice\Service\ModuleRequirements;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
global $APPLICATION;

/** @var string $moduleId Системный идентификатор проверяемого модуля. */
$moduleId = 'otus.autoservice';
$APPLICATION->SetTitle((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_TITLE'));

if ($APPLICATION->GetGroupRight($moduleId) < 'R') {
    $APPLICATION->AuthForm((string)Loc::getMessage('OTUS_AUTOSERVICE_ACCESS_DENIED'));
}

/** @var bool $moduleLoaded Результат подключения классов и include.php модуля. */
$moduleLoaded = Loader::includeModule($moduleId);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (!$moduleLoaded) {
    CAdminMessage::ShowMessage(
        (string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_MODULE_NOT_LOADED')
    );

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

    return;
}

/** @var string[] $requiredModules Идентификаторы зависимостей для таблицы состояния. */
$requiredModules = ModuleRequirements::getRequiredModules();
?>
<div class="adm-detail-content-wrap">
    <div class="adm-detail-content">
        <div class="adm-detail-title">
            <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_SUMMARY'))?>
        </div>
        <div class="adm-detail-content-item-block">
            <table class="adm-detail-content-table edit-table">
                <tbody>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_ENABLED'))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=ModuleConfiguration::isEnabled()
                            ? htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_YES'))
                            : htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_NO'))?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">PHP</td>
                    <td class="adm-detail-content-cell-r">
                        <?=htmlspecialcharsbx(PHP_VERSION)?>
                        (<?=ModuleRequirements::isPhpVersionSupported()
                            ? htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_OK'))
                            : htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_ERROR'))?>)
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_LOG_LEVEL'))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=htmlspecialcharsbx(ModuleConfiguration::getLogLevel())?>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="adm-detail-content">
        <div class="adm-detail-title">
            <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_DEPENDENCIES'))?>
        </div>
        <div class="adm-detail-content-item-block">
            <table class="adm-list-table">
                <thead>
                <tr class="adm-list-table-header">
                    <td class="adm-list-table-cell">
                        <div class="adm-list-table-cell-inner">
                            <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_MODULE'))?>
                        </div>
                    </td>
                    <td class="adm-list-table-cell">
                        <div class="adm-list-table-cell-inner">
                            <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_STATE'))?>
                        </div>
                    </td>
                </tr>
                </thead>
                <tbody>
                <?php /** @var string $requiredModuleId Идентификатор зависимости в текущей строке. */ ?>
                <?php foreach ($requiredModules as $requiredModuleId): ?>
                    <?php /** @var bool $isInstalled Зарегистрирована ли зависимость в системе Bitrix. */ ?>
                    <?php $isInstalled = ModuleManager::isModuleInstalled($requiredModuleId); ?>
                    <tr class="adm-list-table-row">
                        <td class="adm-list-table-cell"><?=htmlspecialcharsbx($requiredModuleId)?></td>
                        <td class="adm-list-table-cell">
                            <?=$isInstalled
                                ? htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_INSTALLED'))
                                : htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_NOT_INSTALLED'))?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
