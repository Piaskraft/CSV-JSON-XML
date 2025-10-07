<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PkshRunService
 *
 * Zadania:
 * - blokada równoległych runów (MySQL GET_LOCK)
 * - start/finish run (pksh_run)
 * - logi (pksh_log) i snapshoty (pksh_snapshot)
 * - wysokopoziomowe runDry/runReal, oparte o DiffService + Updatery
 *
 * Uwaga: Ten serwis NIE renderuje HTML — tylko logika i DB.
 */
class PkshRunService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /* =========================
     *  LOCK
     * ========================= */

    /**
     * Próbuje przejąć blokadę (brak równoległych runów).
     * Zwraca true/false.
     */
    public function acquireLock(): bool
    {
        // MySQL advisory lock: 0s czekania, od razu 1/0
        $row = $this->db->getRow('SELECT GET_LOCK("pksh_run_lock", 0) AS l');
        return isset($row['l']) && (int)$row['l'] === 1;
    }

    public function releaseLock(): void
    {
        $this->db->execute('SELECT RELEASE_LOCK("pksh_run_lock")');
    }

    /* =========================
     *  RUN START/FINISH
     * ========================= */

    public function startRun(int $idSource, bool $dryRun, ?string $checksum = null): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'id_source'  => (int)$idSource,
            'dry_run'    => (int)$dryRun,
            'checksum'   => pSQL((string)$checksum),
            'status'     => pSQL('running'),
            'total'      => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'started_at' => pSQL($now),
            'finished_at'=> null,
        ];
        $ok = $this->db->insert('pksh_run', $data);
        return $ok ? (int)$this->db->Insert_ID() : 0;
    }

    public function finishRun(int $idRun, array $stats, string $status = 'success'): bool
    {
        $now = date('Y-m-d H:i:s');
        $fields = [
            'status'      => pSQL($status),
            'finished_at' => pSQL($now),
        ];
        foreach (['total','updated','skipped','errors'] as $k) {
            if (isset($stats[$k])) {
                $fields[$k] = (int)$stats[$k];
            }
        }
        return $this->db->update('pksh_run', $fields, 'id_run='.(int)$idRun);
    }

    /* =========================
     *  LOGI & SNAPSHOTY
     * ========================= */

    public function log(int $idRun, string $type, string $message, ?int $idProduct = null, $before = null, $after = null): bool
    {
        // before/after jako JSON dla czytelności w logach
        $data = [
            'id_run'     => (int)$idRun,
            'id_product' => $idProduct ? (int)$idProduct : null,
            'type'       => pSQL($type),      // nochange|price|stock|skip|error|disable|enable|rollback
            'message'    => pSQL(Tools::substr($message, 0, 1024)),
            'before_json'=> $before !== null ? pSQL(json_encode($before, JSON_UNESCAPED_UNICODE)) : null,
            'after_json' => $after  !== null ? pSQL(json_encode($after, JSON_UNESCAPED_UNICODE))  : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $this->db->insert('pksh_log', $data);
    }

    /**
     * Zapis bieżącego stanu produktu PRZED zmianą (do rollbacku).
     * $snapshot sugerowany format: ['price'=>.., 'quantity'=>.., 'active'=>.., 'id_shop'=>..]
     */
    public function snapshot(int $idRun, int $idProduct, array $snapshot): bool
    {
        $data = [
            'id_run'     => (int)$idRun,
            'id_product' => (int)$idProduct,
            'data_json'  => pSQL(json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $this->db->insert('pksh_snapshot', $data);
    }

    /* =========================
     *  HIGH LEVEL ORCHESTRATION
     * ========================= */

    /**
     * Wykonaj DRY RUN na podstawie istniejącego pipeline (Preview -> Diff)
     * Zwraca tablicę statystyk.
     */
    public function runDry(int $idSource): array
    {
        $this->guardPipelineAvailability();

        // 1) policz różnice (bez zapisu)
        $diffService = new DiffService();
        $diff = $diffService->compute($idSource, [
            'max_delta_pct_guard' => true, // wewnątrz DiffService powinien respektować guardy
        ]);

        // 2) Statystyki
        $stats = [
            'total'   => (int)$diff['total'] ?? 0,
            'updated' => (int)$diff['affected'] ?? 0,
            'skipped' => (int)$diff['skipped'] ?? 0,
            'errors'  => (int)$diff['errors'] ?? 0,
        ];
        return $stats;
    }

    /**
     * Wykonaj REAL RUN:
     * - wykorzystuje DiffService do policzenia zmian,
     * - używa PriceUpdater/StockUpdater do zapisu,
     * - loguje efekty i snapshoty.
     */
    public function runReal(int $idSource, int $idRun): array
    {
        $this->guardPipelineAvailability();

        $diffService   = new DiffService();
        $stockUpdater  = new StockUpdater();
        $priceUpdater  = new PriceUpdater();

        $diff = $diffService->compute($idSource, [
            'max_delta_pct_guard' => true,
        ]);

        $stats = [
            'total'   => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
        ];

        if (!is_array($diff) || empty($diff['items'])) {
            return $stats;
        }

        $items = $diff['items']; // każdy item: ['id_product', 'changes'=>['price'=>..,'quantity'=>..,'active'=>..], 'reason'=>...]
        $stats['total'] = count($items);

        foreach ($items as $row) {
            $idProduct = (int)($row['id_product'] ?? 0);
            $changes   = $row['changes'] ?? [];
            $reason    = (string)($row['reason'] ?? '');

            if ($idProduct <= 0 || empty($changes)) {
                $this->log($idRun, 'skip', 'Brak id_product lub brak changes', $idProduct, null, $changes);
                $stats['skipped']++;
                continue;
            }

            try {
                // snapshot PRZED zmianą
                $snapshot = $this->readCurrentProductState($idProduct);
                $this->snapshot($idRun, $idProduct, $snapshot);

                // kolejność: cena -> stan/aktywność (żeby spec_price nie dostał wyzerowanej ceny)
                if (isset($changes['price'])) {
                    $priceUpdater->apply($idProduct, $changes['price'], $idSource);
                    $this->log($idRun, 'price', 'Zmieniono cenę '.$reason, $idProduct, ['price'=>$snapshot['price'] ?? null], ['price'=>$changes['price']]);
                }
                if (isset($changes['quantity']) || isset($changes['active'])) {
                    $stockUpdater->apply($idProduct, [
                        'quantity' => $changes['quantity'] ?? null,
                        'active'   => $changes['active'] ?? null,
                        'id_shop'  => $changes['id_shop'] ?? null,
                    ], $idSource);
                    $this->log($idRun, 'stock', 'Zmieniono stan/aktywność '.$reason, $idProduct, ['quantity'=>$snapshot['quantity'] ?? null, 'active'=>$snapshot['active'] ?? null], ['quantity'=>$changes['quantity'] ?? null, 'active'=>$changes['active'] ?? null]);
                }

                $stats['updated']++;
            } catch (\Throwable $e) {
                $this->log($idRun, 'error', 'Wyjątek: '.$e->getMessage(), $idProduct, $changes, null);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Prosty odczyt bieżącego stanu produktu (cena, qty, active).
     * Jeśli używasz kombinacji/spec_price — możesz rozszerzyć pod swoje pole.
     */
    private function readCurrentProductState(int $idProduct): array
    {
        $row = $this->db->getRow('
            SELECT p.active, p.price, IFNULL(sa.quantity, 0) as quantity
            FROM '._DB_PREFIX_.'product p
            LEFT JOIN '._DB_PREFIX_.'stock_available sa ON sa.id_product=p.id_product AND sa.id_product_attribute=0
            WHERE p.id_product='.(int)$idProduct
        );
        return is_array($row) ? $row : [];
    }

    /**
     * Upewnia się, że pipeline istnieje — w razie braków (np. DiffService)
     * rzuci wyjątek z czytelnym komunikatem.
     */
    private function guardPipelineAvailability(): void
    {
        $required = ['DiffService']; // Updatery też:
        foreach ($required as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException('Brak klasy w pipeline: '.$class.'. Upewnij się, że został wgrany plik z implementacją.');
            }
        }
        // Updatery mogą być leniwie wymagane tylko w real run:
        // Sprawdzane dopiero w runReal przy new PriceUpdater/StockUpdater (jeśli brak — \Throwable poleci do logów)
    }
}
