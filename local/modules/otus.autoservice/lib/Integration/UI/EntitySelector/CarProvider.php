<?php

/**
 * Загружает активные автомобили контакта и восстанавливает его архивные машины в истории сделок.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\UI\EntitySelector;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;
use Otus\Autoservice\Model\CarTable;
use Otus\Autoservice\Repository\CarRepository;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Серверный провайдер автомобилей для формы CRM-сделки.
 *
 * Идентификатор контакта принимается из параметров диалога, но не считается
 * доверенным: перед чтением автомобилей повторно проверяются авторизация,
 * наличие таблицы модуля и право текущего пользователя читать контакт CRM.
 */
final class CarProvider extends BaseProvider
{
    /** Идентификатор сущности, одинаковый в PHP-конфигурации и JavaScript-селекторе. */
    public const ENTITY_ID = 'otus_autoservice_car';

    /** Идентификатор отдельной вкладки со списком автомобилей контакта. */
    private const TAB_ID = 'otus_autoservice_cars';

    /** Идентификатор контакта CRM, по которому ограничивается каждая выборка. */
    private int $contactId;

    /** Репозиторий, выполняющий фактический ORM-запрос к таблице автомобилей. */
    private CarRepository $carRepository;

    /**
     * Кэш автомобилей на время одного AJAX-запроса селектора.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $availableCars = null;

    /**
     * Нормализует недоверенные параметры диалога и подготавливает репозиторий.
     *
     * @param array<string, mixed> $options Параметры сущности из JavaScript;
     *        используется только положительный целочисленный `contactId`.
     */
    public function __construct(array $options = [])
    {
        parent::__construct();

        $this->contactId = max(0, (int)($options['contactId'] ?? 0));
        $this->options['contactId'] = $this->contactId;
        $this->carRepository = new CarRepository();
    }

    /**
     * Проверяет возможность выполнить запрос селектора в текущем контексте.
     */
    public function isAvailable(): bool
    {
        if (
            !ModuleConfiguration::isEnabled()
            || $this->contactId <= 0
            || (int)CurrentUser::get()->getId() <= 0
        ) {
            return false;
        }

        if (!Loader::includeModule('crm')) {
            return false;
        }

        if (!Application::getConnection()->isTableExists(CarTable::getTableName())) {
            return false;
        }

        return \CCrmContact::CheckReadPermission($this->contactId);
    }

    /**
     * Возвращает запрошенные автомобили только в пределах выбранного контакта.
     *
     * Метод обслуживает в том числе восстановление сохранённого значения поля.
     * ID автомобиля другого контакта намеренно не возвращается.
     *
     * @param array<int, int|string> $ids Идентификаторы, запрошенные UI Entity Selector.
     *
     * Для восстановления исторической сделки разрешается вернуть неактивный автомобиль,
     * но только если он принадлежит выбранному контакту. В обычный список выбора такие
     * автомобили не добавляются методом fillDialog().
     *
     * @return Item[] Разрешённые автомобили выбранного контакта, найденные среди переданных ID.
     */
    public function getItems(array $ids): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        /** @var array<int, true> $requestedIds Множество положительных ID для быстрого точного фильтра. */
        $requestedIds = [];
        foreach ($ids as $id) {
            /** @var int $normalizedId Нормализованный идентификатор из клиентского запроса. */
            $normalizedId = (int)$id;
            if ($normalizedId > 0) {
                $requestedIds[$normalizedId] = true;
            }
        }

        if ($requestedIds === []) {
            return [];
        }

        /** @var Item[] $items Разрешённые элементы, передаваемые обратно в селектор. */
        $items = [];
        foreach (
            $this->carRepository->findByIdsForContact(
                array_keys($requestedIds),
                $this->contactId
            ) as $car
        ) {
            $items[] = $this->createItem($car);
        }

        return $items;
    }

    /**
     * Заполняет вкладку всеми активными автомобилями выбранного контакта.
     */
    public function fillDialog(Dialog $dialog): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $dialog->addTab(
            new Tab(
                [
                    'id' => self::TAB_ID,
                    'title' => (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_TAB_TITLE'),
                ]
            )
        );

        foreach ($this->getAvailableCars() as $car) {
            $dialog->addItem($this->createItem($car));
        }
    }

    /**
     * Получает единый список автомобилей для заполнения и восстановления выбора.
     *
     * @return array<int, array<string, mixed>> Активные автомобили контакта.
     */
    private function getAvailableCars(): array
    {
        if ($this->availableCars === null) {
            $this->availableCars = $this->carRepository->findActiveByContact($this->contactId);
        }

        return $this->availableCars;
    }

    /**
     * Преобразует ORM-запись в безопасный элемент стандартного селектора Bitrix.
     *
     * @param array<string, mixed> $car Запись автомобиля из CarRepository.
     */
    private function createItem(array $car): Item
    {
        /** @var string $vehicleName Марка и модель без лишних пробелов. */
        $vehicleName = trim((string)$car['MAKE'] . ' ' . (string)$car['MODEL']);

        /** @var string $licensePlate Нормализованный государственный номер автомобиля. */
        $licensePlate = (string)$car['LICENSE_PLATE'];

        /** @var string[] $details Дополнительные характеристики для подзаголовка. */
        $details = [];
        if ((int)($car['YEAR'] ?? 0) > 0) {
            $details[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CAR_SELECTOR_YEAR',
                ['#YEAR#' => (string)(int)$car['YEAR']]
            );
        }
        if (trim((string)($car['COLOR'] ?? '')) !== '') {
            $details[] = trim((string)$car['COLOR']);
        }
        if ((int)($car['MILEAGE'] ?? 0) > 0) {
            $details[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CAR_SELECTOR_MILEAGE',
                ['#MILEAGE#' => number_format((int)$car['MILEAGE'], 0, '.', ' ')]
            );
        }
        if ((string)($car['ACTIVE'] ?? 'N') !== 'Y') {
            $details[] = (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_INACTIVE');
        }

        return new Item(
            [
                'id' => (int)$car['ID'],
                'entityId' => self::ENTITY_ID,
                'title' => (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_CAR_SELECTOR_TITLE',
                    [
                        '#CAR#' => $vehicleName,
                        '#PLATE#' => $licensePlate,
                    ]
                ),
                'subtitle' => implode(' · ', $details),
                'tabs' => self::TAB_ID,
                'searchable' => true,
                'availableInRecentTab' => false,
                'saveable' => false,
                'customData' => [
                    'contactId' => $this->contactId,
                    'licensePlate' => $licensePlate,
                    'make' => (string)$car['MAKE'],
                    'model' => (string)$car['MODEL'],
                ],
            ]
        );
    }
}
