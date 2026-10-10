<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalProductRepository;

class ProductImporter
{
    private $products;

    public function __construct(ExternalProductRepository $products)
    {
        $this->products = $products;
    }

    public function import(
        int $idSource,
        string $externalId,
        array $mappedValues,
        array $categoryIds,
        ?int $idManufacturer,
        ?int $idSupplier,
        int $idLangDefault
    ): int {
        if ($externalId === '' || \Tools::strlen($externalId) > 191) {
            throw new \InvalidArgumentException('External product ID must contain 1 to 191 characters.');
        }
        $link = $this->products->findLink($idSource, $externalId);
        $id = $link['id_product'] ?? null;
        $product = $id === null ? new \Product() : new \Product($id);
        if ($id !== null && !\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Linked product no longer exists: ' . $id);
        }
        $isNew = $id === null;
        if ($isNew && trim((string) ($mappedValues['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('A name is required to create a product.');
        }
        // Fields whose store value differs from what the import last wrote were edited manually: keep them.
        $imported = $link['imported_values'] ?? [];
        $current = $isNew || $imported === [] ? [] : ImportedValues::fingerprint($this->storeValues($product, $idLangDefault));
        $written = [];
        $write = static function (string $key) use ($imported, $current, &$written): bool {
            if (!ImportedValues::writable($imported, $current, $key)) {
                return false;
            }
            $written[] = $key;
            return true;
        };
        foreach (['name' => 'name', 'description' => 'description', 'short_description' => 'description_short'] as $target => $property) {
            if (isset($mappedValues[$target]) && $write($target . '.' . $idLangDefault)) {
                $translations = is_array($product->$property) ? $product->$property : [];
                $value = (string) $mappedValues[$target];
                if ($property === 'description_short') {
                    $limit = (int) \Configuration::get('PS_PRODUCT_SHORT_DESC_LIMIT');
                    $value = ShortDescriptionLimiter::limit($value, $limit > 0 ? $limit : 800);
                }
                $translations[$idLangDefault] = $value;
                $product->$property = $translations;
                if ($property === 'name') {
                    $slugs = is_array($product->link_rewrite) ? $product->link_rewrite : [];
                    $slugs[$idLangDefault] = \Tools::str2url($value) ?: 'product';
                    $product->link_rewrite = $slugs;
                }
            }
        }
        // ObjectModel requires name/link_rewrite in the shop default language too.
        if ($isNew) {
            $shopLanguage = (int) \Configuration::get('PS_LANG_DEFAULT');
            $product->name[$shopLanguage] = $product->name[$shopLanguage] ?? $product->name[$idLangDefault];
            $product->link_rewrite[$shopLanguage] = $product->link_rewrite[$shopLanguage] ?? $product->link_rewrite[$idLangDefault];
            $product->id_shop_default = (int) \Context::getContext()->shop->id;
        }
        if (isset($mappedValues['mpn']) && !\Validate::isMpn((string) $mappedValues['mpn'])) {
            throw new \InvalidArgumentException('mpn must be at most 40 characters.');
        }
        foreach (['reference', 'ean13', 'mpn'] as $field) {
            if (isset($mappedValues[$field]) && $write($field)) {
                $product->$field = (string) $mappedValues[$field];
            }
        }
        // price, regular_price and the discount move together: a manual base price keeps all three.
        $regularPrice = isset($mappedValues['regular_price']) ? (float) $mappedValues['regular_price'] : null;
        $currentPrice = isset($mappedValues['price']) ? (float) $mappedValues['price'] : null;
        $writePrice = $currentPrice !== null || $regularPrice !== null ? $write('price') : ImportedValues::writable($imported, $current, 'price');
        if ($writePrice && $currentPrice !== null) {
            $product->price = $currentPrice;
        }
        if ($writePrice && $regularPrice !== null && ($currentPrice === null || $regularPrice > $currentPrice)) {
            $product->price = $regularPrice;
        }
        if (isset($mappedValues['wholesale_price']) && $write('wholesale_price')) {
            $product->wholesale_price = (float) $mappedValues['wholesale_price'];
        }
        if (isset($mappedValues['weight'])) {
            if ($write('weight')) {
                $product->weight = (float) $mappedValues['weight'];
            }
        } elseif ($isNew) {
            $product->weight = 0.0;
        }
        if (isset($mappedValues['active'])) {
            if ($write('active')) {
                $product->active = (bool) $mappedValues['active'];
            }
        } elseif ($isNew) {
            $product->active = true;
        }
        if ($idManufacturer !== null && $write('manufacturer')) {
            $product->id_manufacturer = $idManufacturer;
        }
        if ($idSupplier !== null && $write('supplier')) {
            $product->id_supplier = $idSupplier;
        }
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $writeCategories = $categoryIds !== [] && $write('categories');
        if ($writeCategories) {
            $product->id_category_default = $categoryIds[0];
        } elseif ($isNew) {
            $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        }
        if (!$product->save()) {
            throw new \RuntimeException('Unable to save product: ' . $externalId);
        }
        if ($writeCategories && !$product->updateCategories($categoryIds)) {
            throw new \RuntimeException('Unable to update product categories: ' . $externalId);
        }
        if ($isNew && $categoryIds === [] && !$product->updateCategories([(int) $product->id_category_default])) {
            throw new \RuntimeException('Unable to associate new product with its default category.');
        }
        if ($idSupplier !== null) {
            // The feed identifier becomes the supplier reference of the product itself.
            (new ProductSupplierLink())->write((int) $product->id, 0, $idSupplier, $externalId);
        }
        // Stock follows orders, so it always syncs from the feed.
        if (isset($mappedValues['quantity'])) {
            \StockAvailable::setQuantity((int) $product->id, 0, (int) $mappedValues['quantity']);
        }
        if ($writePrice) {
            try {
                (new ProductDiscountSetter())->apply((int) $product->id, $regularPrice, $currentPrice);
            } catch (\Throwable $error) {
                \PrestaShopLogger::addLog('Product import discount skipped: ' . $error->getMessage(), 2, null, 'Product', (int) $product->id);
            }
        }
        $this->importImages($product, $mappedValues);
        if ($written !== []) {
            // Fingerprint the saved store values: PrestaShop may normalize them on save (e.g. HTML purification).
            $product->clearCache();
            $saved = ImportedValues::fingerprint($this->storeValues(new \Product((int) $product->id), $idLangDefault));
            $imported = array_intersect_key($saved, array_flip($written)) + $imported;
        }
        $this->products->link($idSource, $externalId, (int) $product->id, null, $imported);

        return (int) $product->id;
    }

    /** Store values the import can write, normalized to strings for comparison. */
    private function storeValues(\Product $product, int $idLang): array
    {
        $categories = \Db::getInstance()->executeS('SELECT `id_category` FROM `' . _DB_PREFIX_ . 'category_product` WHERE `id_product` = ' . (int) $product->id . ' ORDER BY `id_category`');
        if ($categories === false) {
            throw new \RuntimeException('Unable to read product categories.');
        }

        return [
            'name.' . $idLang => (string) ($product->name[$idLang] ?? ''),
            'description.' . $idLang => ImportedValues::text($product->description[$idLang] ?? ''),
            'short_description.' . $idLang => ImportedValues::text($product->description_short[$idLang] ?? ''),
            'reference' => (string) $product->reference,
            'ean13' => (string) $product->ean13,
            'mpn' => (string) $product->mpn,
            'price' => ImportedValues::number($product->price),
            'wholesale_price' => ImportedValues::number($product->wholesale_price),
            'weight' => ImportedValues::number($product->weight),
            'active' => (string) (int) (bool) $product->active,
            'manufacturer' => (string) (int) $product->id_manufacturer,
            'supplier' => (string) (int) $product->id_supplier,
            'categories' => (int) $product->id_category_default . ':' . implode(',', array_column($categories, 'id_category')),
        ];
    }

    private function importImages(\Product $product, array $values): void
    {
        $urls = [];
        foreach (is_array($values['images'] ?? null) ? $values['images'] : [] as $url) {
            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }
        $main = is_string($values['main_image'] ?? null) ? trim($values['main_image']) : '';
        $main = $main !== '' ? $main : ($urls[0] ?? '');
        if ($main !== '') {
            // Attempt the preferred cover first, including a standalone main_image.
            array_unshift($urls, $main);
        }
        foreach (array_unique($urls) as $url) {
            try {
                (new ProductImageAttacher())->attach((int) $product->id, $url);
            } catch (\Throwable $error) {
                \PrestaShopLogger::addLog('Product import image skipped: ' . $error->getMessage(), 2, null, 'Product', (int) $product->id);
            }
        }
    }

}
