<?php

/**
 * Уведомляет ответственного сотрудника о блокировке второго открытого заказа.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Crm;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Формирует системное IM-уведомление без раскрытия недоступной CRM-информации.
 */
final class DealNotificationService
{
    /**
     * Отправляет уведомление ответственному несохранённой сделки.
     *
     * @param int                  $recipientId Ответственный пользователь Bitrix.
     * @param array<string, mixed> $openDeal    Минимальные поля блокирующей сделки.
     * @param int                  $categoryId   Направление для проверки CRM-прав получателя.
     *
     * @return bool true при успешной постановке уведомления в IM.
     */
    public function notifyOpenOrderBlocked(
        int $recipientId,
        array $openDeal,
        int $categoryId
    ): bool {
        if ($recipientId <= 0 || !Loader::includeModule('im')) {
            return false;
        }

        /** @var int $openDealId Идентификатор блокирующей сделки. */
        $openDealId = (int)($openDeal['ID'] ?? 0);

        /** @var bool $canReadDeal Имеет ли именно получатель право видеть найденную сделку. */
        $canReadDeal = $openDealId > 0
            && (bool)\CCrmDeal::CheckReadPermission(
                $openDealId,
                \CCrmPerms::GetUserPermissions($recipientId),
                $categoryId
            );

        /** @var string $message Текст внутреннего уведомления с BBCode-ссылкой при наличии прав. */
        $message = (string)Loc::getMessage('OTUS_AUTOSERVICE_NOTIFY_OPEN_ORDER_GENERIC');
        if ($canReadDeal && $openDealId > 0) {
            /** @var string $dealUrl Внутренний путь к карточке существующего заказа. */
            $dealUrl = \CCrmOwnerType::GetEntityShowPath(
                \CCrmOwnerType::Deal,
                $openDealId,
                false
            );

            /** @var string $safeTitle Однострочное название без BBCode-скобок. */
            $safeTitle = str_replace(
                ['[', ']', "\r", "\n", "\t"],
                ['', '', ' ', ' ', ' '],
                strip_tags((string)($openDeal['TITLE'] ?? ''))
            );

            $message = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_NOTIFY_OPEN_ORDER_DETAILS',
                [
                    '#ID#' => (string)$openDealId,
                    '#TITLE#' => trim($safeTitle),
                    '#URL#' => $dealUrl,
                ]
            );
        }

        /** @var int|false $notificationId ID созданного уведомления либо false. */
        $notificationId = \CIMNotify::Add(
            [
                'TO_USER_ID' => $recipientId,
                'FROM_USER_ID' => 0,
                'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
                'NOTIFY_MODULE' => 'otus.autoservice',
                'NOTIFY_EVENT' => 'open_order_blocked',
                'NOTIFY_TAG' => sprintf(
                    'OTUS_AUTOSERVICE_OPEN_ORDER_%d_USER_%d',
                    $openDealId,
                    $recipientId
                ),
                'MESSAGE' => $message,
                'MESSAGE_OUT' => strip_tags($message),
            ]
        );

        return $notificationId !== false && (int)$notificationId > 0;
    }
}
