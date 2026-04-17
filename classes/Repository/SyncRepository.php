<?php

namespace GSheetsImport\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncRepository
{
    private \Db $db;
    private string $table;

    public function __construct()
    {
        $this->db = \Db::getInstance();
        $this->table = _DB_PREFIX_ . 'gsheets_sync';
    }

    public function upsertRow(string $reference, array $row, int $rowNumber): void
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $referenceSql = pSQL($reference);
        $jsonSql = pSQL($json, true);

        $sql = 'INSERT INTO `' . bqSQL($this->table) . '` 
            (`reference`, `row_number`, `data_json`, `needs_update`, `status`, `error_message`, `last_sync`, `created_at`, `updated_at`)
            VALUES (
                "' . $referenceSql . '",
                ' . (int) $rowNumber . ',
                "' . $jsonSql . '",
                1,
                "pending",
                NULL,
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `row_number` = VALUES(`row_number`),
                `needs_update` = IF(`data_json` <> VALUES(`data_json`), 1, `needs_update`),
                `status` = IF(`data_json` <> VALUES(`data_json`), "pending", `status`),
                `error_message` = IF(`data_json` <> VALUES(`data_json`), NULL, `error_message`),
                `data_json` = VALUES(`data_json`),
                `last_sync` = NOW(),
                `updated_at` = NOW()';

        $this->db->execute($sql);
    }

    public function getPendingBatch(int $limit = 10): array
    {
        $sql = 'SELECT *
            FROM `' . bqSQL($this->table) . '`
            WHERE `needs_update` = 1
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

    public function countPending(): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . bqSQL($this->table) . '` WHERE `needs_update` = 1';
        return (int) $this->db->getValue($sql);
    }

    public function getSummary(): array
    {
        $sql = 'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN `status` = "success" THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN `status` = "error" THEN 1 ELSE 0 END) AS error,
            SUM(CASE WHEN `needs_update` = 1 THEN 1 ELSE 0 END) AS pending
            FROM `' . bqSQL($this->table) . '`';

        $row = $this->db->getRow($sql);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    public function getErrorRows(int $limit = 50): array
    {
        $sql = 'SELECT `reference`, `row_number`, `error_message`, `updated_at`
            FROM `' . bqSQL($this->table) . '`
            WHERE `status` = "error"
            ORDER BY `updated_at` DESC
            LIMIT ' . (int) $limit;

        return $this->db->executeS($sql) ?: [];
    }
}