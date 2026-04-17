<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use GSheetsImport\Repository\SyncRepository;
use Category;
use Configuration;
use Language;
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
        $reference = pSQL((string) $payload['reference']);
        $name = trim((string) $payload['name']);
        $price = (float) $payload['price'];
        $quantity = (int) $payload['quantity'];
        $active = (int) $payload['active'];
        $descriptionShort = trim((string) ($payload['description_short'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $categoryId = (int) ($payload['category_id'] ?? 0);

        $idProduct = (int) Product::getIdByReference($reference);
        $product = $idProduct > 0 ? new Product($idProduct) : new Product();

        if ($idProduct > 0 && !Validate::isLoadedObject($product)) {
            throw new PrestaShopException('Unable to load existing product.');
        }

        $defaultCategoryId = $this->resolveCategoryId($categoryId);

        $product->reference = $reference;
        $product->name = $this->buildMultilangValue(Tools::substr($name, 0, 128));
        $product->link_rewrite = $this->buildMultilangValue(Tools::str2url(Tools::substr($name, 0, 128)));
        $product->description_short = $this->buildMultilangValue($descriptionShort);
        $product->description = $this->buildMultilangValue($description);
        $product->price = $price;
        $product->active = $active;
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

        $product->updateCategories([$defaultCategoryId]);
        StockAvailable::setQuantity((int) $product->id, 0, $quantity);
    }

    private function buildMultilangValue(string $value): array
    {
        $values = [];
        foreach (Language::getLanguages(false) as $language) {
            $values[(int) $language['id_lang']] = $value;
        }

        return $values;
    }

    private function resolveCategoryId(int $requestedCategoryId): int
    {
        if ($requestedCategoryId > 0) {
            $category = new Category($requestedCategoryId);
            if (Validate::isLoadedObject($category)) {
                return $requestedCategoryId;
            }
        }

        return (int) Configuration::get('PS_HOME_CATEGORY');
    }
}