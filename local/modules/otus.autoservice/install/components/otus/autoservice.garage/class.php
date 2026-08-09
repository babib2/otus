<?php

/**
 * Подготавливает данные стандартного GRID автомобилей для вкладки «Гараж» CRM-контакта.
 */

declare(strict_types=1);

use Bitrix\Main\Grid\Options as GridOptions;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\Main\UI\PageNavigation;
use Otus\Autoservice\Service\CarPullService;
use Otus\Autoservice\Service\CarService;
use Otus\Autoservice\Service\ModuleConfiguration;

Loc::loadMessages(__FILE__);

/**
 * Компонент списка автомобилей одного доступного пользователю CRM-контакта.
 */
final class OtusAutoserviceGarageComponent extends CBitrixComponent
{
    /** Количество автомобилей на странице GRID по умолчанию. */
    private const DEFAULT_PAGE_SIZE = 20;

    /** Максимальный размер страницы, защищающий ORM от чрезмерной выборки. */
    private const MAXIMUM_PAGE_SIZE = 100;

    /** @var CarService Сервис кешируемых выборок автомобилей. */
    private $carService;

    /**
     * Нормализует ID контакта до выполнения компонента.
     *
     * @param array<string, mixed> $params Исходные параметры IncludeComponent.
     *
     * @return array<string, mixed> Безопасные параметры компонента.
     */
    public function onPrepareComponentParams($params): array
    {
        $params['CONTACT_ID'] = max(0, (int)($params['CONTACT_ID'] ?? 0));
        $params['GRID_SERVICE_URL'] = isset($params['GRID_SERVICE_URL'])
            && is_string($params['GRID_SERVICE_URL'])
            ? trim($params['GRID_SERVICE_URL'])
            : '';

        return $params;
    }

    /**
     * Проверяет доступ, загружает одну страницу автомобилей и показывает шаблон.
     */
    public function executeComponent(): void
    {
        /** @var int $contactId Контакт-владелец автомобилей текущей вкладки. */
        $contactId = (int)$this->arParams['CONTACT_ID'];

        if (
            !ModuleConfiguration::isEnabled()
            || !Loader::includeModule('crm')
            || $contactId <= 0
            || !\CCrmContact::CheckReadPermission($contactId)
        ) {
            $this->arResult = [
                'ERROR' => (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACCESS_DENIED'),
            ];
            $this->includeComponentTemplate();

            return;
        }

        $this->carService = new CarService();

        /** @var string $gridId Уникальный для контакта ID GRID и его пользовательских настроек. */
        $gridId = 'OTUS_AUTOSERVICE_GARAGE_' . $contactId;

        /** @var string $containerId Уникальный DOM-ID и ключ клиентского экземпляра компонента. */
        $containerId = 'otus-autoservice-garage-' . $contactId;

        /** @var array<int, array<string, mixed>> $filterFields Описание полей main.ui.filter. */
        $filterFields = $this->getFilterFields();

        /** @var FilterOptions $filterOptions Пользовательское состояние стандартного фильтра. */
        $filterOptions = new FilterOptions($gridId);

        /** @var array<string, mixed> $filter Текущие значения фильтра из безопасного Bitrix API. */
        $filter = $filterOptions->getFilter($filterFields);

        /** @var GridOptions $gridOptions Пользовательские настройки сортировки и размера страницы. */
        $gridOptions = new GridOptions($gridId);

        /** @var array{sort: array<string, string>, vars: array<string, string>} $sorting Сортировка GRID. */
        $sorting = $gridOptions->getSorting(
            [
                'sort' => ['ID' => 'DESC'],
                'vars' => ['by' => 'by', 'order' => 'order'],
            ]
        );

        /** @var array<string, mixed> $navigationParameters Параметры страницы из настроек GRID. */
        $navigationParameters = $gridOptions->GetNavParams(
            ['nPageSize' => self::DEFAULT_PAGE_SIZE]
        );

        /** @var int $pageSize Ограниченный размер одной страницы результата. */
        $pageSize = max(
            1,
            min(self::MAXIMUM_PAGE_SIZE, (int)($navigationParameters['nPageSize'] ?? self::DEFAULT_PAGE_SIZE))
        );

        /** @var PageNavigation $navigation Объект стандартной постраничной навигации Bitrix. */
        $navigation = new PageNavigation($gridId);
        $navigation->allowAllRecords(false);
        $navigation->setPageSize($pageSize);
        $navigation->initFromUri();

        /** @var array{items: array<int, array<string, mixed>>, total: int} $page Кешируемая страница ORM. */
        $page = $this->carService->getPageByContact(
            $contactId,
            $filter,
            $sorting['sort'],
            $navigation->getLimit(),
            $navigation->getOffset()
        );
        $navigation->setRecordCount($page['total']);

        /** @var bool $canEdit Может ли пользователь создавать и изменять автомобили контакта. */
        $canEdit = \CCrmContact::CheckUpdatePermission($contactId);

        $this->arResult = [
            'CONTACT_ID' => $contactId,
            'GRID_ID' => $gridId,
            'CONTAINER_ID' => $containerId,
            'CAN_EDIT' => $canEdit,
            'COLUMNS' => $this->getGridColumns(),
            'FILTER_FIELDS' => $filterFields,
            'ROWS' => $this->prepareRows($page['items'], $containerId, $canEdit),
            'NAVIGATION' => $navigation,
            'TOTAL_COUNT' => $page['total'],
            'PULL_WATCH_TAG' => CarPullService::getWatchTag($contactId),
            'PULL_COMMAND' => CarPullService::COMMAND_GARAGE_CHANGED,
            'GRID_SERVICE_URL' => (string)$this->arParams['GRID_SERVICE_URL'],
        ];

        $this->includeComponentTemplate();
    }

    /**
     * Описывает видимые и сортируемые колонки стандартного GRID.
     *
     * @return array<int, array<string, mixed>> Конфигурация main.ui.grid.
     */
    private function getGridColumns(): array
    {
        return [
            ['id' => 'MAKE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MAKE'), 'sort' => 'MAKE', 'default' => true],
            ['id' => 'MODEL', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MODEL'), 'sort' => 'MODEL', 'default' => true],
            ['id' => 'LICENSE_PLATE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_LICENSE_PLATE'), 'sort' => 'LICENSE_PLATE', 'default' => true],
            ['id' => 'YEAR', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_YEAR'), 'sort' => 'YEAR', 'default' => true],
            ['id' => 'COLOR', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_COLOR'), 'sort' => 'COLOR', 'default' => true],
            ['id' => 'MILEAGE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MILEAGE'), 'sort' => 'MILEAGE', 'default' => true],
            ['id' => 'ACTIVE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACTIVE'), 'sort' => 'ACTIVE', 'default' => true],
        ];
    }

    /**
     * Описывает фильтр по полям автомобиля и состоянию архива.
     *
     * @return array<int, array<string, mixed>> Конфигурация main.ui.filter.
     */
    private function getFilterFields(): array
    {
        return [
            ['id' => 'MAKE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MAKE'), 'type' => 'string', 'default' => true],
            ['id' => 'MODEL', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MODEL'), 'type' => 'string', 'default' => true],
            ['id' => 'LICENSE_PLATE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_LICENSE_PLATE'), 'type' => 'string', 'default' => true],
            ['id' => 'YEAR', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_YEAR'), 'type' => 'number'],
            ['id' => 'COLOR', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_COLOR'), 'type' => 'string'],
            ['id' => 'MILEAGE', 'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_MILEAGE'), 'type' => 'number'],
            [
                'id' => 'ACTIVE',
                'name' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACTIVE'),
                'type' => 'list',
                'items' => [
                    'Y' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_STATUS_ACTIVE'),
                    'N' => Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_STATUS_ARCHIVED'),
                ],
            ],
        ];
    }

    /**
     * Формирует строки GRID и разрешённые действия без дополнительных ORM-запросов.
     *
     * @param array<int, array<string, mixed>> $cars       Автомобили одной страницы.
     * @param string                           $containerId Ключ клиентского экземпляра вкладки.
     * @param bool                             $canEdit     Разрешены ли изменяющие действия.
     *
     * @return array<int, array<string, mixed>> Строки main.ui.grid.
     */
    private function prepareRows(array $cars, string $containerId, bool $canEdit): array
    {
        /** @var array<int, array<string, mixed>> $rows Подготовленные строки GRID. */
        $rows = [];

        /** @var array<string, mixed> $car Очередной автомобиль текущего контакта. */
        foreach ($cars as $car) {
            /** @var int $carId Идентификатор строки и аргумент клиентских действий. */
            $carId = (int)$car['ID'];

            /** @var bool $isActive Признак доступности действия архивирования. */
            $isActive = (string)$car['ACTIVE'] === 'Y';

            /** @var array<int, array<string, mixed>> $actions Разрешённые контекстные действия строки. */
            $actions = [
                [
                    'text' => (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACTION_HISTORY'),
                    'onclick' => sprintf(
                        "BX.Otus.Autoservice.Garage.get('%s').history(%d);",
                        \CUtil::JSEscape($containerId),
                        $carId
                    ),
                ],
            ];
            if ($canEdit) {
                $actions[] = [
                    'text' => (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACTION_EDIT'),
                    'onclick' => sprintf(
                        "BX.Otus.Autoservice.Garage.get('%s').edit(%d);",
                        \CUtil::JSEscape($containerId),
                        $carId
                    ),
                ];

                if ($isActive) {
                    $actions[] = [
                        'text' => (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_ACTION_ARCHIVE'),
                        'onclick' => sprintf(
                            "BX.Otus.Autoservice.Garage.get('%s').archive(%d);",
                            \CUtil::JSEscape($containerId),
                            $carId
                        ),
                    ];
                }
            }

            $rows[] = [
                'id' => $carId,
                'data' => $car,
                'columns' => [
                    'MAKE' => htmlspecialcharsbx((string)$car['MAKE']),
                    'MODEL' => htmlspecialcharsbx((string)$car['MODEL']),
                    'LICENSE_PLATE' => htmlspecialcharsbx((string)$car['LICENSE_PLATE']),
                    'YEAR' => $car['YEAR'] === null ? '&mdash;' : (int)$car['YEAR'],
                    'COLOR' => $car['COLOR'] === null || (string)$car['COLOR'] === ''
                        ? '&mdash;'
                        : htmlspecialcharsbx((string)$car['COLOR']),
                    'MILEAGE' => number_format((int)$car['MILEAGE'], 0, '.', ' '),
                    'ACTIVE' => $isActive
                        ? (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_STATUS_ACTIVE')
                        : (string)Loc::getMessage('OTUS_AUTOSERVICE_GARAGE_STATUS_ARCHIVED'),
                ],
                'actions' => $actions,
            ];
        }

        return $rows;
    }
}
