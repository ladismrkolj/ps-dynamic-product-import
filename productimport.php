<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class ProductImport extends Module
{
    /** Hooks that keep the import links in step with products and combinations deleted in the store. */
    public const HOOKS = ['actionProductDelete', 'actionObjectCombinationDeleteAfter'];

    public function __construct()
    {
        $this->name = 'productimport';
        $this->tab = 'administration';
        $this->version = '0.19.1';
        $this->author = 'Product Import';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Product Import');
        $this->description = $this->l('Configure JSON or XML sources, filters and product field expressions.');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        foreach (\ProductImport\Service\SchemaCheck::createStatements(_DB_PREFIX_, _MYSQL_ENGINE_) as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }
        if (!Configuration::updateValue('PIIMPORT_CRON_TOKEN', bin2hex(random_bytes(20)))) {
            return false;
        }
        $feedDir = \ProductImport\Service\SchemaMigrator::defaultFeedDir();
        if ((!is_dir($feedDir) && !@mkdir($feedDir, 0775, true) && !is_dir($feedDir)) || !Configuration::updateValue('PIIMPORT_FEED_DIR', $feedDir)) {
            return false;
        }
        if (!\ProductImport\Service\Upgrade\Upgrader::create()->markAllApplied()) {
            return false;
        }
        if (!$this->registerHook(self::HOOKS)) {
            return false;
        }
        foreach (['AdminPiSource' => 'Product Import', 'AdminPiRunLog' => 'Product Import runs'] as $class => $title) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
            foreach (Language::getLanguages(false) as $language) {
                $tab->name[(int) $language['id_lang']] = $title;
            }
            if (!$tab->add()) {
                return false;
            }
        }
        return true;
    }

    public function uninstall()
    {
        foreach (['pi_image_source', 'pi_import_run', 'pi_external_combination', 'pi_external_product', 'pi_category_mapping', 'pi_manufacturer_mapping', 'pi_source'] as $table) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                return false;
            }
        }
        foreach (['AdminPiRunLog', 'AdminPiSource'] as $class) {
            $idTab = (int) Tab::getIdFromClassName($class);
            if ($idTab && !(new Tab($idTab))->delete()) {
                return false;
            }
        }
        if (!Configuration::deleteByName('PIIMPORT_CRON_TOKEN') || !Configuration::deleteByName('PIIMPORT_FEED_DIR') || !Configuration::deleteByName(\ProductImport\Service\Upgrade\DbUpgradeStore::CONFIG_KEY)) {
            return false;
        }

        return parent::uninstall();
    }

    /** A product was deleted: unless this module deleted it, its links become tombstones and the import skips it from now on. */
    public function hookActionProductDelete($params)
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if ($idProduct <= 0 || \ProductImport\Service\ImportCatalog::isDeleting($idProduct)) {
            return;
        }
        try {
            $names = isset($params['product']) && is_object($params['product']) ? $params['product']->name : null;
            $name = is_array($names) ? ($names[(int) Configuration::get('PS_LANG_DEFAULT')] ?? reset($names)) : $names;
            (new \ProductImport\Repository\ExternalProductRepository())->tombstoneProduct($idProduct, is_string($name) ? $name : null);
        } catch (\Throwable $error) {
            PrestaShopLogger::addLog('productimport: unable to record deleted product ' . $idProduct . ': ' . $error->getMessage(), 3, null, 'Product', $idProduct, true);
        }
    }

    /** A combination was deleted by hand: forget its link so the next run recreates it while the feed still has the variant. */
    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $idCombination = isset($params['object']) && is_object($params['object']) ? (int) $params['object']->id : 0;
        if ($idCombination <= 0) {
            return;
        }
        try {
            (new \ProductImport\Repository\ExternalCombinationRepository())->unlinkCombination($idCombination);
        } catch (\Throwable $error) {
            PrestaShopLogger::addLog('productimport: unable to unlink deleted combination ' . $idCombination . ': ' . $error->getMessage(), 3, null, 'Combination', $idCombination, true);
        }
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiSource'));

        return '';
    }
}
