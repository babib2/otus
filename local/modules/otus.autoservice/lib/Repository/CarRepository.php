<?php

/**
 * Инкапсулирует запросы к таблице автомобилей и скрывает детали D7 ORM от сервисов.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Repository;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;
use Otus\Autoservice\Model\CarTable;

/**
 * Репозиторий автомобилей клиента.
 *
 * Репозиторий не нормализует и не проверяет бизнес-значения. Он принимает уже
 * подготовленные CarService данные, добавляет технические даты и вызывает ORM.
 */
final class CarRepository
{
    /**
     * Создаёт автомобиль и проставляет даты создания и изменения.
     *
     * @param array<string, mixed> $fields Подготовленные поля новой записи.
     */
    public function add(array $fields): AddResult
    {
        /** @var DateTime $now Единое время создания и первоначального изменения записи. */
        $now = new DateTime();
        $fields['DATE_CREATE'] = $now;
        $fields['DATE_UPDATE'] = $now;

        return CarTable::add($fields);
    }

    /**
     * Изменяет автомобиль и всегда обновляет техническую дату изменения.
     *
     * @param int                  $id     Идентификатор изменяемого автомобиля.
     * @param array<string, mixed> $fields Подготовленные изменяемые поля.
     */
    public function update(int $id, array $fields): UpdateResult
    {
        $fields['DATE_UPDATE'] = new DateTime();

        return CarTable::update($id, $fields);
    }

    /**
     * Выполняет мягкое удаление автомобиля, сохраняя его историю.
     *
     * @param int $id        Идентификатор автомобиля.
     * @param int $updatedBy Идентификатор пользователя, выполнившего деактивацию.
     */
    public function deactivate(int $id, int $updatedBy): UpdateResult
    {
        return $this->update(
            $id,
            [
                'ACTIVE' => 'N',
                'UPDATED_BY' => $updatedBy,
            ]
        );
    }

    /**
     * Возвращает автомобиль по первичному ключу.
     *
     * @return array<string, mixed>|null Запись или null, если автомобиль не найден.
     */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|false $car Результат выборки ORM. */
        $car = CarTable::getByPrimary($id)->fetch();

        return $car === false ? null : $car;
    }

    /**
     * Возвращает запрошенные автомобили одного контакта независимо от их активности.
     *
     * Метод предназначен для безопасного восстановления исторических ссылок из сделок:
     * ограничение по контакту не позволяет получить автомобиль другого клиента, а
     * отсутствие фильтра `ACTIVE` сохраняет отображение закрытых заказов после архивации машины.
     *
     * @param int[] $ids       Положительные идентификаторы автомобилей.
     * @param int   $contactId Идентификатор владельца-контакта CRM.
     *
     * @return array<int, array<string, mixed>> Найденные записи по убыванию ID.
     */
    public function findByIdsForContact(array $ids, int $contactId): array
    {
        /** @var int[] $normalizedIds Уникальные положительные ID для безопасного ORM-фильтра. */
        $normalizedIds = [];
        foreach ($ids as $id) {
            /** @var int $normalizedId Очередной приведённый к целому идентификатор. */
            $normalizedId = (int)$id;
            if ($normalizedId > 0) {
                $normalizedIds[$normalizedId] = $normalizedId;
            }
        }

        if ($normalizedIds === [] || $contactId <= 0) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $cars Найденные автомобили заданного контакта. */
        $cars = [];

        /** @var \Bitrix\Main\ORM\Query\Result $queryResult Результат одной пакетной ORM-выборки. */
        $queryResult = CarTable::getList(
            [
                'filter' => [
                    '@ID' => array_values($normalizedIds),
                    '=CONTACT_ID' => $contactId,
                ],
                'order' => ['ID' => 'DESC'],
            ]
        );

        while ($car = $queryResult->fetch()) {
            /** @var array<string, mixed> $car Очередная разрешённая запись автомобиля. */
            $cars[] = $car;
        }

        return $cars;
    }

    /**
     * Возвращает первый автомобиль с указанным нормализованным госномером.
     *
     * @param string $licensePlate Номер без пробелов и дефисов в верхнем регистре.
     *
     * @return array<string, mixed>|null Найденная запись или null.
     */
    public function findByLicensePlate(string $licensePlate): ?array
    {
        /** @var array<string, mixed>|false $car Результат точного поиска по индексу номера. */
        $car = CarTable::getList(
            [
                'filter' => ['=LICENSE_PLATE' => $licensePlate],
                'order' => ['ACTIVE' => 'DESC', 'ID' => 'DESC'],
                'limit' => 1,
            ]
        )->fetch();

        return $car === false ? null : $car;
    }

    /**
     * Возвращает все активные автомобили контакта CRM.
     *
     * @param int $contactId Идентификатор контакта CRM.
     *
     * @return array<int, array<string, mixed>> Список автомобилей по убыванию ID.
     */
    public function findActiveByContact(int $contactId): array
    {
        /** @var array<int, array<string, mixed>> $cars Накопленный результат выборки. */
        $cars = [];

        /** @var \Bitrix\Main\ORM\Query\Result $queryResult Результат запроса по составному индексу. */
        $queryResult = CarTable::getList(
            [
                'filter' => [
                    '=CONTACT_ID' => $contactId,
                    '=ACTIVE' => 'Y',
                ],
                'order' => ['ID' => 'DESC'],
            ]
        );

        while ($car = $queryResult->fetch()) {
            /** @var array<string, mixed> $car Очередной активный автомобиль контакта. */
            $cars[] = $car;
        }

        return $cars;
    }

    /**
     * Возвращает страницу автомобилей контакта для стандартного CRM-GRID.
     *
     * @param array<string, mixed>  $filter Подготовленный сервисом безопасный ORM-фильтр.
     * @param array<string, string> $order  Сортировка только по разрешённым колонкам.
     * @param int                   $limit  Максимальное количество строк страницы.
     * @param int                   $offset Смещение первой строки страницы.
     *
     * @return array<int, array<string, mixed>> Страница автомобилей без дополнительных запросов в цикле.
     */
    public function findPage(array $filter, array $order, int $limit, int $offset): array
    {
        /** @var array<int, array<string, mixed>> $cars Накопленная страница результата GRID. */
        $cars = [];

        /** @var \Bitrix\Main\ORM\Query\Result $queryResult Одна ORM-выборка текущей страницы. */
        $queryResult = CarTable::getList(
            [
                'select' => [
                    'ID',
                    'CONTACT_ID',
                    'MAKE',
                    'MODEL',
                    'LICENSE_PLATE',
                    'YEAR',
                    'COLOR',
                    'MILEAGE',
                    'ACTIVE',
                ],
                'filter' => $filter,
                'order' => $order,
                'limit' => max(1, $limit),
                'offset' => max(0, $offset),
            ]
        );

        while ($car = $queryResult->fetch()) {
            /** @var array<string, mixed> $car Очередной автомобиль текущей страницы. */
            $cars[] = $car;
        }

        return $cars;
    }

    /**
     * Подсчитывает автомобили, соответствующие фильтру GRID.
     *
     * @param array<string, mixed> $filter Подготовленный сервисом безопасный ORM-фильтр.
     */
    public function countByFilter(array $filter): int
    {
        return CarTable::getCount($filter);
    }
}
