<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopException;

class GoogleSheetsRestService
{
    private GoogleJwtAuthService $authService;

    /**
     * Expected columns:
     * A: reference
     * B: name
     * C: price
     * D: quantity
     * E: active
     * F: description_short
     * G: description
     * H: category_id
     */
    private const COLUMN_MAP = [
        0 => 'reference',
        1 => 'name',
        2 => 'price',
        3 => 'quantity',
        4 => 'active',
        5 => 'description_short',
        6 => 'description',
        7 => 'category_id',
    ];

    public function __construct(GoogleJwtAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Fetch rows from Google Sheets REST API.
     */
    public function fetchRows(string $credentialPath): array
    {
        $spreadsheetId = (string) \Configuration::get(\GsheetsImport::CONFIG_SPREADSHEET_ID);
        $sheetName = (string) \Configuration::get(\GsheetsImport::CONFIG_SHEET_NAME);
        $range = (string) \Configuration::get(\GsheetsImport::CONFIG_RANGE, 'A2:Z');

        if ($spreadsheetId === '' || $sheetName === '' || $range === '') {
            throw new PrestaShopException('Google Sheets configuration is incomplete.');
        }

        $accessToken = $this->authService->getAccessToken($credentialPath);
        $sheetRange = $sheetName . '!' . $range;

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' .
            rawurlencode($spreadsheetId) .
            '/values/' .
            rawurlencode($sheetRange) .
            '?majorDimension=ROWS';

        $response = $this->getJson($url, $accessToken);
        $values = $response['values'] ?? [];

        if (!is_array($values)) {
            return [];
        }

        $rows = [];
        foreach ($values as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapped = $this->mapRow($row);
            $mapped['row_number'] = $index + 2;

            if ($mapped['reference'] === '') {
                continue;
            }

            $rows[] = $mapped;
        }

        return $rows;
    }

    /**
     * Execute a GET request with Bearer token.
     */
    private function getJson(string $url, string $accessToken): array
    {
        if (!function_exists('curl_init')) {
            throw new PrestaShopException('cURL extension is required.');
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new PrestaShopException('Google Sheets request failed: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? ('Google Sheets API returned HTTP ' . $httpCode);
            throw new PrestaShopException((string) $message);
        }

        if (!is_array($decoded)) {
            throw new PrestaShopException('Invalid JSON response from Google Sheets API.');
        }

        return $decoded;
    }

    /**
     * Map raw row values to internal fields.
     */
    private function mapRow(array $row): array
    {
        $mapped = [];
        foreach (self::COLUMN_MAP as $index => $field) {
            $mapped[$field] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        $mapped['price'] = $this->normalizeFloat((string) $mapped['price']);
        $mapped['quantity'] = (int) $mapped['quantity'];
        $mapped['active'] = $this->normalizeBoolean((string) $mapped['active']);
        $mapped['category_id'] = (int) $mapped['category_id'];

        return $mapped;
    }

    private function normalizeFloat(string $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], $value);
        return (float) $normalized;
    }

    private function normalizeBoolean(string $value): int
    {
        $value = \Tools::strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'si', 'sí'], true) ? 1 : 0;
    }
}