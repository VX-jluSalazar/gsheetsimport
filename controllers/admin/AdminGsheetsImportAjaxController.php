<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Repository/SyncRepository.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/GoogleJwtAuthService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/GoogleSheetsRestService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/StagingSyncService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/ProductSyncService.php';

class AdminGsheetsImportAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        $this->ajax = true;
        parent::__construct();
    }

    public function ajaxProcessFetchSheet(): void
    {
        try {
            $repository = new \GSheetsImport\Repository\SyncRepository();
            $authService = new \GSheetsImport\Service\GoogleJwtAuthService();
            $sheetsService = new \GSheetsImport\Service\GoogleSheetsRestService($authService);
            $stagingService = new \GSheetsImport\Service\StagingSyncService($sheetsService, $repository);

            $credentialPath = _PS_MODULE_DIR_ . 'gsheetsimport/var/credentials/service-account.json';
            $result = $stagingService->fetchAndStage($credentialPath);

            $this->jsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function ajaxProcessProcessBatch(): void
    {
        try {
            $repository = new \GSheetsImport\Repository\SyncRepository();
            $productSyncService = new \GSheetsImport\Service\ProductSyncService($repository);

            $result = $productSyncService->processBatch(10);

            $this->jsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        die(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
