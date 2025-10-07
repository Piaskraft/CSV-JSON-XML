<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Mapuje surowy rekord z parsera na standardowe pola:
 *  - key, price_raw, qty_raw, variant
 * Używa mapowań z PkshSource (map_col_*).
 */
class PkshFeedNormalizer
{
    /**
     * @param array $raw  - rekord z parsera (assoc)
     * @param array $cfg  - fragment konfiguracji źródła:
     *   ['map_col_key','map_col_price','map_col_qty','map_col_variant']
     * @return array ['key','price_raw','qty_raw','variant','warnings'=>[]]
     */
    public function normalize(array $raw, array $cfg): array
    {
        $w = [];

        $keyCol   = (string)($cfg['map_col_key'] ?? '');
        $priceCol = (string)($cfg['map_col_price'] ?? '');
        $qtyCol   = (string)($cfg['map_col_qty'] ?? '');
        $varCol   = (string)($cfg['map_col_variant'] ?? '');

        // wyciągnij wartości
        $key   = $this->getValue($raw, $keyCol);
        $price = $this->getValue($raw, $priceCol);
        $qty   = $this->getValue($raw, $qtyCol);
        $variant = $varCol ? $this->getValue($raw, $varCol) : null;

        // podstawowe sanity
        if ($key === null || $key === '') {
            $w[] = 'empty key';
        }
        if ($price === null || $price === '') {
            $w[] = 'empty price';
        }
        if ($qty === null || $qty === '') {
            $qty = 0;
            $w[] = 'empty qty -> 0';
        }

        return [
            'key'        => is_string($key) ? trim($key) : (string)$key,
            'price_raw'  => $price,
            'qty_raw'    => $qty,
            'variant'    => $variant,
            'warnings'   => $w,
            'raw'        => $raw, // zostawiamy oryginał dla debug
        ];
    }

    /**
     * Pobiera wartość z tablicy po nazwie kolumny/klucza.
     * Obsługuje proste ścieżki kropkowe (np. "pricing.value").
     */
    protected function getValue(array $raw, string $col)
    {
        if ($col === '') return null;
        if (array_key_exists($col, $raw)) {
            return $raw[$col];
        }
        // spróbuj ścieżki kropkowej
        $parts = explode('.', $col);
        $cur = $raw;
        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }
}
