<?php

/**
 * Подключает проверку автомобиля и незакрытого заказа к событиям добавления и изменения сделки.
 */

declare(strict_types=1);

namespace Otus\Autoservice\EventHandler;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Otus\Autoservice\Integration\Crm\DealNotificationService;
use Otus\Autoservice\Logger\ModuleLogger;
use Otus\Autoservice\Service\DealOpenOrderService;
use Otus\Autoservice\Service\ModuleConfiguration;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Совместимый обработчик legacy-событий CRM, вызываемых штатным API сделок.
 */
final class DealValidationHandler
{
    /**
     * Проверяет новую сделку и возвращает false для отмены её создания.
     *
     * @param array<string, mixed> $fields Поля новой сделки, переданные CRM по ссылке.
     */
    public static function onBeforeAdd(array &$fields): bool
    {
        if (!ModuleConfiguration::isEnabled()) {
            return true;
        }

        try {
            /** @var DealOpenOrderService $service Сервис единого бизнес-правила сделок. */
            $service = new DealOpenOrderService();

            return self::handleResult(
                $service->validateCreate($fields, self::getCurrentUserId()),
                $fields,
                'NEW'
            );
        } catch (Throwable $exception) {
            // Техническая ошибка не скрывается: CRM отменяет сохранение с безопасным текстом.
            $fields['RESULT_MESSAGE'] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_DEAL_VALIDATION_TECHNICAL_ERROR'
            );
            ModuleLogger::warning(
                ModuleLogger::AUDIT_OPEN_ORDER_BLOCKED,
                'NEW',
                [
                    'technical_error' => get_class($exception),
                    'user_id' => self::getCurrentUserId(),
                ]
            );

            return false;
        }
    }

    /**
     * Проверяет итоговое состояние изменяемой сделки и исключает её собственный ID.
     *
     * @param array<string, mixed> $fields Изменяемые поля сделки, переданные CRM по ссылке.
     */
    public static function onBeforeUpdate(array &$fields): bool
    {
        if (!ModuleConfiguration::isEnabled()) {
            return true;
        }

        /** @var string $dealId Строковое представление ID для журнала. */
        $dealId = (string)(int)($fields['ID'] ?? 0);

        try {
            /** @var DealOpenOrderService $service Сервис проверки существующей сделки. */
            $service = new DealOpenOrderService();

            return self::handleResult(
                $service->validateUpdate($fields, self::getCurrentUserId()),
                $fields,
                $dealId
            );
        } catch (Throwable $exception) {
            $fields['RESULT_MESSAGE'] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_DEAL_VALIDATION_TECHNICAL_ERROR'
            );
            ModuleLogger::warning(
                ModuleLogger::AUDIT_OPEN_ORDER_BLOCKED,
                $dealId,
                [
                    'technical_error' => get_class($exception),
                    'user_id' => self::getCurrentUserId(),
                ]
            );

            return false;
        }
    }

    /**
     * Преобразует D7 Result в контракт OnBeforeCrmDealAdd/Update.
     *
     * @param Result               $result Результат бизнес-проверки.
     * @param array<string, mixed> $fields Поля CRM, в которые записывается RESULT_MESSAGE.
     * @param string               $itemId ID сделки или `NEW` для журнала.
     */
    private static function handleResult(Result $result, array &$fields, string $itemId): bool
    {
        if ($result->isSuccess()) {
            return true;
        }

        /** @var string[] $errorMessages Безопасные локализованные сообщения результата. */
        $errorMessages = $result->getErrorMessages();
        $fields['RESULT_MESSAGE'] = implode(' ', $errorMessages);

        /** @var string[] $errorCodes Машинные коды причин блокировки. */
        $errorCodes = [];
        foreach ($result->getErrors() as $error) {
            $errorCodes[] = (string)$error->getCode();
        }

        /** @var array<string, mixed> $resultData Контекст найденной открытой сделки. */
        $resultData = $result->getData();

        /** @var array<string, mixed> $openDeal Минимальные реквизиты блокирующей сделки. */
        $openDeal = isset($resultData['OPEN_DEAL']) && is_array($resultData['OPEN_DEAL'])
            ? $resultData['OPEN_DEAL']
            : [];

        ModuleLogger::warning(
            ModuleLogger::AUDIT_OPEN_ORDER_BLOCKED,
            $itemId,
            [
                'error_codes' => $errorCodes,
                'car_id' => (int)($resultData['CAR_ID'] ?? 0),
                'category_id' => (int)($resultData['CATEGORY_ID'] ?? 0),
                'open_deal_id' => (int)($openDeal['ID'] ?? 0),
                'user_id' => self::getCurrentUserId(),
            ]
        );

        if (in_array(DealOpenOrderService::ERROR_OPEN_ORDER_EXISTS, $errorCodes, true)) {
            self::notifyResponsible($resultData, $openDeal, $itemId);
        }

        return false;
    }

    /**
     * Отправляет уведомление и отдельно журналирует только технический сбой IM.
     *
     * @param array<string, mixed> $resultData Данные результата бизнес-проверки.
     * @param array<string, mixed> $openDeal   Найденная блокирующая сделка.
     * @param string               $itemId     ID отклонённой сделки или `NEW`.
     */
    private static function notifyResponsible(
        array $resultData,
        array $openDeal,
        string $itemId
    ): void {
        /** @var int $recipientId Ответственный предполагаемой новой сделки. */
        $recipientId = (int)($resultData['ASSIGNED_BY_ID'] ?? 0);
        if ($recipientId <= 0) {
            return;
        }

        try {
            /** @var DealNotificationService $notifier Сервис безопасного IM-уведомления. */
            $notifier = new DealNotificationService();
            if (
                !$notifier->notifyOpenOrderBlocked(
                    $recipientId,
                    $openDeal,
                    (int)($resultData['CATEGORY_ID'] ?? 0)
                )
            ) {
                ModuleLogger::warning(
                    ModuleLogger::AUDIT_NOTIFICATION_FAILED,
                    $itemId,
                    ['recipient_id' => $recipientId]
                );
            }
        } catch (Throwable $exception) {
            ModuleLogger::warning(
                ModuleLogger::AUDIT_NOTIFICATION_FAILED,
                $itemId,
                [
                    'recipient_id' => $recipientId,
                    'technical_error' => get_class($exception),
                ]
            );
        }
    }

    /**
     * Возвращает пользователя CRM-операции, включая REST и фоновые сценарии.
     */
    private static function getCurrentUserId(): int
    {
        return class_exists('\\CCrmSecurityHelper')
            ? max(0, (int)\CCrmSecurityHelper::GetCurrentUserID())
            : 0;
    }
}
