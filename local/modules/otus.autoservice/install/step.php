<?php

/**
 * Показывает сообщение об успешной установке модуля и ссылку возврата.
 */

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION Глобальный объект, предоставляющий адрес текущей страницы. */
?>
<form action="<?=$APPLICATION->GetCurPage()?>" method="get">
    <?=CAdminMessage::ShowNote((string)Loc::getMessage('OTUS_AUTOSERVICE_INSTALL_SUCCESS'))?>
    <input type="hidden" name="lang" value="<?=htmlspecialcharsbx(LANGUAGE_ID)?>">
    <input
        type="submit"
        value="<?=htmlspecialcharsbx((string)Loc::getMessage('OTUS_AUTOSERVICE_INSTALL_RETURN'))?>"
    >
</form>
