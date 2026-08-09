# REST API автомобилей

> Назначение файла: подробно описывает авторизацию, параметры, ответы и ошибки внешних REST-методов автомобилей модуля `otus.autoservice`.

## 1. Назначение и границы доступа

API позволяет внешней интеграции получать, создавать, изменять и архивировать автомобили одного CRM-контакта. Методы работают через существующий `CarService`, поэтому используют те же правила нормализации, ORM-валидации, кеширования, аудита, Push&Pull и запрета архивирования автомобиля с незакрытым сервисным заказом.

Для вызова необходимы одновременно:

1. Установленные и активные модули `otus.autoservice`, `rest` и `crm`.
2. OAuth-приложение или входящий вебхук. Внутренний REST-вызов только по пользовательской сессии отклоняется.
3. Разрешение (scope) `otus.autoservice` у токена или вебхука.
4. Пользователь Bitrix, связанный с токеном.
5. Штатное CRM-право пользователя на переданный `CONTACT_ID`:
   - право чтения для `list` и `get`;
   - право изменения для `add`, `update` и `delete`.

Приложение должно передавать `CONTACT_ID` в каждом запросе. Сервер не принимает владельца из `FIELDS` и повторно проверяет, что запрошенный автомобиль принадлежит этому контакту. Отсутствующая и чужая запись возвращают одинаковую ошибку `CAR_REST_CAR_NOT_FOUND`, чтобы не помогать перебору идентификаторов.

## 2. Адрес и формат вызова

Для входящего вебхука адрес имеет стандартный формат Bitrix:

```text
https://portal.example/rest/{user-id}/{webhook-code}/{method}.json
```

Параметры можно передавать POST-формой. Пример чтения активных автомобилей:

```bash
curl --request POST \
  --data-urlencode "CONTACT_ID=15" \
  --data-urlencode "FILTER[ACTIVE]=Y" \
  --data-urlencode "ORDER[ID]=DESC" \
  --data-urlencode "LIMIT=50" \
  "https://portal.example/rest/1/webhook-code/otus.autoservice.car.list.json"
```

OAuth-приложение вызывает тот же метод через штатную конечную точку REST и передаёт `auth` так, как требует REST-модуль Bitrix. Токен и код вебхука нельзя сохранять в репозитории или выводить в журналы.

Методы также можно включать в стандартный `batch` Bitrix. Для batch-подзапроса вебхука модуль использует проверенный ядром `password_id` из общего контекста авторизации; повторно передавать код вебхука внутри команды не требуется.

Успешный ответ оборачивается REST-модулем Bitrix в `result`. Для списка `total` и `next` переносятся на верхний уровень:

```json
{
  "result": {
    "items": [
      {
        "ID": 21,
        "CONTACT_ID": 15,
        "MAKE": "Lada",
        "MODEL": "Vesta",
        "LICENSE_PLATE": "A123AA196",
        "YEAR": 2024,
        "COLOR": "Белый",
        "MILEAGE": 12500,
        "ACTIVE": "Y"
      }
    ]
  },
  "total": 1
}
```

Публичная запись содержит только `ID`, `CONTACT_ID`, `MAKE`, `MODEL`, `LICENSE_PLATE`, `YEAR`, `COLOR`, `MILEAGE` и `ACTIVE`. Поля `CREATED_BY`, `UPDATED_BY`, `DATE_CREATE` и `DATE_UPDATE` наружу не возвращаются.

## 3. `otus.autoservice.car.list`

Возвращает страницу автомобилей одного контакта, включая архивные записи, если фильтр `ACTIVE` не задан.

Параметры:

| Параметр | Обязательный | Описание |
|---|---:|---|
| `CONTACT_ID` | да | Положительный ID CRM-контакта. |
| `FILTER` | нет | Ассоциативный массив разрешённых фильтров. |
| `ORDER` | нет | Не более одного разрешённого поля и `ASC` либо `DESC`. По умолчанию `ID DESC`. |
| `LIMIT` | нет | Размер страницы от 1 до 100, по умолчанию 50. |
| `start` | нет | Стандартное смещение Bitrix от 0 до 100000. Передаётся на верхнем уровне запроса. |

Разрешённые фильтры:

| Поле | Тип и смысл |
|---|---|
| `FIND` | Строковый поиск по марке, модели и госномеру. |
| `MAKE`, `MODEL`, `LICENSE_PLATE`, `COLOR` | Строковый фильтр по соответствующему полю. |
| `YEAR_FROM`, `YEAR_TO` | Целочисленный диапазон года. Нижняя граница не может быть больше верхней. |
| `MILEAGE_FROM`, `MILEAGE_TO` | Целочисленный диапазон пробега. Нижняя граница не может быть больше верхней. |
| `ACTIVE` | Только `Y` или `N`. |

Сортировать разрешено по `ID`, `MAKE`, `MODEL`, `LICENSE_PLATE`, `YEAR`, `COLOR`, `MILEAGE` и `ACTIVE`.

## 4. `otus.autoservice.car.get`

Возвращает один автомобиль.

```bash
curl --request POST \
  --data-urlencode "CONTACT_ID=15" \
  --data-urlencode "ID=21" \
  "https://portal.example/rest/1/webhook-code/otus.autoservice.car.get.json"
```

Обязательны положительные целые `CONTACT_ID` и `ID`. Результат — одна публичная запись автомобиля.

## 5. `otus.autoservice.car.add`

Создаёт активный автомобиль в гараже `CONTACT_ID`. Поля `CONTACT_ID`, `ACTIVE` и технические поля внутри `FIELDS` запрещены.

```bash
curl --request POST \
  --data-urlencode "CONTACT_ID=15" \
  --data-urlencode "FIELDS[MAKE]=Lada" \
  --data-urlencode "FIELDS[MODEL]=Vesta" \
  --data-urlencode "FIELDS[LICENSE_PLATE]=A 123 AA-196" \
  --data-urlencode "FIELDS[YEAR]=2024" \
  --data-urlencode "FIELDS[COLOR]=Белый" \
  --data-urlencode "FIELDS[MILEAGE]=12500" \
  "https://portal.example/rest/1/webhook-code/otus.autoservice.car.add.json"
```

Разрешённые поля `FIELDS`: `MAKE`, `MODEL`, `LICENSE_PLATE`, `YEAR`, `COLOR`, `MILEAGE`. Марка, модель и госномер обязательны по ORM-модели. Пробелы и дефисы госномера удаляются, буквы переводятся в верхний регистр. Успех возвращает HTTP 201 и созданную публичную запись.

## 6. `otus.autoservice.car.update`

Изменяет разрешённые поля существующего автомобиля. Обязательны `CONTACT_ID`, `ID` и непустой `FIELDS`.

```bash
curl --request POST \
  --data-urlencode "CONTACT_ID=15" \
  --data-urlencode "ID=21" \
  --data-urlencode "FIELDS[MILEAGE]=13000" \
  "https://portal.example/rest/1/webhook-code/otus.autoservice.car.update.json"
```

Белый список `FIELDS` совпадает с методом `add`. Сменить владельца или напрямую изменить `ACTIVE` через этот метод нельзя. Результат — запись после изменения.

## 7. `otus.autoservice.car.delete`

Выполняет мягкое удаление: физическая строка и исторические связи сохраняются, а `ACTIVE` становится `N`.

```bash
curl --request POST \
  --data-urlencode "CONTACT_ID=15" \
  --data-urlencode "ID=21" \
  "https://portal.example/rest/1/webhook-code/otus.autoservice.car.delete.json"
```

Успешный ответ:

```json
{
  "result": {
    "ID": 21,
    "ARCHIVED": true
  }
}
```

Если автомобиль участвует в незакрытой сделке сервисной воронки, операция отклоняется с HTTP 409 и кодом `CAR_OPEN_ORDER_EXISTS`.

## 8. Ошибки

REST-модуль Bitrix возвращает ошибку в стандартном виде:

```json
{
  "error": "CAR_REST_INVALID_ARGUMENT",
  "error_description": "Параметр LIMIT отсутствует или имеет недопустимое значение."
}
```

| HTTP | Код | Причина |
|---:|---|---|
| 400 | `CAR_REST_INVALID_ARGUMENT` | Нет обязательного параметра, неверен тип/диапазон либо передано неизвестное поле. |
| 401 | `CAR_REST_AUTH_REQUIRED` | В контексте токена нет пользователя Bitrix. |
| 403 | `CAR_REST_MODULE_DISABLED` | Модуль отключён настройкой. |
| 403 | `CAR_REST_SCOPE_DENIED` | Токен не содержит scope `otus.autoservice`. |
| 403 | `CAR_REST_EXTERNAL_CONTEXT_REQUIRED` | Метод вызван не через OAuth-приложение и не через входящий вебхук. |
| 403 | `CAR_REST_ACCESS_DENIED` | Пользователю токена не хватает CRM-права на контакт. |
| 404 | `CAR_REST_CAR_NOT_FOUND` | Автомобиль отсутствует либо принадлежит другому контакту. |
| 409 | `CAR_OPEN_ORDER_EXISTS` | Архивирование запрещено из-за незакрытого сервисного заказа. |
| 500 | `CAR_REST_INTERNAL_ERROR` | Непредвиденная внутренняя ошибка; детали записаны в системный аудит без ответа клиенту. |

## 9. Проверка после развёртывания

Применить миграцию регистрации обработчика:

```powershell
php local/modules/otus.autoservice/tools/migrate.php --apply
```

Выполнить проверку регистрации, scope, OAuth-подобного контекста, прямого и batch-вызова вебхука, валидации и чтения без изменения автомобилей и CRM-сущностей:

```powershell
php local/modules/otus.autoservice/tools/check_car_rest.php
```

Отдельно разрешить очищаемый тест полного CRUD. Он создаёт одну временную запись, проверяет `add`, `get`, `update` и мягкий `delete`, а в `finally` физически удаляет только эту тестовую запись:

```powershell
php local/modules/otus.autoservice/tools/check_car_rest.php --write-test
```

После локального теста следует выполнить настоящий запрос OAuth-приложением или вебхуком с отдельным scope `otus.autoservice`: CLI-проверка не заменяет проверку сетевого маршрута, настроек приложения и выдачи разрешения на конкретном портале.

Отдельные исходящие REST-события создания и изменения сейчас не регистрируются: вкладки портала уже синхронизируются внутренним Push&Pull, а внешний обмен по проектному плану будет выполняться через журнал синхронизации. Это предотвращает дублирование недоставляемых событий до появления механизма повторных попыток.
