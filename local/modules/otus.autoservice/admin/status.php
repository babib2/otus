<?php

/**
 * Показывает состояние модуля, версию PHP, настройки и доступность зависимостей.
 */

declare(strict_types=1);

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;
use Otus\Autoservice\Integration\Crm\DealCarFieldManager;
use Otus\Autoservice\Integration\Crm\ServiceDealPipelineManager;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Service\ModuleConfiguration;
use Otus\Autoservice\Service\ModuleRequirements;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
global $APPLICATION;

/** @var string $moduleId Системный идентификатор проверяемого модуля. */
$moduleId = 'otus.autoservice';
$APPLICATION->SetTitle((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_TITLE'));

/** @var string $moduleRight Право текущего пользователя на модуль. */
$moduleRight = $APPLICATION->GetGroupRight($moduleId);

if ($moduleRight < 'R') {
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

/** @var \Bitrix\Main\HttpRequest $request Текущий запрос диагностической страницы. */
$request = Context::getCurrent()->getRequest();

/** @var array<string, string>|null $migrationMessage Сообщение о результате миграции или восстановления. */
$migrationMessage = null;

/** @var bool $applyMigrationsRequested Запрошено ли применение новых версий схемы. */
$applyMigrationsRequested = $request->getPost('apply_migrations') === 'Y';

/** @var bool $repairPipelineRequested Запрошено ли восстановление конфигурации сервисной воронки. */
$repairPipelineRequested = $request->getPost('repair_pipeline') === 'Y';

/** @var bool $repairSparePartsCatalogRequested Запрошено ли восстановление инфраструктуры каталога. */
$repairSparePartsCatalogRequested = $request->getPost('repair_spare_parts_catalog') === 'Y';

if (
    $request->isPost()
    && ($applyMigrationsRequested || $repairPipelineRequested || $repairSparePartsCatalogRequested)
) {
    if ($moduleRight < 'W') {
        $migrationMessage = [
            'MESSAGE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_MIGRATION_ACCESS_DENIED'),
            'TYPE' => 'ERROR',
        ];
    } elseif (!check_bitrix_sessid()) {
        $migrationMessage = [
            'MESSAGE' => (string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_INVALID_SESSION'),
            'TYPE' => 'ERROR',
        ];
    } else {
        try {
            if ($repairPipelineRequested) {
                // Сначала применяются возможные миграции, затем повторно сверяется CRM-конфигурация.
                MigrationManager::migrate();
                (new ServiceDealPipelineManager())->repair();
            } elseif ($repairSparePartsCatalogRequested) {
                // Миграции создают основу, а повторный ensure восстанавливает удалённые объекты каталога.
                MigrationManager::migrate();
                (new SparePartsCatalogManager())->ensureExists();
            } else {
                MigrationManager::migrate();
            }

            /** @var string $successMessageCode Код локализованного сообщения об успешном действии. */
            $successMessageCode = match (true) {
                $repairPipelineRequested => 'OTUS_AUTOSERVICE_STATUS_PIPELINE_REPAIR_SUCCESS',
                $repairSparePartsCatalogRequested => 'OTUS_AUTOSERVICE_STATUS_SPARE_PARTS_REPAIR_SUCCESS',
                default => 'OTUS_AUTOSERVICE_STATUS_MIGRATION_SUCCESS',
            };

            $migrationMessage = [
                'MESSAGE' => (string)Loc::getMessage($successMessageCode),
                'TYPE' => 'OK',
            ];
        } catch (Throwable $exception) {
            /** @var string $errorMessageCode Код локализованного сообщения об ошибке действия. */
            $errorMessageCode = match (true) {
                $repairPipelineRequested => 'OTUS_AUTOSERVICE_STATUS_PIPELINE_REPAIR_ERROR',
                $repairSparePartsCatalogRequested => 'OTUS_AUTOSERVICE_STATUS_SPARE_PARTS_REPAIR_ERROR',
                default => 'OTUS_AUTOSERVICE_STATUS_MIGRATION_ERROR',
            };

            $migrationMessage = [
                'MESSAGE' => (string)Loc::getMessage($errorMessageCode),
                'DETAILS' => htmlspecialcharsbx($exception->getMessage()),
                'TYPE' => 'ERROR',
            ];
        }
    }
}

/** @var string[] $requiredModules Идентификаторы зависимостей для таблицы состояния. */
$requiredModules = ModuleRequirements::getRequiredModules();

/** @var string $currentSchemaVersion Последняя успешно применённая миграция. */
$currentSchemaVersion = MigrationManager::getCurrentVersion();

/** @var string $latestSchemaVersion Последняя миграция, известная текущему коду. */
$latestSchemaVersion = MigrationManager::getLatestVersion();

/** @var bool $hasPendingMigrations Есть ли изменения схемы, ожидающие применения. */
$hasPendingMigrations = MigrationManager::hasPendingMigrations();

/** @var int|null $serviceCategoryId Выбранное направление сервисного обслуживания. */
$serviceCategoryId = ModuleConfiguration::getServiceDealCategoryId();

/** @var bool $serviceCategoryExists Существует ли выбранное рабочее направление CRM. */
$serviceCategoryExists = $serviceCategoryId !== null
    && Loader::includeModule('crm')
    && ($serviceCategoryId === 0 || DealCategory::exists($serviceCategoryId));

/** @var string $dealCarFieldName Код связи CRM-сделки с автомобилем. */
$dealCarFieldName = ModuleConfiguration::getDealCarFieldName();

/** @var bool $dealCarFieldExists Создано ли совместимое поле автомобиля в CRM. */
$dealCarFieldExists = (new DealCarFieldManager())->exists();

/** @var ServiceDealPipelineManager $servicePipelineManager Диагностика воронки миграции. */
$servicePipelineManager = new ServiceDealPipelineManager();

/** @var int|null $managedServiceCategoryId Направление, однозначно созданное миграцией. */
$managedServiceCategoryId = $servicePipelineManager->getManagedCategoryId();

/** @var bool $servicePipelineReady Созданы ли направление и все стадии сервиса. */
$servicePipelineReady = $servicePipelineManager->isReady();

/** @var bool $sparePartsCatalogReady Настроены ли каталог, свойство артикула и склад. */
$sparePartsCatalogReady = false;
try {
    $sparePartsCatalogReady = (new SparePartsCatalogManager())->isReady();
} catch (Throwable) {
    // Конфликт внешних ID показывается как неготовая конфигурация, не ломая административную страницу.
}
?>
<div class="adm-detail-content-wrap">
    <?php if ($migrationMessage !== null): ?>
        <?php CAdminMessage::ShowMessage($migrationMessage); ?>
    <?php endif; ?>

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
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_SCHEMA_VERSION'))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=htmlspecialcharsbx($currentSchemaVersion)?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_LATEST_SCHEMA_VERSION'))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=htmlspecialcharsbx($latestSchemaVersion)?>
                        — <?=$hasPendingMigrations
                            ? htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_MIGRATIONS_PENDING'))
                            : htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_STATUS_MIGRATIONS_ACTUAL'))?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STATUS_SERVICE_CATEGORY'
                        ))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?php if ($serviceCategoryId === null): ?>
                            <?=htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_NOT_CONFIGURED'
                            ))?>
                        <?php elseif (!$serviceCategoryExists): ?>
                            ID: <?=$serviceCategoryId?>
                            — <?=htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_SERVICE_CATEGORY_NOT_FOUND'
                            ))?>
                        <?php else: ?>
                            ID: <?=$serviceCategoryId?>
                            — <?=htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_SERVICE_CATEGORY_EXISTS'
                            ))?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STATUS_MANAGED_SERVICE_CATEGORY'
                        ))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?php if ($managedServiceCategoryId === null): ?>
                            <?=htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_PIPELINE_NOT_FOUND'
                            ))?>
                        <?php else: ?>
                            ID: <?=$managedServiceCategoryId?>
                            — <?=$servicePipelineReady
                                ? htmlspecialcharsbx((string)Loc::getMessage(
                                    'OTUS_AUTOSERVICE_STATUS_PIPELINE_READY'
                                ))
                                : htmlspecialcharsbx((string)Loc::getMessage(
                                    'OTUS_AUTOSERVICE_STATUS_PIPELINE_NOT_READY'
                                ))?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STATUS_DEAL_CAR_FIELD'
                        ))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=htmlspecialcharsbx($dealCarFieldName)?>
                        — <?=$dealCarFieldExists
                            ? htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_FIELD_EXISTS'
                            ))
                            : htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_FIELD_MISSING'
                            ))?>
                    </td>
                </tr>
                <tr>
                    <td class="adm-detail-content-cell-l">
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STATUS_SPARE_PARTS_CATALOG'
                        ))?>
                    </td>
                    <td class="adm-detail-content-cell-r">
                        <?=$sparePartsCatalogReady
                            ? htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_SPARE_PARTS_CATALOG_READY'
                            ))
                            : htmlspecialcharsbx((string)Loc::getMessage(
                                'OTUS_AUTOSERVICE_STATUS_SPARE_PARTS_CATALOG_NOT_READY'
                            ))?>
                    </td>
                </tr>
                </tbody>
            </table>

            <?php if (
                ($hasPendingMigrations || !$servicePipelineReady || !$serviceCategoryExists)
                && $moduleRight >= 'W'
            ): ?>
                <form method="post" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPageParam('', []))?>">
                    <?=bitrix_sessid_post()?>
                    <?php if ($hasPendingMigrations): ?>
                        <input type="hidden" name="apply_migrations" value="Y">
                    <?php else: ?>
                        <input type="hidden" name="repair_pipeline" value="Y">
                    <?php endif; ?>
                    <input
                        type="submit"
                        class="adm-btn-save"
                        value="<?=htmlspecialcharsbx((string)Loc::getMessage(
                            $hasPendingMigrations
                                ? 'OTUS_AUTOSERVICE_STATUS_APPLY_MIGRATIONS'
                                : 'OTUS_AUTOSERVICE_STATUS_REPAIR_PIPELINE'
                        ))?>"
                    >
                </form>
            <?php endif; ?>

            <?php if (!$hasPendingMigrations && !$sparePartsCatalogReady && $moduleRight >= 'W'): ?>
                <form method="post" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPageParam('', []))?>">
                    <?=bitrix_sessid_post()?>
                    <input type="hidden" name="repair_spare_parts_catalog" value="Y">
                    <input
                        type="submit"
                        class="adm-btn-save"
                        value="<?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_STATUS_REPAIR_SPARE_PARTS_CATALOG'
                        ))?>"
                    >
                </form>
            <?php endif; ?>
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
