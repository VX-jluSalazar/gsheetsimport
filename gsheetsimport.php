<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class GsheetsImport extends Module
{
    public const CONFIG_SPREADSHEET_ID = 'GSHEETSIMPORT_SPREADSHEET_ID';
    public const CONFIG_PRODUCTS_SHEET_NAME = 'GSHEETSIMPORT_PRODUCTS_SHEET_NAME';
    public const CONFIG_RANGE = 'GSHEETSIMPORT_RANGE';
    public const CONFIG_CRON_TOKEN = 'GSHEETSIMPORT_CRON_TOKEN';

    public function __construct()
    {
        $this->loadClasses();

        $this->name = 'gsheetsimport';
        $this->tab = 'administration';
        $this->version = '1.1.1';
        $this->author = 'Andrés Nacimiento';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Google Sheets Import', [], 'Modules.Gsheetsimport.Admin');
        $this->description = $this->trans('Imports products from Google Sheets using a staging table.', [], 'Modules.Gsheetsimport.Admin');
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => _PS_VERSION_];
    }

    private function loadClasses(): void
    {
        require_once __DIR__ . '/classes/Repository/SyncRepository.php';
        require_once __DIR__ . '/classes/Service/GoogleJwtAuthService.php';
        require_once __DIR__ . '/classes/Service/GoogleSheetsRestService.php';
        require_once __DIR__ . '/classes/Service/StagingSyncService.php';
        require_once __DIR__ . '/classes/Service/ProductSyncService.php';
        require_once __DIR__ . '/classes/Service/ProductSheetExportService.php';
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDatabase()
            && $this->installAdminTab()
            && Configuration::updateValue(self::CONFIG_RANGE, 'A2:P')
            && Configuration::updateValue(self::CONFIG_PRODUCTS_SHEET_NAME, 'Productos')
            && Configuration::updateValue(self::CONFIG_CRON_TOKEN, $this->generateCronToken())
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall(): bool
    {
        return $this->uninstallAdminTab()
            && $this->uninstallDatabase()
            && Configuration::deleteByName(self::CONFIG_SPREADSHEET_ID)
            && Configuration::deleteByName(self::CONFIG_PRODUCTS_SHEET_NAME)
            && Configuration::deleteByName(self::CONFIG_RANGE)
            && Configuration::deleteByName(self::CONFIG_CRON_TOKEN)
            && parent::uninstall();
    }

    public function hookDisplayBackOfficeHeader(): void
    {
        if (Tools::getValue('configure') !== $this->name) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
    }

    public function getContent(): string
    {
        $this->createProductSyncTable();
        $this->migrateLegacyConfig();
        $this->ensureCronToken();

        $output = '';

        if (Tools::isSubmit('submitGsheetsImportConfig')) {
            $output .= $this->postProcessConfiguration();
        }

        $repository = new \GSheetsImport\Repository\SyncRepository();

        $this->context->smarty->assign([
            'form_html' => $this->renderConfigurationForm(),
            'ajax_url' => $this->context->link->getAdminLink('AdminGsheetsImportAjax'),
            'fetch_label' => $this->trans('Synchronize products from Sheet', [], 'Modules.Gsheetsimport.Admin'),
            'process_label' => $this->trans('Create/Update products', [], 'Modules.Gsheetsimport.Admin'),
            'export_label' => $this->trans('Synchronize products to Sheet', [], 'Modules.Gsheetsimport.Admin'),
            'product_summary' => $repository->getSummary(\GSheetsImport\Repository\SyncRepository::DIRECTION_SHEETS_TO_PRESTASHOP),
            'product_rows' => $repository->getRowsForList('all', 500, \GSheetsImport\Repository\SyncRepository::DIRECTION_SHEETS_TO_PRESTASHOP),
            'product_errors' => $repository->getErrorRows(50, \GSheetsImport\Repository\SyncRepository::DIRECTION_SHEETS_TO_PRESTASHOP),
            'export_summary' => $repository->getSummary(\GSheetsImport\Repository\SyncRepository::DIRECTION_PRESTASHOP_TO_SHEETS),
            'cron_links' => $this->getCronLinks(),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    protected function renderConfigurationForm(): string
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Configuration', [], 'Modules.Gsheetsimport.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Spreadsheet ID', [], 'Modules.Gsheetsimport.Admin'),
                        'name' => self::CONFIG_SPREADSHEET_ID,
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Products sheet name', [], 'Modules.Gsheetsimport.Admin'),
                        'name' => self::CONFIG_PRODUCTS_SHEET_NAME,
                        'required' => true,
                    ],
                    [
                        'type' => 'file',
                        'label' => $this->trans('Service Account JSON', [], 'Modules.Gsheetsimport.Admin'),
                        'name' => 'GSHEETSIMPORT_SERVICE_ACCOUNT',
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'submitGsheetsImportConfig',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitGsheetsImportConfig';
        $helper->fields_value = [
            self::CONFIG_SPREADSHEET_ID => (string) Configuration::get(self::CONFIG_SPREADSHEET_ID),
            self::CONFIG_PRODUCTS_SHEET_NAME => (string) Configuration::get(self::CONFIG_PRODUCTS_SHEET_NAME),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function postProcessConfiguration(): string
    {
        $spreadsheetId = trim((string) Tools::getValue(self::CONFIG_SPREADSHEET_ID));
        $productsSheetName = trim((string) Tools::getValue(self::CONFIG_PRODUCTS_SHEET_NAME));

        if ($spreadsheetId === '' || $productsSheetName === '') {
            return $this->displayError($this->trans('Spreadsheet ID and products sheet name are required.', [], 'Modules.Gsheetsimport.Admin'));
        }

        Configuration::updateValue(self::CONFIG_SPREADSHEET_ID, pSQL($spreadsheetId));
        Configuration::updateValue(self::CONFIG_PRODUCTS_SHEET_NAME, pSQL($productsSheetName));
        Configuration::updateValue(self::CONFIG_RANGE, 'A2:P');

        return $this->displayConfirmation($this->trans('Configuration saved.', [], 'Modules.Gsheetsimport.Admin'))
            . $this->handleCredentialUpload();
    }

    protected function handleCredentialUpload(): string
    {
        if (!isset($_FILES['GSHEETSIMPORT_SERVICE_ACCOUNT']) || empty($_FILES['GSHEETSIMPORT_SERVICE_ACCOUNT']['tmp_name'])) {
            return '';
        }

        $file = $_FILES['GSHEETSIMPORT_SERVICE_ACCOUNT'];

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return $this->displayError($this->trans('Credential upload failed.', [], 'Modules.Gsheetsimport.Admin'));
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return $this->displayError($this->trans('Unable to read JSON file.', [], 'Modules.Gsheetsimport.Admin'));
        }

        $decoded = json_decode($content, true);
        if (
            !is_array($decoded) ||
            empty($decoded['type']) ||
            $decoded['type'] !== 'service_account' ||
            empty($decoded['client_email']) ||
            empty($decoded['private_key']) ||
            empty($decoded['token_uri'])
        ) {
            return $this->displayError($this->trans('Invalid Service Account JSON file.', [], 'Modules.Gsheetsimport.Admin'));
        }

        $directory = $this->getCredentialsDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            return $this->displayError($this->trans('Unable to create credentials directory.', [], 'Modules.Gsheetsimport.Admin'));
        }

        @file_put_contents($directory . '/index.php', "<?php\nexit;\n");
        @file_put_contents(
            $directory . '/.htaccess',
            "Order allow,deny\nDeny from all\n\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        );

        $target = $directory . '/service-account.json';

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return $this->displayError($this->trans('Unable to move uploaded credential file.', [], 'Modules.Gsheetsimport.Admin'));
        }

        @chmod($target, 0640);

        return $this->displayConfirmation($this->trans('Credentials uploaded successfully.', [], 'Modules.Gsheetsimport.Admin'));
    }

    public function getCredentialsDirectory(): string
    {
        return _PS_MODULE_DIR_ . $this->name . '/var/credentials';
    }

    public function getCronLinks(): array
    {
        $token = $this->ensureCronToken();
        $actions = [
            'fetchSheet' => $this->trans('Fetch Sheet to staging', [], 'Modules.Gsheetsimport.Admin'),
            'processBatch' => $this->trans('Create/Update one PrestaShop batch', [], 'Modules.Gsheetsimport.Admin'),
            'processAll' => $this->trans('Create/Update PrestaShop products', [], 'Modules.Gsheetsimport.Admin'),
            'pushSheet' => $this->trans('Push modified PrestaShop products to Sheet', [], 'Modules.Gsheetsimport.Admin'),
            'runAll' => $this->trans('Run full synchronization', [], 'Modules.Gsheetsimport.Admin'),
        ];
        $links = [];

        foreach ($actions as $action => $label) {
            $links[] = [
                'label' => $label,
                'url' => $this->context->link->getModuleLink($this->name, 'cron', [
                    'action' => $action,
                    'token' => $token,
                ], true),
            ];
        }

        return $links;
    }

    private function ensureCronToken(): string
    {
        $token = (string) Configuration::get(self::CONFIG_CRON_TOKEN);
        if ($token === '') {
            $token = $this->generateCronToken();
            Configuration::updateValue(self::CONFIG_CRON_TOKEN, $token);
        }

        return $token;
    }

    private function generateCronToken(): string
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(24));
        }

        return Tools::passwdGen(48);
    }

    protected function installDatabase(): bool
    {
        return $this->createProductSyncTable();
    }

    private function createProductSyncTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gsheets_sync` (
            `id_gsheets_sync` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(64) NOT NULL,
            `row_number` INT UNSIGNED DEFAULT NULL,
            `sync_direction` ENUM("sheets_to_prestashop","prestashop_to_sheets") NOT NULL DEFAULT "sheets_to_prestashop",
            `data_json` LONGTEXT NOT NULL,
            `needs_update` TINYINT(1) NOT NULL DEFAULT 1,
            `status` ENUM("pending","success","error") NOT NULL DEFAULT "pending",
            `error_message` TEXT DEFAULT NULL,
            `last_sync` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_gsheets_sync`),
            UNIQUE KEY `uniq_reference_direction` (`reference`, `sync_direction`),
            KEY `idx_status_needs_update` (`status`, `needs_update`),
            KEY `idx_direction_status_needs_update` (`sync_direction`, `status`, `needs_update`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        return Db::getInstance()->execute($sql) && $this->migrateProductSyncTable();
    }

    private function migrateProductSyncTable(): bool
    {
        $table = _DB_PREFIX_ . 'gsheets_sync';
        $db = Db::getInstance();

        $hasDirection = (bool) $db->getValue('SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = "' . pSQL($table) . '"
              AND COLUMN_NAME = "sync_direction"');
        if (!$hasDirection) {
            if (!$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `sync_direction` ENUM("sheets_to_prestashop","prestashop_to_sheets") NOT NULL DEFAULT "sheets_to_prestashop" AFTER `row_number`')) {
                return false;
            }
        }

        $indexes = $db->executeS('SHOW INDEX FROM `' . bqSQL($table) . '`') ?: [];
        $indexNames = array_unique(array_map(static function ($row) {
            return (string) $row['Key_name'];
        }, $indexes));

        if (in_array('uniq_reference', $indexNames, true)) {
            $db->execute('ALTER TABLE `' . bqSQL($table) . '` DROP INDEX `uniq_reference`');
        }

        if (!in_array('uniq_reference_direction', $indexNames, true)) {
            $db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD UNIQUE KEY `uniq_reference_direction` (`reference`, `sync_direction`)');
        }

        if (!in_array('idx_direction_status_needs_update', $indexNames, true)) {
            $db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD KEY `idx_direction_status_needs_update` (`sync_direction`, `status`, `needs_update`)');
        }

        return true;
    }

    protected function uninstallDatabase(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'gsheets_sync`');
    }

    private function migrateLegacyConfig(): void
    {
        $legacySheet = (string) Configuration::get('GSHEETSIMPORT_SHEET_NAME');
        if ($legacySheet !== '' && (string) Configuration::get(self::CONFIG_PRODUCTS_SHEET_NAME) === '') {
            Configuration::updateValue(self::CONFIG_PRODUCTS_SHEET_NAME, pSQL($legacySheet));
        }
    }

    protected function installAdminTab(): bool
    {
        $tabId = (int) Tab::getIdFromClassName('AdminGsheetsImportAjax');
        if ($tabId > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminGsheetsImportAjax';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf');

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Google Sheets Import';
        }

        return (bool) $tab->add();
    }

    protected function uninstallAdminTab(): bool
    {
        $tabId = (int) Tab::getIdFromClassName('AdminGsheetsImportAjax');
        if ($tabId <= 0) {
            return true;
        }

        $tab = new Tab($tabId);
        return (bool) $tab->delete();
    }
}
