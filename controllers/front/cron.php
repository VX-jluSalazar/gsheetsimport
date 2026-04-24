<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Repository/SyncRepository.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/GoogleJwtAuthService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/GoogleSheetsRestService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/StagingSyncService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/ProductSyncService.php';
require_once _PS_MODULE_DIR_ . 'gsheetsimport/classes/Service/ProductSheetExportService.php';

class GsheetsImportCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function initContent(): void
    {
        parent::initContent();

        try {
            $this->validateToken();
            $action = trim((string) Tools::getValue('action', 'runAll'));
            $result = $this->runAction($action);

            $this->jsonResponse([
                'success' => true,
                'action' => $action,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateToken(): void
    {
        $expectedToken = (string) Configuration::get(GsheetsImport::CONFIG_CRON_TOKEN);
        $receivedToken = (string) Tools::getValue('token');

        if ($expectedToken === '' || $receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
            throw new PrestaShopException('Invalid cron token.');
        }
    }

    private function runAction(string $action): array
    {
        switch ($action) {
            case 'fetchSheet':
                return $this->fetchSheet();

            case 'processBatch':
                return $this->processBatch();

            case 'processAll':
                return $this->processAll();

            case 'pushSheet':
                return $this->pushSheet();

            case 'runAll':
                return [
                    'fetch_sheet' => $this->fetchSheet(),
                    'process_all' => $this->processAll(),
                    'push_sheet' => $this->pushSheet(),
                ];
        }

        throw new PrestaShopException('Invalid cron action.');
    }

    private function fetchSheet(): array
    {
        $repository = new \GSheetsImport\Repository\SyncRepository();
        $authService = new \GSheetsImport\Service\GoogleJwtAuthService();
        $sheetsService = new \GSheetsImport\Service\GoogleSheetsRestService($authService);
        $stagingService = new \GSheetsImport\Service\StagingSyncService($sheetsService, $repository);

        return $stagingService->fetchAndStage($this->getCredentialPath());
    }

    private function processBatch(): array
    {
        $repository = new \GSheetsImport\Repository\SyncRepository();
        $productSyncService = new \GSheetsImport\Service\ProductSyncService($repository);

        return $productSyncService->processBatch((int) Tools::getValue('limit', 10));
    }

    private function processAll(): array
    {
        $repository = new \GSheetsImport\Repository\SyncRepository();
        $productSyncService = new \GSheetsImport\Service\ProductSyncService($repository);
        $maxBatches = max(1, (int) Tools::getValue('max_batches', 50));
        $limit = max(1, (int) Tools::getValue('limit', 10));
        $totalProcessed = 0;
        $totalErrors = 0;
        $lastResult = [];

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $lastResult = $productSyncService->processBatch($limit);
            $totalProcessed += (int) ($lastResult['processed'] ?? 0);
            $totalErrors += (int) ($lastResult['errors'] ?? 0);

            if ((int) ($lastResult['pending'] ?? 0) <= 0) {
                break;
            }
        }

        return [
            'processed' => $totalProcessed,
            'errors' => $totalErrors,
            'pending' => (int) ($lastResult['pending'] ?? 0),
            'summary' => $lastResult['summary'] ?? $repository->getSummary(),
        ];
    }

    private function pushSheet(): array
    {
        $repository = new \GSheetsImport\Repository\SyncRepository();
        $authService = new \GSheetsImport\Service\GoogleJwtAuthService();
        $sheetsService = new \GSheetsImport\Service\GoogleSheetsRestService($authService);
        $exportService = new \GSheetsImport\Service\ProductSheetExportService($sheetsService, $repository);

        return $exportService->stageAndPush($this->getCredentialPath());
    }

    private function getCredentialPath(): string
    {
        return _PS_MODULE_DIR_ . 'gsheetsimport/var/credentials/service-account.json';
    }

    private function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        die(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
