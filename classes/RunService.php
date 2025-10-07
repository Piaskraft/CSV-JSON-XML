<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * RunService zgodny ze schematem z install.sql:
 * - status: running|ok|error|aborted
 * - logi: action, old_price, new_price, old_qty, new_qty, reason, details
 * - snapshot: price, quantity, active (bez JSON)
 */
class PkshRunService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /* ========== LOCK (brak równoległych uruchomień) ========== */

    public function acquireLock(): bool
    {
        $row = $this->db->getRow('SELECT GET_LOCK("pksh_run_lock", 0) AS l');
        return isset($row['l']) && (int)$row['l'] === 1;
    }

    public function releaseLock(): void
    {
        $this->db->execute('SELECT RELEASE_LOCK("pksh_run_lock")');
    }

    /* ========== RUN START/FINISH ========== */

    public function startRun(int $idSource, bool $dryRun, ?string $checksum = null): int
    {
        $now = date('Y-m-d H:i:s');
        $idShop = (int)$this->getSourceField($idSource, 'id_shop', 1);

        $ok = $this->db->insert('pksh_run', [
            'id_shop'     => $idShop,
            'id_source'   => (int)$idSource,
            'started_at'  => pSQL($now),
            'finished_at' => null,
            'status'      => pSQL('running'),
            'total'       => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'errors'      => 0,
            'dry_run'     => (int)$dryRun,
            'locked'      => 1,
            'checksum'    => pSQL((string)$checksum),
            'message'     => null,
            'created_at'  => pSQL($now),
            'updated_at'  => pSQL($now),
        ]);
        return $ok ? (int)$this->db->Insert_ID() : 0;
    }

    public function finishRun(int $idRun, array $stats, string $status = 'ok', ?string $message = null): bool
    {
        $now = date('Y-m-d H:i:s');
        $fields = [
            'status'      => pSQL($status), // ok | error | aborted
            'finished_at' => pSQL($now),
            'locked'      => 0,
            'updated_at'  => pSQL($now),
        ];
        foreach (['total','updated','skipped','errors'] as $k) {
            if (isset($stats[$k])) {
                $fields[$k] = (int)$stats[$k];
            }
        }
        if ($message !== null) {
            $fields['message'] = pSQL(Tools::substr($message, 0, 255));
        }
        return $this->db->update('pksh_run', $fields, 'id_run='.(int)$idRun);
    }

    /* ========== LOGI & SNAPSHOTY ========== */

    public function log(int $idRun, string $action, array $data = []): bool
    {
        // $data: id_product?, id_product_attribute?, old_price?, new_price?, old_qty?, new_qty?, reason?, details?
        $row = [
            'id_run'               => (int)$idRun,
            'id_product'           => isset($data['id_product']) ? (int)$data['id_product'] : null,
            'id_product_attribute' => isset($data['id_product_attribute']) ? (int)$data['id_product_attribute'] : null,
            'key_value'            => isset($data['key_value']) ? pSQL($data['key_value']) : null,
            'action'               => pSQL($action), // price|stock|skip|error|nochange|disable|enable|rollback
            'old_price'            => isset($data['old_price']) ? (float)$data['old_price'] : null,
            'new_price'            => isset($data['new_price']) ? (float)$data['new_price'] : null,
            'old_qty'              => isset($data['old_qty']) ? (int)$data['old_qty'] : null,
            'new_qty'              => isset($data['new_qty']) ? (int)$data['new_qty'] : null,
            'reason'               => isset($data['reason']) ? pSQL(Tools::substr((string)$data['reason'], 0, 255)) : null,
            // ✔️ poprawka: start=0, length=65500
            'details'              => isset($data['details']) ? pSQL(Tools::substr((string)$data['details'], 0, 65500)) : null,
            'created_at'           => date('Y-m-d H:i:s'),
        ];
        return $this->db->insert('pksh_log', $row);
    }

    public function snapshot(int $idRun, int $idProduct, array $state): bool
    {
        // $state: price?, quantity?, active?, id_product_attribute?
        $row = [
            'id_run'               => (int)$idRun,
            'id_product'           => (int)$idProduct,
            'id_product_attribute' => isset($state['id_product_attribute']) ? (int)$state['id_product_attribute'] : null,
            'price'                => isset($state['price']) ? (float)$state['price'] : null,
            'quantity'             => isset($state['quantity']) ? (int)$state['quantity'] : null,
            'active'               => isset($state['active']) ? (int)$state['active'] : null,
            'extra'                => isset($state['extra']) ? pSQL(json_encode($state['extra'], JSON_UNESCAPED_UNICODE)) : null,
            'created_at'           => date('Y-m-d H:i:s'),
        ];
        return $this->db->insert('pksh_snapshot', $row);
    }

    /* ========== HIGH LEVEL: DRY / REAL ========== */

    public function runDry(int $idSource): array
    {
        $this->guardPipelineAvailability(['DiffService']);

        $diffService = new DiffService();
        $diff = $diffService->compute($idSource, [
            'max_delta_pct_guard' => true,
        ]);

        return [
            'total'   => (int)($diff['total'] ?? 0),
            'updated' => (int)($diff['affected'] ?? 0),
            'skipped' => (int)($diff['skipped'] ?? 0),
            'errors'  => (int)($diff['errors'] ?? 0),
        ];
    }

    public function runReal(int $idSource, int $idRun): array
    {
        // ✔️ GuardService dołączony do kontroli dostępności
        $this->guardPipelineAvailability(['DiffService','StockUpdater','PriceUpdater','GuardService']);

        $diffService  = new DiffService();
        $stockUpdater = new StockUpdater();
        $priceUpdater = new PriceUpdater();

        $sourceCfg = $this->getSourceConfig($idSource);

        $diff = $diffService->compute($idSource, ['max_delta_pct_guard' => true]);
        $stats = ['total'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];

        if (!is_array($diff) || empty($diff['items'])) {
            return $stats;
        }

        $items = $diff['items'];
        $stats['total'] = count($items);

        foreach ($items as $row) {
            $idProduct = (int)($row['id_product'] ?? 0);
            $changes   = $row['changes'] ?? [];
            $reason    = (string)($row['reason'] ?? '');

            if ($idProduct <= 0 || empty($changes)) {
                $this->log($idRun, 'skip', ['id_product'=>$idProduct, 'reason'=>'missing id_product or changes']);
                $stats['skipped']++;
                continue;
            }

            try {
                // snapshot PRZED zmianą
                $cur = $this->readCurrentProductState($idProduct);
                $this->snapshot($idRun, $idProduct, $cur);

                // GUARD #0 – walidacje twarde (EAN, negative price/qty, anomalie…)
                $guard = new GuardService();
                $guardRes = $guard->validateForUpdate($idProduct, $changes, $sourceCfg);
                if (!$guardRes['ok']) {
                    $this->log($idRun, 'skip', [
                        'id_product' => $idProduct,
                        'reason'     => $guardRes['reason'],
                        'details'    => $guardRes['details'],
                        'old_price'  => isset($cur['price']) ? (float)$cur['price'] : null,
                        'new_price'  => isset($changes['price']) ? (float)$changes['price'] : null,
                        'old_qty'    => isset($cur['quantity']) ? (int)$cur['quantity'] : null,
                        'new_qty'    => isset($changes['quantity']) ? (int)$changes['quantity'] : null,
                    ]);
                    $stats['skipped']++;
                    continue;
                }

                // GUARD #1 – max_delta_pct
                if (isset($changes['price'])) {
                    $newPrice = (float)$changes['price'];
                    $oldPrice = isset($cur['price']) ? (float)$cur['price'] : null;
                    $maxDelta = isset($sourceCfg['max_delta_pct']) ? (float)$sourceCfg['max_delta_pct'] : 50.0;

                    if ($oldPrice !== null && $oldPrice > 0.0) {
                        $deltaPct = abs(($newPrice - $oldPrice) / $oldPrice * 100.0);
                        if ($deltaPct > $maxDelta) {
                            $this->log($idRun, 'skip', [
                                'id_product' => $idProduct,
                                'old_price'  => $oldPrice,
                                'new_price'  => $newPrice,
                                'reason'     => 'max_delta_pct',
                                'details'    => 'Δ='.$deltaPct.'% > '.$maxDelta.'%',
                            ]);
                            $stats['skipped']++;
                            unset($changes['price']); // pozwólmy nadal zmienić qty/active
                        }
                    }
                }

                // ZAPIS: cena -> stan/aktywność
                if (array_key_exists('price', $changes)) {
                    $priceUpdater->apply($idProduct, [
                        'price' => (float)$changes['price'],
                        'id_shop' => (int)$sourceCfg['id_shop'],
                        'mode' => (string)$sourceCfg['price_update_mode'],
                        'tax_rule_group_id' => (int)$sourceCfg['tax_rule_group_id'],
                    ], $idSource);

                    $this->log($idRun, 'price', [
                        'id_product' => $idProduct,
                        'old_price'  => isset($cur['price']) ? (float)$cur['price'] : null,
                        'new_price'  => (float)$changes['price'],
                        'reason'     => $reason,
                    ]);
                }

                if (array_key_exists('quantity', $changes) || array_key_exists('active', $changes)) {
                    $stockUpdater->apply($idProduct, [
                        'quantity' => $changes['quantity'] ?? null,
                        'active'   => $changes['active'] ?? null,
                        'id_shop'  => (int)$sourceCfg['id_shop'],
                        'buffer'   => (int)$sourceCfg['stock_buffer'],
                        'zero_qty_policy' => (string)$sourceCfg['zero_qty_policy'],
                    ]);

                    $this->log($idRun, 'stock', [
                        'id_product' => $idProduct,
                        'old_qty'    => isset($cur['quantity']) ? (int)$cur['quantity'] : null,
                        'new_qty'    => isset($changes['quantity']) ? (int)$changes['quantity'] : null,
                        'reason'     => $reason,
                    ]);
                }

                $stats['updated']++;
            } catch (\Throwable $e) {
                $this->log($idRun, 'error', [
                    'id_product' => $idProduct,
                    'reason'     => 'exception',
                    'details'    => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /* ========== HELPERS ========== */

    private function readCurrentProductState(int $idProduct): array
    {
        $row = $this->db->getRow('
            SELECT p.active, p.price, IFNULL(sa.quantity, 0) as quantity
            FROM '._DB_PREFIX_.'product p
            LEFT JOIN '._DB_PREFIX_.'stock_available sa
                ON sa.id_product=p.id_product AND sa.id_product_attribute=0
            WHERE p.id_product='.(int)$idProduct
        );
        return is_array($row) ? $row : [];
    }

    private function getSourceConfig(int $idSource): array
    {
        $row = $this->db->getRow('SELECT id_shop, price_update_mode, tax_rule_group_id, zero_qty_policy, stock_buffer, max_delta_pct
            FROM '._DB_PREFIX_.'pksh_source WHERE id_source='.(int)$idSource);
        if (!$row) {
            $row = [
                'id_shop'=>1,
                'price_update_mode'=>'impact',
                'tax_rule_group_id'=>0,
                'zero_qty_policy'=>'disable',
                'stock_buffer'=>0,
                'max_delta_pct'=>50.0
            ];
        }
        return $row;
    }

    private function getSourceField(int $idSource, string $field, $default = null)
    {
        return $this->db->getValue('SELECT `'.pSQL($field).'` FROM '._DB_PREFIX_.'pksh_source WHERE id_source='.(int)$idSource) ?: $default;
    }

    private function guardPipelineAvailability(array $classes): void
    {
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException('Brak klasy: '.$class);
            }
        }
    }
}
