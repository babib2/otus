<?php

/**
 * Публикует адресные PushPull-события об изменении гаража конкретного контакта.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\Signer;
use Otus\Autoservice\Logger\ModuleLogger;
use Throwable;

/**
 * Сервис синхронизации открытых вкладок «Гараж» без раскрытия данных посторонним пользователям.
 */
final class CarPullService
{
    /** Команда клиентской подписки, сообщающая об изменении списка автомобилей. */
    public const COMMAND_GARAGE_CHANGED = 'garageChanged';

    /** Префикс непрогнозируемого watch-тега контакта. */
    private const WATCH_TAG_PREFIX = 'otus_autoservice_garage_contact_';

    /** Изолированная соль системной подписи watch-тега гаража. */
    private const WATCH_TAG_SIGNATURE_SALT = 'otus.autoservice.garage.pull';

    /**
     * Возвращает watch-тег, на который подписывается открытая вкладка контакта.
     *
     * @param int $contactId Идентификатор текущего контакта CRM.
     */
    public static function getWatchTag(int $contactId): string
    {
        /** @var string $contactKey Положительный ID, подписываемый системным ключом Bitrix. */
        $contactKey = (string)max(0, $contactId);

        /** @var string $signature Неподбираемая без серверного ключа часть watch-тега. */
        $signature = (new Signer())->getSignature(
            $contactKey,
            self::WATCH_TAG_SIGNATURE_SALT
        );

        return self::WATCH_TAG_PREFIX . $signature;
    }

    /**
     * Уведомляет только пользователей, подписанных на гараж изменённого контакта.
     *
     * @param int    $contactId Идентификатор владельца автомобиля.
     * @param int    $carId     Идентификатор изменённого автомобиля.
     * @param string $operation Машинное имя операции: create, update или archive.
     * @param string $originId  Клиентский экземпляр, который обновит себя по AJAX-ответу.
     */
    public static function publish(
        int $contactId,
        int $carId,
        string $operation,
        string $originId = ''
    ): void
    {
        if ($contactId <= 0 || !Loader::includeModule('pull') || !class_exists('\\CPullWatch')) {
            return;
        }

        try {
            /** @var string|null $normalizedOriginId Безопасный идентификатор вкладки без произвольного содержимого. */
            $normalizedOriginId = preg_replace('/[^a-z0-9_-]/i', '', $originId);
            $normalizedOriginId = substr($normalizedOriginId ?? '', 0, 64);

            \CPullWatch::AddToStack(
                self::getWatchTag($contactId),
                [
                    'module_id' => ModuleConfiguration::MODULE_ID,
                    'command' => self::COMMAND_GARAGE_CHANGED,
                    'params' => [
                        'contactId' => $contactId,
                        'carId' => max(0, $carId),
                        'operation' => $operation,
                        'originId' => $normalizedOriginId,
                    ],
                ]
            );
        } catch (Throwable $exception) {
            ModuleLogger::warning(
                ModuleLogger::AUDIT_CAR_PULL_FAILED,
                (string)$carId,
                [
                    'contact_id' => $contactId,
                    'operation' => $operation,
                    'exception' => get_class($exception),
                ]
            );
        }
    }
}
