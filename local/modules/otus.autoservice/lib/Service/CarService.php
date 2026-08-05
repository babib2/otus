<?php

/**
 * Реализует прикладные операции с автомобилями и нормализует входные данные.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Service;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Otus\Autoservice\Repository\CarRepository;

/**
 * Сервис управления автомобилями клиентов автосервиса.
 *
 * Все публичные методы принимают названия полей в формате D7 (`CONTACT_ID`,
 * `LICENSE_PLATE` и так далее). Технические поля ID и дат от пользователя
 * игнорируются, чтобы их формировал репозиторий и база данных.
 */
final class CarService
{
    /**
     * Поля автомобиля, разрешённые для массового заполнения извне.
     *
     * @var string[]
     */
    private const WRITABLE_FIELDS = [
        'CONTACT_ID',
        'MAKE',
        'MODEL',
        'LICENSE_PLATE',
        'YEAR',
        'COLOR',
        'MILEAGE',
        'ACTIVE',
    ];

    /** @var CarRepository Репозиторий, выполняющий операции D7 ORM. */
    private $repository;

    /**
     * Принимает репозиторий извне для тестирования или создаёт стандартный.
     */
    public function __construct(?CarRepository $repository = null)
    {
        $this->repository = $repository ?? new CarRepository();
    }

    /**
     * Создаёт автомобиль клиента.
     *
     * @param array<string, mixed> $data   Пользовательские поля автомобиля.
     * @param int                  $userId Пользователь Bitrix, создающий запись.
     */
    public function create(array $data, int $userId): AddResult
    {
        /** @var array<string, mixed> $fields Разрешённые и нормализованные поля ORM. */
        $fields = $this->prepareFields($data);
        $fields['CREATED_BY'] = max(0, $userId);
        $fields['UPDATED_BY'] = max(0, $userId);

        return $this->repository->add($fields);
    }

    /**
     * Изменяет разрешённые поля существующего автомобиля.
     *
     * @param int                  $id     Идентификатор автомобиля.
     * @param array<string, mixed> $data   Изменяемые пользовательские поля.
     * @param int                  $userId Пользователь Bitrix, изменяющий запись.
     */
    public function update(int $id, array $data, int $userId): UpdateResult
    {
        /** @var array<string, mixed> $fields Разрешённые и нормализованные поля ORM. */
        $fields = $this->prepareFields($data);
        $fields['UPDATED_BY'] = max(0, $userId);

        return $this->repository->update($id, $fields);
    }

    /**
     * Деактивирует автомобиль без физического удаления истории.
     */
    public function deactivate(int $id, int $userId): UpdateResult
    {
        return $this->repository->deactivate($id, max(0, $userId));
    }

    /**
     * Возвращает автомобиль по идентификатору.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * Ищет автомобиль по номеру в любом поддерживаемом пользовательском формате.
     *
     * @return array<string, mixed>|null
     */
    public function getByLicensePlate(string $licensePlate): ?array
    {
        return $this->repository->findByLicensePlate(
            $this->normalizeLicensePlate($licensePlate)
        );
    }

    /**
     * Возвращает активные автомобили указанного контакта CRM.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveByContact(int $contactId): array
    {
        return $this->repository->findActiveByContact($contactId);
    }

    /**
     * Приводит государственный номер к единому виду для хранения и поиска.
     *
     * Удаляются пробелы и дефисы, буквы переводятся в верхний регистр. Другие
     * символы пока сохраняются, чтобы не блокировать иностранные форматы номеров.
     */
    public function normalizeLicensePlate(string $licensePlate): string
    {
        /** @var string $upperCasePlate Номер после удаления внешних пробелов и смены регистра. */
        $upperCasePlate = mb_strtoupper(trim($licensePlate), 'UTF-8');

        /** @var string|null $normalizedPlate Результат удаления внутренних разделителей. */
        $normalizedPlate = preg_replace('/[\s\-]+/u', '', $upperCasePlate);

        return $normalizedPlate ?? $upperCasePlate;
    }

    /**
     * Оставляет разрешённые поля и приводит их к типам ORM.
     *
     * @param array<string, mixed> $data Исходные данные контроллера или REST-метода.
     *
     * @return array<string, mixed> Безопасный набор полей для репозитория.
     */
    private function prepareFields(array $data): array
    {
        /** @var array<string, bool> $allowedFieldMap Карта полей для быстрого фильтра. */
        $allowedFieldMap = array_fill_keys(self::WRITABLE_FIELDS, true);

        /** @var array<string, mixed> $fields Данные без технических и неизвестных полей. */
        $fields = array_intersect_key($data, $allowedFieldMap);

        /** @var string $stringField Очередное текстовое поле, очищаемое от внешних пробелов. */
        foreach (['MAKE', 'MODEL', 'COLOR'] as $stringField) {
            if (array_key_exists($stringField, $fields)) {
                $fields[$stringField] = trim((string)$fields[$stringField]);
            }
        }

        if (array_key_exists('LICENSE_PLATE', $fields)) {
            $fields['LICENSE_PLATE'] = $this->normalizeLicensePlate(
                (string)$fields['LICENSE_PLATE']
            );
        }

        if (array_key_exists('CONTACT_ID', $fields)) {
            $fields['CONTACT_ID'] = (int)$fields['CONTACT_ID'];
        }

        if (array_key_exists('YEAR', $fields)) {
            $fields['YEAR'] = $fields['YEAR'] === '' || $fields['YEAR'] === null
                ? null
                : (int)$fields['YEAR'];
        }

        if (array_key_exists('COLOR', $fields) && $fields['COLOR'] === '') {
            $fields['COLOR'] = null;
        }

        if (array_key_exists('MILEAGE', $fields)) {
            $fields['MILEAGE'] = (int)$fields['MILEAGE'];
        }

        if (array_key_exists('ACTIVE', $fields)) {
            $fields['ACTIVE'] = in_array($fields['ACTIVE'], ['N', 0, '0', false], true)
                ? 'N'
                : 'Y';
        }

        return $fields;
    }
}
