<?php

namespace ProductImport\Service;

/** Matches predefined features and values by exact name in one language, creating what is missing. */
class FeatureResolver
{
    /** @var array<string, int> Per-instance (per-run) caches. */
    private array $features = [];
    private array $values = [];

    /** @return array{id_feature: int, id_feature_value: int} */
    public function resolve(string $name, string $value, int $idLang): array
    {
        if (!\Validate::isGenericName($name)) {
            throw new \InvalidArgumentException('feature name contains characters PrestaShop rejects (<>={})');
        }
        if (!\Validate::isGenericName($value)) {
            throw new \InvalidArgumentException('feature value contains characters PrestaShop rejects (<>={})');
        }
        $idFeature = $this->feature($name, $idLang);

        return ['id_feature' => $idFeature, 'id_feature_value' => $this->value($idFeature, $value, $idLang)];
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
        $key = $idFeature . '|' . $idLang . '|' . $value;
        if (isset($this->values[$key])) {
            return $this->values[$key];
        }
        $id = \Db::getInstance()->getValue(
            'SELECT v.`id_feature_value` FROM `' . _DB_PREFIX_ . 'feature_value` v INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` vl ON vl.`id_feature_value` = v.`id_feature_value`'
            . ' WHERE v.`id_feature` = ' . (int) $idFeature . ' AND (v.`custom` IS NULL OR v.`custom` = 0) AND vl.`id_lang` = ' . (int) $idLang
            . " AND BINARY vl.`value` = '" . pSQL($value) . "' ORDER BY v.`id_feature_value`"
        );
        if ($id === false || $id === null) {
            $featureValue = new \FeatureValue();
            $featureValue->id_feature = $idFeature;
            $featureValue->custom = false;
            foreach (\Language::getLanguages(false) as $language) {
                $featureValue->value[(int) $language['id_lang']] = $value;
            }
            if (!$featureValue->add()) {
                throw new \RuntimeException('Unable to create feature value: ' . $value);
            }
            $id = $featureValue->id;
        }

        return $this->values[$key] = (int) $id;
    }
}
