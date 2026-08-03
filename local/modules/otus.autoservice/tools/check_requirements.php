<?php

/**
 * Выполняет безопасную CLI-проверку PHP и наличия исходников зависимостей Bitrix.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Запрещаем запуск из браузера, чтобы не раскрывать сведения об окружении сайта.
    http_response_code(404);
    exit(1);
}

/** @var array<int, string> $argv Аргументы командной строки, переданные PHP. */

/**
 * @var string $documentRoot Абсолютный нормализованный путь к корню сайта.
 * Первый CLI-аргумент имеет приоритет; без него путь вычисляется от каталога модуля.
 */
$documentRoot = isset($argv[1])
    ? rtrim(str_replace('\\', '/', (string)$argv[1]), '/')
    : str_replace('\\', '/', dirname(__DIR__, 4));

if (!is_dir($documentRoot . '/bitrix/modules/main')) {
    fwrite(STDERR, "Bitrix document root not found: {$documentRoot}" . PHP_EOL);
    exit(1);
}

require_once dirname(__DIR__) . '/lib/Service/ModuleRequirements.php';

use Otus\Autoservice\Service\ModuleRequirements;

/** @var bool $hasErrors Накопительный признак хотя бы одного нарушенного требования. */
$hasErrors = false;

printf(
    "PHP: %s (required >= %s) [%s]%s",
    PHP_VERSION,
    ModuleRequirements::MINIMUM_PHP_VERSION,
    ModuleRequirements::isPhpVersionSupported() ? 'OK' : 'ERROR',
    PHP_EOL
);

if (!ModuleRequirements::isPhpVersionSupported()) {
    $hasErrors = true;
}

/** @var string $moduleId Идентификатор зависимости, проверяемой на текущей итерации. */
foreach (ModuleRequirements::getRequiredModules() as $moduleId) {
    /**
     * @var bool $isAvailable Найдены ли исходники зависимости в штатном или локальном каталоге.
     */
    $isAvailable = is_dir($documentRoot . '/bitrix/modules/' . $moduleId)
        || is_dir($documentRoot . '/local/modules/' . $moduleId);
    printf(
        "Bitrix module %s: %s%s",
        $moduleId,
        $isAvailable ? 'SOURCE AVAILABLE' : 'SOURCE NOT FOUND',
        PHP_EOL
    );

    if (!$isAvailable) {
        $hasErrors = true;
    }
}

exit($hasErrors ? 1 : 0);
