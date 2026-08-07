<?php

/**
 * Создаёт отдельную CRM-воронку сервисного обслуживания и настраивает её стадии.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Migration;

use Otus\Autoservice\Integration\Crm\ServiceDealPipelineManager;

/**
 * Третья миграция модуля — воспроизводимая конфигурация направления сделок.
 */
final class Version202608050003CreateServiceDealPipeline implements MigrationInterface
{
    /** Версия миграции в хронологическом формате YYYYMMDDNNNN. */
    private const VERSION = '202608050003';

    /**
     * Возвращает уникальную версию миграции.
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Создаёт или повторно настраивает управляемую сервисную воронку.
     */
    public function up(): void
    {
        /** @var ServiceDealPipelineManager $pipelineManager Менеджер CRM-конфигурации. */
        $pipelineManager = new ServiceDealPipelineManager();
        $pipelineManager->ensureExists();
    }

    /**
     * Удаляет только пустую воронку, созданную модулем.
     *
     * Если в ней уже есть сделки, направление сохраняется для защиты истории.
     */
    public function down(): void
    {
        /** @var ServiceDealPipelineManager $pipelineManager Менеджер безопасного отката. */
        $pipelineManager = new ServiceDealPipelineManager();
        $pipelineManager->removeIfOwned();
    }
}
