<?php

namespace ProductImport\Service;

/** Small injectable boundary for catalog operations not owned by the existing importers. */
class ImportCatalog
{
    public function savedPrice(int $idProduct): float
    {
        return (float) $this->product($idProduct)->price;
    }

    /** Returns false, leaving the product alone, when its active flag differs from what the import last wrote. */
    public function deactivateProduct(int $idProduct, ?bool $importedActive = null): bool
    {
        $product = $this->product($idProduct);
        if ($importedActive !== null && (bool) $product->active !== $importedActive) {
            return false;
        }
        $product->active = false;
        if (!$product->save()) {
            throw new \RuntimeException('Unable to deactivate product ' . $idProduct);
        }
        return true;
    }

    public function zeroCombination(int $idProduct, int $idCombination): void
    {
        \StockAvailable::setQuantity($idProduct, $idCombination, 0);
    }

    /** Id of a product other than $exceptProduct that holds this reference, or null. One query. */
    public function productIdByReference(string $reference, ?int $exceptProduct = null): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . "product` WHERE `reference` = '" . pSQL($reference) . "'"
            . ($exceptProduct === null ? '' : ' AND `id_product` <> ' . (int) $exceptProduct)
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    private function product(int $id): \Product
    {
        $product = new \Product($id);
        if (!\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Product does not exist: ' . $id);
        }
        return $product;
    }
}
