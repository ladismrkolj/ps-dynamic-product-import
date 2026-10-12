<?php

namespace ProductImport\Service;

/**
 * Finds features by exact name (or takes a known id) and their predefined values by normalised text in one language,
 * creating what is missing. A value matches an existing one ignoring case and repeated or edge whitespace; a new value
 * keeps the spelling it was first seen with.
 */
class FeatureResolver
{
    /** @var array<string, int> Per-instance (per-run) caches. */
    private array $features = [];
    /** @var array<string, array<string,int>> "id_feature|id_lang" => normalised value => id_feature_value */
    private array $valueIndex = [];

    /** @return array{id_feature: int, id_feature_value: int} */
    public function resolve(string $name, string $value, int $idLang): array
    {
        if (!\Validate::isGenericName($name)) {
            throw new \InvalidArgumentException('feature name contains characters PrestaShop rejects (<>={})');
        }
        self::checkValue($value);
        $idFeature = $this->feature($name, $idLang);

        return ['id_feature' => $idFeature, 'id_feature_value' => $this->value($idFeature, $value, $idLang)];
    }

    /** @return array{id_feature: int, id_feature_value: int} */
    public function resolveById(int $idFeature, string $value, int $idLang): array
    {
        self::checkValue($value);

        return ['id_feature' => $idFeature, 'id_feature_value' => $this->value($idFeature, $value, $idLang)];
    }

    private static function checkValue(string $value): void
    {
        if (!\Validate::isGenericName($value)) {
            throw new \InvalidArgumentException('feature value contains characters PrestaShop rejects (<>={})');
        }
    }

    private function feature(string $name, int $idLang): int
    {
        $key = $idLang . '|' . $name;
        if (isset($this->features[$key])) {
            return $this->features[$key];
        }
        $id = \Db::getInstance()->getValue(
            'SELECT f.`id_feature` FROM `' . _DB_PREFIX_ . 'feature` f INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON fl.`id_feature` = f.`id_feature`'
            . ' WHERE fl.`id_lang` = ' . (int) $idLang . " AND BINARY fl.`name` = '" . pSQL($name) . "' ORDER BY f.`id_feature`"
        );
        if ($id === false || $id === null) {
            $feature = new \Feature();
            foreach (\Language::getLanguages(false) as $language) {
                $feature->name[(int) $language['id_lang']] = $name;
            }
            if (!$feature->add()) {
                throw new \RuntimeException('Unable to create feature: ' . $name);
            }
            $id = $feature->id;
        }

        return $this->features[$key] = (int) $id;
    }

    private function value(int $idFeature, string $value, int $idLang): int
    {
        $key = $idFeature . '|' . $idLang;
        $this->valueIndex[$key] ??= $this->loadValues($idFeature, $idLang);
        $normalized = FeatureValueMatcher::normalize($value);
        if (isset($this->valueIndex[$key][$normalized])) {
            return $this->valueIndex[$key][$normalized];
        }
        $featureValue = new \FeatureValue();
        $featureValue->id_feature = $idFeature;
        $featureValue->custom = false;
        foreach (\Language::getLanguages(false) as $language) {
            $featureValue->value[(int) $language['id_lang']] = $value;
        }
        if (!$featureValue->add()) {
            throw new \RuntimeException('Unable to create feature value: ' . $value);
        }

        return $this->valueIndex[$key][$normalized] = (int) $featureValue->id;
    }

    /** @return array<string,int> */
    private function loadValues(int $idFeature, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT v.`id_feature_value`, vl.`value` FROM `' . _DB_PREFIX_ . 'feature_value` v INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` vl ON vl.`id_feature_value` = v.`id_feature_value`'
            . ' WHERE v.`id_feature` = ' . (int) $idFeature . ' AND (v.`custom` IS NULL OR v.`custom` = 0) AND vl.`id_lang` = ' . (int) $idLang
            . ' ORDER BY v.`id_feature_value`'
        );
        if ($rows === false) {
            throw new \RuntimeException('Unable to read the values of feature #' . $idFeature);
        }

        return FeatureValueMatcher::index(array_column($rows, 'value', 'id_feature_value'));
    }
}
