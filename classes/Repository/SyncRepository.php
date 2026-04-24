<?php

namespace GSheetsImport\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncRepository
{
    public const DIRECTION_SHEETS_TO_PRESTASHOP = 'sheets_to_prestashop';
    public const DIRECTION_PRESTASHOP_TO_SHEETS = 'prestashop_to_sheets';

    private \Db $db;
    private string $table;

    public function __construct()
    {
        $this->db = \Db::getInstance();
        $this->table = _DB_PREFIX_ . 'gsheets_sync';
    }

    public function upsertRow(string $reference, array $row, int $rowNumber, bool $productExists): void
    {
        $this->upsertStagingRow($reference, $row, $rowNumber, self::DIRECTION_SHEETS_TO_PRESTASHOP, !$productExists, true);
    }

    public function upsertExportRow(string $reference, array $row, int $rowNumber, bool $forcePending = false): void
    {
        $this->upsertStagingRow($reference, $row, $rowNumber, self::DIRECTION_PRESTASHOP_TO_SHEETS, $forcePending, $forcePending);
    }

    private function upsertStagingRow(string $reference, array $row, int $rowNumber, string $direction, bool $forcePending, bool $initialPending): void
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $referenceSql = pSQL($reference);
        $jsonSql = pSQL($json, true);
        $directionSql = pSQL($direction);
        $forcePendingSql = (int) $forcePending;
        $initialPendingSql = (int) $initialPending;
        $initialStatusSql = $initialPending ? 'pending' : 'success';

        $sql = 'INSERT INTO `' . bqSQL($this->table) . '` 
            (`reference`, `row_number`, `sync_direction`, `data_json`, `needs_update`, `status`, `error_message`, `last_sync`, `created_at`, `updated_at`)
            VALUES (
                "' . $referenceSql . '",
                ' . (int) $rowNumber . ',
                "' . $directionSql . '",
                "' . $jsonSql . '",
                ' . $initialPendingSql . ',
                "' . pSQL($initialStatusSql) . '",
                NULL,
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `row_number` = VALUES(`row_number`),
                `sync_direction` = VALUES(`sync_direction`),
                `needs_update` = IF(`data_json` <> VALUES(`data_json`) OR ' . $forcePendingSql . ' = 1, 1, `needs_update`),
                `status` = IF(`data_json` <> VALUES(`data_json`) OR ' . $forcePendingSql . ' = 1, "pending", `status`),
                `error_message` = IF(`data_json` <> VALUES(`data_json`) OR ' . $forcePendingSql . ' = 1, NULL, `error_message`),
                `data_json` = VALUES(`data_json`),
                `last_sync` = NOW(),
                `updated_at` = NOW()';

        $this->db->execute($sql);
    }

    public function getPendingBatch(int $limit = 10, string $direction = self::DIRECTION_SHEETS_TO_PRESTASHOP): array
    {
        $sql = 'SELECT *
            FROM `' . bqSQL($this->table) . '`
            WHERE `needs_update` = 1
              AND `sync_direction` = "' . pSQL($direction) . '"
            ORDER BY `id_gsheets_sync` ASC
            LIMIT ' . (int) $limit;

        return $this->db->executeS($sql) ?: [];
    }

    public function markSuccess(int $id): void
    {
        $this->db->update('gsheets_sync', [
            'needs_update' => 0,
            'status' => 'success',
            'error_message' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id_gsheets_sync` = ' . (int) $id, 1, true, true);
    }

    public function markError(int $id, string $message): void
    {
        $this->db->update('gsheets_sync', [
            'status' => 'error',
            'error_message' => pSQL($message, true),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id_gsheets_sync` = ' . (int) $id, 1, true, true);
    }

    public function updateRowNumber(int $id, int $rowNumber): void
    {
        $this->db->update('gsheets_sync', [
            'row_number' => (int) $rowNumber,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id_gsheets_sync` = ' . (int) $id);
    }

    public function countPending(string $direction = self::DIRECTION_SHEETS_TO_PRESTASHOP): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . bqSQL($this->table) . '`
            WHERE `needs_update` = 1
              AND `sync_direction` = "' . pSQL($direction) . '"';
        return (int) $this->db->getValue($sql);
    }

    public function getSummary(string $direction = self::DIRECTION_SHEETS_TO_PRESTASHOP): array
    {
        $sql = 'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN `status` = "success" THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN `status` = "error" THEN 1 ELSE 0 END) AS error,
            SUM(CASE WHEN `needs_update` = 1 THEN 1 ELSE 0 END) AS pending
            FROM `' . bqSQL($this->table) . '`
            WHERE `sync_direction` = "' . pSQL($direction) . '"';

        $row = $this->db->getRow($sql);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    public function getErrorRows(int $limit = 50, string $direction = self::DIRECTION_SHEETS_TO_PRESTASHOP): array
    {
        $sql = 'SELECT `reference`, `row_number`, `error_message`, `updated_at`
            FROM `' . bqSQL($this->table) . '`
            WHERE `status` = "error"
              AND `sync_direction` = "' . pSQL($direction) . '"
            ORDER BY `updated_at` DESC
            LIMIT ' . (int) $limit;

        return $this->db->executeS($sql) ?: [];
    }

    public function getCategoryValuesFromStaging(): array
    {
        $sql = 'SELECT `data_json`
            FROM `' . bqSQL($this->table) . '`
            WHERE `data_json` IS NOT NULL
              AND `data_json` != ""';

        $rows = $this->db->executeS($sql) ?: [];
        $categories = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['data_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }

            $value = trim((string) ($payload['category'] ?? ''));
            if ($value === '') {
                continue;
            }

            $categories[] = $value;
        }

        return array_values(array_unique($categories));
    }

    public function getRowsForList(string $filter = 'all', int $limit = 500, string $direction = self::DIRECTION_SHEETS_TO_PRESTASHOP): array
    {
        $where = 'WHERE `sync_direction` = "' . pSQL($direction) . '"';
        if ($filter === 'pending') {
            $where .= ' AND `needs_update` = 1';
        } elseif ($filter === 'synchronized') {
            $where .= ' AND `status` = "success" AND `needs_update` = 0';
        }

        $sql = 'SELECT `reference`, `row_number`, `status`, `needs_update`, `error_message`, `updated_at`
            FROM `' . bqSQL($this->table) . '`
            ' . $where . '
            ORDER BY `updated_at` DESC
            LIMIT ' . (int) $limit;

        return $this->db->executeS($sql) ?: [];
    }
}
