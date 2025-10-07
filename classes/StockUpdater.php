<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Aktualizuje stan i aktywność produktu (id_product_attribute=0).
 * Uwzględnia: stock_buffer, zero_qty_policy (disable|backorder|none), id_shop.
 */
class StockUpdater
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * $payload: quantity?, active?, id_shop (int), buffer (int), zero_qty_policy (string)
     */
    public function apply(int $idProduct, array $payload): void
    {
        $idShop  = (int)($payload['id_shop'] ?? 1);
        $buffer  = max(0, (int)($payload['buffer'] ?? 0));
        $policy  = (string)($payload['zero_qty_policy'] ?? 'disable');

        $newQty  = isset($payload['quantity']) ? max(0, (int)$payload['quantity'] - $buffer) : null;
        $newAct  = isset($payload['active']) ? (int)$payload['active'] : null;

        // transakcja
        $this->db->execute('START TRANSACTION');
        try {
            if ($newAct !== null) {
                $this->db->update('product',      ['active'=>$newAct], 'id_product='.(int)$idProduct, 1, true);
                $this->db->update('product_shop', ['active'=>$newAct], 'id_product='.(int)$idProduct.' AND id_shop='.(int)$idShop, 0, true);
            }

            if ($newQty !== null) {
                // stock_available: konkret shop lub global (id_shop=0). Prosto: ustawiamy dla shopu.
                $where = 'id_product='.(int)$idProduct.' AND id_product_attribute=0 AND id_shop='.(int)$idShop;
                $exists = (int)$this->db->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'stock_available WHERE '.$where);
                if ($exists) {
                    $this->db->update('stock_available', ['quantity'=>$newQty], $where);
                } else {
                    $this->db->insert('stock_available', [
                        'id_product' => (int)$idProduct,
                        'id_product_attribute' => 0,
                        'id_shop' => (int)$idShop,
                        'quantity' => $newQty,
                        'out_of_stock' => 2,
                    ]);
                }

                // zero_qty_policy
                if ($newQty <= 0) {
                    if ($policy === 'disable') {
                        $this->db->update('product',      ['active'=>0], 'id_product='.(int)$idProduct, 1, true);
                        $this->db->update('product_shop', ['active'=>0], 'id_product='.(int)$idProduct.' AND id_shop='.(int)$idShop, 0, true);
                    } elseif ($policy === 'backorder') {
                        $this->db->update('stock_available', ['out_of_stock'=>1], $where);
                    }
                }
            }

            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
    }
}
