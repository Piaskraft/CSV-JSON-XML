<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Liczy cenę docelową:
 *  - konwersja PLN->EUR (jeśli potrzeba)
 *  - marża: fixed lub tiered
 *  - końcówki: fixed99 / custom (np. 0.95)
 *  - minimalna marża: ostrzeżenie jeśli poniżej
 */
class PkshPricing
{
    /** 
     * @param array $row   ['price_raw'=>, 'qty_raw'=>]
     * @param array $cfg   wycinek PkshSource (price_currency, rate_mode, fixed_rate, margin*, ending*, min_margin_pct)
     * @param PkshEcbProvider $rates
     * @return array ['price_eur','price_target','rate_used','margin_pct_effective','warnings'=>[]]
     */
    public function compute(array $row, array $cfg, PkshEcbProvider $rates): array
    {
        $warnings = [];
        $priceRaw = $this->toFloat($row['price_raw'] ?? null);

        // 1) Konwersja do EUR
        $priceEur = null;
        $rateUsed = null;

        if (($cfg['price_currency'] ?? 'PLN') === 'PLN') {
            if (($cfg['rate_mode'] ?? 'ecb') === 'fixed') {
                $rateUsed = (float)($cfg['fixed_rate'] ?? 0);
                if ($rateUsed <= 0) {
                    return ['error'=>'fixed_rate<=0'];
                }
                $priceEur = $priceRaw / $rateUsed;
            } else {
                $got = $rates->getPlnPerEur((float)($cfg['fixed_rate'] ?? 0));
                if (!$got['ok'] || $got['rate'] <= 0) {
                    return ['error'=>'rate_not_available'];
                }
                $rateUsed = (float)$got['rate'];
                $priceEur = $priceRaw / $rateUsed;
                if ($got['mode'] !== 'ecb-live' && $got['mode'] !== 'ecb-cache') {
                    $warnings[] = 'rate_fallback_'.$got['mode'];
                }
            }
        } else { // EUR
            $priceEur = $priceRaw;
            $rateUsed = 1.0;
        }

        // sanity
        if ($priceEur === null || $priceEur < 0) {
            return ['error'=>'invalid_price_eur'];
        }

        // 2) Marża
        $marginMode = (string)($cfg['margin_mode'] ?? 'fixed');
        $marginPct  = 0.0;

        if ($marginMode === 'fixed') {
            $marginPct = (float)($cfg['margin_fixed_pct'] ?? 0);
        } else {
            $marginPct = $this->resolveTieredMargin($priceEur, (string)($cfg['margin_tiers'] ?? '[]'));
        }

        $priceWithMargin = $priceEur * (1.0 + ($marginPct / 100.0));

        // 3) Końcówki
        $endingMode = (string)($cfg['ending_mode'] ?? 'none');
        $endingVal  = trim((string)($cfg['ending_value'] ?? ''));
        $priceTarget = $this->applyEnding($priceWithMargin, $endingMode, $endingVal);

        // 4) Minimalna marża (warning)
        $minMarginPct = (float)($cfg['min_margin_pct'] ?? 0);
        $effMarginPct = $priceEur > 0 ? (($priceTarget / $priceEur) - 1.0) * 100.0 : 0.0;
        if ($minMarginPct > 0 && $effMarginPct + 0.0001 < $minMarginPct) {
            $warnings[] = 'below_min_margin';
        }

        return [
            'price_eur'            => round($priceEur, 4),
            'price_target'         => round($priceTarget, 2),
            'rate_used'            => $rateUsed,
            'margin_pct_effective' => round($effMarginPct, 3),
            'warnings'             => $warnings,
        ];
    }

    protected function resolveTieredMargin(float $priceEur, string $tiersJson): float
    {
        $tiers = json_decode($tiersJson, true);
        if (!is_array($tiers)) return 0.0;

        // oczekujemy formatu: [{"from":0,"to":100,"pct":10.0}, ...]
        foreach ($tiers as $t) {
            $from = isset($t['from']) ? (float)$t['from'] : 0;
            $to   = isset($t['to'])   ? (float)$t['to']   : PHP_FLOAT_MAX;
            if ($priceEur >= $from && $priceEur < $to) {
                return (float)($t['pct'] ?? 0.0);
            }
        }
        return 0.0;
    }

    protected function applyEnding(float $price, string $mode, string $value): float
    {
        if ($mode === 'fixed99') {
            $int = floor($price);
            return (float)($int + 0.99);
        }
        if ($mode === 'custom' && $value !== '') {
            // value np. "0.95" → ustaw końcówkę
            $v = (float)str_replace(',', '.', $value);
            $int = floor($price);
            // jeśli price < 1, ustaw po prostu v
            if ($int <= 0) return $v;
            return (float)($int + $v);
        }
        // default: zwykłe zaokrąglenie do 0.01
        return round($price, 2);
    }

    protected function toFloat($v): float
    {
        if (is_string($v)) {
            $v = str_replace([' ', "\xc2\xa0"], '', $v);
            $v = str_replace(',', '.', $v);
        }
        return (float)$v;
    }
}
