<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * GuardService – twarde walidacje przed zapisem.
 * Zwraca wynik w formacie: ['ok'=>bool, 'reason'=>string, 'details'=>string]
 */
class GuardService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Waliduje, czy dany produkt i zmiany mogą zostać zapisane.
     * $sourceCfg może zawierać progi jak: max_delta_pct, qty_max itp.
     */
    public function validateForUpdate(int $idProduct, array $changes, array $sourceCfg): array
    {
        // 1) Produkt musi istnieć
        $prod = $this->db->getRow('SELECT id_product, ean13, active, price FROM '._DB_PREFIX_.'product WHERE id_product='.(int)$idProduct);
        if (!$prod) {
            return $this->fail('not_found', 'product not found');
        }

        // 2) EAN wymagany? (jeśli Twój proces na nim polega – włącz)
        // Jeżeli nie chcesz tej walidacji, zakomentuj 5 linii poniżej.
        $ean = (string)$prod['ean13'];
        if ($ean === '' || $ean === '0') {
            return $this->fail('missing_ean', 'empty ean13');
        }

        // 3) Cena nie może być ujemna
        if (array_key_exists('price', $changes)) {
            $newPrice = (float)$changes['price'];
            if ($newPrice < 0) {
                return $this->fail('negative_price', 'price < 0');
            }
            // 3a) Max delta pct – TU tylko sygnalizujemy; twarde sprawdzenie masz też w RunService
            $maxDelta = isset($sourceCfg['max_delta_pct']) ? (float)$sourceCfg['max_delta_pct'] : 50.0;
            $oldPrice = isset($prod['price']) ? (float)$prod['price'] : null;
            if ($oldPrice !== null && $oldPrice > 0) {
                $deltaPct = abs(($newPrice - $oldPrice) / $oldPrice * 100.0);
                if ($deltaPct > ($maxDelta * 4)) { // ultra-bezpiecznik (np. +200% kiedy guard w diff się wysypie)
                    return $this->fail('delta_insane', 'Δ='.$deltaPct.'% > '.($maxDelta*4).'%');
                }
            }
        }

        // 4) Ilość – nie ujemna, nie absurdalnie duża (domyślny limit 100k)
        if (array_key_exists('quantity', $changes) && $changes['quantity'] !== null) {
            $qty = (int)$changes['quantity'];
            if ($qty < 0) {
                return $this->fail('negative_qty', 'quantity < 0');
            }
            $qtyMax = isset($sourceCfg['qty_max']) ? (int)$sourceCfg['qty_max'] : 100000;
            if ($qty > $qtyMax) {
                return $this->fail('qty_anomaly', 'quantity='.$qty.' > '.$qtyMax);
            }
        }

        // 5) Active może być tylko 0/1 jeśli jest podany
        if (array_key_exists('active', $changes) && $changes['active'] !== null) {
            $a = (int)$changes['active'];
            if (!in_array($a, [0,1], true)) {
                return $this->fail('active_invalid', 'active must be 0|1');
            }
        }

        return ['ok' => true, 'reason' => null, 'details' => null];
    }

    private function fail(string $reason, string $details): array
    {
        return ['ok' => false, 'reason' => $reason, 'details' => $details];
    }
}
