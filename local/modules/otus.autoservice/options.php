<?php

/**
 * Выводит и сохраняет основные настройки модуля в административной части Bitrix.
 */

declare(strict_types=1);

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Otus\Autoservice\Integration\Crm\ServiceDealPipelineManager;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
/** @var CUser $USER Текущий пользователь административного раздела. */
global $APPLICATION, $USER;

/** @var string $moduleId Системный идентификатор, используемый при чтении прав и настроек. */
$moduleId = 'otus.autoservice';

if (!Loader::includeModule($moduleId)) {
    CAdminMessage::ShowMessage(
        (string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_MODULE_NOT_LOADED')
    );

    return;
}

if (!$USER->IsAdmin() && $APPLICATION->GetGroupRight($moduleId) < 'W') {
    $APPLICATION->AuthForm((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_ACCESS_DENIED'));
}

/** @var \Bitrix\Main\HttpRequest $request Текущий HTTP-запрос страницы настроек. */
$request = Context::getCurrent()->getRequest();

/** @var bool $saved Нужно ли показать уведомление об успешном изменении настроек. */
$saved = false;

/** @var string[] $optionErrors Ошибки проверки административной формы. */
$optionErrors = [];

/** @var array<int, array<string, mixed>> $dealCategories Доступные направления CRM-сделок. */
$dealCategories = Loader::includeModule('crm')
    ? DealCategory::getAll(true)
    : [];

/** @var int[] $availableCategoryIds Белый список ID из штатного CRM API. */
$availableCategoryIds = array_map(
    static function (array $category): int {
        return (int)$category['ID'];
    },
    $dealCategories
);

// Изменения принимаются только POST-запросом с действительным идентификатором сессии.
if ($request->isPost() && check_bitrix_sessid()) {
    if ($request->getPost('RestoreDefaults') !== null) {
        // Сброс возвращает воронку миграции и не затрагивает версию схемы или владение CRM-полем.
        foreach (
            [
                ModuleConfiguration::OPTION_ENABLED,
                ModuleConfiguration::OPTION_LOG_LEVEL,
                ModuleConfiguration::OPTION_STOCK_PROVIDER,
                ModuleConfiguration::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID,
            ] as $optionName
        ) {
            /** @var string $optionName Очередная пользовательская настройка для сброса. */
            Option::delete($moduleId, ['name' => $optionName]);
        }

        /** @var int|null $managedCategoryId Направление, найденное по метаданным миграции. */
        $managedCategoryId = (new ServiceDealPipelineManager())->getManagedCategoryId();
        if ($managedCategoryId !== null) {
            Option::set(
                $moduleId,
                ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID,
                (string)$managedCategoryId
            );
        } else {
            Option::delete(
                $moduleId,
                ['name' => ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID]
            );
        }

        $saved = true;
    } elseif (
        $request->getPost('Update') !== null
        || $request->getPost('Apply') !== null
    ) {
        /** @var string $enabled Нормализованный флаг включения: только `Y` или `N`. */
        $enabled = $request->getPost(ModuleConfiguration::OPTION_ENABLED) === 'Y'
            ? 'Y'
            : 'N';

        /** @var string $logLevel Запрошенный уровень журналирования до проверки белого списка. */
        $logLevel = (string)$request->getPost(ModuleConfiguration::OPTION_LOG_LEVEL);

        if (!in_array($logLevel, ModuleConfiguration::getAllowedLogLevels(), true)) {
            $logLevel = 'error';
        }

        /** @var string $stockProviderCode Запрошенный код источника внешних остатков. */
        $stockProviderCode = (string)$request->getPost(ModuleConfiguration::OPTION_STOCK_PROVIDER);
        if (!in_array($stockProviderCode, ModuleConfiguration::getAllowedStockProviderCodes(), true)) {
            $optionErrors[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_INVALID'
            );
        }

        /** @var string $stockResponsibleUserIdValue ID ответственного складских документов либо пустая строка. */
        $stockResponsibleUserIdValue = trim(
            (string)$request->getPost(
                ModuleConfiguration::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID
            )
        );
        if ($stockResponsibleUserIdValue !== '') {
            /** @var array<string, mixed>|null $stockResponsibleUser Активный пользователь из белого списка БД. */
            $stockResponsibleUser = preg_match('/^[1-9][0-9]*$/D', $stockResponsibleUserIdValue) === 1
                ? UserTable::getRow(
                    [
                        'select' => ['ID'],
                        'filter' => [
                            '=ID' => (int)$stockResponsibleUserIdValue,
                            '=ACTIVE' => 'Y',
                        ],
                    ]
                )
                : null;
            if (
                $stockResponsibleUser === null
                || (string)(int)$stockResponsibleUser['ID'] !== $stockResponsibleUserIdValue
            ) {
                $optionErrors[] = (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_STOCK_RESPONSIBLE_INVALID'
                );
            }
        }

        /** @var string $categoryIdValue ID выбранного направления либо пустая строка. */
        $categoryIdValue = trim(
            (string)$request->getPost(ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID)
        );

        if (
            $categoryIdValue !== ''
            && (
                preg_match('/^\d+$/', $categoryIdValue) !== 1
                || !in_array((int)$categoryIdValue, $availableCategoryIds, true)
            )
        ) {
            $optionErrors[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_INVALID'
            );
        }

        if ($optionErrors === []) {
            Option::set($moduleId, ModuleConfiguration::OPTION_ENABLED, $enabled);
            Option::set($moduleId, ModuleConfiguration::OPTION_LOG_LEVEL, $logLevel);
            Option::set(
                $moduleId,
                ModuleConfiguration::OPTION_STOCK_PROVIDER,
                $stockProviderCode
            );

            if ($stockResponsibleUserIdValue === '') {
                Option::delete(
                    $moduleId,
                    ['name' => ModuleConfiguration::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID]
                );
            } else {
                Option::set(
                    $moduleId,
                    ModuleConfiguration::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID,
                    $stockResponsibleUserIdValue
                );
            }

            if ($categoryIdValue === '') {
                Option::delete(
                    $moduleId,
                    ['name' => ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID]
                );
            } else {
                Option::set(
                    $moduleId,
                    ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID,
                    $categoryIdValue
                );
            }

            $saved = true;
        }
    }
}

if ($saved) {
    CAdminMessage::ShowNote((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_SAVED'));
}

/** @var string $optionError Очередная ошибка формы, показываемая администратору. */
foreach ($optionErrors as $optionError) {
    CAdminMessage::ShowMessage($optionError);
}

/**
 * @var array<int, array{DIV: string, TAB: string, TITLE: string}> $tabs
 * Конфигурация вкладок, передаваемая стандартному CAdminTabControl.
 */
$tabs = [
    [
        'DIV' => 'otus_autoservice_main',
        'TAB' => Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_TAB'),
        'TITLE' => Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_TAB_TITLE'),
    ],
];
/** @var CAdminTabControl $tabControl Объект, формирующий стандартную вкладку настроек. */
$tabControl = new CAdminTabControl('otusAutoserviceOptions', $tabs);

/** @var bool $enabled Текущее сохранённое состояние прикладной логики. */
$enabled = ModuleConfiguration::isEnabled();

/** @var string $logLevel Текущий проверенный уровень журналирования. */
$logLevel = ModuleConfiguration::getLogLevel();

/** @var string $stockProviderCode Текущий проверенный код источника внешних остатков. */
$stockProviderCode = ModuleConfiguration::getStockProviderCode();

/** @var int|null $stockResponsibleUserId Ответственный складских документов для cron. */
$stockResponsibleUserId = ModuleConfiguration::getStockDocumentResponsibleUserId();

/** @var int|null $serviceCategoryId Выбранное направление сервисного обслуживания. */
$serviceCategoryId = ModuleConfiguration::getServiceDealCategoryId();

/** @var string $dealCarFieldName Технический код поля связи сделки с автомобилем. */
$dealCarFieldName = ModuleConfiguration::getDealCarFieldName();
?>
<form method="post" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPage())?>?mid=<?=urlencode($moduleId)?>&amp;lang=<?=urlencode(LANGUAGE_ID)?>">
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%">
            <label for="otus-autoservice-enabled">
                <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_ENABLED'))?>
            </label>
        </td>
        <td width="60%">
            <input
                id="otus-autoservice-enabled"
                type="checkbox"
                name="<?=htmlspecialcharsbx(ModuleConfiguration::OPTION_ENABLED)?>"
                value="Y"
                <?=$enabled ? 'checked' : ''?>
            >
        </td>
    </tr>
    <tr>
        <td width="40%">
            <label for="otus-autoservice-log-level">
                <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL'))?>
            </label>
        </td>
        <td width="60%">
            <select
                id="otus-autoservice-log-level"
                name="<?=htmlspecialcharsbx(ModuleConfiguration::OPTION_LOG_LEVEL)?>"
            >
                <?php /** @var string $allowedLogLevel Допустимое значение очередного пункта списка. */ ?>
                <?php foreach (ModuleConfiguration::getAllowedLogLevels() as $allowedLogLevel): ?>
                    <option
                        value="<?=htmlspecialcharsbx($allowedLogLevel)?>"
                        <?=$allowedLogLevel === $logLevel ? 'selected' : ''?>
                    >
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_OPTIONS_LOG_LEVEL_' . strtoupper($allowedLogLevel)
                        ))?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td width="40%">
            <label for="otus-autoservice-stock-provider">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER'
                ))?>
            </label>
        </td>
        <td width="60%">
            <select
                id="otus-autoservice-stock-provider"
                name="<?=htmlspecialcharsbx(ModuleConfiguration::OPTION_STOCK_PROVIDER)?>"
            >
                <?php /** @var string $allowedStockProviderCode Код очередного встроенного поставщика. */ ?>
                <?php foreach (ModuleConfiguration::getAllowedStockProviderCodes() as $allowedStockProviderCode): ?>
                    <option
                        value="<?=htmlspecialcharsbx($allowedStockProviderCode)?>"
                        <?=$allowedStockProviderCode === $stockProviderCode ? 'selected' : ''?>
                    >
                        <?=htmlspecialcharsbx((string)Loc::getMessage(
                            'OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_' . strtoupper($allowedStockProviderCode)
                        ))?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="adm-info-message-wrap">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_STOCK_PROVIDER_HELP'
                ))?>
            </div>
        </td>
    </tr>
    <tr>
        <td width="40%">
            <label for="otus-autoservice-stock-responsible-user-id">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_STOCK_RESPONSIBLE'
                ))?>
            </label>
        </td>
        <td width="60%">
            <input
                id="otus-autoservice-stock-responsible-user-id"
                type="number"
                min="1"
                step="1"
                name="<?=htmlspecialcharsbx(
                    ModuleConfiguration::OPTION_STOCK_DOCUMENT_RESPONSIBLE_USER_ID
                )?>"
                value="<?=$stockResponsibleUserId === null ? '' : $stockResponsibleUserId?>"
            >
            <div class="adm-info-message-wrap">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_STOCK_RESPONSIBLE_HELP'
                ))?>
            </div>
        </td>
    </tr>
    <tr>
        <td width="40%">
            <label for="otus-autoservice-service-category">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY'
                ))?>
            </label>
        </td>
        <td width="60%">
            <select
                id="otus-autoservice-service-category"
                name="<?=htmlspecialcharsbx(ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID)?>"
            >
                <option value="">
                    <?=htmlspecialcharsbx((string)Loc::getMessage(
                        'OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_NOT_SELECTED'
                    ))?>
                </option>
                <?php /** @var array<string, mixed> $dealCategory Очередное направление CRM. */ ?>
                <?php foreach ($dealCategories as $dealCategory): ?>
                    <?php /** @var int $dealCategoryId Числовой ID направления из CRM API. */ ?>
                    <?php $dealCategoryId = (int)$dealCategory['ID']; ?>
                    <option
                        value="<?=$dealCategoryId?>"
                        <?=$serviceCategoryId === $dealCategoryId ? 'selected' : ''?>
                    >
                        <?=htmlspecialcharsbx((string)$dealCategory['NAME'])?>
                        (ID: <?=$dealCategoryId?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="adm-info-message-wrap">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_SERVICE_CATEGORY_HELP'
                ))?>
            </div>
        </td>
    </tr>
    <tr>
        <td width="40%">
            <?=htmlspecialcharsbx((string)Loc::getMessage(
                'OTUS_AUTOSERVICE_OPTIONS_DEAL_CAR_FIELD'
            ))?>
        </td>
        <td width="60%">
            <input
                type="text"
                value="<?=htmlspecialcharsbx($dealCarFieldName)?>"
                size="35"
                readonly
            >
            <div class="adm-info-message-wrap">
                <?=htmlspecialcharsbx((string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_OPTIONS_DEAL_CAR_FIELD_HELP'
                ))?>
            </div>
        </td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input
        type="submit"
        name="Update"
        value="<?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_SAVE'))?>"
        class="adm-btn-save"
    >
    <input
        type="submit"
        name="Apply"
        value="<?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_APPLY'))?>"
    >
    <input
        type="submit"
        name="RestoreDefaults"
        value="<?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_DEFAULTS'))?>"
        onclick="return confirm('<?=CUtil::JSEscape((string)Loc::getMessage('OTUS_AUTOSERVICE_OPTIONS_DEFAULTS_CONFIRM'))?>');"
    >
    <?=bitrix_sessid_post()?>
    <?php $tabControl->End(); ?>
</form>
