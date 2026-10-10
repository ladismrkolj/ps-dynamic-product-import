<?php

namespace ProductImport\Repository;

/** Remembers which image URL produced which product image, per product. */
class ImageSourceRepository
{
    public static function hashUrl(string $url): string
    {
        return sha1($url);
    }

    /** Returns the mapped image only while it still exists for that product; otherwise forgets the mapping. */
    public function findExisting(int $idProduct, string $url): ?int
    {
        $hash = self::hashUrl($url);
        $db = \Db::getInstance();
        $idImage = $db->getValue(
            'SELECT s.`id_image` FROM `' . _DB_PREFIX_ . 'pi_image_source` s'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'image` i ON i.`id_image` = s.`id_image` AND i.`id_product` = s.`id_product`'
            . ' WHERE s.`id_product` = ' . (int) $idProduct . " AND s.`url_hash` = '" . pSQL($hash) . "'"
        );
        if ($idImage !== false && $idImage !== null) {
            return (int) $idImage;
        }
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_image_source` WHERE `id_product` = ' . (int) $idProduct . " AND `url_hash` = '" . pSQL($hash) . "'");

        return null;
    }

    public function remember(int $idProduct, string $url, int $idImage): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_image_source` (`id_product`, `url_hash`, `id_image`) VALUES ('
            . (int) $idProduct . ", '" . pSQL(self::hashUrl($url)) . "', " . (int) $idImage . ') ON DUPLICATE KEY UPDATE `id_image` = VALUES(`id_image`)'
        )) {
            throw new \RuntimeException('Unable to record image source mapping.');
        }
    }

    /** Cheap cleanup of mappings whose image was deleted for this product. */
    public function forgetMissingForProduct(int $idProduct): void
    {
        \Db::getInstance()->execute(
            'DELETE s FROM `' . _DB_PREFIX_ . 'pi_image_source` s LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON i.`id_image` = s.`id_image`'
            . ' WHERE s.`id_product` = ' . (int) $idProduct . ' AND i.`id_image` IS NULL'
        );
    }
}
