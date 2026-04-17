<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use GSheetsImport\Repository\SyncRepository;

class StagingSyncService
{
    private GoogleSheetsRestService $googleSheetsService;
    private SyncRepository $syncRepository;

    public function __construct(
        GoogleSheetsRestService $googleSheetsService,
        SyncRepository $syncRepository
    ) {
        $this->googleSheetsService = $googleSheetsService;
        $this->syncRepository = $syncRepository;
    }

    public function fetchAndStage(string $credentialPath): array
    {
        $rows = $this->googleSheetsService->fetchRows($credentialPath);

        foreach ($rows as $row) {
            $reference = pSQL((string) $row['reference']);
            $rowNumber = (int) ($row['row_number'] ?? 0);
            $this->syncRepository->upsertRow($reference, $row, $rowNumber);
        }

        return [
            'total_rows' => count($rows),
            'summary' => $this->syncRepository->getSummary(),
        ];
    }
}