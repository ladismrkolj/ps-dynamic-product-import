<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class ProductImport extends Module
{
    public function __construct()
    {
        $this->name = 'productimport';
        $this->tab = 'administration';
        $this->version = '0.17.1';
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

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiSource'));

        return '';
    }
}
