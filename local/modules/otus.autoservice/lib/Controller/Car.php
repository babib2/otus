<?php

/**
 * Обрабатывает защищённые AJAX-команды формы автомобилей во вкладке «Гараж».
 */

declare(strict_types=1);

namespace Otus\Autoservice\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Otus\Autoservice\Logger\ModuleLogger;
use Otus\Autoservice\Service\CarHistoryService;
use Otus\Autoservice\Service\CarService;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Модульный D7-контроллер CRUD автомобилей и чтения истории сервисных сделок.
 */
final class Car extends Controller
{
    /** @var CarService Сервис бизнес-правил и ORM-операций автомобилей. */
    private $carService;

    /** @var CarHistoryService Сервис защищённой пакетной истории ремонтов автомобиля. */
    private $carHistoryService;

    /**
     * Создаёт контроллер со стандартным сервисом автомобилей.
     */
    public function __construct(?Request $request = null)
    {
        parent::__construct($request);
        $this->carService = new CarService();
        $this->carHistoryService = new CarHistoryService();
    }

    /**
     * Ограничивает все действия POST-запросами с авторизацией и CSRF-проверкой.
     *
     * @return array<string, array<string, array<int, object>>> Конфигурация фильтров D7 Engine.
     */
    public function configureActions(): array
    {
        /** @var array<int, object> $mutationFilters Общие фильтры безопасности всех AJAX-команд. */
        $mutationFilters = [
            new Authentication(),
            new HttpMethod([HttpMethod::METHOD_POST]),
            new Csrf(),
        ];

        return [
            'get' => ['prefilters' => $mutationFilters],
            'history' => ['prefilters' => $mutationFilters],
            'create' => ['prefilters' => $mutationFilters],
            'update' => ['prefilters' => $mutationFilters],
            'archive' => ['prefilters' => $mutationFilters],
        ];
    }

    /**
     * Возвращает данные автомобиля для формы изменения после повторной проверки доступа.
     *
     * @param int $contactId Контакт из открытой карточки CRM.
     * @param int $id        Запрошенный автомобиль.
     *
     * @return array<string, mixed>|null Данные формы либо null с ошибками контроллера.
     */
    public function getAction(int $contactId, int $id): ?array
    {
        if (!$this->ensureContactPermission($contactId, true)) {
            return null;
        }

        /** @var array<string, mixed>|null $car Автомобиль, проверяемый на принадлежность контакту. */
        $car = $this->carService->getById($id);
        if ($car === null || (int)$car['CONTACT_ID'] !== $contactId) {
            $this->denyCarAccess($contactId, $id);

            return null;
        }

        return $this->formatCar($car);
    }

    /**
     * Возвращает одну страницу доступных сервисных сделок и запчастей автомобиля.
     *
     * @param int $contactId Контакт из открытой карточки CRM.
     * @param int $id        Автомобиль, для которого открывается история.
     * @param int $page      Номер страницы истории, начиная с единицы.
     * @param int $pageSize  Количество сделок на странице.
     *
     * @return array<string, mixed>|null Данные модального окна либо null с ошибками контроллера.
     */
    public function historyAction(
        int $contactId,
        int $id,
        int $page = 1,
        int $pageSize = CarHistoryService::DEFAULT_PAGE_SIZE
    ): ?array {
        if (!$this->ensureContactPermission($contactId, false)) {
            return null;
        }

        /** @var int $userId Авторизованный пользователь, чьи CRM-права применяются к истории. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);

        /** @var Result $result Проверенная и постраничная история либо прикладная ошибка. */
        $result = $this->carHistoryService->getPage(
            $id,
            $contactId,
            $userId,
            $page,
            $pageSize
        );

        /** @var Error $error Очередная ошибка для отдельного аудита попытки доступа к чужой записи. */
        foreach ($result->getErrors() as $error) {
            if ($error->getCode() === CarHistoryService::ERROR_CAR_NOT_FOUND) {
                $this->denyCarAccess($contactId, $id);

                return null;
            }
        }

        if (!$this->copyResultErrors($result)) {
            return null;
        }

        return $result->getData();
    }

    /**
     * Создаёт автомобиль в гараже контакта, если пользователь может изменять контакт.
     *
     * @param int                  $contactId Владелец из контекста карточки CRM.
     * @param array<string, mixed> $fields    Данные пользовательской формы.
     * @param string               $originId  Клиентский экземпляр, инициировавший изменение.
     *
     * @return array<string, mixed>|null Результат для обновления GRID либо ошибки контроллера.
     */
    public function createAction(
        int $contactId,
        array $fields = [],
        string $originId = ''
    ): ?array {
        if (!$this->ensureContactPermission($contactId, true)) {
            return null;
        }

        /** @var int $userId Авторизованный пользователь, создающий автомобиль. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);

        /** @var \Bitrix\Main\ORM\Data\AddResult $result Результат бизнес-сервиса и D7 ORM. */
        $result = $this->carService->createForContact(
            $contactId,
            $fields,
            $userId,
            $originId
        );
        if (!$this->copyResultErrors($result)) {
            return null;
        }

        return [
            'id' => (int)$result->getId(),
            'message' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_CREATED'),
        ];
    }

    /**
     * Изменяет автомобиль только внутри гаража указанного контакта.
     *
     * @param int                  $contactId Контакт из открытой карточки CRM.
     * @param int                  $id        Изменяемый автомобиль.
     * @param array<string, mixed> $fields    Данные пользовательской формы.
     * @param string               $originId  Клиентский экземпляр, инициировавший изменение.
     *
     * @return array<string, mixed>|null Результат для интерфейса либо ошибки контроллера.
     */
    public function updateAction(
        int $contactId,
        int $id,
        array $fields = [],
        string $originId = ''
    ): ?array {
        if (!$this->ensureContactPermission($contactId, true)) {
            return null;
        }

        /** @var int $userId Авторизованный пользователь, изменяющий автомобиль. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);

        /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат проверки владельца и ORM-изменения. */
        $result = $this->carService->updateForContact(
            $id,
            $contactId,
            $fields,
            $userId,
            $originId
        );
        if (!$this->copyResultErrors($result)) {
            return null;
        }

        return [
            'id' => $id,
            'message' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_UPDATED'),
        ];
    }

    /**
     * Архивирует автомобиль без физического удаления связанных исторических данных.
     *
     * @param int $contactId Контакт из открытой карточки CRM.
     * @param int    $id       Архивируемый автомобиль.
     * @param string $originId Клиентский экземпляр, инициировавший архивирование.
     *
     * @return array<string, mixed>|null Результат для интерфейса либо ошибки контроллера.
     */
    public function archiveAction(int $contactId, int $id, string $originId = ''): ?array
    {
        if (!$this->ensureContactPermission($contactId, true)) {
            return null;
        }

        /** @var int $userId Авторизованный пользователь, архивирующий автомобиль. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);

        /** @var \Bitrix\Main\ORM\Data\UpdateResult $result Результат мягкого удаления записи. */
        $result = $this->carService->deactivateForContact(
            $id,
            $contactId,
            $userId,
            $originId
        );
        if (!$this->copyResultErrors($result)) {
            return null;
        }

        return [
            'id' => $id,
            'message' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_ARCHIVED'),
        ];
    }

    /**
     * Проверяет состояние модуля и штатное CRM-право пользователя на контакт.
     *
     * @param int  $contactId Проверяемый контакт CRM.
     * @param bool $update    Требуется ли право изменения вместо чтения.
     */
    private function ensureContactPermission(int $contactId, bool $update): bool
    {
        /** @var bool $allowed Итог штатной проверки CRM с учётом настроенных ролей. */
        $allowed = ModuleConfiguration::isEnabled()
            && $contactId > 0
            && Loader::includeModule('crm')
            && ($update
                ? \CCrmContact::CheckUpdatePermission($contactId)
                : \CCrmContact::CheckReadPermission($contactId));

        if ($allowed) {
            return true;
        }

        /** @var int $userId Пользователь отклонённого запроса для системного аудита. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);
        ModuleLogger::warning(
            ModuleLogger::AUDIT_CAR_ACCESS_DENIED,
            (string)$contactId,
            [
                'contact_id' => $contactId,
                'user_id' => $userId,
                'required_permission' => $update ? 'update' : 'read',
            ]
        );
        $this->addError(
            new Error(
                (string)Loc::getMessage(
                    $update
                        ? 'OTUS_AUTOSERVICE_CAR_ACCESS_DENIED'
                        : 'OTUS_AUTOSERVICE_CAR_READ_ACCESS_DENIED'
                )
            )
        );

        return false;
    }

    /**
     * Регистрирует попытку обратиться к автомобилю другого контакта.
     *
     * @param int $contactId Контакт, переданный клиентским интерфейсом.
     * @param int $carId     Запрошенный автомобиль.
     */
    private function denyCarAccess(int $contactId, int $carId): void
    {
        /** @var int $userId Пользователь отклонённого запроса. */
        $userId = (int)($this->getCurrentUser()?->getId() ?? 0);
        ModuleLogger::warning(
            ModuleLogger::AUDIT_CAR_ACCESS_DENIED,
            (string)$carId,
            [
                'contact_id' => $contactId,
                'car_id' => $carId,
                'user_id' => $userId,
            ]
        );
        $this->addError(
            new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_NOT_FOUND'))
        );
    }

    /**
     * Копирует ошибки D7-результата в стандартный AJAX-ответ контроллера.
     *
     * @param Result $result Результат ORM-операции или бизнес-проверки.
     */
    private function copyResultErrors(Result $result): bool
    {
        if ($result->isSuccess()) {
            return true;
        }

        /** @var Error $error Очередная ошибка D7, возвращаемая клиентскому интерфейсу. */
        foreach ($result->getErrors() as $error) {
            $this->addError($error);
        }

        return false;
    }

    /**
     * Оставляет только поля, необходимые форме редактирования автомобиля.
     *
     * @param array<string, mixed> $car Исходная ORM-запись автомобиля.
     *
     * @return array<string, mixed> Безопасные скалярные данные клиентской формы.
     */
    private function formatCar(array $car): array
    {
        return [
            'id' => (int)$car['ID'],
            'contactId' => (int)$car['CONTACT_ID'],
            'make' => (string)$car['MAKE'],
            'model' => (string)$car['MODEL'],
            'licensePlate' => (string)$car['LICENSE_PLATE'],
            'year' => $car['YEAR'] === null ? null : (int)$car['YEAR'],
            'color' => $car['COLOR'] === null ? '' : (string)$car['COLOR'],
            'mileage' => (int)$car['MILEAGE'],
            'active' => (string)$car['ACTIVE'],
        ];
    }
}
