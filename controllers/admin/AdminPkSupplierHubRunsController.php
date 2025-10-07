<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminPkSupplierHubRunsController extends ModuleAdminController
{
    /** @var PkshRunService */
    private $service;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pksh_run';
        $this->className = 'stdClass'; // używamy plain rows
        $this->lang = false;
        $this->list_no_link = true;
        $this->allow_export = false;

        parent::__construct();

        $this->service = new PkshRunService();

        $this->fields_list = [
            'id_run' => ['title' => $this->l('ID'), 'align' => 'text-center', 'class' => 'fixed-width-xs'],
            'id_source' => ['title' => $this->l('Source'), 'align' => 'text-center'],
            'dry_run' => ['title' => $this->l('Dry'), 'type' => 'bool', 'align' => 'text-center'],
            'status' => ['title' => $this->l('Status'), 'align' => 'text-center'],
            'total' => ['title' => $this->l('Total')],
            'updated' => ['title' => $this->l('Updated')],
            'skipped' => ['title' => $this->l('Skipped')],
            'errors' => ['title' => $this->l('Errors')],
            'checksum' => ['title' => $this->l('Checksum')],
            'started_at' => ['title' => $this->l('Started')],
            'finished_at' => ['title' => $this->l('Finished')],
        ];

        $this->bulk_actions = [];
        $this->addRowAction('view');
        $this->addRowAction('export');
    }

    /* =========================
     *  LIST & VIEW
     * ========================= */

    public function renderList()
    {
        $this->toolbar_title = $this->l('Runs & Logs');

        // Szybkie akcje globalne (góra strony)
        $this->page_header_toolbar_btn['refresh'] = [
            'href' => self::$currentIndex.'&token='.$this->token,
            'desc' => $this->l('Refresh'),
            'icon' => 'process-icon-refresh',
        ];

        return parent::renderList();
    }

    public function renderView()
    {
        $idRun = (int)Tools::getValue('id_run');
        if ($idRun <= 0) {
            return $this->displayError($this->l('Missing id_run'));
        }

        // Główka runu
        $run = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'pksh_run WHERE id_run='.(int)$idRun);
        if (!$run) {
            return $this->displayError($this->l('Run not found'));
        }

        // Paginacja logów
        $page  = max(1, (int)Tools::getValue('page', 1));
        $limit = 50;
        $offset= ($page-1)*$limit;

        $logs = Db::getInstance()->executeS('
            SELECT * FROM '._DB_PREFIX_.'pksh_log
            WHERE id_run='.(int)$idRun.'
            ORDER BY id_log ASC
            LIMIT '.$offset.', '.$limit
        );
        $count = (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'pksh_log WHERE id_run='.(int)$idRun);

        // Widok prosty: karta z danymi + tabela logów + przyciski eksport/rollback
        $this->context->smarty->assign([
            'run'   => $run,
            'logs'  => $logs,
            'count' => $count,
            'page'  => $page,
            'pages' => max(1, ceil($count / $limit)),
            'export_link' => $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], [
                'exportLogs' => 1,
                'id_run'     => $idRun,
            ]),
            'rollback_link' => $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], [
                'doRollback' => 1,
                'id_run'     => $idRun,
            ]),
        ]);

        return $this->createTemplate('run_view.tpl')->fetch();
    }

    /* =========================
     *  ACTIONS
     * ========================= */

    public function postProcess()
    {
        // uruchomienie runu z poziomu BO (np. przyciski per źródło)
        if (Tools::isSubmit('doRunDry')) {
            $this->actionRunDry();
        }
        if (Tools::isSubmit('doRunReal')) {
            $this->actionRunReal();
        }

        // eksport logów
        if (Tools::isSubmit('exportLogs')) {
            $this->actionExportLogs();
        }

        // rollback
        if (Tools::isSubmit('doRollback')) {
            $this->actionRollback();
        }

        parent::postProcess();
    }

    protected function actionRunDry()
    {
        $idSource = (int)Tools::getValue('id_source');
        if ($idSource <= 0) {
            $this->errors[] = $this->l('Missing id_source');
            return;
        }

        if (!$this->service->acquireLock()) {
            $this->errors[] = $this->l('Another run is in progress (HTTP 409).');
            return;
        }

        try {
            $checksum = md5('dry-'.$idSource.'-'.time());
            $idRun = $this->service->startRun($idSource, true, $checksum);
            if (!$idRun) {
                throw new \RuntimeException('Cannot start run');
            }

            $stats = $this->service->runDry($idSource);
            $this->service->finishRun($idRun, $stats, 'success');

            $this->confirmations[] = sprintf(
                $this->l('Dry run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );
        } catch (\Throwable $e) {
            if (!empty($idRun)) {
                $this->service->finishRun((int)$idRun, ['errors'=>1], 'failed');
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->service->releaseLock();
        }
    }

    protected function actionRunReal()
    {
        $idSource = (int)Tools::getValue('id_source');
        if ($idSource <= 0) {
            $this->errors[] = $this->l('Missing id_source');
            return;
        }

        if (!$this->service->acquireLock()) {
            $this->errors[] = $this->l('Another run is in progress (HTTP 409).');
            return;
        }

        $idRun = 0;
        try {
            $checksum = md5('real-'.$idSource.'-'.time());
            $idRun = $this->service->startRun($idSource, false, $checksum);
            if (!$idRun) {
                throw new \RuntimeException('Cannot start run');
            }

            $stats = $this->service->runReal($idSource, $idRun);
            $this->service->finishRun($idRun, $stats, 'success');

            $this->confirmations[] = sprintf(
                $this->l('Real run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );
        } catch (\Throwable $e) {
            if ($idRun) {
                $this->service->finishRun($idRun, ['errors'=>1], 'failed');
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->service->releaseLock();
        }
    }

    protected function actionExportLogs()
    {
        $idRun = (int)Tools::getValue('id_run');
        if ($idRun <= 0) {
            $this->errors[] = $this->l('Missing id_run');
            return;
        }

        $rows = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'pksh_log WHERE id_run='.(int)$idRun.' ORDER BY id_log ASC');
        $filename = 'pksh_logs_run_'.$idRun.'.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        // nagłówki
        fputcsv($out, ['id_log','id_run','id_product','type','message','before_json','after_json','created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id_log'], $r['id_run'], $r['id_product'], $r['type'], $r['message'], $r['before_json'], $r['after_json'], $r['created_at']
            ]);
        }
        fclose($out);
        exit;
    }

    protected function actionRollback()
    {
        $idRun = (int)Tools::getValue('id_run');
        if ($idRun <= 0) {
            $this->errors[] = $this->l('Missing id_run');
            return;
        }

        if (!class_exists('RollbackService')) {
            $this->errors[] = $this->l('RollbackService not found. Please add it to the module.');
            return;
        }
        try {
            $svc = new RollbackService();
            $count = $svc->rollbackRun($idRun);
            $this->confirmations[] = sprintf($this->l('Rollback done: %d items restored.'), (int)$count);
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    /* =========================
     *  TEMPLATES
     * ========================= */

    public function createTemplate($tpl_name)
    {
        // Szukamy najpierw w module, potem w override
        $this->context->smarty->setTemplateDir(array_merge(
            [$this->module->getLocalPath().'views/templates/admin/'],
            $this->context->smarty->getTemplateDir()
        ));
        return $this->context->smarty->createTemplate($tpl_name, $this->context->smarty);
    }
}
