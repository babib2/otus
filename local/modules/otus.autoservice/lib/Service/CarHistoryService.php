<?php

/**
 * Формирует защищённую постраничную историю сервисных сделок и запчастей одного автомобиля.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Crm\ContactTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Otus\Autoservice\Repository\CarRepository;

Loc::loadMessages(__FILE__);

/**
 * Сервис чтения истории ремонтов без изменения автомобилей, сделок и товарных позиций.
 *
 * Сделки выбираются штатной CRM-фабрикой с SQL-ограничением прав конкретного
 * пользователя. Ответственные и товарные позиции загружаются пакетно после
 * получения одной страницы доступных сделок, поэтому запросов внутри циклов нет.
 */
final class CarHistoryService
{
    /** Код ошибки отсутствующего автомобиля либо его принадлежности другому контакту. */
    public const ERROR_CAR_NOT_FOUND = 'CAR_HISTORY_CAR_NOT_FOUND';

    /** Код ошибки отсутствия CRM-права чтения контакта. */
    public const ERROR_ACCESS_DENIED = 'CAR_HISTORY_ACCESS_DENIED';

    /** Количество сделок на первой и каждой следующей странице истории. */
    public const DEFAULT_PAGE_SIZE = 20;

    /** Верхняя граница страницы, защищающая пакетные запросы от чрезмерной выборки. */
    private const MAXIMUM_PAGE_SIZE = 50;

    /** @var CarRepository Репозиторий проверки существования и владельца автомобиля. */
    private $carRepository;

    /**
     * Принимает репозиторий извне для изолированного тестирования или создаёт стандартный.
     */
    public function __construct(?CarRepository $carRepository = null)
    {
        $this->carRepository = $carRepository ?? new CarRepository();
    }

    /**
     * Возвращает одну страницу доступной пользователю истории автомобиля.
     *
     * @param int $carId     Автомобиль, по которому запрашивается история.
     * @param int $contactId Контакт-владелец из контекста открытой CRM-карточки.
     * @param int $userId    Пользователь, чьи CRM-права применяются к сделкам и контакту.
     * @param int $page      Номер страницы, начиная с единицы.
     * @param int $pageSize  Запрошенный размер страницы в безопасных границах.
     */
    public function getPage(
        int $carId,
        int $contactId,
        int $userId,
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): Result {
        /** @var Result $result Результат с данными страницы либо локализованной ошибкой. */
        $result = new Result();

        if (!Loader::includeModule('crm')) {
            $result->addError(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_CRM_REQUIRED'))
            );

            return $result;
        }

        if (
            $contactId <= 0
            || $userId <= 0
            || !Container::getInstance()
                ->getUserPermissions($userId)
                ->item()
                ->canRead(\CCrmOwnerType::Contact, $contactId)
        ) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_ACCESS_DENIED'),
                    self::ERROR_ACCESS_DENIED
                )
            );

            return $result;
        }

        /** @var array<string, mixed>|null $car Автомобиль, проверяемый вместе с владельцем. */
        $car = $this->carRepository->findById($carId);
        if ($car === null || (int)$car['CONTACT_ID'] !== $contactId) {
            $result->addError(
                new Error(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_CAR_NOT_FOUND'),
                    self::ERROR_CAR_NOT_FOUND
                )
            );

            return $result;
        }

        /** @var int|null $categoryId Настроенное направление сервисного обслуживания. */
        $categoryId = ModuleConfiguration::getServiceDealCategoryId();
        if ($categoryId === null) {
            $result->addError(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_CATEGORY_REQUIRED'))
            );

            return $result;
        }

        /** @var \Bitrix\Crm\Service\Factory|null $dealFactory Фабрика штатных CRM-сделок. */
        $dealFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
        if ($dealFactory === null) {
            $result->addError(
                new Error((string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_FACTORY_REQUIRED'))
            );

            return $result;
        }

        /** @var int $normalizedPage Положительный номер фактически запрашиваемой страницы. */
        $normalizedPage = max(1, $page);

        /** @var int $normalizedPageSize Размер страницы после ограничения допустимого диапазона. */
        $normalizedPageSize = max(1, min(self::MAXIMUM_PAGE_SIZE, $pageSize));

        /** @var string $carFieldName Проверенный код пользовательского поля автомобиля. */
        $carFieldName = ModuleConfiguration::getDealCarFieldName();

        /** @var array<string, int> $dealFilter Ограничение сервисной воронкой, автомобилем и владельцем. */
        $dealFilter = [
            '=CATEGORY_ID' => $categoryId,
            '=' . $carFieldName => $carId,
            '=CONTACT_ID' => $contactId,
        ];

        /** @var int $totalCount Количество доступных пользователю сделок во всей истории. */
        $totalCount = $dealFactory->getItemsCountFilteredByPermissions(
            $dealFilter,
            $userId
        );

        /** @var int $maximumPage Последняя существующая страница либо единица для пустой истории. */
        $maximumPage = max(1, (int)ceil($totalCount / $normalizedPageSize));
        $normalizedPage = min($normalizedPage, $maximumPage);

        /** @var int $offset Безопасное смещение нормализованной страницы в общей выборке. */
        $offset = ($normalizedPage - 1) * $normalizedPageSize;

        /** @var Item[] $deals Одна страница сделок после применения CRM-прав в SQL. */
        $deals = $totalCount > 0
            ? $dealFactory->getItemsFilteredByPermissions(
                [
                    'select' => [
                        Item::FIELD_NAME_ID,
                        Item::FIELD_NAME_TITLE,
                        Item::FIELD_NAME_CREATED_TIME,
                        Item::FIELD_NAME_STAGE_ID,
                        Item::FIELD_NAME_ASSIGNED,
                        Item::FIELD_NAME_OPPORTUNITY,
                        Item::FIELD_NAME_CURRENCY_ID,
                    ],
                    'filter' => $dealFilter,
                    'order' => [
                        Item::FIELD_NAME_CREATED_TIME => 'DESC',
                        Item::FIELD_NAME_ID => 'DESC',
                    ],
                    'limit' => $normalizedPageSize,
                    'offset' => $offset,
                ],
                $userId
            )
            : [];

        /** @var int[] $dealIds Идентификаторы страницы для одного запроса товарных позиций. */
        $dealIds = [];

        /** @var int[] $assignedUserIds Идентификаторы ответственных для одного запроса пользователей. */
        $assignedUserIds = [];

        /** @var Item $deal Очередная уже доступная пользователю сделка. */
        foreach ($deals as $deal) {
            $dealIds[] = $deal->getId();

            /** @var int $assignedUserId Ответственный текущей сделки. */
            $assignedUserId = (int)$deal->get(Item::FIELD_NAME_ASSIGNED);
            if ($assignedUserId > 0) {
                $assignedUserIds[$assignedUserId] = $assignedUserId;
            }
        }

        /** @var array<int, array<int, array<string, mixed>>> $productRows Товарные позиции по ID сделки. */
        $productRows = $this->loadProductRows($dealIds);

        /** @var array<int, array{id: int, name: string, url: string}> $users Ответственные по ID. */
        $users = $this->loadUsers(array_values($assignedUserIds));

        /** @var array<string, string> $stageNames Названия стадий только сервисного направления. */
        $stageNames = \CCrmDeal::GetStageNames($categoryId);

        /** @var array<int, array<string, mixed>> $historyItems Готовые безопасные данные интерфейса. */
        $historyItems = [];

        /** @var Item $deal Очередная сделка для объединения с уже загруженными справочниками. */
        foreach ($deals as $deal) {
            /** @var int $dealId Идентификатор сделки и ключ товарных позиций. */
            $dealId = $deal->getId();

            /** @var string $currencyId Валюта суммы сделки и её товарных позиций. */
            $currencyId = (string)$deal->get(Item::FIELD_NAME_CURRENCY_ID);
            if ($currencyId === '') {
                $currencyId = (string)\CCrmCurrency::GetBaseCurrencyID();
            }

            /** @var int $assignedUserId Идентификатор ответственного либо ноль. */
            $assignedUserId = (int)$deal->get(Item::FIELD_NAME_ASSIGNED);

            /** @var array{id: int, name: string, url: string} $assignedUser Данные ответственного без лишних полей. */
            $assignedUser = $users[$assignedUserId] ?? [
                'id' => $assignedUserId,
                'name' => $assignedUserId > 0
                    ? (string)Loc::getMessage(
                        'OTUS_AUTOSERVICE_CAR_HISTORY_UNKNOWN_USER',
                        ['#ID#' => $assignedUserId]
                    )
                    : (string)Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_UNASSIGNED'),
                'url' => '',
            ];

            /** @var string $stageId Код текущей стадии сервисной сделки. */
            $stageId = (string)$deal->get(Item::FIELD_NAME_STAGE_ID);

            /** @var float $opportunity Сумма сделки в её исходной валюте. */
            $opportunity = (float)$deal->get(Item::FIELD_NAME_OPPORTUNITY);

            /** @var \Bitrix\Main\Web\Uri|null $dealUrl Обновляемый штатным CRM-роутером адрес карточки. */
            $dealUrl = Container::getInstance()
                ->getRouter()
                ->getItemDetailUrl(\CCrmOwnerType::Deal, $dealId, $categoryId);

            $historyItems[] = [
                'id' => $dealId,
                'title' => (string)$deal->get(Item::FIELD_NAME_TITLE),
                'dateCreated' => $this->formatDate($deal->get(Item::FIELD_NAME_CREATED_TIME)),
                'stageName' => (string)($stageNames[$stageId]
                    ?? Loc::getMessage('OTUS_AUTOSERVICE_CAR_HISTORY_UNKNOWN_STAGE')),
                'assignedBy' => $assignedUser,
                'amount' => $opportunity,
                'currencyId' => $currencyId,
                'amountFormatted' => $this->formatMoney($opportunity, $currencyId),
                'url' => $dealUrl?->getUri() ?? '',
                'products' => $this->formatProductRows(
                    $productRows[$dealId] ?? [],
                    $currencyId
                ),
            ];
        }

        /** @var string $contactName Отображаемое имя владельца автомобиля. */
        $contactName = $this->loadContactName($contactId);

        /** @var string $carName Марка, модель и государственный номер автомобиля. */
        $carName = $this->formatCarName($car);

        $result->setData(
            [
                'carId' => $carId,
                'contactId' => $contactId,
                'title' => (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_CAR_HISTORY_TITLE',
                    [
                        '#CAR#' => $carName,
                        '#CONTACT#' => $contactName,
                    ]
                ),
                'items' => $historyItems,
                'pagination' => [
                    'page' => $normalizedPage,
                    'pageSize' => $normalizedPageSize,
                    'total' => $totalCount,
                    'hasMore' => $offset + count($historyItems) < $totalCount,
                ],
            ]
        );

        return $result;
    }

    /**
     * Загружает товарные позиции всех сделок страницы одним ORM-запросом.
     *
     * @param int[] $dealIds Доступные пользователю сделки текущей страницы.
     *
     * @return array<int, array<int, array<string, mixed>>> Строки, сгруппированные по сделке.
     */
    private function loadProductRows(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        /** @var array<int, array<int, array<string, mixed>>> $rowsByDeal Накопленные позиции по владельцу. */
        $rowsByDeal = [];

        /** @var array<string, mixed> $row Очередная товарная позиция сделки. */
        foreach (
            ProductRowTable::getList(
                [
                    'select' => [
                        'ID',
                        'OWNER_ID',
                        'PRODUCT_ID',
                        'PRODUCT_NAME',
                        'PRICE',
                        'QUANTITY',
                        'MEASURE_NAME',
                        'SORT',
                    ],
                    'filter' => [
                        '=OWNER_TYPE' => \CCrmOwnerTypeAbbr::Deal,
                        '@OWNER_ID' => $dealIds,
                    ],
                    'order' => [
                        'OWNER_ID' => 'ASC',
                        'SORT' => 'ASC',
                        'ID' => 'ASC',
                    ],
                ]
            )->fetchAll() as $row
        ) {
            /** @var int $ownerId Сделка-владелец текущей товарной позиции. */
            $ownerId = (int)$row['OWNER_ID'];
            $rowsByDeal[$ownerId][] = $row;
        }

        return $rowsByDeal;
    }

    /**
     * Загружает ответственных всех сделок страницы одним ORM-запросом.
     *
     * @param int[] $userIds Уникальные положительные идентификаторы пользователей.
     *
     * @return array<int, array{id: int, name: string, url: string}> Пользователи по ID.
     */
    private function loadUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var array<int, array{id: int, name: string, url: string}> $users Пользователи по первичному ключу. */
        $users = [];

        /** @var array<string, mixed> $user Очередной пользователь из D7 ORM. */
        foreach (
            UserTable::getList(
                [
                    'select' => ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'LOGIN'],
                    'filter' => ['@ID' => $userIds],
                ]
            )->fetchAll() as $user
        ) {
            /** @var int $userId Идентификатор ответственного. */
            $userId = (int)$user['ID'];
            $users[$userId] = [
                'id' => $userId,
                'name' => $this->formatPersonName($user, (string)$user['LOGIN']),
                'url' => '/company/personal/user/' . $userId . '/',
            ];
        }

        return $users;
    }

    /**
     * Форматирует товарные позиции одной сделки после пакетной загрузки.
     *
     * @param array<int, array<string, mixed>> $rows       Исходные строки CRM.
     * @param string                           $currencyId Валюта сделки.
     *
     * @return array<int, array<string, mixed>> Данные без внутренних технических полей.
     */
    private function formatProductRows(array $rows, string $currencyId): array
    {
        /** @var array<int, array<string, mixed>> $products Готовые позиции интерфейса. */
        $products = [];

        /** @var array<string, mixed> $row Очередная строка из b_crm_product_row. */
        foreach ($rows as $row) {
            /** @var int $productId Идентификатор каталожного товара либо ноль для пользовательской строки. */
            $productId = (int)$row['PRODUCT_ID'];

            /** @var string $productName Снимок названия товара в момент сохранения сделки. */
            $productName = trim((string)$row['PRODUCT_NAME']);
            if ($productName === '') {
                $productName = (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_CAR_HISTORY_UNKNOWN_PRODUCT',
                    ['#ID#' => $productId]
                );
            }

            /** @var float $quantity Количество товара в заказ-наряде. */
            $quantity = (float)$row['QUANTITY'];

            /** @var float $price Цена одной единицы в валюте сделки. */
            $price = (float)$row['PRICE'];

            $products[] = [
                'id' => $productId,
                'name' => $productName,
                'quantity' => $quantity,
                'quantityFormatted' => $this->formatQuantity($quantity),
                'measureName' => trim((string)$row['MEASURE_NAME']),
                'price' => $price,
                'priceFormatted' => $this->formatMoney($price, $currencyId),
                'sumFormatted' => $this->formatMoney($price * $quantity, $currencyId),
            ];
        }

        return $products;
    }

    /**
     * Возвращает имя контакта без телефонов, электронной почты и других персональных полей.
     */
    private function loadContactName(int $contactId): string
    {
        /** @var array<string, mixed>|null $contact Минимальные поля владельца автомобиля. */
        $contact = ContactTable::getByPrimary(
            $contactId,
            ['select' => ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME']]
        )->fetch() ?: null;

        if ($contact === null) {
            return (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CAR_HISTORY_UNKNOWN_CONTACT',
                ['#ID#' => $contactId]
            );
        }

        return $this->formatPersonName(
            $contact,
            (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_CAR_HISTORY_UNKNOWN_CONTACT',
                ['#ID#' => $contactId]
            )
        );
    }

    /**
     * Формирует наименование автомобиля из разрешённых неперсональных полей.
     *
     * @param array<string, mixed> $car Запись собственной ORM-таблицы модуля.
     */
    private function formatCarName(array $car): string
    {
        /** @var string $modelName Марка и модель без лишних разделителей. */
        $modelName = trim((string)$car['MAKE'] . ' ' . (string)$car['MODEL']);

        /** @var string $licensePlate Нормализованный государственный номер. */
        $licensePlate = trim((string)$car['LICENSE_PLATE']);

        return $licensePlate === ''
            ? $modelName
            : $modelName . ' — ' . $licensePlate;
    }

    /**
     * Собирает отображаемое ФИО из минимального набора полей D7 ORM.
     *
     * @param array<string, mixed> $fields   Поля пользователя или контакта.
     * @param string               $fallback Значение при отсутствии всех частей имени.
     */
    private function formatPersonName(array $fields, string $fallback): string
    {
        /** @var string[] $parts Непустые части имени в привычном русском порядке. */
        $parts = array_filter(
            [
                trim((string)($fields['LAST_NAME'] ?? '')),
                trim((string)($fields['NAME'] ?? '')),
                trim((string)($fields['SECOND_NAME'] ?? '')),
            ],
            static fn(string $part): bool => $part !== ''
        );

        /** @var string $name Итоговое имя без повторных пробелов. */
        $name = implode(' ', $parts);

        return $name !== '' ? $name : $fallback;
    }

    /**
     * Форматирует дату CRM без передачи часового объекта в JSON-контроллер.
     *
     * @param mixed $value Значение поля CREATED_TIME CRM-элемента.
     */
    private function formatDate(mixed $value): string
    {
        if ($value instanceof DateTime || $value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }

        return '';
    }

    /**
     * Форматирует денежное значение штатным CRM-форматтером и удаляет HTML.
     */
    private function formatMoney(float $amount, string $currencyId): string
    {
        /** @var string $formattedMoney Текст CRM, который может содержать безопасные HTML-сущности пробела и валюты. */
        $formattedMoney = (string)\CCrmCurrency::MoneyToString($amount, $currencyId);

        return html_entity_decode($formattedMoney, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Удаляет незначащие нули из количества, сохраняя до четырёх знаков после запятой.
     */
    private function formatQuantity(float $quantity): string
    {
        $formattedQuantity = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');

        return $formattedQuantity !== '' ? $formattedQuantity : '0';
    }
}
