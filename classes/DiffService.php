<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once __DIR__.'/PreviewService.php';
require_once __DIR__.'/ProductResolver.php';

class PkshDiffService
{
    /**
     * Zwraca pierwsze $limit różnic (lub rekordów bez zmian, jeśli $onlyChanges=false).
     */
    public function run(PkshSource $source, int $limit = 200, bool $onlyChanges = true): array
    {
        $preview = (new PkshPreviewService())->run($source, 5000); // skanujemy więcej niż 50, ale i tak limitujemy wynik
        if (!$preview['ok']) return $preview;

        $resolver = new PkshProductResolver();
        $rowsOut = [];
        $stats = ['checked'=>0,'matched'=>0,'changes'=>0,'skipped'=>0];

        foreach ($preview['rows'] as $r) {
            $stats['checked']++;

            // pomin błędne
            if (!empty($r['errors'])) { $stats['skipped']++; continue; }

            $ids = $resolver->find($r['key'], (string)$source->key_type, (int)$source->id_shop);
            if (!$ids['id_product']) { $stats['skipped']++; continue; }
            $stats['matched']++;

            $cur = $resolver->getCurrent($ids, (int)$source->id_shop);
            $deltaPrice = null;
            if ($r['price_target'] !== null && $cur['price'] !== null) {
                $deltaPrice = $cur['price'] != 0 ? (($r['price_target'] - $cur['price']) / $cur['price']) * 100.0 : 100.0;
            }
            $deltaQty = ($r['qty_raw'] !== null && $cur['qty'] !== null) ? ($r['qty_raw'] - $cur['qty']) : null;

            $guardHit = false;
            if ($deltaPrice !== null) {
                $maxDelta = (float)$source->max_delta_pct;
                if ($maxDelta > 0 && abs($deltaPrice) > $maxDelta) {
                    $guardHit = true;
                }
            }

            $changed = ($deltaPrice !== null && abs($deltaPrice) >= 0.01) || ($deltaQty !== null && $deltaQty != 0);

            if ($onlyChanges && !$changed) continue;

            if ($changed) $stats['changes']++;

            $rowsOut[] = [
                'key'       => $r['key'],
                'id_product'=> $ids['id_product'],
                'id_attr'   => $ids['id_product_attribute'],
                'cur_price' => $cur['price'],
                'new_price' => $r['price_target'],
                'delta_price_pct' => $deltaPrice !== null ? round($deltaPrice, 2) : null,
                'cur_qty'   => $cur['qty'],
                'new_qty'   => $r['qty_raw'],
                'delta_qty' => $deltaQty,
                'guard_hit' => $guardHit,
                'warnings'  => $r['warnings'],
            ];

            if (count($rowsOut) >= $limit) break;
        }

        return ['ok'=>true,'rows'=>$rowsOut,'stats'=>$stats,'metrics'=>$preview['metrics']];
    }
}
