<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use GSheetsImport\Repository\SyncRepository;
use Category;
use Configuration;
use Db;
use Feature;
use FeatureValue;
use Image;
use ImageManager;
use Language;
use Manufacturer;
use PrestaShopException;
use PrestaShopLogger;
use Product;
use StockAvailable;
use Tools;
use Validate;

class ProductSyncService
{
    private SyncRepository $syncRepository;

    public function __construct(SyncRepository $syncRepository)
    {
        $this->syncRepository = $syncRepository;
    }

    public function processBatch(int $limit = 10): array
    {
        $rows = $this->syncRepository->getPendingBatch($limit);
        $processed = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $syncId = (int) $row['id_gsheets_sync'];

            try {
                $payload = json_decode((string) $row['data_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->validatePayload($payload);
                $this->upsertProduct($payload);
                $this->syncRepository->markSuccess($syncId);
                ++$processed;
            } catch (\Throwable $e) {
                $this->syncRepository->markError($syncId, $e->getMessage());
                PrestaShopLogger::addLog(
                    sprintf('GSheets import error for "%s": %s', (string) $row['reference'], $e->getMessage()),
                    3
                );
                ++$errors;
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'pending' => $this->syncRepository->countPending(),
            'summary' => $this->syncRepository->getSummary(),
        ];
    }

    private function validatePayload(array $payload): void
    {
        if (empty($payload['reference'])) {
            throw new PrestaShopException('Missing product reference.');
        }

        if (empty($payload['name'])) {
            throw new PrestaShopException('Missing product name.');
        }

        if ((float) $payload['price'] < 0) {
            throw new PrestaShopException('Negative price is not allowed.');
        }

        if ((int) $payload['quantity'] < 0) {
            throw new PrestaShopException('Negative stock is not allowed.');
        }
    }

    private function upsertProduct(array $payload): void
    {
        $reference = trim((string) $payload['reference']);
        $name = (string) $payload['name'];
        $price = (float) $payload['price'];
        $quantity = (int) $payload['quantity'];
        $description = (string) ($payload['description'] ?? '');
        $descriptionShort = Tools::substr(trim(strip_tags($description)), 0, 800);
        $isbn = trim((string) ($payload['isbn'] ?? ''));
        $weight = max(0.0, (float) ($payload['weight'] ?? 0));
        $taxRate = max(0.0, (float) ($payload['tax_rate'] ?? 0));
        $brand = trim((string) ($payload['brand'] ?? ''));
        $category = (string) ($payload['category'] ?? '');
        $imageUrls = (string) ($payload['image_urls'] ?? '');

        $idProduct = $this->resolveProductIdByReference($reference);
        $product = $idProduct > 0 ? new Product($idProduct) : new Product();

        if ($idProduct > 0 && !Validate::isLoadedObject($product)) {
            throw new PrestaShopException('Unable to load existing product.');
        }

        $resolvedCategories = $this->resolveCategoryIds($category);
        $categoryIds = $resolvedCategories['ids'];
        $defaultCategoryId = $resolvedCategories['default_id'];
        $manufacturerId = $this->resolveManufacturerId($brand);
        $taxRulesGroupId = $this->resolveTaxRulesGroupId($taxRate);

        $product->reference = $reference;
        $product->name = $this->buildMultilangValue(Tools::substr($name, 0, 128));
        $product->link_rewrite = $this->buildMultilangValue(Tools::str2url(Tools::substr($name, 0, 128)));
        $product->description_short = $this->buildMultilangValue($descriptionShort);
        $product->description = $this->buildMultilangValue($description);
        $product->price = $price;
        $product->active = 1;
        $product->isbn = $isbn;
        $product->weight = $weight;
        $product->id_tax_rules_group = $taxRulesGroupId;
        $product->id_manufacturer = $manufacturerId;
        $product->id_category_default = $defaultCategoryId;
        $product->available_for_order = 1;
        $product->show_price = 1;
        $product->minimal_quantity = 1;
        $product->state = 1;
        $product->condition = 'new';
        $product->visibility = 'both';

        $fieldValidation = $product->validateFields(false, true);
        if ($fieldValidation !== true) {
            throw new PrestaShopException((string) $fieldValidation);
        }

        $langValidation = $product->validateFieldsLang(false, true);
        if ($langValidation !== true) {
            throw new PrestaShopException((string) $langValidation);
        }

        if (!$product->save()) {
            throw new PrestaShopException('Product save failed.');
        }

        $product->updateCategories($categoryIds);
        StockAvailable::setQuantity((int) $product->id, 0, $quantity);
        $this->syncFeatures((int) $product->id, $payload);
        $this->syncImages($product, $imageUrls);
    }

    private function buildMultilangValue(string $value): array
    {
        $values = [];
        foreach (Language::getLanguages(false) as $language) {
            $values[(int) $language['id_lang']] = $value;
        }

        return $values;
    }

    private function resolveProductIdByReference(string $reference): int
    {
        if ($reference !== '') {
            return (int) Product::getIdByReference($reference);
        }

        return 0;
    }

    private function resolveCategoryIds(string $requestedCategory): array
    {
        $defaultCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
        $requestedCategory = trim($requestedCategory);

        if ($requestedCategory === '') {
            return [
                'ids' => [$defaultCategoryId],
                'default_id' => $defaultCategoryId,
            ];
        }

        $paths = preg_split('/\s*[,;|]+\s*/', $requestedCategory) ?: [];
        $paths = array_values(array_filter(array_map('trim', $paths), static function ($value) {
            return $value !== '';
        }));

        if (empty($paths)) {
            $paths = [$requestedCategory];
        }

        $ids = [];
        $defaultLeafId = 0;
        foreach ($paths as $path) {
            $segments = preg_split('/\s*>\s*/', $path) ?: [];
            $segments = array_values(array_filter(array_map('trim', $segments), static function ($value) {
                return $value !== '';
            }));

            if (empty($segments)) {
                continue;
            }

            // Accept numeric category IDs as explicit single-node paths.
            if (count($segments) === 1 && ctype_digit($segments[0])) {
                $category = new Category((int) $segments[0]);
                if (Validate::isLoadedObject($category)) {
                    $ids[] = (int) $segments[0];
                    if ($defaultLeafId === 0) {
                        $defaultLeafId = (int) $segments[0];
                    }
                }
                continue;
            }

            $parentId = $defaultCategoryId;
            $resolvedPathIds = [];

            foreach ($segments as $segmentName) {
                $categoryId = $this->findCategoryIdByNameAndParent($segmentName, $parentId);
                if ($categoryId <= 0) {
                    $categoryId = $this->createCategory($segmentName, $parentId);
                    if ($categoryId <= 0) {
                        throw new PrestaShopException(sprintf('Unable to create category "%s".', $segmentName));
                    }
                }

                $resolvedPathIds[] = $categoryId;
                $parentId = $categoryId;
            }

            if (!empty($resolvedPathIds)) {
                $ids = array_merge($ids, $resolvedPathIds);
                if ($defaultLeafId === 0) {
                    $defaultLeafId = (int) end($resolvedPathIds);
                }
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [
                'ids' => [$defaultCategoryId],
                'default_id' => $defaultCategoryId,
            ];
        }

        return [
            'ids' => $ids,
            'default_id' => $defaultLeafId > 0 ? $defaultLeafId : (int) end($ids),
        ];
    }

    private function findCategoryIdByNameAndParent(string $name, int $parentId): int
    {
        $sql = 'SELECT c.`id_category`
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.`id_category` = c.`id_category`)
            WHERE c.`id_parent` = ' . (int) $parentId . '
              AND cl.`name` = "' . pSQL($name) . '"
            ORDER BY c.`id_category` ASC';

        $rows = Db::getInstance()->executeS($sql) ?: [];
        return isset($rows[0]['id_category']) ? (int) $rows[0]['id_category'] : 0;
    }

    private function createCategory(string $name, int $parentId): int
    {
        $category = new Category();
        $category->id_parent = $parentId;
        $category->active = 1;
        $category->is_root_category = 0;

        $nameByLang = [];
        $rewriteByLang = [];
        foreach (Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $nameByLang[$idLang] = $name;
            $rewriteByLang[$idLang] = Tools::str2url($name);
        }

        $category->name = $nameByLang;
        $category->link_rewrite = $rewriteByLang;

        if (!$category->add()) {
            return 0;
        }

        return (int) $category->id;
    }

    private function resolveManufacturerId(string $brand): int
    {
        if ($brand === '') {
            return 0;
        }

        $manufacturerId = (int) Manufacturer::getIdByName($brand);
        if ($manufacturerId > 0) {
            return $manufacturerId;
        }

        $manufacturer = new Manufacturer();
        $manufacturer->name = $brand;
        $manufacturer->active = 1;
        if (!$manufacturer->add()) {
            throw new PrestaShopException(sprintf('Unable to create manufacturer "%s".', $brand));
        }

        return (int) $manufacturer->id;
    }

    private function resolveTaxRulesGroupId(float $taxRate): int
    {
        if ($taxRate <= 0) {
            return 0;
        }

        $rate = (float) number_format($taxRate, 6, '.', '');
        $sql = 'SELECT trg.`id_tax_rules_group`
            FROM `' . _DB_PREFIX_ . 'tax_rules_group` trg
            INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` tr ON (tr.`id_tax_rules_group` = trg.`id_tax_rules_group`)
            INNER JOIN `' . _DB_PREFIX_ . 'tax` t ON (t.`id_tax` = tr.`id_tax`)
            WHERE trg.`active` = 1
              AND t.`rate` >= ' . ($rate - 0.001) . '
              AND t.`rate` <= ' . ($rate + 0.001) . '
            ORDER BY trg.`id_tax_rules_group` ASC';

        $rows = Db::getInstance()->executeS($sql) ?: [];
        $result = isset($rows[0]['id_tax_rules_group']) ? (int) $rows[0]['id_tax_rules_group'] : 0;

        return max(0, $result);
    }

    private function syncFeatures(int $productId, array $payload): void
    {
        if (!Feature::isFeatureActive()) {
            return;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $featureMap = [
            'Idioma' => trim((string) ($payload['feature_language'] ?? '')),
            'Nivel' => trim((string) ($payload['feature_level'] ?? '')),
            'Tipo de material' => trim((string) ($payload['feature_material_type'] ?? '')),
            'ISBN' => trim((string) ($payload['feature_isbn'] ?? '')),
        ];

        foreach ($featureMap as $featureName => $featureValue) {
            if ($featureValue === '') {
                continue;
            }

            $idFeature = (int) Feature::addFeatureImport($featureName);
            if ($idFeature <= 0) {
                continue;
            }

            $idFeatureValue = (int) FeatureValue::addFeatureValueImport($idFeature, $featureValue, $productId, $idLang, false);
            if ($idFeatureValue <= 0) {
                continue;
            }

            Product::addFeatureProductImport($productId, $idFeature, $idFeatureValue);
        }
    }

    private function syncImages(Product $product, string $rawImageUrls): void
    {
        $urls = $this->parseImageUrls($rawImageUrls);
        if (empty($urls)) {
            return;
        }

        $product->deleteImages();

        foreach ($urls as $index => $url) {
            $image = new Image();
            $image->id_product = (int) $product->id;
            $image->position = Image::getHighestPosition((int) $product->id) + 1;
            $image->cover = $index === 0 ? 1 : 0;

            if (!$image->add()) {
                throw new PrestaShopException('Unable to create product image.');
            }

            if (!ImageManager::copyImg((int) $product->id, (int) $image->id, $url, 'products', true)) {
                $image->delete();
                throw new PrestaShopException(sprintf('Unable to import image from URL: %s', $url));
            }
        }
    }

    private function parseImageUrls(string $rawImageUrls): array
    {
        $parts = preg_split('/[\r\n,;|]+/', trim($rawImageUrls)) ?: [];
        $urls = [];
        foreach ($parts as $part) {
            $url = trim($part);
            if ($url === '') {
                continue;
            }
            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }
}
