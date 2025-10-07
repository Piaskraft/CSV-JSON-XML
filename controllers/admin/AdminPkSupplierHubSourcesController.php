<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminPkSupplierHubSourcesController extends ModuleAdminController
{
    /** @var PkshRunService */
    private $runService;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pksh_source';
        $this->className = 'PkshSource';
        $this->lang = false;
        $this->list_no_link = false;
        $this->allow_export = false;

        parent::__construct();

        // kolumny listy
        $this->fields_list = [
            'id_source' => ['title' => $this->l('ID'), 'align' => 'text-center', 'class' => 'fixed-width-xs'],
            'name'      => ['title' => $this->l('Name')],
            'type'      => ['title' => $this->l('Type')],           // csv/json/xml
            'url'       => ['title' => $this->l('URL')],
            'active'    => ['title' => $this->l('Active'), 'type'=>'bool', 'align'=>'text-center'],
            'updated_at'=> ['title' => $this->l('Updated')],
        ];

        // akcje wiersza: edycja + nasze biegi
        $this->addRowAction('edit');
        $this->addRowAction('runDry');
        $this->addRowAction('runReal');

        // formularz edycji robisz już w module – tu wystarczy list + akcje
        $this->runService = new PkshRunService();
    }

    public function renderList()
    {
        $this->toolbar_title = $this->l('Sources');

        // skróty w toolbarze
        $this->page_header_toolbar_btn['runs'] = [
            'href' => $this->context->link->getAdminLink('AdminPkSupplierHubRuns'),
            'desc' => $this->l('Open: Runs & Logs'),
            'icon' => 'process-icon-list'
        ];

        return parent::renderList();
    }

    public function initToolbar()
    {
        parent::initToolbar();
        // bez „Add new” jeśli edycja robiona inną ścieżką – zostaw jak chcesz
    }

    /* ========= ROW ACTION BUTTONS ========= */

    protected function displayRunDryLink($token = null, $id = 0)
    {
        $tpl = $this->createTemplate('helpers/list/list_action.tpl');
        $href = self::$currentIndex.'&token='.$this->token.'&runDry=1&id_source='.(int)$id;
        $tpl->assign([
            'href'  => $href,
            'action'=> $this->l('Run Dry'),
            'id'    => (int)$id,
            'class' => 'btn btn-default',
            'icon'  => 'process-icon-cogs',
        ]);
        return $tpl->fetch();
    }

    protected function displayRunRealLink($token = null, $id = 0)
    {
        $tpl = $this->createTemplate('helpers/list/list_action.tpl');
        $href = self::$currentIndex.'&token='.$this->token.'&runReal=1&id_source='.(int)$id;
        $tpl->assign([
            'href'  => $href,
            'action'=> $this->l('Run Real'),
            'id'    => (int)$id,
            'class' => 'btn btn-warning',
            'icon'  => 'process-icon-play',
        ]);
        return $tpl->fetch();
    }

    public function postProcess()
    {
        // uruchomienia
        if (Tools::isSubmit('runDry')) {
            $this->handleRunDry();
        }
        if (Tools::isSubmit('runReal')) {
            $this->handleRunReal();
        }

        parent::postProcess();
    }

    private function handleRunDry(): void
    {
        $idSource = (int)Tools::getValue('id_source');
        if ($idSource <= 0) {
            $this->errors[] = $this->l('Missing id_source');
            return;
        }

        if (!$this->runService->acquireLock()) {
            $this->errors[] = $this->l('Another run is in progress (HTTP 409).');
            return;
        }

        $idRun = 0;
        try {
            $checksum = md5('dry-'.$idSource.'-'.time());
            $idRun = $this->runService->startRun($idSource, true, $checksum);
            if (!$idRun) {
                throw new \RuntimeException('Cannot start run');
            }

            $stats = $this->runService->runDry($idSource);
            $this->runService->finishRun($idRun, $stats, 'success');

            $this->confirmations[] = sprintf(
                $this->l('Dry run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );

            // szybki link do widoku runu
            $link = $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], ['viewpksh_run'=>1, 'id_run'=>$idRun]);
            $this->confirmations[] = '<a class="btn btn-default" href="'.htmlspecialchars($link).'">'.$this->l('Open this run').'</a>';

        } catch (\Throwable $e) {
            if ($idRun) {
                $this->runService->finishRun($idRun, ['errors'=>1], 'failed');
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->runService->releaseLock();
        }
    }

    private function handleRunReal(): void
    {
        $idSource = (int)Tools::getValue('id_source');
        if ($idSource <= 0) {
            $this->errors[] = $this->l('Missing id_source');
            return;
        }

        if (!$this->runService->acquireLock()) {
            $this->errors[] = $this->l('Another run is in progress (HTTP 409).');
            return;
        }

        $idRun = 0;
        try {
            $checksum = md5('real-'.$idSource.'-'.time());
            $idRun = $this->runService->startRun($idSource, false, $checksum);
            if (!$idRun) {
                throw new \RuntimeException('Cannot start run');
            }

            $stats = $this->runService->runReal($idSource, $idRun);
            $this->runService->finishRun($idRun, $stats, 'success');

            $this->confirmations[] = sprintf(
                $this->l('Real run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );
            $link = $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], ['viewpksh_run'=>1, 'id_run'=>$idRun]);
            $this->confirmations[] = '<a class="btn btn-default" href="'.htmlspecialchars($link).'">'.$this->l('Open this run').'</a>';

        } catch (\Throwable $e) {
            if ($idRun) {
                $this->runService->finishRun($idRun, ['errors'=>1], 'failed');
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->runService->releaseLock();
        }
    }
}
