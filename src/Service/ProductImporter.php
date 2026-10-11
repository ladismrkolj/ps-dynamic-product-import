<?php

namespace ProductImport\Service;

use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\DeliveryTimeNoteType;
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
        foreach (['available_now', 'available_later', 'delivery_in_stock', 'delivery_out_stock', 'meta_title', 'meta_description'] as $field) {
            if (isset($mappedValues[$field]) && $write($field . '.' . $idLangDefault)) {
                $translations = is_array($product->$field) ? $product->$field : [];
                $translations[$idLangDefault] = (string) $mappedValues[$field];
                $product->$field = $translations;
            }
        }
        $deliveryTime = isset($mappedValues['delivery_time']) ? (int) $mappedValues['delivery_time'] : (isset($mappedValues['delivery_in_stock']) || isset($mappedValues['delivery_out_stock']) ? DeliveryTimeNoteType::TYPE_SPECIFIC : null);
        if ($deliveryTime !== null && $write('delivery_time')) {
            $product->additional_delivery_times = $deliveryTime;
        }
        if (isset($mappedValues['additional_shipping_cost']) && $write('additional_shipping_cost')) {
            $product->additional_shipping_cost = (float) $mappedValues['additional_shipping_cost'];
        }
        if (isset($mappedValues['on_sale']) && $write('on_sale')) {
            $product->on_sale = (bool) $mappedValues['on_sale'];
        }
        // Resolved by the runner: features as [id_feature, id_feature_value] rows, carriers as id_reference values.
        $writeFeatures = is_array($mappedValues['features'] ?? null) && $write('features');
        $writeCarriers = is_array($mappedValues['carriers'] ?? null) && $write('carriers');
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
        if ($isNew) {
            // Link at once: a failure further down must not leave an orphan that the next run duplicates.
            $this->products->link($idSource, $externalId, (int) $product->id, null, null);
        }
        if ($writeCategories && !$product->updateCategories($categoryIds)) {
            throw new \RuntimeException('Unable to update product categories: ' . $externalId);
        }
        if ($isNew && $categoryIds === [] && !$product->updateCategories([(int) $product->id_category_default])) {
            throw new \RuntimeException('Unable to associate new product with its default category.');
        }
        if ($writeFeatures) {
            $product->deleteFeatures();
            foreach ($mappedValues['features'] as $feature) {
                if (!\Product::addFeatureProductImport((int) $product->id, (int) $feature['id_feature'], (int) $feature['id_feature_value'])) {
                    throw new \RuntimeException('Unable to assign product features: ' . $externalId);
                }
            }
            \SpecificPriceRule::applyAllRules([(int) $product->id]);
        }
        if ($writeCarriers) {
            $product->setCarriers(array_map('intval', $mappedValues['carriers']));
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

    /**
     * Second-pass write of the related products (accessories), once every product of the run exists. Returns false,
     * leaving them alone, when the store holds a list other than the one the import last wrote.
     *
     * @param list<int> $relatedIds
     */
    public function importRelated(int $idSource, string $externalId, int $idProduct, array $relatedIds): bool
    {
        $link = $this->products->findLink($idSource, $externalId);
        $imported = $link['imported_values'] ?? [];
        $current = ImportedValues::fingerprint(['related_products' => self::relatedFingerprint($idProduct)]);
        if (!ImportedValues::writable($imported, $current, 'related_products')) {
            return false;
        }
        $db = \Db::getInstance();
        if (!$db->delete('accessory', 'id_product_1 = ' . $idProduct)) {
            throw new \RuntimeException('Unable to clear related products.');
        }
        \Product::changeAccessoriesForProduct(array_values(array_unique(array_map('intval', $relatedIds))), $idProduct);
        $saved = ImportedValues::fingerprint(['related_products' => self::relatedFingerprint($idProduct)]);
        $this->products->saveImportedValues($idSource, $externalId, $saved + $imported);

        return true;
    }

    private static function relatedFingerprint(int $idProduct): string
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_product_2` FROM `' . _DB_PREFIX_ . 'accessory` WHERE `id_product_1` = ' . $idProduct);
        if ($rows === false) {
            throw new \RuntimeException('Unable to read related products.');
        }

        return NativeFields::listFingerprint(array_column($rows, 'id_product_2'));
    }

    private static function featuresFingerprint(int $idProduct, int $idLang): string
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT fl.`name`, fvl.`value` FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON fl.`id_feature` = fp.`id_feature` AND fl.`id_lang` = ' . $idLang
            . ' INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang
            . ' WHERE fp.`id_product` = ' . $idProduct
        );
        if ($rows === false) {
            throw new \RuntimeException('Unable to read product features.');
        }

        return NativeFields::listFingerprint(array_map(static fn (array $row): string => $row['name'] . '=' . $row['value'], $rows));
    }

    private static function carriersFingerprint(\Product $product): string
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_carrier_reference` FROM `' . _DB_PREFIX_ . 'product_carrier` WHERE `id_product` = ' . (int) $product->id . ' AND `id_shop` = ' . (int) \Context::getContext()->shop->id);
        if ($rows === false) {
            throw new \RuntimeException('Unable to read product carriers.');
        }

        return NativeFields::listFingerprint(array_column($rows, 'id_carrier_reference'));
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
            'available_now.' . $idLang => (string) ($product->available_now[$idLang] ?? ''),
            'available_later.' . $idLang => (string) ($product->available_later[$idLang] ?? ''),
            'delivery_in_stock.' . $idLang => (string) ($product->delivery_in_stock[$idLang] ?? ''),
            'delivery_out_stock.' . $idLang => (string) ($product->delivery_out_stock[$idLang] ?? ''),
            'meta_title.' . $idLang => (string) ($product->meta_title[$idLang] ?? ''),
            'meta_description.' . $idLang => (string) ($product->meta_description[$idLang] ?? ''),
            'delivery_time' => (string) (int) $product->additional_delivery_times,
            'additional_shipping_cost' => ImportedValues::number($product->additional_shipping_cost),
            'on_sale' => (string) (int) (bool) $product->on_sale,
            'features' => self::featuresFingerprint((int) $product->id, $idLang),
            'carriers' => self::carriersFingerprint($product),
            'related_products' => self::relatedFingerprint((int) $product->id),
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
