<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Aktualizacja ceny wg trybu:
 *  - impact: zapis do product/product_shop.price (netto)
 *  - specific_price: tworzy wpis w specific_price z ceną stałą (reduction=0)
 * Uwzględnia id_shop i opcjonalnie tax_rule_group_id (>0).
 */
class PriceUpdater
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * $payload: price (float, netto), id_shop (int), mode ('impact'|'specific_price'), tax_rule_group_id (int)
     */
    public function apply(int $idProduct, array $payload, int $idSource): void
    {
        $price = (float)$payload['price'];
        if ($price < 0) {
            throw new \InvalidArgumentException('Negative price');
        }

        $idShop  = (int)($payload['id_shop'] ?? 1);
        $mode    = (string)($payload['mode'] ?? 'impact');
        $idTrg   = (int)($payload['tax_rule_group_id'] ?? 0);

        $this->db->execute('START TRANSACTION');
        try {
            if ($mode === 'specific_price') {
                // prosty wpis z konkretną ceną
                $this->db->insert('specific_price', [
                    'id_specific_price_rule' => 0,
                    'id_cart' => 0,
                    'id_product' => (int)$idProduct,
                    'id_shop' => (int)$idShop,
                    'id_shop_group' => 0,
                    'id_currency' => 0,
                    'id_country' => 0,
                    'id_group' => 0,
                    'id_customer' => 0,
                    'price' => (float)$price,        // stała cena
                    'from_quantity' => 1,
                    'reduction' => 0,
                    'reduction_tax' => 0,
                    'reduction_type' => 'amount',
                    'from' => '0000-00-00 00:00:00',
                    'to' => '0000-00-00 00:00:00',
                ]);
            } else {
                // impact: bezpośrednia cena produktu (netto)
                $this->db->update('product',      ['price'=>(float)$price], 'id_product='.(int)$idProduct, 1, true);
                $this->db->update('product_shop', ['price'=>(float)$price], 'id_product='.(int)$idProduct.' AND id_shop='.(int)$idShop, 0, true);
                if ($idTrg > 0) {
                    $this->db->update('product',      ['id_tax_rules_group'=>$idTrg], 'id_product='.(int)$idProduct, 1, true);
                    $this->db->update('product_shop', ['id_tax_rules_group'=>$idTrg], 'id_product='.(int)$idProduct.' AND id_shop='.(int)$idShop, 0, true);
                }
            }

            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
    }
}
