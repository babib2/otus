<?php

/**
 * Создаёт и проверяет отдельную CRM-воронку сервисного обслуживания и её стадии.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Crm;

use Bitrix\Crm\Category\CategoryPullManager;
use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Category\Entity\DealCategoryTable;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Otus\Autoservice\Service\ModuleConfiguration;
use RuntimeException;
use Throwable;

Loc::loadMessages(__FILE__);

/**
 * Управляет воспроизводимой конфигурацией сервисного направления CRM.
 *
 * Направление создаётся через D7 DealCategoryTable, а не DealCategory::add().
 * Это намеренное решение: высокоуровневый метод автоматически изменяет все
 * существующие CRM-роли, тогда как права проекта должны настраиваться вручную.
 */
final class ServiceDealPipelineManager
{
    /** Идентификатор источника для однозначного поиска созданной воронки. */
    private const ORIGIN_ID = 'SERVICE_DEAL_PIPELINE';

    /** Идентификатор интеграции, записываемый в метаданные направления CRM. */
    private const ORIGINATOR_ID = 'otus.autoservice';

    /** Позиция направления в списке CRM-воронок. */
    private const CATEGORY_SORT = 500;

    /** Имя глобальной блокировки, сериализующей создание и удаление воронки. */
    private const PIPELINE_LOCK_NAME = 'otus.autoservice.service_deal_pipeline';

    /** Максимальное время ожидания глобальной блокировки в секундах. */
    private const PIPELINE_LOCK_TIMEOUT = 30;

    /**
     * Описание стадий поверх стандартных кодов, создаваемых Bitrix для направления.
     *
     * LOSE и APOLOGY сохраняются как две штатные неуспешные финальные стадии.
     * Это позволяет закрывать заказ без ремонта и отменять его клиентом, не
     * смешивая такие случаи с успешной стадией «Завершено».
     *
     * @var array<string, array{option: string, message: string, sort: int, system: string, color: string, semantics: string}>
     */
    private const STAGES = [
        'NEW' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_RECEPTION,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_RECEPTION',
            'sort' => 10,
            'system' => 'Y',
            'color' => '#39A8EF',
            'semantics' => PhaseSemantics::PROCESS,
        ],
        'PREPARATION' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_DIAGNOSTICS,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_DIAGNOSTICS',
            'sort' => 20,
            'system' => 'N',
            'color' => '#2FC6F6',
            'semantics' => PhaseSemantics::PROCESS,
        ],
        'PREPAYMENT_INVOICE' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_WAITING_PARTS,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_WAITING_PARTS',
            'sort' => 30,
            'system' => 'N',
            'color' => '#FFA900',
            'semantics' => PhaseSemantics::PROCESS,
        ],
        'EXECUTING' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_REPAIR,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_REPAIR',
            'sort' => 40,
            'system' => 'N',
            'color' => '#2FC6F6',
            'semantics' => PhaseSemantics::PROCESS,
        ],
        'FINAL_INVOICE' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_QUALITY_CHECK,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_QUALITY_CHECK',
            'sort' => 50,
            'system' => 'N',
            'color' => '#7BD500',
            'semantics' => PhaseSemantics::PROCESS,
        ],
        'WON' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_COMPLETED,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_COMPLETED',
            'sort' => 60,
            'system' => 'Y',
            'color' => '#7BD500',
            'semantics' => PhaseSemantics::SUCCESS,
        ],
        'LOSE' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_FAILED,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_FAILED',
            'sort' => 70,
            'system' => 'Y',
            'color' => '#FF5752',
            'semantics' => PhaseSemantics::FAILURE,
        ],
        'APOLOGY' => [
            'option' => ModuleConfiguration::OPTION_SERVICE_STAGE_CANCELLED,
            'message' => 'OTUS_AUTOSERVICE_PIPELINE_STAGE_CANCELLED',
            'sort' => 80,
            'system' => 'N',
            'color' => '#FF5752',
            'semantics' => PhaseSemantics::FAILURE,
        ],
    ];

    /**
     * Возвращает существующее управляемое направление или создаёт новое.
     *
     * Повторный вызов находит запись по ORIGINATOR_ID и ORIGIN_ID, обновляет
     * требуемые стадии и повторно сохраняет их идентификаторы в настройках.
     */
    public function ensureExists(): int
    {
        return $this->ensureExistsWithSelection(true);
    }

    /**
     * Восстанавливает управляемое направление, не заменяя другую корректную активную воронку.
     *
     * Если активная настройка отсутствует либо указывает на удалённое направление,
     * восстановленная миграционная воронка становится активной автоматически.
     */
    public function repair(): int
    {
        return $this->ensureExistsWithSelection(false);
    }

    /**
     * Создаёт или восстанавливает направление с выбранной политикой активной настройки.
     *
     * @param bool $selectAsActive Нужно ли безусловно выбрать управляемую воронку как рабочую.
     */
    private function ensureExistsWithSelection(bool $selectAsActive): int
    {
        if (!Loader::includeModule('crm')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_CRM_REQUIRED')
            );
        }

        /** @var \Bitrix\Main\DB\Connection $connection Соединение для глобальной блокировки миграции. */
        $connection = Application::getConnection();
        if (!$connection->lock(self::PIPELINE_LOCK_NAME, self::PIPELINE_LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_OPERATION_LOCK_TIMEOUT')
            );
        }

        try {
            return $this->ensureExistsUnderLock($selectAsActive);
        } finally {
            $connection->unlock(self::PIPELINE_LOCK_NAME);
        }
    }

    /**
     * Находит или создаёт направление внутри уже полученной глобальной блокировки.
     */
    private function ensureExistsUnderLock(bool $selectAsActive): int
    {
        /** @var array<string, mixed>|null $category Управляемое направление из CRM. */
        $category = $this->findManagedCategory();
        if ($category !== null) {
            /** @var int $categoryId ID ранее созданного направления. */
            $categoryId = (int)$category['ID'];
            $this->configureStages($categoryId);
            $this->saveCategoryOptions($categoryId, $selectAsActive);

            return $categoryId;
        }

        if ($this->hasNameConflict()) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_NAME_CONFLICT')
            );
        }

        /** @var \Bitrix\Main\ORM\Data\AddResult $addResult Результат создания направления D7. */
        $addResult = DealCategoryTable::add(
            [
                'NAME' => (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_NAME'),
                'SORT' => self::CATEGORY_SORT,
                'ORIGIN_ID' => self::ORIGIN_ID,
                'ORIGINATOR_ID' => self::ORIGINATOR_ID,
            ]
        );

        if (!$addResult->isSuccess()) {
            throw new RuntimeException(implode('; ', $addResult->getErrorMessages()));
        }

        /** @var int $categoryId ID созданного сервисного направления. */
        $categoryId = (int)$addResult->getId();

        try {
            $this->refreshCategoryCaches($categoryId);
            DealCategory::createDefaultStages($categoryId);
            $this->configureStages($categoryId);
            $this->saveCategoryOptions($categoryId, $selectAsActive);
        } catch (Throwable $exception) {
            $this->deleteCategoryIfEmpty($categoryId);
            $this->clearOptions($categoryId);
            throw $exception;
        }

        return $categoryId;
    }

    /**
     * Сбрасывает списки направлений в памяти и уведомляет открытые интерфейсы CRM.
     */
    private function refreshCategoryCaches(int $categoryId): void
    {
        // Штатный update сбрасывает закрытый статический кеш legacy-класса без изменения CRM-ролей.
        DealCategory::update(
            $categoryId,
            [
                'NAME' => (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_NAME'),
                'SORT' => self::CATEGORY_SORT,
            ]
        );

        /** @var \Bitrix\Crm\Service\Factory|null $factory Фабрика сделок с собственным кешем направлений. */
        $factory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
        if ($factory !== null) {
            $factory->clearCategoriesCache();
        }

        CategoryPullManager::getInstance()->sendEventCategoriesUpdated(\CCrmOwnerType::Deal);
    }

    /**
     * Возвращает ID направления, однозначно помеченного как созданное модулем.
     *
     * Выбранное администратором рабочее направление может отличаться от него,
     * поэтому метод ищет объект по метаданным источника, а не по пользовательской
     * настройке OPTION_SERVICE_DEAL_CATEGORY_ID.
     */
    public function getManagedCategoryId(): ?int
    {
        if (!Loader::includeModule('crm')) {
            return null;
        }

        /** @var array<string, mixed>|null $category Направление миграции из CRM. */
        $category = $this->findManagedCategory();
        if ($category === null) {
            return null;
        }

        /** @var int $categoryId Положительный ID физической CRM-воронки. */
        $categoryId = (int)$category['ID'];

        return $categoryId > 0 ? $categoryId : null;
    }

    /**
     * Проверяет существование управляемого направления и всех стадий миграции.
     */
    public function isReady(): bool
    {
        /** @var int|null $categoryId Направление, найденное по метаданным миграции. */
        $categoryId = $this->getManagedCategoryId();
        if ($categoryId === null || !DealCategory::exists($categoryId)) {
            return false;
        }

        /** @var string $entityId Идентификатор справочника стадий этого направления. */
        $entityId = DealCategory::getStatusEntityID($categoryId);

        /** @var array<string, array<string, mixed>> $statuses Текущие стадии CRM по STATUS_ID. */
        $statuses = \CCrmStatus::GetStatus($entityId);

        /** @var string $suffix Стабильная часть кода очередной стадии. */
        /** @var array<string, mixed> $stageDefinition Ожидаемое описание стадии. */
        foreach (self::STAGES as $suffix => $stageDefinition) {
            /** @var string $stageId Полный код стадии с пространством направления. */
            $stageId = $this->buildStageId($categoryId, $suffix);
            if (
                !isset($statuses[$stageId])
                || Option::get(
                    ModuleConfiguration::MODULE_ID,
                    (string)$stageDefinition['option'],
                    ''
                ) !== $stageId
            ) {
                return false;
            }

            /** @var array<string, mixed> $status Фактические поля проверяемой стадии. */
            $status = $statuses[$stageId];
            if (
                (string)$status['NAME']
                    !== (string)Loc::getMessage((string)$stageDefinition['message'])
                || (string)$status['SEMANTICS'] !== (string)$stageDefinition['semantics']
                || (int)$status['SORT'] !== (int)$stageDefinition['sort']
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Удаляет созданную модулем пустую воронку при полном откате данных.
     *
     * Направление с существующими сделками сохраняется даже при удалении модуля,
     * чтобы деинсталляция не уничтожила пользовательскую историю CRM.
     */
    public function removeIfOwned(): void
    {
        if (!Loader::includeModule('crm')) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_CRM_REQUIRED')
            );
        }

        /** @var \Bitrix\Main\DB\Connection $connection Соединение для блокировки отката миграции. */
        $connection = Application::getConnection();
        if (!$connection->lock(self::PIPELINE_LOCK_NAME, self::PIPELINE_LOCK_TIMEOUT)) {
            throw new RuntimeException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_OPERATION_LOCK_TIMEOUT')
            );
        }

        try {
            $this->removeIfOwnedUnderLock();
        } finally {
            $connection->unlock(self::PIPELINE_LOCK_NAME);
        }
    }

    /**
     * Удаляет пустое направление и его технические настройки внутри блокировки.
     */
    private function removeIfOwnedUnderLock(): void
    {
        /** @var int $storedCategoryId Последний сохранённый ID созданного направления. */
        $storedCategoryId = (int)Option::get(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_CREATED_ID,
            '0'
        );

        /** @var array<string, mixed>|null $managedCategory Авторитетный поиск по метке источника. */
        $managedCategory = $this->findManagedCategory();

        /** @var int $categoryId Фактический ID либо сохранённый ID уже удалённой записи. */
        $categoryId = $managedCategory !== null
            ? (int)$managedCategory['ID']
            : $storedCategoryId;

        if ($categoryId > 0) {
            $this->deleteCategoryIfEmpty($categoryId);
        }

        $this->clearOptions($categoryId > 0 ? $categoryId : null);
    }

    /**
     * Возвращает направление, ранее созданное этой интеграцией.
     *
     * @return array<string, mixed>|null Метаданные направления либо null.
     */
    private function findManagedCategory(): ?array
    {
        /** @var array<string, mixed>|false $category Результат точного поиска по источнику. */
        $category = DealCategoryTable::getList(
            [
                'select' => ['ID', 'NAME', 'ORIGIN_ID', 'ORIGINATOR_ID'],
                'filter' => [
                    '=ORIGIN_ID' => self::ORIGIN_ID,
                    '=ORIGINATOR_ID' => self::ORIGINATOR_ID,
                ],
                'order' => ['ID' => 'ASC'],
                'limit' => 1,
            ]
        )->fetch();

        return $category === false ? null : $category;
    }

    /**
     * Не позволяет создать вторую одноимённую воронку неизвестного владельца.
     */
    private function hasNameConflict(): bool
    {
        return DealCategoryTable::getList(
            [
                'select' => ['ID'],
                'filter' => [
                    '=NAME' => (string)Loc::getMessage('OTUS_AUTOSERVICE_PIPELINE_NAME'),
                ],
                'limit' => 1,
            ]
        )->fetch() !== false;
    }

    /**
     * Переименовывает и упорядочивает штатные стадии нового направления.
     */
    private function configureStages(int $categoryId): void
    {
        /** @var string $entityId Справочник стадий конкретного CRM-направления. */
        $entityId = DealCategory::getStatusEntityID($categoryId);

        /** @var \CCrmStatus $statusManager Штатный менеджер стадий CRM. */
        $statusManager = new \CCrmStatus($entityId);

        /** @var array<string, array<string, mixed>> $existingStatuses Стадии до настройки. */
        $existingStatuses = \CCrmStatus::GetStatus($entityId);

        /** @var string $suffix Стабильная часть кода стадии Bitrix. */
        /** @var array<string, mixed> $stageDefinition Локализуемое описание стадии. */
        foreach (self::STAGES as $suffix => $stageDefinition) {
            /** @var string $stageId Полный STATUS_ID с пространством направления. */
            $stageId = $this->buildStageId($categoryId, $suffix);

            /** @var array<string, mixed> $fields Поля создаваемой или обновляемой стадии. */
            $fields = [
                'NAME' => (string)Loc::getMessage((string)$stageDefinition['message']),
                'SORT' => (int)$stageDefinition['sort'],
                'SYSTEM' => (string)$stageDefinition['system'],
                'COLOR' => (string)$stageDefinition['color'],
                'SEMANTICS' => (string)$stageDefinition['semantics'],
            ];

            if (isset($existingStatuses[$stageId])) {
                $statusManager->Update((int)$existingStatuses[$stageId]['ID'], $fields);
            } else {
                $statusManager->Add(
                    array_merge($fields, ['STATUS_ID' => $stageId])
                );
            }

            if ($statusManager->GetLastError() !== '') {
                throw new RuntimeException((string)$statusManager->GetLastError());
            }

            Option::set(
                ModuleConfiguration::MODULE_ID,
                (string)$stageDefinition['option'],
                $stageId
            );
        }
    }

    /**
     * Сохраняет технический ID управляемого направления и при необходимости выбирает его активным.
     *
     * @param int  $categoryId    ID созданной или восстановленной CRM-воронки.
     * @param bool $selectAsActive Нужно ли заменить текущую активную настройку безусловно.
     */
    private function saveCategoryOptions(int $categoryId, bool $selectAsActive): void
    {
        if ($selectAsActive || !$this->configuredServiceCategoryExists()) {
            Option::set(
                ModuleConfiguration::MODULE_ID,
                ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID,
                (string)$categoryId
            );
        }

        Option::set(
            ModuleConfiguration::MODULE_ID,
            ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_CREATED_ID,
            (string)$categoryId
        );
    }

    /**
     * Проверяет, что активная настройка указывает на существующее направление CRM.
     *
     * Основная воронка Bitrix имеет специальный ID 0 и не хранится в таблице
     * дополнительных направлений, поэтому для неё не вызывается DealCategory::exists().
     */
    private function configuredServiceCategoryExists(): bool
    {
        /** @var int|null $configuredCategoryId Активное направление из настроек модуля. */
        $configuredCategoryId = ModuleConfiguration::getServiceDealCategoryId();

        return $configuredCategoryId !== null
            && ($configuredCategoryId === 0 || DealCategory::exists($configuredCategoryId));
    }

    /**
     * Удаляет только управляемую воронку без сделок.
     */
    private function deleteCategoryIfEmpty(int $categoryId): void
    {
        /** @var array<string, mixed>|false $category Проверяемые метаданные владельца направления. */
        $category = DealCategoryTable::getList(
            [
                'select' => ['ID', 'ORIGIN_ID', 'ORIGINATOR_ID'],
                'filter' => ['=ID' => $categoryId],
                'limit' => 1,
            ]
        )->fetch();

        if (
            $category === false
            || (string)$category['ORIGIN_ID'] !== self::ORIGIN_ID
            || (string)$category['ORIGINATOR_ID'] !== self::ORIGINATOR_ID
            || DealCategory::hasDependencies($categoryId)
        ) {
            return;
        }

        DealCategory::delete($categoryId);
    }

    /**
     * Удаляет технические настройки и не затрагивает выбранную вручную воронку.
     *
     * @param int|null $categoryId ID созданного направления либо null, если запись уже отсутствует.
     */
    private function clearOptions(?int $categoryId): void
    {
        if (
            $categoryId !== null
            && ModuleConfiguration::getServiceDealCategoryId() === $categoryId
        ) {
            Option::delete(
                ModuleConfiguration::MODULE_ID,
                ['name' => ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_ID]
            );
        }

        Option::delete(
            ModuleConfiguration::MODULE_ID,
            ['name' => ModuleConfiguration::OPTION_SERVICE_DEAL_CATEGORY_CREATED_ID]
        );

        /** @var array<string, mixed> $stageDefinition Очередная настройка стадии для удаления. */
        foreach (self::STAGES as $stageDefinition) {
            Option::delete(
                ModuleConfiguration::MODULE_ID,
                ['name' => (string)$stageDefinition['option']]
            );
        }
    }

    /**
     * Формирует полный CRM-код стадии, например `C3:NEW`.
     */
    private function buildStageId(int $categoryId, string $suffix): string
    {
        /** @var string $namespace Пространство `C<ID>`; для основной воронки оно пустое. */
        $namespace = DealCategory::prepareStageNamespaceID($categoryId);

        return $namespace === '' ? $suffix : $namespace . ':' . $suffix;
    }
}
