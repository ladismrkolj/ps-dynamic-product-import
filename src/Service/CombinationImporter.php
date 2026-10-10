<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalCombinationRepository;

class CombinationImporter
{
    private $combinations;
    private $images;

    public function __construct(ExternalCombinationRepository $combinations, ?ProductImageAttacher $images = null)
    {
        $this->combinations = $combinations;
        $this->images = $images ?? new ProductImageAttacher();
    }

    public function import(
        int $idSource,
        string $parentExternalId,
        string $variantExternalId,
        int $idProduct,
        float $baseProductPrice,
        array $mappedFields,
        array $attributeIds,
        ?int $idSupplier = null
    ): int {
        // Identity is parentExternalId:variantExternalId, scoped by id_source.
        $externalId = $parentExternalId . ':' . $variantExternalId;
        if ($parentExternalId === '' || $variantExternalId === '' || \Tools::strlen($externalId) > 191) {
            throw new \InvalidArgumentException('Parent and variant identifiers are required; combined identity must fit 191 characters.');
        }
        if ($attributeIds === [] || min(array_map('intval', $attributeIds)) <= 0) {
            throw new \InvalidArgumentException('A combination requires resolved positive attribute IDs.');
        }
        $product = new \Product($idProduct);
        if (!\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Combination parent product does not exist.');
        }
        $link = $this->combinations->findLink($idSource, $externalId);
        $id = $link['id_product_attribute'] ?? null;
        $combination = $id === null ? new \Combination() : new \Combination($id);
        if ($id !== null && (!\Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct)) {
            throw new \RuntimeException('Linked combination is missing or belongs to another product.');
        }
        // Fields whose store value differs from what the import last wrote were edited manually: keep them.
        $imported = $link['imported_values'] ?? [];
        $current = $id === null || $imported === [] ? [] : ImportedValues::fingerprint(self::storeValues($combination));
        $written = [];
        $combination->id_product = $idProduct;
        if (isset($mappedFields['mpn']) && !\Validate::isMpn((string) $mappedFields['mpn'])) {
            throw new \InvalidArgumentException('mpn must be at most 40 characters.');
        }
        foreach (['reference', 'ean13', 'mpn'] as $field) {
            if (isset($mappedFields[$field]) && ImportedValues::writable($imported, $current, $field)) {
                $combination->$field = (string) $mappedFields[$field];
                $written[] = $field;
            }
        }
        if (isset($mappedFields['price']) && ImportedValues::writable($imported, $current, 'price')) {
            // product_attribute.price is the impact, using the same tax basis as the base price.
            $combination->price = round((float) $mappedFields['price'] - $baseProductPrice, 6);
            $written[] = 'price';
        }
        $defaultId = (int) \Db::getInstance()->getValue(
            'SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_shop` WHERE `id_product` = ' . (int) $idProduct
            . ' AND `id_shop` = ' . (int) \Context::getContext()->shop->id . ' AND `default_on` = 1'
        );
        // Preserve an existing default; the first imported combination becomes default.
        $combination->default_on = !$defaultId || $defaultId === (int) $combination->id ? 1 : null;
        if (!$combination->save() || !$combination->setAttributes(array_values(array_unique(array_map('intval', $attributeIds))))) {
            throw new \RuntimeException('Unable to save combination or its attributes.');
        }
        if ($idSupplier !== null) {
            // The variant's feed reference becomes the supplier reference of this combination.
            (new ProductSupplierLink())->write($idProduct, (int) $combination->id, $idSupplier, $variantExternalId);
        }
        // Combinations have no native active flag: false means zero stock, not hidden.
        if (isset($mappedFields['active']) && !(bool) $mappedFields['active']) {
            \StockAvailable::setQuantity($idProduct, (int) $combination->id, 0);
        } elseif (isset($mappedFields['quantity'])) {
            \StockAvailable::setQuantity($idProduct, (int) $combination->id, (int) $mappedFields['quantity']);
        }
        $product->updateDefaultAttribute($idProduct);
        if (is_string($mappedFields['image'] ?? null) && trim($mappedFields['image']) !== '') {
            $idImage = null;
            try {
                $idImage = $this->images->attach($idProduct, trim($mappedFields['image']));
                if (!$combination->setImages([$idImage])) {
                    throw new \RuntimeException('Unable to associate combination image.');
                }
            } catch (\Throwable $error) {
                \PrestaShopLogger::addLog('Combination image skipped: ' . $error->getMessage(), 2, null, 'Product', $idProduct);
            }
        }
        $imported = array_intersect_key(ImportedValues::fingerprint(self::storeValues($combination)), array_flip($written)) + $imported;
        $this->combinations->link($idSource, $externalId, (int) $combination->id, $idProduct, null, $imported);

        return (int) $combination->id;
    }

    /** Store values the import can write, normalized to strings for comparison. */
    private static function storeValues(\Combination $combination): array
    {
        return [
            'reference' => (string) $combination->reference,
            'ean13' => (string) $combination->ean13,
            'mpn' => (string) $combination->mpn,
            'price' => ImportedValues::number($combination->price),
        ];
    }
}
