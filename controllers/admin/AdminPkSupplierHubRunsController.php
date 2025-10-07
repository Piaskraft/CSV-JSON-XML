<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

@require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/RunService.php';
@require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/RollbackService.php';


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
            'id_source'   => ['title' => $this->l('ID'), 'align' => 'text-center', 'class' => 'fixed-width-xs'],
            'name'        => ['title' => $this->l('Name')],
            'type'        => ['title' => $this->l('Type')],           // csv/json/xml
            'url'         => ['title' => $this->l('URL')],
            'price_update_mode' => ['title'=>$this->l('Price mode')],  // impact/specific_price
            'id_shop'     => ['title' => $this->l('Shop'), 'align'=>'text-center'],
            'active'      => ['title' => $this->l('Active'), 'type'=>'bool', 'align'=>'text-center'],
            'updated_at'  => ['title' => $this->l('Updated')],
        ];

        // akcje wiersza: edycja + nasze biegi
        $this->addRowAction('edit');
        $this->addRowAction('runDry');
        $this->addRowAction('runReal');

        $this->runService = new PkshRunService();
    }

    public function renderList()
    {
        $this->toolbar_title = $this->l('Sources');

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
        // Dodaj przycisk "Add new"
        $this->page_header_toolbar_btn['new'] = [
            'href' => self::$currentIndex.'&addpksh_source=1&token='.$this->token,
            'desc' => $this->l('Add new source'),
            'icon' => 'process-icon-new'
        ];
    }

    /* ========= FORM ========= */

    public function renderForm()
    {
        // Mapa typów i polityk
        $types = [
            ['id'=>'csv','name'=>'CSV'],
            ['id'=>'json','name'=>'JSON'],
            ['id'=>'xml','name'=>'XML'],
        ];
        $priceModes = [
            ['id'=>'impact','name'=>$this->l('Impact (base price)')],
            ['id'=>'specific_price','name'=>$this->l('Specific price')],
        ];
        $zeroQty = [
            ['id'=>'none','name'=>$this->l('None')],
            ['id'=>'disable','name'=>$this->l('Disable product')],
            ['id'=>'backorder','name'=>$this->l('Allow orders (backorder)')],
        ];

        $obj = $this->loadObject(true);
        $isEdit = Validate::isLoadedObject($obj);

        // Maski dla sekretów (pokazujemy gwiazdki, nie nadpisujemy, jeśli puste)
        $masked = '********';

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Source'),
                'icon'  => 'icon-exchange',
            ],
            'input' => [
                ['type'=>'text','label'=>$this->l('Name'),'name'=>'name','required'=>true],
                ['type'=>'switch','label'=>$this->l('Active'),'name'=>'active','is_bool'=>true,
                    'values'=>[
                        ['id'=>'active_on','value'=>1,'label'=>$this->l('Enabled')],
                        ['id'=>'active_off','value'=>0,'label'=>$this->l('Disabled')],
                    ]
                ],
                ['type'=>'select','label'=>$this->l('Type'),'name'=>'type','required'=>true,'options'=>['query'=>$types,'id'=>'id','name'=>'name']],
                ['type'=>'text','label'=>$this->l('URL'),'name'=>'url','required'=>true,'desc'=>$this->l('Feed endpoint')],
                // AUTH
                ['type'=>'select','label'=>$this->l('Auth type'),'name'=>'auth_type','options'=>[
                    'query'=>[
                        ['id'=>'none','name'=>$this->l('None')],
                        ['id'=>'basic','name'=>'Basic'],
                        ['id'=>'bearer','name'=>'Bearer token'],
                    ],
                    'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Auth user'),'name'=>'auth_user','form_group_class'=>'auth-basic'],
                ['type'=>'password','label'=>$this->l('Auth pass'),'name'=>'auth_pass','form_group_class'=>'auth-basic','autocomplete'=>'new-password','placeholder'=>$masked],
                ['type'=>'password','label'=>$this->l('Auth token'),'name'=>'auth_token','form_group_class'=>'auth-bearer','autocomplete'=>'new-password','placeholder'=>$masked],
                // HEADERS (opcjonalnie JSON)
                ['type'=>'textarea','label'=>$this->l('Headers (JSON)'),'name'=>'headers_json','rows'=>3,'cols'=>50,'desc'=>$this->l('Optional HTTP headers as JSON object')],
                // RUNTIME
                ['type'=>'select','label'=>$this->l('Price update mode'),'name'=>'price_update_mode','options'=>['query'=>$priceModes,'id'=>'id','name'=>'name']],
                ['type'=>'text','label'=>$this->l('Tax rule group ID'),'name'=>'tax_rule_group_id','desc'=>$this->l('Optional')],
                ['type'=>'select','label'=>$this->l('Zero qty policy'),'name'=>'zero_qty_policy','options'=>['query'=>$zeroQty,'id'=>'id','name'=>'name']],
                ['type'=>'text','label'=>$this->l('Stock buffer'),'name'=>'stock_buffer','desc'=>$this->l('Subtract this from feed qty')],
                ['type'=>'text','label'=>$this->l('Max delta %'),'name'=>'max_delta_pct','desc'=>$this->l('Guard: max allowed price change %')],
                ['type'=>'text','label'=>$this->l('Shop ID'),'name'=>'id_shop','required'=>true],
            ],
            'submit' => ['title'=>$this->l('Save')]
        ];

        // Ustaw domyślne wartości + maski
        if ($isEdit) {
            // dla pól hasłowych – pokaż maskę w value (front), ale NIE zapisuj jeśli przyjdzie pustka lub maska
            if (!empty($obj->auth_pass)) {
                $obj->auth_pass = $masked;
            }
            if (!empty($obj->auth_token)) {
                $obj->auth_token = $masked;
            }
        }

        return parent::renderForm();
    }

    public function postProcess()
    {
        // uruchomienia
        if (Tools::isSubmit('runDry')) {
            return $this->handleRunDry();
        }
        if (Tools::isSubmit('runReal')) {
            return $this->handleRunReal();
        }

        // Zapis/edycja źródła (maskowanie sekretów + ACL)
        if (Tools::isSubmit('submitAddpksh_source')) {
            try {
                $this->requirePermission('edit');

                // Jeśli maska lub pustka -> nie nadpisuj istniejących wartości
                $masked = '********';
                $id = (int)Tools::getValue('id_source');

                if ($id > 0) {
                    $current = Db::getInstance()->getRow('SELECT auth_pass, auth_token FROM '._DB_PREFIX_.'pksh_source WHERE id_source='.(int)$id);

                    $pass = Tools::getValue('auth_pass');
                    if ($pass === '' || $pass === $masked) {
                        unset($_POST['auth_pass']);
                    }

                    $token = Tools::getValue('auth_token');
                    if ($token === '' || $token === $masked) {
                        unset($_POST['auth_token']);
                    }
                }

                // Headers JSON – walidacja: jeśli puste, zapisuj NULL
                $headers = Tools::getValue('headers_json');
                if ($headers !== '' && $headers !== null) {
                    json_decode($headers, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException($this->l('Headers JSON is invalid.'));
                    }
                }

            } catch (\Throwable $e) {
                $this->errors[] = $e->getMessage();
                return;
            }
        }

        parent::postProcess();
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

    private function requirePermission(string $perm): void
    {
        if (empty($this->tabAccess[$perm])) {
            throw new \RuntimeException($this->l('You do not have permission to perform this action.'));
        }
    }

    private function handleRunDry(): void
    {
        $this->requirePermission('edit');

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
            $this->runService->finishRun($idRun, $stats, 'ok');

            $this->confirmations[] = sprintf(
                $this->l('Dry run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );

            $link = $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], ['viewpksh_run'=>1, 'id_run'=>$idRun]);
            $this->confirmations[] = '<a class="btn btn-default" href="'.htmlspecialchars($link).'">'.$this->l('Open this run').'</a>';

        } catch (\Throwable $e) {
            if ($idRun) {
                $this->runService->finishRun($idRun, ['errors'=>1], 'error', $e->getMessage());
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->runService->releaseLock();
        }
    }

    private function handleRunReal(): void
    {
        $this->requirePermission('edit');

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
            $this->runService->finishRun($idRun, $stats, 'ok');

            $this->confirmations[] = sprintf(
                $this->l('Real run finished. Total: %d, Updated: %d, Skipped: %d, Errors: %d'),
                $stats['total'], $stats['updated'], $stats['skipped'], $stats['errors']
            );
            $link = $this->context->link->getAdminLink('AdminPkSupplierHubRuns', true, [], ['viewpksh_run'=>1, 'id_run'=>$idRun]);
            $this->confirmations[] = '<a class="btn btn-default" href="'.htmlspecialchars($link).'">'.$this->l('Open this run').'</a>';

        } catch (\Throwable $e) {
            if ($idRun) {
                $this->runService->finishRun($idRun, ['errors'=>1], 'error', $e->getMessage());
            }
            $this->errors[] = $e->getMessage();
        } finally {
            $this->runService->releaseLock();
        }
    }
}
