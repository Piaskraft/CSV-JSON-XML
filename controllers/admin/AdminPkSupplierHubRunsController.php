<?php
class AdminPkSupplierHubRunsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display   = 'view';
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign([
            'title' => $this->l('Runs & Logs'),
            'subtitle' => $this->l('Podsumowanie uruchomień, logi, eksport, rollback.'),
        ]);
        $this->setTemplate('runs.tpl');
    }
}
