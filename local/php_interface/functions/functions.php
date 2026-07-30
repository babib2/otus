<?

/**
 * Преобразовывает из формата 15 100 м² или 450 000 ₽ в 15100 и 450000 соответственно
 */
function cleanValue($value) {
    // Удаляем пробелы и символы, которые меняют формат
    $cleaned = preg_replace('/[^\d]/', '', $value);
    return intval($cleaned);
}

function formatNumber(&$value)
{
	$explodeValue = explode(".",$value);

    $value = number_format(intval($value), 0, '', ' ');

    /*if($explodeValue[1])
    {
		$value .= "." .$explodeValue[1];
	}*/

	return $value;
}
/**
 * Получить путь до файла
 * */
function getPathFile($value)
{
	$fileArray = \CFile::GetFileArray($value);
	if($fileArray)
		return $fileArray["SRC"];
	return "";
}	


/**
 * Копировать значение поля файл в другое поле
 * */
function copyImageField($entityTypeId, $nameFieldTo, $valueFieldFrom)
{
	$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
    $fieldCollection = $factory->getFieldsCollection();
    $field = $fieldCollection->getField($nameFieldTo);
   
    $fileUploader = \Bitrix\Crm\Service\Container::getInstance()->getFileUploader();
    $fileUploader->registerFileId($field, $valueFieldFrom);
}
/**
 * Получить значение списка по значению из другого списка
 * */
function retrieveTheValueFromAListByTheValueFromAnotherList(string $xmlIdFieldTo, mixed $value) : mixed
{
	if($value && is_int(intval($value)))
	{
		$valueByInt = \CUserFieldEnum::GetList(array(), array("ID" => $value))->arResult[0]["VALUE"];
		$rsData = \CUserTypeEntity::GetList( array($by=>$order), array("XML_ID" => $xmlIdFieldTo));
        $rsDataId = $rsData->Fetch()["ID"];
        if($rsDataId) {
            $rsEnum = \CUserFieldEnum::GetList(array(), array("USER_FIELD_ID" => $rsDataId, "VALUE" => $valueByInt));
            $arEnum = $rsEnum->GetNext();
            return $arEnum['ID'];
        }
	}
	return "";
}
/**
 * Получить элемент по ID элемента и ID сущности
 * */
function getItemByElementId(array $entityData) : mixed
{
	$service = \Bitrix\Main\DI\ServiceLocator::getInstance()->get($entityData["ENTITY_TYPE_ID"]);
	
    return $service->getItem($entityData["ELEMENT_ID"]);
}
/**
 * Получить значение поля типа "Список"
 * */
function checkEmptyListField(mixed $value) : mixed
{
	if(is_array($value))
	{
		if($value)
		{
			return getValueFromListFieldArray($value);
		}
		return $value;
	}
	if(empty($value))
        return $value;
    else
        return getValueFromListField(intval($value));
}
/**
 * Является ли значение поле типа "Привязка к элементам CRM" элементом сущности
 * */
function checkLinkSomeEntity(string $value, int $entityTypeId) : mixed
{
	$entityData = hexToDec($value);

    //Если в поле нет значения
    if(!array_key_exists("ELEMENT_ID", $entityData) && !array_key_exists("ENTITY_TYPE_ID", $entityData))
        return false;

    //Если в поле содержит значение не из СП $entityTypeId
    if($entityData["ENTITY_TYPE_ID"] != $entityTypeId)
        return false;

    return $entityData;
}
/**
 * Получить ID  поля типа "Список" по XML_ID значения
 * */
function getIdByXmlId($xmlId) : mixed
{
	if($xmlId)
	{
		return  \CUserFieldEnum::GetList(array(), array("XML_ID" => $xmlId))->arResult[0]["ID"];
	}
	return "";

}
/**
 * Получить значение поля типа "Список" по Идентификатору значения
 * */
function getValueFromListFieldArray($values) : array
{
	$array = \CUserFieldEnum::GetList(array(), array("ID" => $values))->arResult;
	$values2 = array();
	foreach ($array as $item) {
	    $values2[] = $item['VALUE'];
	}
	return  $values2;
}
/**
 * Получить значение поля типа "Список" по Идентификатору значения
 * */
function getValueFromListField(int $value) : mixed
{
	if($value && is_int(intval($value)))
		return  \CUserFieldEnum::GetList(array(), array("ID" => $value))->arResult[0]["VALUE"];
	else
		return "";
}
/**
 * Получить ID сущность и ID элемента из поля типа "Привязка к элементам CRM" множественое (Может содержать значение из нескольких сущностей)
 * */
function hexToDec(string $hex) : array
{
	$explodeData = explode("_", $hex);
	$entityTypeId = hexdec(removeFirstWord($explodeData[0]));
	$entityId = $explodeData[1];
	return ["ENTITY_TYPE_ID" => $entityTypeId, "ELEMENT_ID" => $entityId];
}
/**
 * Закодировать элемент в 16-ричную ситему для мультиполя
 * */
function DecToHex(string $entityTypeId, string $elementId) : string
{
	$entityTypeId = dechex($entityTypeId);
	$entityId = 'T'.$entityTypeId.'_'.$elementId;
	return $entityId;
}
/**
 * Удалить первую букву строку
 * */
function removeFirstWord(string $word) : string
{
	if(trim($word))
		return substr($word, 1);
	else
		return [];
}
