<?php
require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/Source.php';
require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/PreviewService.php';
require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/DiffService.php';
require_once _PS_MODULE_DIR_.'pk_supplier_hub/classes/ProductResolver.php';




class AdminPkSupplierHubSourcesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap  = true;
        $this->table      = 'pksh_source';
        $this->className  = 'PkshSource';
        $this->identifier = 'id_source';
        $this->lang       = false;

        parent::__construct();

        // LISTA
        $this->fields_list = [
            'id_source' => ['title'=>$this->l('ID'), 'align'=>'text-center', 'class'=>'fixed-width-xs'],
            'active'    => ['title'=>$this->l('Active'), 'type'=>'bool', 'align'=>'center'],
            'name'      => ['title'=>$this->l('Name')],
            'file_type' => ['title'=>$this->l('Type')],
            'key_type'  => ['title'=>$this->l('Key')],
            'price_currency'    => ['title'=>$this->l('Currency')],
            'rate_mode'         => ['title'=>$this->l('Rate')],
            'price_update_mode' => ['title'=>$this->l('Price mode')],
            'last_run_at'       => ['title'=>$this->l('Last run'), 'type'=>'datetime'],
        ];

        $this->bulk_actions = [
            'delete' => ['text' => $this->l('Delete selected'), 'icon' => 'icon-trash']
        ];

        // Akcje: edit/delete + details (użyjemy jako PREVIEW)
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->addRowAction('details'); // nazwiemy to "Preview"
    }

    /** FORMULARZ CREATE/EDIT */
    public function renderForm()
    {
        if (!($obj = $this->loadObject(true))) return '';

        $this->fields_form = [
            'legend' => ['title' => $this->l('Source')],
            'input'  => [
                ['type'=>'switch','label'=>$this->l('Active'),'name'=>'active','is_bool'=>true,'values'=>[
                    ['id'=>'on','value'=>1,'label'=>$this->l('Yes')],
                    ['id'=>'off','value'=>0,'label'=>$this->l('No')],
                ]],
                ['type'=>'text','label'=>$this->l('Name'),'name'=>'name','required'=>true],
                ['type'=>'select','label'=>$this->l('File type'),'name'=>'file_type','options'=>[
                    'query'=>[['id'=>'csv','name'=>'csv'],['id'=>'xml','name'=>'xml'],['id'=>'json','name'=>'json']],
                    'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('URL'),'name'=>'url','desc'=>$this->l('HTTP(S) address to the feed')],
                ['type'=>'select','label'=>$this->l('Auth type'),'name'=>'auth_type','options'=>[
                    'query'=>[['id'=>'none','name'=>'none'],['id'=>'basic','name'=>'basic'],['id'=>'bearer','name'=>'bearer'],['id'=>'header','name'=>'header'],['id'=>'query','name'=>'query']],
                    'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Login'),'name'=>'auth_login'],
                ['type'=>'text','label'=>$this->l('Password/Token'),'name'=>'auth_password'],
                ['type'=>'text','label'=>$this->l('Bearer token'),'name'=>'auth_token'],
                ['type'=>'textarea','label'=>$this->l('Headers JSON'),'name'=>'headers','desc'=>$this->l('e.g. {"X-Api-Key":"..."}')],
                ['type'=>'textarea','label'=>$this->l('Query params JSON'),'name'=>'query_params','desc'=>$this->l('e.g. {"key":"value"}')],
                ['type'=>'text','label'=>$this->l('CSV delimiter'),'name'=>'delimiter'],
                ['type'=>'text','label'=>$this->l('CSV enclosure'),'name'=>'enclosure'],
                ['type'=>'text','label'=>$this->l('JSON items path'),'name'=>'items_path','desc'=>$this->l('a.b.c')],
                ['type'=>'text','label'=>$this->l('XML item XPath'),'name'=>'item_xpath','desc'=>$this->l('//root/item')],
                ['type'=>'select','label'=>$this->l('Key type'),'name'=>'key_type','options'=>[
                    'query'=>[['id'=>'ean','name'=>'ean'],['id'=>'reference','name'=>'reference'],['id'=>'supplier_reference','name'=>'supplier_reference']],
                    'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Map: key'),'name'=>'map_col_key','required'=>true],
                ['type'=>'text','label'=>$this->l('Map: price'),'name'=>'map_col_price','required'=>true],
                ['type'=>'text','label'=>$this->l('Map: qty'),'name'=>'map_col_qty','required'=>true],
                ['type'=>'text','label'=>$this->l('Map: variant (opt)'),'name'=>'map_col_variant'],
                ['type'=>'select','label'=>$this->l('Feed currency'),'name'=>'price_currency','options'=>[
                    'query'=>[['id'=>'PLN','name'=>'PLN'],['id'=>'EUR','name'=>'EUR']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'select','label'=>$this->l('Rate mode'),'name'=>'rate_mode','options'=>[
                    'query'=>[['id'=>'ecb','name'=>'ecb'],['id'=>'fixed','name'=>'fixed']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Fixed rate'),'name'=>'fixed_rate'],
                ['type'=>'select','label'=>$this->l('Margin mode'),'name'=>'margin_mode','options'=>[
                    'query'=>[['id'=>'fixed','name'=>'fixed'],['id'=>'tiered','name'=>'tiered']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Margin fixed %'),'name'=>'margin_fixed_pct'],
                ['type'=>'textarea','label'=>$this->l('Margin tiers JSON'),'name'=>'margin_tiers','desc'=>$this->l('[{"from":0,"to":100,"pct":10.0}]')],
                ['type'=>'select','label'=>$this->l('Ending mode'),'name'=>'ending_mode','options'=>[
                    'query'=>[['id'=>'none','name'=>'none'],['id'=>'fixed99','name'=>'fixed99'],['id'=>'custom','name'=>'custom']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Ending value'),'name'=>'ending_value','desc'=>$this->l('e.g. 0.99')],
                ['type'=>'text','label'=>$this->l('Min margin %'),'name'=>'min_margin_pct'],
                ['type'=>'text','label'=>$this->l('Max delta %'),'name'=>'max_delta_pct'],
                ['type'=>'select','label'=>$this->l('Zero qty policy'),'name'=>'zero_qty_policy','options'=>[
                    'query'=>[['id'=>'disable','name'=>'disable'],['id'=>'backorder','name'=>'backorder'],['id'=>'none','name'=>'none']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Stock buffer'),'name'=>'stock_buffer'],
                ['type'=>'select','label'=>$this->l('Price update mode'),'name'=>'price_update_mode','options'=>[
                    'query'=>[['id'=>'impact','name'=>'impact'],['id'=>'specific_price','name'=>'specific_price']], 'id'=>'id','name'=>'name'
                ]],
                ['type'=>'text','label'=>$this->l('Tax rule group ID'),'name'=>'tax_rule_group_id','desc'=>$this->l('0 = keep product default')],
            ],
            'submit' => ['title' => $this->l('Save')],
        ];

        return parent::renderForm();
    }

    /** Kliknięcie w „details” na liście – używamy jako Preview */
    public function renderView()
    {
        $id = (int)Tools::getValue($this->identifier);
        if ($id <= 0) {
            $this->errors[] = $this->l('Invalid source ID.');
            return parent::renderList();
        }

        $source = new PkshSource($id);
        if (!Validate::isLoadedObject($source)) {
            $this->errors[] = $this->l('Source not found.');
            return parent::renderList();
        }
          // jeśli proszą o diff:
if (Tools::getValue('drydiff')) {
    $svc = new PkshDiffService();
    $out = $svc->run($source, 200, true);
    if (!$out['ok']) {
        $this->errors[] = $out['error'];
        return parent::renderList();
    }
    $this->context->smarty->assign([
        'source'  => $source,
        'rows'    => $out['rows'],
        'stats'   => $out['stats'],
        'metrics' => $out['metrics'],
    ]);
    return $this->createTemplate('diff.tpl')->fetch();
}



        try {
            $svc = new PkshPreviewService();
            $out = $svc->run($source, 50);
            if (!$out['ok']) {
                $this->errors[] = $out['error'];
                return parent::renderList();
            }

            $this->context->smarty->assign([
                'source'  => $source,
                'rows'    => $out['rows'],
                'metrics' => $out['metrics'],
                'title'   => $this->l('Preview first 50 records'),
            ]);

            return $this->createTemplate('preview.tpl')->fetch();
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return parent::renderList();
        }
    }

    /** Podmiana etykiety „Details” na „Preview” */
    public function displayDetailsLink($token = null, $id = 0, $name = null)
    {
        $tpl = $this->createTemplate('helpers/list/list_action_view.tpl');
        $tpl->assign([
            'href' => self::$currentIndex.'&'.$this->identifier.'='.(int)$id.'&view'.$this->table.'&token='.($token ?: $this->token),
            'action' => $this->l('Preview'),
            'id' => (int)$id,
        ]);
        return $tpl->fetch();
    }
}
