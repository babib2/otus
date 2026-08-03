<?php

/**
 * Запрашивает подтверждение удаления и выбор способа сохранения данных модуля.
 */

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @var CMain $APPLICATION Глобальный объект административного приложения.
 * Его текущий адрес используется как действие второго шага удаления.
 */
?>
<form action="<?=$APPLICATION->GetCurPage()?>" method="post">
    <?=CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('OTUS_AUTOSERVICE_UNINSTALL_WARNING'),
        'TYPE' => 'WARNING',
    ])?>
    <p>
        <label>
            <input type="checkbox" name="save_data" value="Y" checked>
            <?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_UNINSTALL_SAVE_DATA'))?>
        </label>
    </p>
    <input type="hidden" name="lang" value="<?=htmlspecialcharsbx(LANGUAGE_ID)?>">
    <input type="hidden" name="id" value="otus.autoservice">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?=bitrix_sessid_post()?>
    <input
        type="submit"
        value="<?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_UNINSTALL_CONTINUE'))?>"
    >
</form>
