<?php

use ProductImport\Repository\ImportRunRepository;

// PS9 removed the l()/translation fallback from AdminController; it
// only exists on ModuleAdminController now.
class AdminPiRunLogController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pi_import_run';
        $this->identifier = 'id_run';
        $this->lang = false;
        parent::__construct();
    }

    public function postProcess()
    {
        // Read-only: never invoke AdminController's generic mutation handlers.
    }

    public function initContent()
    {
        $this->content = $this->access('view') ? $this->renderList() : '';
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign(['content' => $this->content, 'show_page_header_toolbar' => false, 'page_header_toolbar_title' => $this->trans('Product Import runs')]);
    }

    public function renderList()
    {
        $repository = new ImportRunRepository();
        $idSource = (int) Tools::getValue('id_source');
        $rows = $idSource > 0 ? $repository->findRecent($idSource) : $repository->findAll();
        // HelperList's list_content.tpl escapes text values itself (escape:'html'); never pre-escape.
        $helper = new HelperList();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->title = $this->trans('Recent import runs');
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->simple_header = true;
        $helper->actions = [];
        $fields = [];
        foreach (['source_name' => 'Source', 'started_at' => 'Started', 'finished_at' => 'Finished', 'status' => 'Status', 'triggered_by' => 'Triggered by', 'created_count' => 'Created', 'updated_count' => 'Updated', 'unchanged_count' => 'Unchanged', 'skipped_count' => 'Skipped', 'failed_count' => 'Failed', 'deleted_count' => 'Deleted', 'error_log' => 'Errors'] as $key => $label) {
            $fields[$key] = ['title' => $this->trans($label)];
        }
        return $helper->generateList($rows, $fields);
    }
}
