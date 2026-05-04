<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use Db;
use GSheetsImport\Repository\SyncRepository;
use Image;
use Language;
use Link;
use Manufacturer;
use PrestaShopLogger;
use Product;
use StockAvailable;
use Tools;
use Validate;

class ProductSheetExportService
{
    private GoogleSheetsRestService $googleSheetsService;
    private SyncRepository $syncRepository;

    public function __construct(GoogleSheetsRestService $googleSheetsService, SyncRepository $syncRepository)
    {
        $this->googleSheetsService = $googleSheetsService;
        $this->syncRepository = $syncRepository;
    }

    public function stageAndPush(string $credentialPath): array
    {
        $sheetRowsByReference = $this->getSheetRowsByReference($credentialPath);
        $productIds = $this->getChangedProductIdsWithReference();
        $staged = 0;

        foreach ($productIds as $productId) {
            $payload = $this->buildPayload((int) $productId);
            if (empty($payload['reference'])) {
                continue;
            }

            $reference = $this->normalizeReference((string) $payload['reference']);
            $payload['reference'] = $reference;

            $sheetRow = $sheetRowsByReference[$this->getReferenceKey($reference)] ?? null;
            $rowNumber = is_array($sheetRow) ? (int) ($sheetRow['row_number'] ?? 0) : 0;
            $sheetDiffers = !$this->payloadMatchesSheetRow($payload, $sheetRow);
            $this->syncRepository->upsertExportRow($reference, $payload, $rowNumber, $sheetDiffers);
            ++$staged;
        }

        $result = $this->pushPendingRows($credentialPath, $sheetRowsByReference);
        $result['staged'] = $staged;
        $result['summary'] = $this->syncRepository->getSummary(SyncRepository::DIRECTION_PRESTASHOP_TO_SHEETS);

        return $result;
    }

    private function pushPendingRows(string $credentialPath, array $sheetRowsByReference = []): array
    {
        if (empty($sheetRowsByReference)) {
            $sheetRowsByReference = $this->getSheetRowsByReference($credentialPath);
        }

        $rows = $this->syncRepository->getPendingBatch(500, SyncRepository::DIRECTION_PRESTASHOP_TO_SHEETS);
        $updated = 0;
        $appended = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $syncId = (int) $row['id_gsheets_sync'];

            try {
                $payload = json_decode((string) $row['data_json'], true, 512, JSON_THROW_ON_ERROR);
                $rowNumber = (int) $row['row_number'];
                $reference = $this->normalizeReference((string) ($payload['reference'] ?? $row['reference']));
                $payload['reference'] = $reference;
                $sheetRow = $sheetRowsByReference[$this->getReferenceKey($reference)] ?? null;
                $sheetRowNumber = is_array($sheetRow) ? (int) ($sheetRow['row_number'] ?? 0) : 0;

                if ($sheetRowNumber >= 2) {
                    $rowNumber = $sheetRowNumber;
                    $this->syncRepository->updateRowNumber($syncId, $rowNumber);
                }

                if ($rowNumber >= 2) {
                    $this->googleSheetsService->writeRow($credentialPath, $rowNumber, $payload);
                    ++$updated;
                } else {
                    $newRowNumber = $this->googleSheetsService->appendRow($credentialPath, $payload);
                    if ($newRowNumber > 0) {
                        $this->syncRepository->updateRowNumber($syncId, $newRowNumber);
                        $payload['row_number'] = $newRowNumber;
                        $sheetRowsByReference[$this->getReferenceKey($reference)] = $payload;
                    }
                    ++$appended;
                }

                $this->syncRepository->markSuccess($syncId);
            } catch (\Throwable $e) {
                $this->syncRepository->markError($syncId, $e->getMessage());
                PrestaShopLogger::addLog(
                    sprintf('GSheets export error for "%s": %s', (string) $row['reference'], $e->getMessage()),
                    3
                );
                ++$errors;
            }
        }

        return [
            'updated' => $updated,
            'appended' => $appended,
            'errors' => $errors,
            'pending' => $this->syncRepository->countPending(SyncRepository::DIRECTION_PRESTASHOP_TO_SHEETS),
        ];
    }

    private function getSheetRowsByReference(string $credentialPath): array
    {
        $rows = $this->googleSheetsService->fetchRows($credentialPath);
        $indexed = [];

        foreach ($rows as $row) {
            $reference = $this->normalizeReference((string) ($row['reference'] ?? ''));
            if ($reference !== '') {
                $key = $this->getReferenceKey($reference);
                if (!isset($indexed[$key])) {
                    $indexed[$key] = $row;
                }
            }
        }

        return $indexed;
    }

    private function normalizeReference(string $reference): string
    {
        return trim($reference);
    }

    private function getReferenceKey(string $reference): string
    {
        return Tools::strtolower($this->normalizeReference($reference));
    }

    private function payloadMatchesSheetRow(array $payload, ?array $sheetRow): bool
    {
        if (!is_array($sheetRow)) {
            return false;
        }

        unset($sheetRow['row_number']);

        return $this->normalizeForCompare($payload) === $this->normalizeForCompare($sheetRow);
    }

    private function normalizeForCompare(array $payload): array
    {
        $fields = [
            'product_id',
            'name',
            'reference',
            'isbn',
            'quantity',
            'price',
            'tax_rate',
            'brand',
            'category',
            'weight',
            'description',
            'image_urls',
            'feature_language',
            'feature_level',
            'feature_material_type',
            'feature_isbn',
        ];

        $normalized = [];
        foreach ($fields as $field) {
            $value = $payload[$field] ?? '';
            if (in_array($field, ['product_id', 'quantity'], true)) {
                $normalized[$field] = (int) $value;
            } elseif (in_array($field, ['price', 'tax_rate', 'weight'], true)) {
                $normalized[$field] = (float) $value;
            } else {
                $normalized[$field] = trim((string) $value);
            }
        }

        return $normalized;
    }

    private function getChangedProductIdsWithReference(): array
    {
        $sql = 'SELECT p.`id_product`
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'gsheets_sync` gs ON (
                LOWER(TRIM(gs.`reference`)) = LOWER(TRIM(p.`reference`))
                AND gs.`sync_direction` = "' . pSQL(SyncRepository::DIRECTION_PRESTASHOP_TO_SHEETS) . '"
            )
            WHERE p.`reference` IS NOT NULL
              AND TRIM(p.`reference`) != ""
              AND (
                gs.`id_gsheets_sync` IS NULL
                OR gs.`needs_update` = 1
                OR gs.`status` != "success"
                OR p.`date_upd` > gs.`updated_at`
              )
            ORDER BY p.`date_upd` DESC';

        $rows = Db::getInstance()->executeS($sql) ?: [];

        return array_map(static function ($row) {
            return (int) $row['id_product'];
        }, $rows);
    }

    private function buildPayload(int $productId): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $product = new Product($productId, false, $idLang);

        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $featureValues = $this->getFeatureValues($productId, $idLang);

        return [
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'reference' => trim((string) $product->reference),
            'isbn' => trim((string) $product->isbn),
            'quantity' => (int) StockAvailable::getQuantityAvailableByProduct($productId, 0),
            'price' => (float) $product->price,
            'tax_rate' => $this->getTaxRate((int) $product->id_tax_rules_group),
            'brand' => $this->getManufacturerName((int) $product->id_manufacturer),
            'category' => $this->getCategoryPaths($productId, $idLang),
            'weight' => (float) $product->weight,
            'description' => $this->normalizeDescription((string) $product->description),
            'image_urls' => $this->getImageUrls($product, $idLang),
            'feature_language' => $featureValues['Idioma'] ?? '',
            'feature_level' => $featureValues['Nivel'] ?? '',
            'feature_material_type' => $featureValues['Tipo de material'] ?? '',
            'feature_isbn' => $featureValues['ISBN'] ?? '',
        ];
    }

    private function getTaxRate(int $taxRulesGroupId): float
    {
        if ($taxRulesGroupId <= 0) {
            return 0.0;
        }

        $sql = 'SELECT t.`rate`
            FROM `' . _DB_PREFIX_ . 'tax_rule` tr
            INNER JOIN `' . _DB_PREFIX_ . 'tax` t ON (t.`id_tax` = tr.`id_tax`)
            WHERE tr.`id_tax_rules_group` = ' . (int) $taxRulesGroupId . '
            ORDER BY tr.`id_tax_rule` ASC';

        return (float) Db::getInstance()->getValue($sql);
    }

    private function getManufacturerName(int $manufacturerId): string
    {
        if ($manufacturerId <= 0) {
            return '';
        }

        $manufacturer = new Manufacturer($manufacturerId);

        return Validate::isLoadedObject($manufacturer) ? (string) $manufacturer->name : '';
    }

    private function getCategoryPaths(int $productId, int $idLang): string
    {
        $rows = Product::getProductCategoriesFull($productId, $idLang) ?: [];
        $paths = [];

        foreach ($rows as $row) {
            $categoryId = (int) ($row['id_category'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $path = $this->getCategoryPath($categoryId, $idLang);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return implode(', ', array_values(array_unique($paths)));
    }

    private function getCategoryPath(int $categoryId, int $idLang): string
    {
        $names = [];
        $currentId = $categoryId;

        while ($currentId > 2) {
            $row = Db::getInstance()->getRow('SELECT c.`id_parent`, cl.`name`
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.`id_category` = c.`id_category`)
                WHERE c.`id_category` = ' . (int) $currentId . '
                  AND cl.`id_lang` = ' . (int) $idLang);

            if (!$row || empty($row['name'])) {
                break;
            }

            array_unshift($names, (string) $row['name']);
            $currentId = (int) $row['id_parent'];
        }

        return implode(' > ', $names);
    }

    private function getImageUrls(Product $product, int $idLang): string
    {
        $images = Image::getImages($idLang, (int) $product->id) ?: [];
        $link = Context::getContext()->link;
        $urls = [];
        $rewrite = is_array($product->link_rewrite) ? (string) ($product->link_rewrite[$idLang] ?? reset($product->link_rewrite)) : (string) $product->link_rewrite;

        if (!$link instanceof Link) {
            return '';
        }

        foreach ($images as $image) {
            $imageId = (int) ($image['id_image'] ?? 0);
            if ($imageId <= 0) {
                continue;
            }

            $urls[] = $link->getImageLink($rewrite, $imageId, 'large_default');
        }

        return implode(', ', $urls);
    }

    private function getFeatureValues(int $productId, int $idLang): array
    {
        $sql = 'SELECT fl.`name`, fvl.`value`
            FROM `' . _DB_PREFIX_ . 'feature_product` fp
            INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON (fl.`id_feature` = fp.`id_feature` AND fl.`id_lang` = ' . (int) $idLang . ')
            INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON (fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . (int) $idLang . ')
            WHERE fp.`id_product` = ' . (int) $productId;

        $rows = Db::getInstance()->executeS($sql) ?: [];
        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['name']] = (string) $row['value'];
        }

        return $values;
    }

    private function normalizeDescription(string $description): string
    {
        $description = str_replace(["\r\n", "\r", "\n"], '<br>', $description);

        return Tools::stripslashes($description);
    }
}
