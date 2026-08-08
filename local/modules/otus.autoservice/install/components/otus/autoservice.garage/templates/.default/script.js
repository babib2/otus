/*
 * Управляет формами автомобилей, AJAX-командами, GRID и PushPull вкладки «Гараж».
 */

(function (BX) {
    'use strict';

    BX.namespace('BX.Otus.Autoservice');

    /**
     * Клиентский экземпляр гаража одного CRM-контакта.
     *
     * @param {Object} options Настройки, сформированные серверным шаблоном.
     * @constructor
     */
    function Garage(options)
    {
        /** @type {Object} Нормализованные параметры компонента. */
        this.options = options || {};

        /** @type {string} DOM-ID контейнера вкладки. */
        this.containerId = String(this.options.containerId || '');

        /** @type {string} Идентификатор стандартного GRID. */
        this.gridId = String(this.options.gridId || '');

        /** @type {number} Контакт-владелец отображаемых автомобилей. */
        this.contactId = parseInt(this.options.contactId, 10) || 0;

        /** @type {string} Уникальный инициатор запросов этой вкладки для адресного подавления Push-эха. */
        this.instanceId = 'garage_' + BX.util.getRandomString(24);

        /** @type {Object} Локализованные сообщения интерфейса. */
        this.messages = this.options.messages || {};

        /** @type {string} Защищённый адрес повторных запросов стандартного GRID. */
        this.gridServiceUrl = String(this.options.gridServiceUrl || '');

        /** @type {?BX.PopupWindow} Текущая форма создания или изменения. */
        this.popup = null;

        /** @type {Object<string, HTMLInputElement>} Поля открытой формы. */
        this.formFields = {};

        /** @type {?Function} Функция снятия PushPull-подписки. */
        this.unsubscribePull = null;

        /** @type {boolean} Зарегистрирован ли watch-тег этим экземпляром. */
        this.watchTagExtended = false;

        /** @type {?Function} Обработчик подмены URL только для GRID текущего контакта. */
        this.onBeforeGridRequest = null;

        this.bindAddButton();
        this.configureGridRequests();
        this.subscribePull();
    }

    /** @type {Object<string, Garage>} Реестр вкладок в основной странице и CRM-слайдерах. */
    Garage.instances = {};

    /** @type {Object<string, number>} Число экземпляров страницы, использующих один watch-тег. */
    Garage.watchReferenceCounts = {};

    /**
     * Создаёт либо заменяет клиентский экземпляр после обычной или AJAX-загрузки GRID.
     *
     * @param {Object} options Серверные настройки конкретной вкладки.
     * @returns {Garage}
     */
    Garage.create = function (options)
    {
        const containerId = String((options || {}).containerId || '');
        if (Garage.instances[containerId])
        {
            Garage.instances[containerId].destroy();
        }

        Garage.instances[containerId] = new Garage(options);

        return Garage.instances[containerId];
    };

    /**
     * Возвращает экземпляр, на который ссылаются действия строки GRID.
     *
     * @param {string} containerId DOM-ID вкладки.
     * @returns {?Garage}
     */
    Garage.get = function (containerId)
    {
        return Garage.instances[String(containerId)] || null;
    };

    /**
     * Освобождает всплывающее окно и PushPull-подписку перед повторной инициализацией.
     */
    Garage.prototype.destroy = function ()
    {
        if (typeof this.onBeforeGridRequest === 'function')
        {
            BX.removeCustomEvent(window, 'Grid::beforeRequest', this.onBeforeGridRequest);
            this.onBeforeGridRequest = null;
        }

        if (typeof this.unsubscribePull === 'function')
        {
            this.unsubscribePull();
            this.unsubscribePull = null;
        }

        const watchTag = String(this.options.pullWatchTag || '');
        if (this.watchTagExtended && watchTag)
        {
            Garage.watchReferenceCounts[watchTag] = Math.max(
                0,
                (Garage.watchReferenceCounts[watchTag] || 1) - 1
            );

            if (Garage.watchReferenceCounts[watchTag] === 0)
            {
                if (BX.PULL && typeof BX.PULL.clearWatch === 'function')
                {
                    BX.PULL.clearWatch(watchTag);
                }

                delete Garage.watchReferenceCounts[watchTag];
            }

            this.watchTagExtended = false;
        }

        if (this.popup)
        {
            this.popup.destroy();
            this.popup = null;
        }
    };

    /**
     * Направляет сортировку, фильтрацию, пагинацию и ручное обновление на подписанный endpoint.
     */
    Garage.prototype.configureGridRequests = function ()
    {
        if (!this.gridServiceUrl || !BX.Main || !BX.Main.gridManager)
        {
            return;
        }

        /** @type {?BX.Main.grid} Экземпляр GRID, созданный стандартным компонентом до гаража. */
        const grid = BX.Main.gridManager.getInstanceById(this.gridId);
        if (!grid)
        {
            return;
        }

        grid.baseUrl = this.gridServiceUrl;

        this.onBeforeGridRequest = function (gridData, eventArgs) {
            const requestGrid = gridData && typeof gridData.getParent === 'function'
                ? gridData.getParent()
                : null;
            if (!requestGrid || requestGrid.getId() !== this.gridId || !eventArgs)
            {
                return;
            }

            /** @type {URL} targetUrl URL endpoint с неизменяемой серверной подписью контакта. */
            const targetUrl = new URL(this.gridServiceUrl, window.location.origin);

            /** @type {string} sourceUrl URL сортировки или страницы, сформированный стандартным GRID. */
            const sourceUrl = String(eventArgs.url || '');
            if (sourceUrl)
            {
                /** @type {URL} parsedSourceUrl Исходный URL для переноса только параметров состояния GRID. */
                const parsedSourceUrl = new URL(sourceUrl, window.location.href);
                parsedSourceUrl.searchParams.forEach(function (value, name) {
                    if (name !== 'site' && name !== 'sessid' && name.indexOf('PARAMS[') !== 0)
                    {
                        targetUrl.searchParams.set(name, value);
                    }
                });
            }

            eventArgs.url = targetUrl.pathname + targetUrl.search;
        }.bind(this);

        BX.addCustomEvent(window, 'Grid::beforeRequest', this.onBeforeGridRequest);
    };

    /**
     * Подключает кнопку добавления, если у пользователя есть право изменения контакта.
     */
    Garage.prototype.bindAddButton = function ()
    {
        const container = BX(this.containerId);
        if (!container || !this.options.canEdit)
        {
            return;
        }

        const addButton = container.querySelector('[data-role="garage-add-car"]');
        if (addButton)
        {
            BX.bind(addButton, 'click', this.openCreateForm.bind(this));
        }
    };

    /**
     * Подписывает открытую вкладку на приватный watch-тег текущего контакта.
     */
    Garage.prototype.subscribePull = function ()
    {
        if (!BX.PULL || !this.options.pullWatchTag)
        {
            return;
        }

        const watchTag = String(this.options.pullWatchTag);
        BX.PULL.extendWatch(watchTag, true);
        Garage.watchReferenceCounts[watchTag] = (Garage.watchReferenceCounts[watchTag] || 0) + 1;
        this.watchTagExtended = true;

        this.unsubscribePull = BX.PULL.subscribe({
            moduleId: 'otus.autoservice',
            command: String(this.options.pullCommand || 'garageChanged'),
            callback: function (parameters) {
                const eventParameters = parameters || {};
                if (
                    (parseInt(eventParameters.contactId, 10) || 0) === this.contactId
                    && String(eventParameters.originId || '') !== this.instanceId
                )
                {
                    this.reloadGrid();
                }
            }.bind(this),
        });
    };

    /**
     * Открывает пустую форму создания автомобиля.
     */
    Garage.prototype.openCreateForm = function ()
    {
        this.openForm(null);
    };

    /**
     * Загружает разрешённые данные автомобиля и открывает форму изменения.
     *
     * @param {number} carId Идентификатор строки GRID.
     */
    Garage.prototype.edit = function (carId)
    {
        this.runAction('get', {
            contactId: this.contactId,
            id: parseInt(carId, 10) || 0,
        }).then(function (response) {
            this.openForm(response.data || {});
        }.bind(this)).catch(this.showRequestError.bind(this));
    };

    /**
     * Запрашивает подтверждение и выполняет мягкое удаление автомобиля.
     *
     * @param {number} carId Идентификатор архивируемой строки GRID.
     */
    Garage.prototype.archive = function (carId)
    {
        BX.UI.Dialogs.MessageBox.confirm(
            String(this.messages.archiveConfirm || ''),
            String(this.messages.archiveTitle || ''),
            function (messageBox) {
                messageBox.close();
                this.runAction('archive', {
                    contactId: this.contactId,
                    id: parseInt(carId, 10) || 0,
                }).then(function (response) {
                    this.notify(response.data && response.data.message);
                    this.reloadGrid();
                }.bind(this)).catch(this.showRequestError.bind(this));
            }.bind(this),
            String(this.messages.archiveButton || '')
        );
    };

    /**
     * Строит стандартное всплывающее окно создания или изменения автомобиля.
     *
     * @param {?Object} car Данные автомобиля либо null для новой записи.
     */
    Garage.prototype.openForm = function (car)
    {
        if (this.popup)
        {
            this.popup.destroy();
            this.popup = null;
        }

        /** @type {boolean} Признак режима изменения существующего автомобиля. */
        const isEdit = car && (parseInt(car.id, 10) || 0) > 0;

        /** @type {HTMLDivElement} Безопасно собранное DOM-содержимое формы. */
        const content = BX.create('div', {
            props: {className: 'otus-autoservice-garage-form'},
        });

        this.formFields = {};
        content.appendChild(this.createField('MAKE', this.messages.make, car ? car.make : '', 'text', true));
        content.appendChild(this.createField('MODEL', this.messages.model, car ? car.model : '', 'text', true));
        content.appendChild(this.createField('LICENSE_PLATE', this.messages.licensePlate, car ? car.licensePlate : '', 'text', true));
        content.appendChild(this.createField('YEAR', this.messages.year, car && car.year !== null ? car.year : '', 'number', false));
        content.appendChild(this.createField('COLOR', this.messages.color, car ? car.color : '', 'text', false));
        content.appendChild(this.createField('MILEAGE', this.messages.mileage, car ? car.mileage : 0, 'number', false));

        /** @type {Garage} Ссылка для обработчиков кнопок совместимого PopupWindow. */
        const self = this;

        this.popup = new BX.PopupWindow(
            'otus-autoservice-garage-form-' + this.contactId,
            null,
            {
                titleBar: String(isEdit ? this.messages.formEditTitle : this.messages.formCreateTitle),
                content: content,
                closeIcon: true,
                closeByEsc: true,
                overlay: true,
                autoHide: false,
                buttons: [
                    new BX.PopupWindowButton({
                        text: String(this.messages.save || ''),
                        className: 'ui-btn ui-btn-success',
                        events: {
                            click: function () {
                                self.save(isEdit ? parseInt(car.id, 10) : 0, this);
                            },
                        },
                    }),
                    new BX.PopupWindowButtonLink({
                        text: String(this.messages.cancel || ''),
                        events: {
                            click: function () {
                                self.popup.close();
                            },
                        },
                    }),
                ],
            }
        );
        this.popup.show();
    };

    /**
     * Создаёт одно поле формы исключительно DOM-методами без вставки пользовательского HTML.
     *
     * @param {string} name Машинное имя поля ORM.
     * @param {string} label Локализованная подпись.
     * @param {string|number} value Текущее значение.
     * @param {string} type Тип HTML-input.
     * @param {boolean} required Обязательность заполнения.
     * @returns {HTMLDivElement}
     */
    Garage.prototype.createField = function (name, label, value, type, required)
    {
        const input = BX.create('input', {
            props: {
                className: 'ui-ctl-element',
                type: type,
                value: value === null || typeof value === 'undefined' ? '' : String(value),
            },
            attrs: {
                name: name,
                required: required ? 'required' : null,
                min: type === 'number' ? '0' : null,
                step: type === 'number' ? '1' : null,
            },
        });
        this.formFields[name] = input;

        return BX.create('div', {
            props: {className: 'otus-autoservice-garage-form__field'},
            children: [
                BX.create('label', {
                    props: {className: 'otus-autoservice-garage-form__label'},
                    text: String(label || '') + (required ? ' *' : ''),
                }),
                BX.create('div', {
                    props: {className: 'ui-ctl ui-ctl-textbox ui-ctl-w100'},
                    children: [input],
                }),
            ],
        });
    };

    /**
     * Проверяет форму и вызывает действие создания или изменения.
     *
     * @param {number} carId Нулевой ID для создания либо существующий ID для изменения.
     * @param {BX.PopupWindowButton} button Нажатая кнопка сохранения.
     */
    Garage.prototype.save = function (carId, button)
    {
        const fields = this.collectFields();
        if (!fields)
        {
            return;
        }

        this.setButtonWaiting(button, true);

        /** @type {string} Имя серверного действия по режиму формы. */
        const action = carId > 0 ? 'update' : 'create';

        /** @type {Object} Параметры защищённой AJAX-команды. */
        const data = {
            contactId: this.contactId,
            fields: fields,
        };
        if (carId > 0)
        {
            data.id = carId;
        }

        this.runAction(action, data).then(function (response) {
            this.setButtonWaiting(button, false);
            this.popup.close();
            this.notify(response.data && response.data.message);
            this.reloadGrid();
        }.bind(this)).catch(function (response) {
            this.setButtonWaiting(button, false);
            this.showRequestError(response);
        }.bind(this));
    };

    /**
     * Собирает и валидирует пользовательские значения до серверной проверки.
     *
     * @returns {?Object<string, string|number|null>} Поля ORM либо null при ошибке.
     */
    Garage.prototype.collectFields = function ()
    {
        const make = String(this.formFields.MAKE.value || '').trim();
        const model = String(this.formFields.MODEL.value || '').trim();
        const licensePlate = String(this.formFields.LICENSE_PLATE.value || '').trim();

        if (!make || !model || !licensePlate)
        {
            this.notify(this.messages.requiredFields, true);
            return null;
        }

        const yearText = String(this.formFields.YEAR.value || '').trim();
        const year = yearText === '' ? null : Number(yearText);
        const maximumYear = new Date().getFullYear() + 1;
        if (
            year !== null
            && (!/^\d+$/.test(yearText) || !Number.isInteger(year) || year < 1886 || year > maximumYear)
        )
        {
            this.notify(this.messages.invalidYear, true);
            return null;
        }

        const mileageText = String(this.formFields.MILEAGE.value || '').trim();
        const mileage = mileageText === '' ? 0 : Number(mileageText);
        if (!Number.isInteger(mileage) || mileage < 0)
        {
            this.notify(this.messages.invalidMileage, true);
            return null;
        }

        return {
            MAKE: make,
            MODEL: model,
            LICENSE_PLATE: licensePlate,
            YEAR: year,
            COLOR: String(this.formFields.COLOR.value || '').trim(),
            MILEAGE: mileage,
        };
    };

    /**
     * Вызывает модульный D7-контроллер; BX.ajax автоматически передаёт sessid.
     *
     * @param {string} action Короткое имя действия контроллера.
     * @param {Object} data Параметры действия.
     * @returns {Promise}
     */
    Garage.prototype.runAction = function (action, data)
    {
        if (action !== 'get')
        {
            data.originId = this.instanceId;
        }

        return BX.ajax.runAction(
            String(this.options.actionPrefix) + '.' + action,
            {data: data}
        );
    };

    /**
     * Перезагружает только GRID текущего контакта.
     */
    Garage.prototype.reloadGrid = function ()
    {
        if (BX.Main && BX.Main.gridManager)
        {
            BX.Main.gridManager.reload(this.gridId);
        }
    };

    /**
     * Показывает первый безопасный текст ошибки AJAX либо общее сообщение.
     *
     * @param {Object} response Отклонённый ответ BX.ajax.runAction.
     */
    Garage.prototype.showRequestError = function (response)
    {
        const errors = response && Array.isArray(response.errors) ? response.errors : [];
        const message = errors.length > 0 && errors[0].message
            ? errors[0].message
            : this.messages.requestFailed;

        this.notify(message, true);
    };

    /**
     * Выводит стандартное уведомление Bitrix с безопасным текстовым содержимым.
     *
     * @param {string} message Пользовательское сообщение.
     * @param {boolean} error Признак сообщения об ошибке.
     */
    Garage.prototype.notify = function (message, error)
    {
        const safeMessage = String(message || this.messages.requestFailed || '');
        if (BX.UI && BX.UI.Notification && BX.UI.Notification.Center)
        {
            BX.UI.Notification.Center.notify({
                content: BX.create('span', {text: safeMessage}),
                autoHideDelay: error ? 7000 : 3000,
            });
            return;
        }

        window.alert(safeMessage);
    };

    /**
     * Блокирует или разблокирует кнопку формы на время AJAX-запроса.
     *
     * @param {BX.PopupWindowButton} button Кнопка совместимого PopupWindow.
     * @param {boolean} waiting Состояние ожидания.
     */
    Garage.prototype.setButtonWaiting = function (button, waiting)
    {
        if (!button || !button.buttonNode)
        {
            return;
        }

        button.buttonNode.style.pointerEvents = waiting ? 'none' : '';
        if (waiting)
        {
            BX.addClass(button.buttonNode, 'ui-btn-wait');
        }
        else
        {
            BX.removeClass(button.buttonNode, 'ui-btn-wait');
        }
    };

    BX.Otus.Autoservice.Garage = Garage;
})(BX);
