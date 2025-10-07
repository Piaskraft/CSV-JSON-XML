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

    /*
     * UWAGA: Modyfikacja metody uninstall() zgodnie z załączonym zrzutem i nową logiką.
     * Sprzątanie zakładek zostało przeniesione do tej metody.
     */
    public function uninstall()
    {
        // najpierw wołamy rodzica
        if (!parent::uninstall()) {
            return false;
        }

        // Sprzątamy zakładki (wcześniej to było w returnie)
        if (!$this->uninstallTabs()) {
            return false;
        }

        // sprzątamy DB (DROP tabel z pliku sql/uninstall.sql)
        return $this->uninstallDb();
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
        // Używamy zaimplementowanej w module logiki dla installDb (z pliku)
        if (!file_exists($path)) {
            return false;
        }
        $sql = Tools::file_get_contents($path);
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

    /**
     * Nowa, szczegółowa implementacja uninstallDb() z Twojej prośby.
     * Zastępuje poprzednią prostszą wersję.
     */
    private function uninstallDb(): bool
    {
        $path = dirname(__FILE__).'/sql/uninstall.sql';
        if (!file_exists($path)) {
            return true; // nic do roboty (bezpiecznie przejść)
        }

        $sql = Tools::file_get_contents($path);
        if ($sql === false) {
            return false;
        }

        // Podmień placeholdery na realne wartości Presty
        $sql = strtr($sql, [
            'PREFIX_'    => _DB_PREFIX_,
            'ENGINE_TYPE'  => _MYSQL_ENGINE_, // zostawione dla spójności z install.sql
        ]);

        // rozbij na pojedyncze komendy
        $queries = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
        foreach ($queries as $q) {
            if (!Db::getInstance()->execute($q)) {
                return false;
            }
        }
        return true;
    }
    
    // Zostawiono tylko to co jest potrzebne do działania (usuwając poprzednie, prostsze uninstallDb() i executeSqlFile())
    
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