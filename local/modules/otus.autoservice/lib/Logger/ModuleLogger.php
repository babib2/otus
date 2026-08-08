<?php

/**
 * Записывает структурированные диагностические события модуля в системный журнал Bitrix.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Logger;

use Throwable;

/**
 * Безопасная обёртка над CEventLog для аудита бизнес-ограничений модуля.
 */
final class ModuleLogger
{
    /** Идентификатор попытки создать второй открытый заказ. */
    public const AUDIT_OPEN_ORDER_BLOCKED = 'OTUS_AUTOSERVICE_OPEN_ORDER_BLOCKED';

    /** Идентификатор ошибки отправки уведомления ответственному. */
    public const AUDIT_NOTIFICATION_FAILED = 'OTUS_AUTOSERVICE_NOTIFICATION_FAILED';

    /** Идентификатор успешного создания автомобиля. */
    public const AUDIT_CAR_CREATED = 'OTUS_AUTOSERVICE_CAR_CREATED';

    /** Идентификатор успешного изменения автомобиля. */
    public const AUDIT_CAR_UPDATED = 'OTUS_AUTOSERVICE_CAR_UPDATED';

    /** Идентификатор архивирования автомобиля. */
    public const AUDIT_CAR_ARCHIVED = 'OTUS_AUTOSERVICE_CAR_ARCHIVED';

    /** Идентификатор отклонённой попытки доступа к автомобилю или контакту. */
    public const AUDIT_CAR_ACCESS_DENIED = 'OTUS_AUTOSERVICE_CAR_ACCESS_DENIED';

    /** Идентификатор некритичной ошибки публикации PushPull-события. */
    public const AUDIT_CAR_PULL_FAILED = 'OTUS_AUTOSERVICE_CAR_PULL_FAILED';

    /**
     * Записывает предупреждение, не прерывая основной CRM-сценарий.
     *
     * @param string               $auditType Стабильный машинный тип события.
     * @param string               $itemId    ID сделки либо метка новой записи.
     * @param array<string, mixed> $context   Неперсональные диагностические значения.
     */
    public static function warning(string $auditType, string $itemId, array $context): void
    {
        self::write('WARNING', $auditType, $itemId, $context);
    }

    /**
     * Записывает успешное прикладное действие в системный журнал Bitrix.
     *
     * @param string               $auditType Стабильный машинный тип события.
     * @param string               $itemId    Идентификатор изменённой сущности.
     * @param array<string, mixed> $context   Неперсональные параметры операции.
     */
    public static function info(string $auditType, string $itemId, array $context): void
    {
        self::write('INFO', $auditType, $itemId, $context);
    }

    /**
     * Форматирует контекст единым способом и безопасно вызывает системный журнал.
     *
     * @param string               $severity  Уровень события CEventLog.
     * @param string               $auditType Стабильный машинный тип события.
     * @param string               $itemId    Идентификатор связанной сущности.
     * @param array<string, mixed> $context   Диагностические значения без объектов.
     */
    private static function write(string $severity, string $auditType, string $itemId, array $context): void
    {
        if (!class_exists('\\CEventLog')) {
            return;
        }

        /** @var string|false $description JSON-контекст для административного журнала. */
        $description = json_encode(
            self::normalizeContext($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        try {
            \CEventLog::Add(
                [
                    'SEVERITY' => $severity,
                    'AUDIT_TYPE_ID' => $auditType,
                    'MODULE_ID' => 'otus.autoservice',
                    'ITEM_ID' => $itemId,
                    'DESCRIPTION' => $description === false ? '{}' : $description,
                ]
            );
        } catch (Throwable $exception) {
            // Ошибка диагностической подсистемы не должна менять результат CRM-операции.
        }
    }

    /**
     * Оставляет в контексте только скалярные значения и небольшие массивы скаляров.
     *
     * @param array<string, mixed> $context Исходные данные вызывающего кода.
     *
     * @return array<string, mixed> Значения, безопасные для JSON и журнала.
     */
    private static function normalizeContext(array $context): array
    {
        /** @var array<string, mixed> $normalizedContext Отфильтрованный контекст. */
        $normalizedContext = [];

        /** @var string $key Имя диагностического параметра. */
        /** @var mixed $value Значение диагностического параметра. */
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalizedContext[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $normalizedContext[$key] = array_values(
                    array_filter(
                        $value,
                        static function ($item): bool {
                            return is_scalar($item) || $item === null;
                        }
                    )
                );
            }
        }

        return $normalizedContext;
    }
}
