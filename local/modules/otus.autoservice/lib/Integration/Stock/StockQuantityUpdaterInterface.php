<?php

/**
 * Определяет заменяемый контракт применения абсолютного остатка к штатному каталогу Bitrix.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use Bitrix\Main\Result;
use Closure;

/**
 * Позволяет рабочему сервису использовать каталог, а диагностике — безопасную тестовую реализацию.
 */
interface StockQuantityUpdaterInterface
{
    /**
     * Применяет полученное извне абсолютное физическое количество одной запчасти.
     *
     * Успешный Result обязан содержать идентификатор склада, режим обновления, количества
     * до и после операции и ID проведённого документа, если движение его потребовало.
     *
     * @param StockItem $item Проверенная запчасть из настроенного CRM-каталога.
     * @param int $absoluteQuantity Неотрицательный абсолютный остаток внешнего сервиса.
     * @param Closure(Result): void|null $transactionalSuccessCallback Обязательная запись
     * успешного результата, выполняемая до коммита изменения каталога.
     *
     * @return Result Штатный результат применения либо безопасные поштучные ошибки.
     */
    public function apply(
        StockItem $item,
        int $absoluteQuantity,
        ?Closure $transactionalSuccessCallback = null
    ): Result;
}
