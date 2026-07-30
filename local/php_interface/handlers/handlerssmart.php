<?php

use Bitrix\Main\LoaderException;

use Bitrix\Crm\Service;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use \Bitrix\Main;

/**
 * Класс для подмен фабрик смарт-процессов
 * class SmartObjectController
 */
class SmartProcessController extends Service\Container
{
    function __construct()
    {
        DI\ServiceLocator::getInstance()->addInstance('crm.service.container', $this);
    }

    /**
     * Подмены для смарт-процессов
     * @param int $entityTypeId
     * @return Service\Factory|null
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws LoaderException
     */
    public function getFactory(int $entityTypeId): ?Service\Factory
    {
        if(empty($entityTypeId)) return null;
        $arEntityId = [
            
        ];
        foreach ($arEntityId as $key => $className) {
            if ($entityTypeId == $key)
            {

                // Сгенерируем название сервиса ->
                $identifier = static::getIdentifierByClassName(static::$dynamicFactoriesClassName, [$entityTypeId]);
                // ... и проверим - вдруг уже есть объект класса?
                if ( Main\DI\ServiceLocator::getInstance()->has($identifier) )
                {
                    return Main\DI\ServiceLocator::getInstance()->get($identifier);
                }

                // Объекта нет. Получим 'объект смарт-процесса'
                $type = $this->getTypeByEntityTypeId($entityTypeId);
                if ( !$type )
                {
                    // Не получилось, смарт-процесс удален
                    return null;
                }

                // Создадим фабрику, запомним ее 
                $factory = new $className($type);
                Main\DI\ServiceLocator::getInstance()->addInstance(
                    $identifier,
                    $factory
                );
                // Вернем подмененную фабрику
                return $factory;
            }
        }

        return parent::getFactory($entityTypeId);
    }
}
