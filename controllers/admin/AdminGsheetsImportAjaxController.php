<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class AdminGsheetsImportAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = false;
        $this->ajax = true;

        parent::__construct();
    }

    public function ajaxProcessFetchSheet(): void
    {
        try {
            /** @var \GSheetsImport\Service\StagingSyncService $service */
            $service = SymfonyContainer::getInstance()->get('gsheetsimport.service.staging_sync');
            $result = $service->fetchAndStage();

            $this->jsonResponse([
                'success' => true,
                'message' => 'Sheet data loaded into staging successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function ajaxProcessProcessBatch(): void
    {
        try {
            /** @var \GSheetsImport\Service\ProductSyncService $service */
            $service = SymfonyContainer::getInstance()->get('gsheetsimport.service.product_sync');
            $result = $service->processBatch(10);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Batch processed successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    protected function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        die(Tools::jsonEncode($payload));
    }
}
