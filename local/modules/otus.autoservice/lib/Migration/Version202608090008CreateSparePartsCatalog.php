<?php

/**
 * Подключает штатный CRM-каталог, свойство артикула и склад к модулю автосервиса.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Otus\Autoservice\Integration\Catalog\SparePartsCatalogManager;

/**
 * Восьмая миграция модуля — подготовка инфраструктуры каталога без демонстрационных товаров.
 */
final class Version202608090008CreateSparePartsCatalog implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608090008';

    /** Возвращает уникальную версию миграции для MigrationManager. */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /** Создаёт или восстанавливает объекты каталога без изменения глобального режима складского учёта. */
    public function up(): void
    {
        /** @var SparePartsCatalogManager $manager Менеджер штатных объектов catalog и iblock. */
        $manager = new SparePartsCatalogManager();
        $manager->ensureExists();
    }

    /** Удаляет только неиспользуемые объекты, однозначно принадлежащие модулю. */
    public function down(): void
    {
        /** @var SparePartsCatalogManager $manager Менеджер безопасного отката каталога запчастей. */
        $manager = new SparePartsCatalogManager();
        $manager->removeIfOwned();
    }
}
