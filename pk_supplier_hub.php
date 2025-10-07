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

    /* =========================
     *  INSTALL / UNINSTALL
     * ========================= */

   public function install()
{
    $ok = parent::install()
        && $this->installTabs()
        && $this->installDb();

    if ($ok && !Configuration::get('PKSH_CRON_TOKEN')) {
        Configuration::updateValue('PKSH_CRON_TOKEN', Tools::substr(sha1(_COOKIE_KEY_.microtime(true)), 0, 28));
    }
    return $ok;
}

    /**
     * Odinstalowanie:
     * 1) parent::uninstall()
     * 2) kasujemy zakładki BO
     * 3) usuwamy tabele wg sql/uninstall.sql
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }
        if (!$this->uninstallTabs()) {
            return false;
        }
        return $this->uninstallDb();
    }

    /* =========================
     *  BACK OFFICE TABS
     * ========================= */

    /**
     * Tworzy: rodzica "PK Supplier Hub" + dzieci:
     *  - Sources
     *  - Runs & Logs
     */
    protected function installTabs()
    {
        // Parent
        $idParent = $this->ensureTab(
            'AdminPkSupplierHub',
            $this->l('PK Supplier Hub'),
            -1,           // Root
            'icon-sync'   // opcjonalna ikonka
        );
        if (!$idParent) {
            return false;
        }

        // Children
        if (!$this->ensureTab('AdminPkSupplierHubSources', $this->l('Sources'), (int)$idParent)) {
            return false;
        }
        if (!$this->ensureTab('AdminPkSupplierHubRuns', $this->l('Runs & Logs'), (int)$idParent)) {
            return false;
        }

        return true;
    }

    /**
     * Usuwamy dzieci -> potem rodzica
     */
    protected function uninstallTabs()
    {
        foreach (['AdminPkSupplierHubRuns', 'AdminPkSupplierHubSources', 'AdminPkSupplierHub'] as $className) {
            $id = (int) Tab::getIdFromClassName($className);
            if ($id) {
                $tab = new Tab($id);
                if (!$tab->delete()) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Zapewnia istnienie zakładki, zwraca jej ID (0 przy błędzie)
     */
    protected function ensureTab($className, $name, $idParent = -1, $icon = null)
    {
        $id = (int) Tab::getIdFromClassName($className);
        if ($id) {
            return $id;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->module = $this->name;
        $tab->id_parent = (int) $idParent;

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $name;
        }

        if ($icon) {
            // W PS8 obsługuje się właściwość icon jako string (font-awesome)
            $tab->icon = $icon;
        }

        if (!$tab->add()) {
            return 0;
        }
        return (int) $tab->id;
    }

    /* =========================
     *  DATABASE
     * ========================= */

    protected function installDb()
    {
        $path = __DIR__ . '/sql/install.sql';
        if (!file_exists($path)) {
            return false;
        }

        $sql = Tools::file_get_contents($path);
        if ($sql === false) {
            return false;
        }

        // Podmień prefix i silnik
        $sql = str_replace(
            ['PREFIX_', 'ENGINE_TYPE'],
            [_DB_PREFIX_, _MYSQL_ENGINE_],
            $sql
        );

        // Rozbij na zapytania – identyczny wzorzec jak w uninstallDb()
        $queries = array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]+/m', $sql))
        );

        $db = Db::getInstance();
        foreach ($queries as $q) {
            if ($q !== '' && !$db->execute($q)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Czyta i wykonuje sql/uninstall.sql (DROP-y w poprawnej kolejności)
     */
    private function uninstallDb(): bool
    {
        $path = __DIR__ . '/sql/uninstall.sql';
        if (!file_exists($path)) {
            // Brak pliku = nic do roboty, uznaj jako sukces
            return true;
        }

        $sql = Tools::file_get_contents($path);
        if ($sql === false) {
            return false;
        }

        $sql = strtr($sql, [
            'PREFIX_'     => _DB_PREFIX_,
            'ENGINE_TYPE' => _MYSQL_ENGINE_, // zostawione dla spójności z install.sql (nieużywane tutaj)
        ]);

        $queries = array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]+/m', $sql))
        );

        $db = Db::getInstance();
        foreach ($queries as $q) {
            if ($q !== '' && !$db->execute($q)) {
                return false;
            }
        }
        return true;
    }

    /* =========================
     *  CONFIG PAGE (DASHBOARD)
     * ========================= */

    public function getContent()
    {
        $html = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $html .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        $linkSources = $this->context->link->getAdminLink('AdminPkSupplierHubSources');
        $linkRuns    = $this->context->link->getAdminLink('AdminPkSupplierHubRuns');

        $html .= '<div class="panel">';
        $html .= '<h3><i class="icon icon-cogs"></i> ' . $this->displayName . '</h3>';
        $html .= '<p>' . $this->l('Hub do synchronizacji cen i stanów z wieloma dostawcami.') . '</p>';

        $html .= '<div class="well">';
        $html .= '<a class="btn btn-primary" href="' . htmlspecialchars($linkSources) . '"><i class="icon-download"></i> ' . $this->l('Open: Sources') . '</a> ';
        $html .= '<a class="btn btn-default" href="' . htmlspecialchars($linkRuns) . '"><i class="icon-list"></i> ' . $this->l('Open: Runs & Logs') . '</a>';
        $html .= '</div>';

        $html .= '<p class="help-block">' . $this->l('Na początek: dodaj źródło dostawcy, wykonaj Dry Run, sprawdź diff, potem Real Run.') . '</p>';
        $html .= '</div>';

        return $html;
    }
}
