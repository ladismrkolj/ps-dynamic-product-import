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

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'productimport/views/css/admin.css?v=' . (int) @filemtime(dirname(__DIR__, 3) . '/views/css/admin.css'));
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
        $titles = ['source_name' => 'Source', 'started_at' => 'Started', 'finished_at' => 'Took', 'status' => 'Status', 'triggered_by' => 'Triggered by', 'created_count' => 'Created', 'updated_count' => 'Updated', 'unchanged_count' => 'Unchanged', 'skipped_count' => 'Skipped', 'failed_count' => 'Failed', 'deleted_count' => 'Deleted', 'error_log' => 'Log'];
        foreach ($titles as $key => $label) {
            $fields[$key] = ['title' => $this->trans($label), 'search' => false, 'orderby' => false, 'callback_object' => $this];
            if (substr($key, -6) === '_count') {
                $fields[$key]['align'] = 'text-right';
            }
        }
        $fields['source_name']['callback'] = 'sourceCell';
        $fields['started_at']['callback'] = 'startedCell';
        $fields['finished_at']['callback'] = 'tookCell';
        $fields['status']['callback'] = 'statusCell';
        $fields['error_log']['callback'] = 'logCell';
        // Only the log column may wrap; every other cell stays on one line.
        return $helper->generateList($rows, $fields);
    }

    public function sourceCell($value, $row)
    {
        return Tools::safeOutput($value !== null && $value !== '' ? $value : '(deleted source #' . (int) ($row['id_source'] ?? 0) . ')');
    }

    public function startedCell($value)
    {
        return Tools::safeOutput(substr((string) $value, 0, 16));
    }

    public function tookCell($value, $row)
    {
        $start = strtotime((string) ($row['started_at'] ?? ''));
        $end = strtotime((string) $value);
        if (!$value || $start === false || $end === false) {
            return '';
        }
        $seconds = max(0, $end - $start);

        return $seconds >= 60 ? floor($seconds / 60) . ' min ' . ($seconds % 60) . ' s' : $seconds . ' s';
    }

    public function statusCell($value)
    {
        $kinds = ['completed' => 'ok', 'completed_with_errors' => 'warn', 'failed' => 'bad', 'running' => 'run'];

        return '<span class="pi-badge pi-' . ($kinds[$value] ?? 'off') . '">' . Tools::safeOutput(str_replace('_', ' ', (string) $value)) . '</span>';
    }

    public function logCell($value)
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\R/', $text);
        $first = Tools::safeOutput(mb_substr((string) $lines[0], 0, 120) . (mb_strlen((string) $lines[0]) > 120 ? '…' : ''));
        if (count($lines) === 1 && mb_strlen($text) <= 120) {
            return $first;
        }

        return '<details class="pi-log"><summary>' . $first . ' <span class="pi-muted">(' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')</span></summary><pre>' . Tools::safeOutput($text) . '</pre></details>';
    }
}
