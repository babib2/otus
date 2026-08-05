<?php

/**
 * Устанавливает и удаляет модуль, проверяет зависимости и запускает миграции.
 */

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Otus\Autoservice\EventHandler\EventRegistry;
use Otus\Autoservice\Migration\MigrationManager;
use Otus\Autoservice\Service\ModuleRequirements;

Loc::loadMessages(__FILE__);

// Регистрирует классы миграций и ORM до первого InstallDB() нового модуля.
require_once dirname(__DIR__) . '/include.php';
require_once dirname(__DIR__) . '/lib/EventHandler/EventRegistry.php';
require_once dirname(__DIR__) . '/lib/Migration/MigrationInterface.php';
require_once dirname(__DIR__) . '/lib/Migration/MigrationManager.php';
require_once dirname(__DIR__) . '/lib/Service/ModuleRequirements.php';

/**
 * Установщик модуля автосервиса OTUS.
 *
 * Класс вызывается штатной страницей управления модулями Bitrix. Он проверяет
 * окружение, регистрирует модуль, устанавливает файлы, события и миграции,
 * а при удалении позволяет администратору сохранить прикладные данные.
 */
class otus_autoservice extends CModule
{
    /** @var string Системный идентификатор модуля в реестре Bitrix. */
    public $MODULE_ID = 'otus.autoservice';

    /** @var string Версия модуля, прочитанная из install/version.php. */
    public $MODULE_VERSION;

    /** @var string Дата выпуска текущей версии модуля. */
    public $MODULE_VERSION_DATE;

    /** @var string Локализованное название для административного интерфейса. */
    public $MODULE_NAME;

    /** @var string Локализованное описание назначения модуля. */
    public $MODULE_DESCRIPTION;

    /** @var string Название разработчика или поставщика решения. */
    public $PARTNER_NAME;

    /** @var string Адрес сайта разработчика или поставщика решения. */
    public $PARTNER_URI;

    /** @var string Флаг поддержки стандартных групповых прав Bitrix: `Y` или `N`. */
    public $MODULE_GROUP_RIGHTS = 'Y';

    /**
     * Инициализирует метаданные модуля.
     *
     * Версия загружается из отдельного файла, а отображаемые тексты — из русской
     * локализации установщика. Запасные значения защищают список модулей от ошибок
     * при повреждённом или неполном файле версии.
     */
    public function __construct()
    {
        /**
         * @var array{VERSION?: string, VERSION_DATE?: string} $arModuleVersion
         * Метаданные, которые заполняет подключаемый install/version.php.
         */
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = isset($arModuleVersion['VERSION'])
            ? (string)$arModuleVersion['VERSION']
            : '0.1.0';
        $this->MODULE_VERSION_DATE = isset($arModuleVersion['VERSION_DATE'])
            ? (string)$arModuleVersion['VERSION_DATE']
            : '';
        $this->MODULE_NAME = (string)Loc::getMessage('OTUS_AUTOSERVICE_MODULE_NAME');
        $this->MODULE_DESCRIPTION = (string)Loc::getMessage('OTUS_AUTOSERVICE_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = (string)Loc::getMessage('OTUS_AUTOSERVICE_PARTNER_NAME');
        $this->PARTNER_URI = 'https://otus.ru/';
    }

    /**
     * Устанавливает модуль и его инфраструктуру.
     *
     * Сначала выполняется проверка окружения. После регистрации модуля по очереди
     * устанавливаются административные файлы, события и миграции. Любое исключение
     * запускает компенсирующий откат уже выполненных операций.
     *
     * @return bool true при успешной установке, false при ошибке требований или шага.
     */
    public function DoInstall()
    {
        /** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
        global $APPLICATION;

        /** @var string[] $requirementErrors Локализованные ошибки текущего окружения. */
        $requirementErrors = $this->getRequirementErrors();
        if ($requirementErrors !== []) {
            $APPLICATION->ThrowException(implode('<br>', $requirementErrors));

            return false;
        }

        try {
            // Регистрация выполняется первой, чтобы настройки и события получили владельца.
            ModuleManager::registerModule($this->MODULE_ID);

            // Каждый шаг возвращает bool или выбрасывает исключение при технической ошибке.
            if (!$this->InstallFiles() || !$this->InstallEvents() || !$this->InstallDB()) {
                throw new RuntimeException(
                    (string)Loc::getMessage('OTUS_AUTOSERVICE_INSTALLATION_FAILED')
                );
            }
        } catch (Throwable $exception) {
            /** @var Throwable $exception Первичная ошибка установки, показываемая администратору. */
            $this->rollbackInstallation();
            $APPLICATION->ThrowException($exception->getMessage());

            return false;
        }

        $APPLICATION->IncludeAdminFile(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_INSTALL_TITLE'),
            __DIR__ . '/step.php'
        );

        return true;
    }

    /**
     * Запрашивает параметры и удаляет модуль.
     *
     * На первом шаге отображает форму с включённым сохранением данных. На втором
     * проверяет сессию, удаляет события и файлы, при необходимости откатывает
     * миграции и только затем исключает модуль из реестра Bitrix.
     *
     * @return bool true после показа формы или успешного удаления, false при ошибке сессии.
     */
    public function DoUninstall()
    {
        /** @var CMain $APPLICATION Глобальный объект административного приложения Bitrix. */
        global $APPLICATION;

        /** @var \Bitrix\Main\HttpRequest $request Текущий запрос мастера удаления модуля. */
        $request = Application::getInstance()->getContext()->getRequest();
        if ((int)$request->get('step') < 2) {
            $APPLICATION->IncludeAdminFile(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_UNINSTALL_TITLE'),
                __DIR__ . '/unstep1.php'
            );

            return true;
        }

        if (!check_bitrix_sessid()) {
            $APPLICATION->ThrowException(
                (string)Loc::getMessage('OTUS_AUTOSERVICE_INVALID_SESSION')
            );

            return false;
        }

        /** @var bool $saveData Нужно ли оставить настройки и прикладные данные после удаления. */
        $saveData = $request->getPost('save_data') === 'Y';

        $this->UnInstallEvents(); // Сначала прекращаем вызов кода удаляемого модуля.
        $this->UnInstallFiles();  // Затем убираем административные точки входа.
        $this->UnInstallDB(['save_data' => $saveData ? 'Y' : 'N']); // Данные — по выбору.

        if (!$saveData) {
            Option::delete($this->MODULE_ID);
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            (string)Loc::getMessage('OTUS_AUTOSERVICE_UNINSTALL_TITLE'),
            __DIR__ . '/unstep2.php'
        );

        return true;
    }

    /**
     * Копирует административные точки входа модуля.
     *
     * Исходники остаются в local/modules, а в /bitrix/admin помещаются только
     * короткие прокси-файлы, необходимые стандартному административному роутингу.
     *
     * @return bool Результат штатной операции CopyDirFiles().
     */
    public function InstallFiles()
    {
        return CopyDirFiles(
            __DIR__ . '/admin',
            Application::getDocumentRoot() . '/bitrix/admin',
            true,
            true
        );
    }

    /**
     * Удаляет только файлы, скопированные установщиком.
     *
     * DeleteDirFiles() сопоставляет исходный и целевой каталоги, поэтому соседние
     * административные файлы других модулей не затрагиваются.
     *
     * @return bool Всегда true после завершения штатной операции удаления.
     */
    public function UnInstallFiles()
    {
        DeleteDirFiles(
            __DIR__ . '/admin',
            Application::getDocumentRoot() . '/bitrix/admin'
        );

        return true;
    }

    /**
     * Регистрирует обработчики событий модуля через единый EventRegistry.
     *
     * @return bool Всегда true, если реестр не выбросил исключение.
     */
    public function InstallEvents()
    {
        EventRegistry::install();

        return true;
    }

    /**
     * Удаляет зарегистрированные обработчики событий модуля.
     *
     * @return bool Всегда true, если реестр не выбросил исключение.
     */
    public function UnInstallEvents()
    {
        EventRegistry::uninstall();

        return true;
    }

    /**
     * Применяет новые версионируемые миграции схемы данных.
     *
     * @return bool Всегда true, если менеджер миграций завершился без исключения.
     */
    public function InstallDB()
    {
        MigrationManager::migrate();

        return true;
    }

    /**
     * Удаляет схему данных только при явном отказе от её сохранения.
     *
     * @param array<string, string> $arParams Параметры удаления; ключ `save_data`
     *        содержит `Y` для сохранения данных или `N` для полного отката.
     *
     * @return bool Всегда true, если откат миграций завершился без исключения.
     */
    public function UnInstallDB($arParams = [])
    {
        if (($arParams['save_data'] ?? 'Y') !== 'Y') {
            MigrationManager::rollbackAll();
        }

        return true;
    }

    /**
     * Возвращает локализованные ошибки требований окружения.
     *
     * @return string[]
     */
    private function getRequirementErrors(): array
    {
        /** @var string[] $errors Сообщения, блокирующие регистрацию модуля. */
        $errors = [];

        if (!ModuleRequirements::isPhpVersionSupported()) {
            $errors[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_PHP_VERSION_ERROR',
                [
                    '#CURRENT#' => PHP_VERSION,
                    '#REQUIRED#' => ModuleRequirements::MINIMUM_PHP_VERSION,
                ]
            );
        }

        /** @var string[] $missingModules Обязательные модули, не зарегистрированные в Bitrix. */
        $missingModules = ModuleRequirements::getMissingModules();
        if ($missingModules !== []) {
            $errors[] = (string)Loc::getMessage(
                'OTUS_AUTOSERVICE_DEPENDENCIES_ERROR',
                ['#MODULES#' => implode(', ', $missingModules)]
            );
        }

        return $errors;
    }

    /**
     * Откатывает частично выполненную установку.
     *
     * Метод вызывается только при ошибке DoInstall(). Он удаляет созданные текущей
     * попыткой данные, события и файлы, после чего снимает регистрацию модуля.
     */
    private function rollbackInstallation(): void
    {
        if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
            return;
        }

        // Порядок обратен логической установке и оставляет систему без «висячих» записей.
        $this->UnInstallDB(['save_data' => 'N']);
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }
}
