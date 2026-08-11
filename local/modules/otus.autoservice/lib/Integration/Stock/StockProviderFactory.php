<?php

/**
 * Выбирает реализацию источника внешних остатков по настройке или внедрённой карте.
 */

declare(strict_types=1);

namespace Otus\Autoservice\Integration\Stock;

use Bitrix\Main\Localization\Loc;
use InvalidArgumentException;
use Otus\Autoservice\Service\ModuleConfiguration;
use RuntimeException;

Loc::loadMessages(__FILE__);

/**
 * Изолирует прикладные сервисы от конкретного конструктора поставщика.
 *
 * Карта в конструкторе является простой точкой DI: тест или другая поставка модуля
 * может передать собственную реализацию, не меняя фабрику и синхронизацию.
 */
final class StockProviderFactory
{
    /**
     * Зарегистрированные реализации, индексированные стабильным машинным кодом.
     *
     * @var array<string, StockProviderInterface>
     */
    private array $providers;

    /**
     * Создаёт фабрику со встроенными либо явно внедрёнными поставщиками.
     *
     * @param StockProviderInterface[]|null $providers Список реализаций для DI или null для штатного набора.
     */
    public function __construct(?array $providers = null)
    {
        /** @var StockProviderInterface[] $providerList Фактический список реализаций для регистрации. */
        $providerList = $providers ?? [
            new RandomOrgStockProvider(),
            new FakeStockProvider(),
        ];

        if ($providerList === []) {
            throw new InvalidArgumentException('At least one stock provider must be registered.');
        }

        /** @var array<string, StockProviderInterface> $indexedProviders Проверенная карта реализаций. */
        $indexedProviders = [];
        /** @var mixed $provider Очередное значение внедрённого списка. */
        foreach ($providerList as $provider) {
            if (!$provider instanceof StockProviderInterface) {
                throw new InvalidArgumentException('Every stock provider must implement StockProviderInterface.');
            }

            /** @var string $providerCode Машинный код очередной реализации. */
            $providerCode = $provider->getCode();
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $providerCode) !== 1) {
                throw new InvalidArgumentException('Stock provider code has an invalid format.');
            }
            if (isset($indexedProviders[$providerCode])) {
                throw new InvalidArgumentException('Stock provider code must be unique.');
            }

            $indexedProviders[$providerCode] = $provider;
        }

        $this->providers = $indexedProviders;
    }

    /**
     * Возвращает поставщика по явному коду или текущей настройке модуля.
     *
     * @param string|null $providerCode Явный код для теста либо null для b_option.
     */
    public function create(?string $providerCode = null): StockProviderInterface
    {
        /** @var string $resolvedCode Итоговый код после чтения безопасной настройки. */
        $resolvedCode = $providerCode ?? ModuleConfiguration::getStockProviderCode();
        if (!isset($this->providers[$resolvedCode])) {
            throw new RuntimeException(
                (string)Loc::getMessage(
                    'OTUS_AUTOSERVICE_STOCK_PROVIDER_UNKNOWN',
                    ['#CODE#' => $resolvedCode]
                )
            );
        }

        return $this->providers[$resolvedCode];
    }

    /**
     * Возвращает коды фактически внедрённых реализаций для диагностики.
     *
     * @return string[]
     */
    public function getAvailableCodes(): array
    {
        return array_keys($this->providers);
    }
}
