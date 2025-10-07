<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class RollbackService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Przywraca wszystkie snapshoty z danego runu.
     * Zwraca liczbę produktów, którym odtworzono stan.
     */
    public function rollbackRun(int $idRun): int
    {
        $snapshots = $this->db->executeS('
            SELECT * FROM '._DB_PREFIX_.'pksh_snapshot
            WHERE id_run='.(int)$idRun.'
            ORDER BY id_snapshot DESC
        ');
        if (!$snapshots) {
            return 0;
        }

        $count = 0;
        foreach ($snapshots as $s) {
            $idProduct = (int)$s['id_product'];
            $data = json_decode((string)$s['data_json'], true);

            if (!is_array($data)) {
                continue;
            }

            $price   = isset($data['price']) ? (float)$data['price'] : null;
            $qty     = array_key_exists('quantity', $data) ? (int)$data['quantity'] : null;
            $active  = isset($data['active']) ? (int)$data['active'] : null;

            $this->db->execute('START TRANSACTION');

            try {
                if ($price !== null) {
                    $this->db->update('product', ['price' => (float)$price], 'id_product='.(int)$idProduct, 1, true);
                    $this->db->update('product_shop', ['price' => (float)$price], 'id_product='.(int)$idProduct, 0, true);
                }
                if ($active !== null) {
                    $this->db->update('product', ['active' => (int)$active], 'id_product='.(int)$idProduct, 1, true);
                    $this->db->update('product_shop', ['active' => (int)$active], 'id_product='.(int)$idProduct, 0, true);
                }
                if ($qty !== null) {
                    // globalnie (id_product_attribute=0) – multistore możesz rozszerzyć wg id_shop w snapshot
                    $where = 'id_product='.(int)$idProduct.' AND id_product_attribute=0';
                    $exists = (int)$this->db->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'stock_available WHERE '.$where);
                    if ($exists) {
                        $this->db->update('stock_available', ['quantity' => (int)$qty], $where);
                    } else {
                        $this->db->insert('stock_available', [
                            'id_product' => (int)$idProduct,
                            'id_product_attribute' => 0,
                            'quantity' => (int)$qty,
                            'out_of_stock' => 2,
                        ]);
                    }
                }

                $this->db->execute('COMMIT');

                // log „rollback”
                $this->db->insert('pksh_log', [
                    'id_run'     => (int)$idRun,
                    'id_product' => (int)$idProduct,
                    'type'       => pSQL('rollback'),
                    'message'    => pSQL('Snapshot restored'),
                    'before_json'=> null,
                    'after_json' => pSQL(json_encode($data, JSON_UNESCAPED_UNICODE)),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                $count++;
            } catch (\Throwable $e) {
                $this->db->execute('ROLLBACK');
                // błąd w logach
                $this->db->insert('pksh_log', [
                    'id_run'     => (int)$idRun,
                    'id_product' => (int)$idProduct,
                    'type'       => pSQL('error'),
                    'message'    => pSQL('Rollback error: '.$e->getMessage()),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        return $count;
    }
}
