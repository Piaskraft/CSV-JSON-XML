<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Pk_Supplier_Hub extends Module
{
    public function __construct()
    {
        $this->name = 'pk_supplier_hub';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PK';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PK Supplier Hub');
        $this->description = $this->l('Multi-dostawca: sync cen/stanów, PLN→EUR, marże, guardy, dry-run, logi, rollback (PrestaShop 8).');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->installTabs()
            && $this->installDb();
    }

    public function uninstall()
    {
        return $this->uninstallTabs()
            && $this->uninstallDb()
            && parent::uninstall();
    }

    /* ========== TABS (BO) ========== */

    protected function installTabs()
    {
        // Parent
        $idParent = $this->ensureTab(
            'AdminPkSupplierHub',
            $this->l('PK Supplier Hub'),
            -1, // Root
            'icon-sync' // optional FA icon
        );

        // Children
        $this->ensureTab(
            'AdminPkSupplierHubSources',
            $this->l('Sources'),
            (int)$idParent
        );
        $this->ensureTab(
            'AdminPkSupplierHubRuns',
            $this->l('Runs & Logs'),
            (int)$idParent
        );

        return true;
    }

    protected function uninstallTabs()
    {
        foreach (['AdminPkSupplierHubRuns','AdminPkSupplierHubSources','AdminPkSupplierHub'] as $className) {
            if ($id = (int)Tab::getIdFromClassName($className)) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }
        return true;
    }

    protected function ensureTab($className, $name, $idParent = -1, $icon = null)
    {
        $id = (int)Tab::getIdFromClassName($className);
        if ($id) {
            return $id;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->module = $this->name;
        $tab->id_parent = (int)$idParent;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int)$lang['id_lang']] = $name;
        }
        if ($icon) {
            $tab->icon = $icon;
        }
        $tab->add();
        return (int)$tab->id;
    }

    /* ========== DB ========== */

    protected function installDb()
    {
        $path = __DIR__ . '/sql/install.sql';
        return $this->executeSqlFile($path);
    }

    protected function uninstallDb()
    {
        $path = __DIR__ . '/sql/uninstall.sql';
        return $this->executeSqlFile($path);
    }

    protected function executeSqlFile($file)
    {
        if (!file_exists($file)) {
            return false;
        }
        $sql = Tools::file_get_contents($file);
        // Replace prefix & engine
        $sql = str_replace(
            ['PREFIX_', 'ENGINE_TYPE'],
            [_DB_PREFIX_, _MYSQL_ENGINE_],
            $sql
        );
        $queries = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/m', $sql)));
        foreach ($queries as $q) {
            if (!Db::getInstance()->execute($q)) {
                return false;
            }
        }
        return true;
    }

    /* ========== CONFIG PAGE ========== */

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('submit'.$this->name)) {
            $html .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        $linkSources = $this->context->link->getAdminLink('AdminPkSupplierHubSources');
        $linkRuns    = $this->context->link->getAdminLink('AdminPkSupplierHubRuns');

        $html .= '<div class="panel">';
        $html .= '<h3><i class="icon icon-cogs"></i> '.$this->displayName.'</h3>';
        $html .= '<p>'.$this->l('Hub do synchronizacji cen i stanów z wieloma dostawcami.').'</p>';
        $html .= '<div class="well">';
        $html .= '<a class="btn btn-primary" href="'.htmlspecialchars($linkSources).'"><i class="icon-download"></i> '.$this->l('Open: Sources').'</a> ';
        $html .= '<a class="btn btn-default" href="'.htmlspecialchars($linkRuns).'"><i class="icon-list"></i> '.$this->l('Open: Runs & Logs').'</a>';
        $html .= '</div>';
        $html .= '<p class="help-block">'.$this->l('Na początek: dodaj źródło dostawcy, wykonaj Dry Run, sprawdź diff, potem Real Run.').'</p>';
        $html .= '</div>';

        return $html;
    }
}
