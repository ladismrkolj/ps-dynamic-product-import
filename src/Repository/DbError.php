<?php

namespace ProductImport\Repository;

class DbError
{
    /** Builds "<prefix>: <database message> (<number>)" from the last failed query and logs it once. */
    public static function message(string $prefix): string
    {
        $db = \Db::getInstance();
        $text = trim((string) $db->getMsgError());
        if ($text === '') {
            return $prefix . '.';
        }
        $message = $prefix . ': ' . $text . ' (' . (int) $db->getNumberError() . ')';
        \PrestaShopLogger::addLog('productimport: ' . $message, 3, (int) $db->getNumberError() ?: null, 'productimport', 0, true);

        return $message;
    }
}
