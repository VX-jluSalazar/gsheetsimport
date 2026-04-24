<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopException;

class GoogleSheetsRestService
{
    private const SHEET_DATA_RANGE = 'A2:P';
    private const SHEET_APPEND_RANGE = 'A:P';

    private GoogleJwtAuthService $authService;

    /**
     * Expected columns:
     * A: product_id
     * B: name
     * C: reference
     * D: isbn
     * E: quantity
     * F: price
     * G: tax_rate
     * H: brand
     * I: category
     * J: weight
     * K: description
     * L: image_urls
     * M: feature_language
     * N: feature_level
     * O: feature_material_type
     * P: feature_isbn
     */
    private const COLUMN_MAP = [
        0 => 'product_id',
        1 => 'name',
        2 => 'reference',
        3 => 'isbn',
        4 => 'quantity',
        5 => 'price',
        6 => 'tax_rate',
        7 => 'brand',
        8 => 'category',
        9 => 'weight',
        10 => 'description',
        11 => 'image_urls',
        12 => 'feature_language',
        13 => 'feature_level',
        14 => 'feature_material_type',
        15 => 'feature_isbn',
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
        $sheetName = (string) \Configuration::get(\GsheetsImport::CONFIG_PRODUCTS_SHEET_NAME);

        if ($spreadsheetId === '' || $sheetName === '') {
            throw new PrestaShopException('Google Sheets configuration is incomplete.');
        }

        $accessToken = $this->authService->getAccessToken($credentialPath);
        $sheetRange = $sheetName . '!' . self::SHEET_DATA_RANGE;

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

    public function writeRow(string $credentialPath, int $rowNumber, array $payload): void
    {
        if ($rowNumber < 2) {
            throw new PrestaShopException('Invalid Google Sheets row number.');
        }

        $spreadsheetId = (string) \Configuration::get(\GsheetsImport::CONFIG_SPREADSHEET_ID);
        $sheetName = (string) \Configuration::get(\GsheetsImport::CONFIG_PRODUCTS_SHEET_NAME);

        if ($spreadsheetId === '' || $sheetName === '') {
            throw new PrestaShopException('Google Sheets configuration is incomplete.');
        }

        $accessToken = $this->authService->getAccessToken($credentialPath);
        $sheetRange = $sheetName . '!A' . $rowNumber . ':P' . $rowNumber;
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' .
            rawurlencode($spreadsheetId) .
            '/values/' .
            rawurlencode($sheetRange) .
            '?valueInputOption=USER_ENTERED';

        $this->requestJson($url, $accessToken, 'PUT', [
            'majorDimension' => 'ROWS',
            'values' => [$this->payloadToSheetRow($payload)],
        ]);
    }

    public function appendRow(string $credentialPath, array $payload): int
    {
        $spreadsheetId = (string) \Configuration::get(\GsheetsImport::CONFIG_SPREADSHEET_ID);
        $sheetName = (string) \Configuration::get(\GsheetsImport::CONFIG_PRODUCTS_SHEET_NAME);

        if ($spreadsheetId === '' || $sheetName === '') {
            throw new PrestaShopException('Google Sheets configuration is incomplete.');
        }

        $accessToken = $this->authService->getAccessToken($credentialPath);
        $sheetRange = $sheetName . '!' . self::SHEET_APPEND_RANGE;
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' .
            rawurlencode($spreadsheetId) .
            '/values/' .
            rawurlencode($sheetRange) .
            ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $response = $this->requestJson($url, $accessToken, 'POST', [
            'majorDimension' => 'ROWS',
            'values' => [$this->payloadToSheetRow($payload)],
        ]);

        $updatedRange = (string) ($response['updates']['updatedRange'] ?? '');
        if (preg_match('/![A-Z]+(\d+):/', $updatedRange, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Execute a GET request with Bearer token.
     */
    private function getJson(string $url, string $accessToken): array
    {
        return $this->requestJson($url, $accessToken, 'GET');
    }

    private function requestJson(string $url, string $accessToken, string $method = 'GET', ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new PrestaShopException('cURL extension is required.');
        }

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

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

    private function payloadToSheetRow(array $payload): array
    {
        $row = [];
        foreach (self::COLUMN_MAP as $field) {
            $row[] = isset($payload[$field]) ? (string) $payload[$field] : '';
        }

        return $row;
    }

    /**
     * Map raw row values to internal fields.
     */
    private function mapRow(array $row): array
    {
        $mapped = [];
        foreach (self::COLUMN_MAP as $index => $field) {
            $mapped[$field] = isset($row[$index]) ? (string) $row[$index] : '';
        }

        $mapped['product_id'] = (int) trim($mapped['product_id']);
        $mapped['reference'] = trim($mapped['reference']);
        $mapped['isbn'] = trim($mapped['isbn']);
        $mapped['quantity'] = $this->normalizeInt($mapped['quantity']);
        $mapped['price'] = $this->normalizeFloat($mapped['price']);
        $mapped['tax_rate'] = $this->normalizeFloat($mapped['tax_rate']);
        $mapped['brand'] = trim($mapped['brand']);
        $mapped['category'] = trim($mapped['category']);
        $mapped['weight'] = $this->normalizeFloat($mapped['weight']);
        $mapped['image_urls'] = trim($mapped['image_urls']);
        $mapped['feature_language'] = trim($mapped['feature_language']);
        $mapped['feature_level'] = trim($mapped['feature_level']);
        $mapped['feature_material_type'] = trim($mapped['feature_material_type']);
        $mapped['feature_isbn'] = trim($mapped['feature_isbn']);
        $mapped['description'] = $this->normalizeDescription($mapped['description']);

        return $mapped;
    }

    private function normalizeFloat(string $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], $value);
        return (float) $normalized;
    }

    private function normalizeInt(string $value): int
    {
        return (int) trim($value);
    }

    private function normalizeDescription(string $value): string
    {
        return (string) preg_replace("/\r\n|\r|\n/", '<br>', $value);
    }
}
