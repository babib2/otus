<?php

/**
 * Прокси-точка входа из /bitrix/admin на страницу состояния локального модуля.
 */

/** @var string $localPage Основной путь страницы для локального размещения модуля. */
$localPage = $_SERVER['DOCUMENT_ROOT']
    . '/local/modules/otus.autoservice/admin/status.php';

/** @var string $bitrixPage Резервный путь для размещения модуля в bitrix/modules. */
$bitrixPage = $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/otus.autoservice/admin/status.php';

// Локальный путь имеет приоритет; резервный сохраняет переносимость дистрибутива.
require_once is_file($localPage) ? $localPage : $bitrixPage;
