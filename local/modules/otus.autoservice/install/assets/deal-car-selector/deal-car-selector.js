/**
 * Заменяет ввод числового ID автомобиля на селектор Bitrix и оформляет сохранённое значение.
 *
 * Исходное поле `UF_CRM_OTUS_CAR_ID` не удаляется: оно остаётся единственным
 * источником значения для штатного CRM-редактора и серверной валидации модуля.
 */

;(function(BX, window, document)
{
    'use strict';

    /** Системное имя пользовательского поля автомобиля в CRM-сделке. */
    const FIELD_NAME = 'UF_CRM_OTUS_CAR_ID';

    /** Идентификатор серверной сущности из `.settings.php` модуля. */
    const ENTITY_ID = 'otus_autoservice_car';

    /** Контекст изолирует недавние элементы селектора от других интерфейсов Bitrix. */
    const SELECTOR_CONTEXT = 'OTUS_AUTOSERVICE_DEAL_CAR';

    /** Интервал контроля смены основного контакта и перерисовки CRM-поля. */
    const REFRESH_INTERVAL_MS = 750;

    /** Пауза перед повторной фоновой загрузкой подписи после сетевой ошибки. */
    const VIEW_LOAD_RETRY_MS = 5000;

    /** Активные декораторы полей; отсоединённые DOM-узлы удаляются при очередной проверке. */
    let selectors = [];

    /** Отображения сохранённого автомобиля в режиме просмотра CRM-карточки. */
    let viewDisplays = [];

    /** Наблюдатель находит поле после динамического переключения CRM между просмотром и редактированием. */
    let mutationObserver = null;

    /**
     * Возвращает локализованное сообщение Bitrix с безопасной русской заменой.
     *
     * @param {string} code Код сообщения из PHP-обработчика OnProlog.
     * @param {string} fallback Резервный текст, если локализация ещё не загружена.
     * @returns {string}
     */
    function getMessage(code, fallback)
    {
        const message = BX.message(code);

        return typeof message === 'string' && message !== '' ? message : fallback;
    }

    /**
     * Получает активный редактор CRM текущей карточки.
     *
     * @returns {object|null}
     */
    function getEditor()
    {
        if (!BX.Crm || !BX.Crm.EntityEditor || typeof BX.Crm.EntityEditor.getDefault !== 'function')
        {
            return null;
        }

        return BX.Crm.EntityEditor.getDefault();
    }

    /**
     * Определяет основной контакт из актуального клиентского поля CRM.
     *
     * Сначала используются публичные методы полного CLIENT-контрола. Для облегчённой
     * карточки предусмотрен совместимый резерв через коллекцию `_contactInfos`,
     * после чего проверяются значения модели сделки.
     *
     * @returns {number} Положительный ID контакта либо 0.
     */
    function resolvePrimaryContactId()
    {
        const editor = getEditor();
        if (!editor)
        {
            return 0;
        }

        const clientControl = typeof editor.getControlById === 'function'
            ? editor.getControlById('CLIENT')
            : null;

        if (clientControl)
        {
            if (
                typeof clientControl.getPrimaryEntityTypeName === 'function'
                && typeof clientControl.getPrimaryEntityId === 'function'
            )
            {
                const primaryType = String(clientControl.getPrimaryEntityTypeName() || '').toLowerCase();
                if (primaryType === 'contact')
                {
                    return Math.max(0, Number.parseInt(clientControl.getPrimaryEntityId(), 10) || 0);
                }

                if (
                    typeof clientControl.getSecondaryEntityTypeName === 'function'
                    && String(clientControl.getSecondaryEntityTypeName() || '').toLowerCase() === 'contact'
                )
                {
                    let contacts = null;
                    if (typeof clientControl.getAllSecondaryEntityInfos === 'function')
                    {
                        contacts = clientControl.getAllSecondaryEntityInfos();
                    }
                    else if (typeof clientControl.getSecondaryEntities === 'function')
                    {
                        contacts = clientControl.getSecondaryEntities();
                    }

                    if (Array.isArray(contacts))
                    {
                        const firstContact = contacts.find(function(contact)
                        {
                            return contact && typeof contact.getId === 'function' && contact.getId() > 0;
                        });

                        return firstContact
                            ? Math.max(0, Number.parseInt(firstContact.getId(), 10) || 0)
                            : 0;
                    }
                }
            }

            if (
                clientControl._contactInfos
                && typeof clientControl._contactInfos.length === 'function'
                && clientControl._contactInfos.length() > 0
                && typeof clientControl._contactInfos.get === 'function'
            )
            {
                const contact = clientControl._contactInfos.get(0);
                if (contact && typeof contact.getId === 'function')
                {
                    return Math.max(0, Number.parseInt(contact.getId(), 10) || 0);
                }
            }
        }

        if (typeof editor.getModel !== 'function')
        {
            return 0;
        }

        const model = editor.getModel();
        if (!model || typeof model.getField !== 'function')
        {
            return 0;
        }

        const contactId = Number.parseInt(model.getField('CONTACT_ID', 0), 10) || 0;
        if (contactId > 0)
        {
            return contactId;
        }

        const contactIds = model.getField('CONTACT_IDS', []);

        return Array.isArray(contactIds) && contactIds.length > 0
            ? Math.max(0, Number.parseInt(contactIds[0], 10) || 0)
            : 0;
    }

    /**
     * Показывает уведомление штатным центром Bitrix или резервным alert().
     *
     * @param {string} content Текст уведомления без HTML.
     */
    function notify(content)
    {
        if (BX.UI && BX.UI.Notification && BX.UI.Notification.Center)
        {
            BX.UI.Notification.Center.notify({ content: BX.Text.encode(content) });

            return;
        }

        window.alert(content);
    }

    /**
     * Декорирует один реально отрисованный input пользовательского поля.
     *
     * @param {HTMLInputElement} input Штатный числовой input CRM.
     * @constructor
     */
    function DealCarSelector(input)
    {
        /** Штатный input, значение которого сохранит CRM. */
        this.input = input;

        /** Признак заблокированного штатного поля, права которого нельзя обходить интерфейсом. */
        this.readOnly = input.disabled || input.readOnly;

        /** Корневой DOM-контейнер нового интерфейса. */
        this.host = null;

        /** Текст выбранного автомобиля. */
        this.valueNode = null;

        /** Кнопка открытия UI Entity Selector. */
        this.chooseButton = null;

        /** Кнопка очистки сохранённого ID. */
        this.clearButton = null;

        /** Предупреждение о смене основного контакта. */
        this.warningNode = null;

        /** Последний известный контакт, использованный для построения диалога. */
        this.contactId = resolvePrimaryContactId();

        /** Контакт, для которого текущее значение автомобиля было выбрано или подтверждено. */
        this.valueContactId = this.getValue() > 0 ? this.contactId : 0;

        /** Текущий экземпляр диалога; уничтожается при смене контакта. */
        this.dialog = null;

        /** Защищает от повторного предупреждения при начальной загрузке контакта. */
        this.contactWasResolved = this.contactId > 0;

        this.render();
        this.resolveCurrentTitle();
    }

    DealCarSelector.prototype = {
        /** Создаёт интерфейс рядом со скрытым штатным input. */
        render: function()
        {
            this.input.classList.add('otus-autoservice-car-selector-native-input');
            this.input.dataset.otusAutoserviceCarSelector = 'Y';

            this.valueNode = BX.create('div', {
                props: { className: 'otus-autoservice-car-selector-value' },
            });
            this.chooseButton = BX.create('button', {
                props: {
                    className: 'otus-autoservice-car-selector-button',
                    type: 'button',
                    disabled: this.readOnly,
                },
                events: { click: this.open.bind(this) },
            });
            this.clearButton = BX.create('button', {
                props: {
                    className: 'otus-autoservice-car-selector-button otus-autoservice-car-selector-clear',
                    type: 'button',
                    disabled: this.readOnly,
                },
                text: getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CLEAR', 'Очистить'),
                events: { click: this.clear.bind(this) },
            });
            this.warningNode = BX.create('div', {
                props: { className: 'otus-autoservice-car-selector-warning' },
                style: { display: 'none' },
            });

            const actions = BX.create('div', {
                props: { className: 'otus-autoservice-car-selector-actions' },
                children: [this.chooseButton, this.clearButton],
            });
            this.host = BX.create('div', {
                props: { className: 'otus-autoservice-car-selector-host' },
                children: [this.valueNode, actions, this.warningNode],
            });

            this.input.parentNode.insertBefore(this.host, this.input.nextSibling);
            this.refreshText();
        },

        /** Возвращает нормализованное текущее значение штатного поля. */
        getValue: function()
        {
            return Math.max(0, Number.parseInt(this.input.value, 10) || 0);
        },

        /**
         * Записывает ID в штатный input и уведомляет CRM-контрол о пользовательском изменении.
         *
         * @param {number|string} value Новый ID либо пустая строка.
         * @param {string} title Человекочитаемая подпись выбранного автомобиля.
         */
        setValue: function(value, title)
        {
            if (this.readOnly)
            {
                return;
            }

            const normalizedValue = Math.max(0, Number.parseInt(value, 10) || 0);
            this.input.value = normalizedValue > 0 ? String(normalizedValue) : '';
            this.valueContactId = normalizedValue > 0 ? this.contactId : 0;
            this.input.dataset.otusAutoserviceCarTitle = title || '';
            this.input.dispatchEvent(new window.Event('input', { bubbles: true }));
            this.input.dispatchEvent(new window.Event('change', { bubbles: true }));
            this.warningNode.style.display = 'none';
            this.refreshText();
        },

        /** Обновляет подпись и видимость кнопок по текущему значению. */
        refreshText: function()
        {
            const value = this.getValue();
            const knownTitle = this.input.dataset.otusAutoserviceCarTitle || '';
            let title = getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_EMPTY', 'Автомобиль не выбран');

            if (value > 0)
            {
                title = knownTitle || getMessage(
                    'OTUS_AUTOSERVICE_CAR_SELECTOR_CURRENT_ID',
                    'Автомобиль №#ID#',
                ).replace('#ID#', String(value));
            }

            this.valueNode.textContent = title;
            this.chooseButton.textContent = value > 0
                ? getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CHANGE', 'Изменить')
                : getMessage('OTUS_AUTOSERVICE_CAR_SELECTOR_CHOOSE', 'Выбрать автомобиль');
            this.clearButton.style.display = value > 0 ? '' : 'none';
        },

        /** Создаёт диалог с параметрами актуального основного контакта. */
        createDialog: function(contactId)
        {
            const value = this.getValue();
            const shouldPreselect = value > 0 && this.valueContactId === contactId;

            return new BX.UI.EntitySelector.Dialog({
                targetNode: this.chooseButton,
                context: SELECTOR_CONTEXT,
                multiple: false,
                dropdownMode: true,
                enableSearch: true,
                width: 520,
                height: 330,
                entities: [
                    {
                        id: ENTITY_ID,
                        dynamicLoad: true,
                        dynamicSearch: false,
                        options: { contactId: contactId },
                    },
                ],
                preselectedItems: shouldPreselect ? [[ENTITY_ID, value]] : [],
                events: {
                    'Item:onSelect': function(event)
                    {
                        const item = event.getData().item;
                        this.setValue(item.getId(), item.getTitle());
                        event.getTarget().hide();
                    }.bind(this),
                    onLoad: function(event)
                    {
                        const dialog = event.getTarget();
                        const selectedItem = shouldPreselect
                            ? dialog.getItem({ entityId: ENTITY_ID, id: value })
                            : null;
                        if (
                            selectedItem
                            && !(typeof selectedItem.isHidden === 'function' && selectedItem.isHidden())
                        )
                        {
                            this.valueContactId = contactId;
                            this.input.dataset.otusAutoserviceCarTitle = selectedItem.getTitle();
                            this.refreshText();

                            return;
                        }

                        if (shouldPreselect)
                        {
                            this.valueContactId = 0;

                            window.setTimeout(function()
                            {
                                if (this.dialog === dialog)
                                {
                                    this.destroyDialog();
                                }
                            }.bind(this), 0);
                        }
                    }.bind(this),
                    onLoadError: function()
                    {
                        notify(getMessage(
                            'OTUS_AUTOSERVICE_CAR_SELECTOR_LOAD_ERROR',
                            'Не удалось загрузить автомобили контакта.',
                        ));
                    },
                },
            });
        },

        /** Открывает список автомобилей только после выбора основного контакта. */
        open: function()
        {
            if (this.readOnly)
            {
                return;
            }

            const contactId = resolvePrimaryContactId();
            if (contactId <= 0)
            {
                notify(getMessage(
                    'OTUS_AUTOSERVICE_CAR_SELECTOR_NO_CONTACT',
                    'Сначала выберите основной контакт сделки.',
                ));

                return;
            }

            if (!this.dialog || this.contactId !== contactId)
            {
                this.destroyDialog();
                this.contactId = contactId;
                this.dialog = this.createDialog(contactId);
            }

            this.dialog.show();
        },

        /** Очищает автомобиль, сохраняя выбранный контакт сделки. */
        clear: function()
        {
            if (this.readOnly)
            {
                return;
            }

            this.destroyDialog();
            this.setValue('', '');
            this.warningNode.style.display = 'none';
        },

        /**
         * Обновляет состояние при смене основного контакта без автоматической потери данных.
         *
         * Сервер всё равно не позволит сохранить автомобиль прежнего контакта.
         * Пользователю показывается предупреждение и при открытии строится новый диалог.
         *
         * @param {number} contactId Текущий контакт из CLIENT-поля CRM.
         */
        handleContactChange: function(contactId)
        {
            if (contactId === this.contactId)
            {
                return;
            }

            const previousContactId = this.contactId;
            const contactWasResolved = this.contactWasResolved;
            this.contactId = contactId;
            this.destroyDialog();

            if (
                this.contactWasResolved
                && previousContactId > 0
                && this.getValue() > 0
            )
            {
                this.warningNode.textContent = getMessage(
                    'OTUS_AUTOSERVICE_CAR_SELECTOR_CONTACT_CHANGED',
                    'Основной контакт изменён. Проверьте выбранный автомобиль.',
                );
                this.warningNode.style.display = '';
            }

            if (contactId > 0)
            {
                this.contactWasResolved = true;

                if (!contactWasResolved && this.getValue() > 0)
                {
                    this.valueContactId = contactId;
                    this.resolveCurrentTitle();
                }
            }
        },

        /** Фоново восстанавливает название уже сохранённого автомобиля по его ID. */
        resolveCurrentTitle: function()
        {
            const value = this.getValue();
            if (value <= 0 || this.contactId <= 0)
            {
                return;
            }

            this.dialog = this.createDialog(this.contactId);
            this.dialog.load();
        },

        /** Освобождает старый диалог и его обработчики. */
        destroyDialog: function()
        {
            if (this.dialog && typeof this.dialog.destroy === 'function')
            {
                this.dialog.destroy();
            }

            this.dialog = null;
        },

        /** Освобождает DOM-ссылки декоратора удалённого CRM-контрола. */
        destroy: function()
        {
            this.destroyDialog();
            this.input = null;
            this.host = null;
            this.valueNode = null;
            this.chooseButton = null;
            this.clearButton = null;
            this.warningNode = null;
        },
    };

    /**
     * Заменяет числовое значение поля человекочитаемым названием в режиме просмотра сделки.
     *
     * @param {object|null} control Штатный контрол числового пользовательского поля Bitrix.
     * @param {HTMLElement} valueNode DOM-узел, в котором штатно отображается числовой ID.
     * @constructor
     */
    function DealCarViewDisplay(control, valueNode)
    {
        /** Штатный контрол нужен для чтения значения независимо от показанного текста. */
        this.control = control;

        /** Узел просмотра, содержимое которого заменяется названием автомобиля. */
        this.valueNode = valueNode;

        /** Последний ID автомобиля, для которого был запущен запрос провайдера. */
        this.carId = this.getValue();

        /** Последний ID контакта, использованный при безопасном восстановлении автомобиля. */
        this.contactId = 0;

        /** Служебный диалог выполняет стандартную серверную загрузку элемента без показа popup. */
        this.dialog = null;

        /** Момент времени, до которого повторный запрос после ошибки откладывается. */
        this.retryAfter = 0;

        this.valueNode.dataset.otusAutoserviceCarView = 'Y';
        this.refresh(resolvePrimaryContactId());
    }

    DealCarViewDisplay.prototype = {
        /** Возвращает ID автомобиля из CRM-контрола, модели или исходного числового текста. */
        getValue: function()
        {
            if (this.control && typeof this.control.getValue === 'function')
            {
                return Math.max(0, Number.parseInt(this.control.getValue(), 10) || 0);
            }

            const editor = getEditor();
            if (editor && typeof editor.getModel === 'function')
            {
                const model = editor.getModel();
                if (model && typeof model.getField === 'function')
                {
                    const modelValue = Number.parseInt(model.getField(FIELD_NAME, 0), 10) || 0;
                    if (modelValue > 0)
                    {
                        return modelValue;
                    }
                }
            }

            if (
                this.carId > 0
                && this.valueNode
                && this.valueNode.dataset.otusAutoserviceCarResolved === 'Y'
            )
            {
                return this.carId;
            }

            return this.valueNode
                ? Math.max(0, Number.parseInt(this.valueNode.textContent, 10) || 0)
                : this.carId;
        },

        /** Перезапрашивает подпись, если CRM сменила контакт или значение поля. */
        refresh: function(contactId)
        {
            const carId = this.getValue();
            const contextUnchanged = carId === this.carId && contactId === this.contactId;
            if (contextUnchanged && this.dialog)
            {
                return;
            }

            if (contextUnchanged && this.retryAfter > Date.now())
            {
                return;
            }

            this.destroyDialog();
            this.carId = carId;
            this.contactId = contactId;
            this.retryAfter = 0;

            if (carId <= 0 || contactId <= 0)
            {
                return;
            }

            this.loadTitle(carId, contactId);
        },

        /** Загружает один сохранённый автомобиль через тот же защищённый серверный провайдер. */
        loadTitle: function(carId, contactId)
        {
            this.dialog = new BX.UI.EntitySelector.Dialog({
                context: SELECTOR_CONTEXT,
                multiple: false,
                entities: [
                    {
                        id: ENTITY_ID,
                        dynamicLoad: true,
                        dynamicSearch: false,
                        options: { contactId: contactId },
                    },
                ],
                preselectedItems: [[ENTITY_ID, carId]],
                events: {
                    onLoad: function(event)
                    {
                        if (
                            !this.valueNode
                            || !document.documentElement.contains(this.valueNode)
                            || this.carId !== carId
                            || this.contactId !== contactId
                        )
                        {
                            return;
                        }

                        const item = event.getTarget().getItem({ entityId: ENTITY_ID, id: carId });
                        if (!item || (typeof item.isHidden === 'function' && item.isHidden()))
                        {
                            return;
                        }

                        const title = item.getTitle();
                        const subtitle = typeof item.getSubtitle === 'function' ? item.getSubtitle() : '';
                        this.retryAfter = 0;
                        this.valueNode.dataset.otusAutoserviceCarResolved = 'Y';
                        this.valueNode.textContent = '';
                        this.valueNode.appendChild(BX.create('div', {
                            props: { className: 'otus-autoservice-car-view-title' },
                            text: title,
                        }));

                        if (subtitle)
                        {
                            this.valueNode.appendChild(BX.create('div', {
                                props: { className: 'otus-autoservice-car-view-subtitle' },
                                text: subtitle,
                            }));
                        }
                    }.bind(this),
                    onLoadError: function(event)
                    {
                        if (this.dialog !== event.getTarget())
                        {
                            return;
                        }

                        this.retryAfter = Date.now() + VIEW_LOAD_RETRY_MS;
                        this.destroyDialog();
                    }.bind(this),
                },
            });
            this.dialog.load();
        },

        /** Освобождает служебный диалог после перерисовки CRM-контрола. */
        destroyDialog: function()
        {
            if (this.dialog && typeof this.dialog.destroy === 'function')
            {
                this.dialog.destroy();
            }

            this.dialog = null;
        },

        /** Освобождает ссылки на удалённый режим просмотра поля. */
        destroy: function()
        {
            this.destroyDialog();
            this.control = null;
            this.valueNode = null;
        },
    };

    /** Находит новые экземпляры штатного input и добавляет к ним селектор. */
    function decorateInputs()
    {
        const inputs = document.querySelectorAll('input[name="' + FIELD_NAME + '"]');
        inputs.forEach(function(input)
        {
            if (input.dataset.otusAutoserviceCarSelector === 'Y')
            {
                return;
            }

            selectors.push(new DealCarSelector(input));
        });
    }

    /** Находит штатное числовое представление поля и заменяет его подписью автомобиля. */
    function decorateViewFields()
    {
        const editor = getEditor();
        const control = editor && typeof editor.getControlById === 'function'
            ? editor.getControlById(FIELD_NAME)
            : null;
        const wrappers = document.querySelectorAll('[data-cid]');

        wrappers.forEach(function(wrapper)
        {
            if (String(wrapper.getAttribute('data-cid') || '').toUpperCase() !== FIELD_NAME)
            {
                return;
            }

            if (wrapper.querySelector('input[name="' + FIELD_NAME + '"]'))
            {
                return;
            }

            const valueNode = wrapper.querySelector('.ui-entity-editor-content-block-number');
            if (!valueNode || valueNode.dataset.otusAutoserviceCarView === 'Y')
            {
                return;
            }

            viewDisplays.push(new DealCarViewDisplay(control, valueNode));
        });
    }

    /** Запускает оба представления пользовательского поля для текущего режима CRM-карточки. */
    function decorateFields()
    {
        decorateInputs();
        decorateViewFields();
    }

    /** Удаляет отключённые декораторы и отслеживает актуальный основной контакт. */
    function refreshSelectors()
    {
        const contactId = resolvePrimaryContactId();
        selectors = selectors.filter(function(selector)
        {
            if (!selector.input || !document.documentElement.contains(selector.input))
            {
                selector.destroy();

                return false;
            }

            selector.handleContactChange(contactId);

            return true;
        });

        viewDisplays = viewDisplays.filter(function(viewDisplay)
        {
            if (!viewDisplay.valueNode || !document.documentElement.contains(viewDisplay.valueNode))
            {
                viewDisplay.destroy();

                return false;
            }

            viewDisplay.refresh(contactId);

            return true;
        });

        decorateFields();
    }

    /** Запускает декорирование после готовности DOM и следит за динамической CRM-разметкой. */
    BX.ready(function()
    {
        decorateFields();

        mutationObserver = new window.MutationObserver(function()
        {
            decorateFields();
        });
        mutationObserver.observe(document.body, { childList: true, subtree: true });

        window.setInterval(refreshSelectors, REFRESH_INTERVAL_MS);
    });
})(window.BX, window, document);
