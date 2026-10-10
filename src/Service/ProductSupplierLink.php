<?php

namespace ProductImport\Service;

/**
 * Makes a feed identifier visible as the supplier reference (PrestaShop product_supplier row, shown in the
 * product page's Suppliers section). Link information: always written, no manual-edit protection.
 */
class ProductSupplierLink
{
    public const MAX_LENGTH = 64;

    /** id_product_attribute 0 is the product itself; a combination uses its own id. */
    public function write(int $idProduct, int $idProductAttribute, int $idSupplier, string $reference): void
    {
        if (\Tools::strlen($reference) > self::MAX_LENGTH || !\Validate::isReference($reference)) {
            throw new \InvalidArgumentException('Feed identifier "' . $reference . '" cannot be used as a supplier reference (max ' . self::MAX_LENGTH . ' characters, none of <>;={}).');
        }
        $id = (int) \ProductSupplier::getIdByProductAndSupplier($idProduct, $idProductAttribute, $idSupplier);
        $row = new \ProductSupplier($id ?: null);
        if (!$id) {
            $row->id_product = $idProduct;
            $row->id_product_attribute = $idProductAttribute;
            $row->id_supplier = $idSupplier;
        }
        $row->product_supplier_reference = $reference;
        $row->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        if (!$row->save()) {
            throw new \RuntimeException('Unable to save the supplier reference of product ' . $idProduct . '.');
        }
    }
}
