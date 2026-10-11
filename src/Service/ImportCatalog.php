<?php

namespace ProductImport\Service;

/** Small injectable boundary for catalog operations not owned by the existing importers. */
class ImportCatalog
{
    /** @var array<int,true> products the import is deleting right now; the delete hook must not tombstone them */
    private static array $deleting = [];

    /**
     * The price PrestaShop adds combination impacts to, read after the product price and discount were written:
     * the sale price when the module's always-on discount applies, else the product price (also when the base
     * price is protected as a manual edit, because the store's own values are used).
     */
    public function variantBasePrice(int $idProduct): float
    {
        $price = \Db::getInstance()->getValue(
            'SELECT `price` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . $idProduct
            . ' AND `id_shop` = ' . (int) \Context::getContext()->shop->id
        );
        if ($price === false || $price === null) {
            throw new \RuntimeException('Product does not exist: ' . $idProduct);
        }

        return ProductDiscountSetter::impactBase((float) $price, (new ProductDiscountSetter())->activeReduction($idProduct));
    }

    public static function isDeleting(int $idProduct): bool
    {
        return isset(self::$deleting[$idProduct]);
    }

    public function isProductActive(int $idProduct): bool
    {
        return (bool) $this->product($idProduct)->active;
    }

    /**
     * Returns false, leaving the product alone, when its active flag differs from what the import last wrote.
     * A hidden product with no redirect of the user's own redirects to its default category (301).
     */
    public function deactivateProduct(int $idProduct, ?bool $importedActive = null): bool
    {
        $product = $this->product($idProduct);
        if ($importedActive !== null && (bool) $product->active !== $importedActive) {
            return false;
        }
        $product->active = false;
        if (MissingLifecycle::shouldSetCategoryRedirect((string) $product->redirect_type)) {
            $product->redirect_type = MissingLifecycle::REDIRECT_CATEGORY;
            $product->id_type_redirected = 0;
        }
        if (!$product->save()) {
            throw new \RuntimeException('Unable to deactivate product ' . $idProduct);
        }
        return true;
    }

    /** Deletes the product for good (PrestaShop has no trash). The delete hook ignores it. */
    public function deleteProduct(int $idProduct): void
    {
        $product = $this->product($idProduct);
        self::$deleting[$idProduct] = true;
        try {
            if (!$product->delete()) {
                throw new \RuntimeException('Unable to delete product ' . $idProduct);
            }
        } finally {
            unset(self::$deleting[$idProduct]);
        }
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
