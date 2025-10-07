<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * DiffService
 * - pobiera pełny feed danego źródła (HttpClient)
 * - parsuje (ParserFactory lub fallback CSV/JSON/XML), jak PreviewService
 * - (opcjonalnie) normalizuje, jeśli masz FeedNormalizer
 * - przelicza ceny do EUR przez EcbProvider, jeśli rekord ma 'currency' != 'EUR'
 * - dopasowuje produkty po: ean OR reference OR supplier_reference (w tej kolejności)
 * - tworzy plan zmian: price / quantity (bez zapisu), z guardem max_delta_pct
 *
 * Zwraca:
 * [
 *   'total'    => int,     // rekordów z feedu po wstępnej normalizacji
 *   'affected' => int,     // ile produktów ma jakąś zmianę (price/qty)
 *   'skipped'  => int,     // pominięte (braki, guardy, błędy walidacji)
 *   'errors'   => int,     // błędy niekrytyczne (np. wyjątki per rekord)
 *   'items'    => [
 *      [
 *        'id_product' => 123,
 *        'changes'    => ['price'=>99.99, 'quantity'=>12, 'active'=>1, 'id_shop'=>1],
 *        'reason'     => 'price: 89.99→99.99 (Δ+11.1%); qty: 5→12',
 *      ],
 *      ...
 *   ]
 * ]
 */
class DiffService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * @param int   $idSource
     * @param array $options  ['max_delta_pct_guard'=>bool]
     */
    public function compute(int $idSource, array $options = []): array
    {
        $opts = array_merge(['max_delta_pct_guard' => true], $options);

        // 1) Konfiguracja źródła
        $src = $this->getSource($idSource);
        if (!$src) {
            return ['total'=>0,'affected'=>0,'skipped'=>0,'errors'=>1,'items'=>[]];
        }

        // 2) Pobierz + zparsuj CAŁY feed
        try {
            [$items, $ctype] = $this->fetchAll($src);
        } catch (\Throwable $e) {
            return ['total'=>0,'affected'=>0,'skipped'=>0,'errors'=>1,'items'=>[], 'error'=>$e->getMessage()];
        }

        // 3) Normalizacja (opcjonalnie)
        if (class_exists('FeedNormalizer')) {
            try {
                $norm = new FeedNormalizer();
                $items = $norm->normalize($items, $src);
            } catch (\Throwable $e) {
                // jak normalizacja padnie, używamy surowych items
            }
        }

        $total    = 0;
        $affected = 0;
        $skipped  = 0;
        $errors   = 0;
        $result   = [];

        // 4) Guardy konfiguracyjne
        $idShop    = (int)($src['id_shop'] ?? 1);
        $maxDelta  = isset($src['max_delta_pct']) ? (float)$src['max_delta_pct'] : 50.0;
        $zeroPol   = (string)($src['zero_qty_policy'] ?? 'disable');
        $stockBuf  = (int)($src['stock_buffer'] ?? 0);
        $priceMode = (string)($src['price_update_mode'] ?? 'impact');
        $taxGrpId  = (int)($src['tax_rule_group_id'] ?? 0);

        $ecb = new EcbProvider();

        foreach ($items as $row) {
            $total++;

            // 4a) Wyciągnij klucze identyfikacyjne
            $ean  = $this->val($row, ['ean','ean13','EAN','ean_13']);
            $ref  = $this->val($row, ['reference','sku','SKU']);
            $sref = $this->val($row, ['supplier_reference','supplier_ref','sup_ref']);

            // 4b) Cena i waluta (opcjonalnie)
            $priceRaw = $this->num($this->val($row, ['price','net_price','gross_price','cena']));
            $currency = strtoupper(trim((string)$this->val($row, ['currency','curr','waluta'], 'EUR')));
            $qtyRaw   = $this->int($this->val($row, ['quantity','qty','stock','ilosc'], null));
            $active   = $this->val($row, ['active','enabled','is_active'], null);

            // Skoryguj qty buforem (podglądowo)
            if ($qtyRaw !== null) {
                $qtyRaw = max(0, (int)$qtyRaw - $stockBuf);
            }

            // Konwersja waluty na EUR jeśli trzeba (i mamy sensowną cenę)
            $priceEur = null;
            if ($priceRaw !== null) {
                if ($currency && $currency !== 'EUR') {
                    $rate = $ecb->getRate($currency, 'EUR'); // fallback 1.0 jeśli brak
                    $priceEur = round($priceRaw * $rate, 2);
                } else {
                    $priceEur = round($priceRaw, 2);
                }
            }

            // 4c) Rozwiąż produkt
            $resolved = $this->resolveProduct($ean, $ref, $sref);
            if (!$resolved) {
                $skipped++;
                continue;
            }
            $idProduct = (int)$resolved['id_product'];

            // 4d) Aktualny stan
            $cur = $this->readCurrentProductState($idProduct, $idShop);
            if (!$cur) {
                $skipped++;
                continue;
            }

            $changes = [];
            $reasons = [];

            // 4e) Różnice ceny
            if ($priceEur !== null) {
                $old = isset($cur['price']) ? (float)$cur['price'] : null;
                if ($old === null || $this->differsPrice($old, $priceEur)) {
                    // Guard max delta w DIFF (opcjonalny – drugi jest w RunService)
                    if ($opts['max_delta_pct_guard'] && $old !== null && $old > 0) {
                        $deltaPct = abs(($priceEur - $old) / $old * 100.0);
                        if ($deltaPct > $maxDelta) {
                            $skipped++;
                            continue;
                        }
                    }
                    $changes['price'] = $priceEur;
                    $reasons[] = 'price: '.$this->fmtPrice($old).'→'.$this->fmtPrice($priceEur).' (Δ'. $this->fmtDeltaPct($old, $priceEur) .')';
                }
            }

            // 4f) Różnice ilości
            if ($qtyRaw !== null) {
                $oldQ = isset($cur['quantity']) ? (int)$cur['quantity'] : null;
                if ($oldQ === null || $oldQ !== $qtyRaw) {
                    $changes['quantity'] = $qtyRaw;
                    $reasons[] = 'qty: '.($oldQ===null?'?':$oldQ).'→'.$qtyRaw;
                }
            }

            // 4g) Active (opcjonalnie z feedu)
            if ($active !== null) {
                $a = (int)((string)$active === '1' || strtolower((string)$active) === 'true');
                $oldA = isset($cur['active']) ? (int)$cur['active'] : null;
                if ($oldA === null || $oldA !== $a) {
                    $changes['active'] = $a;
                    $reasons[] = 'active: '.($oldA===1?'1':'0').'→'.($a===1?'1':'0');
                }
            }

            if (empty($changes)) {
                // nochange
                continue;
            }

            // dołącz kontekst sklepu do changes (dla updaterów)
            $changes['id_shop'] = $idShop;

            $result[] = [
                'id_product' => $idProduct,
                'changes'    => $changes,
                'reason'     => implode('; ', $reasons),
                // przydatne debug-info (nie wykorzystywane przez run):
                'key'        => $ean ?: ($ref ?: $sref),
                'price_mode' => $priceMode,
                'tax_rule_group_id' => $taxGrpId,
                'zero_qty_policy'   => $zeroPol,
            ];
            $affected++;
        }

        return [
            'total'    => $total,
            'affected' => $affected,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'items'    => $result,
        ];
    }

    /* ============================ FETCH & PARSE ============================ */

    /** @return array [items[], 'type'] */
    private function fetchAll(array $src): array
    {
        $http = new HttpClient([
            'connect_timeout' => 5,
            'response_timeout'=> 25,
            'max_retries'     => 2,
            'rate_limit'      => 6,
            'rate_window'     => 2,
            'auth_type'       => $src['auth_type'] ?? 'none',
            'auth_user'       => $src['auth_user'] ?? null,
            'auth_pass_or_token' => $this->resolveSecret($src),
            'headers'         => $this->decodeHeaders($src['headers_json'] ?? null),
        ]);

        $res = $http->get($src['url']);
        $ct  = $this->resolveContentType($res['headers'], $src['type'] ?? null);
        $raw = $res['body'];

        // ParserFactory jeśli jest
        if (class_exists('ParserFactory')) {
            $factory = new ParserFactory();
            $parser  = $factory->make($ct);
            if (is_object($parser) && method_exists($parser, 'parse')) {
                $data = $parser->parse($raw);
                return [is_array($data) ? $data : [], $ct];
            }
        }

        // Fallback: jak w PreviewService
        switch ($ct) {
            case 'csv':  $items = $this->parseCsv($raw); break;
            case 'xml':  $items = $this->parseXml($raw); break;
            case 'json':
            default:     $items = $this->parseJson($raw); break;
        }
        return [$items, $ct];
    }

    private function resolveSecret(array $src)
    {
        if (($src['auth_type'] ?? '') === 'basic') {
            return $src['auth_pass'] ?? null;
        }
        if (($src['auth_type'] ?? '') === 'bearer') {
            return $src['auth_token'] ?? null;
        }
        return null;
    }

    private function decodeHeaders(?string $json): array
    {
        if (!$json) return [];
        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $k=>$v) {
            $out[] = trim($k).': '.trim((string)$v);
        }
        return $out;
    }

    private function resolveContentType(array $headers, ?string $fallback): string
    {
        $ct = isset($headers['content-type']) ? strtolower(trim(explode(';', $headers['content-type'])[0])) : '';
        if (strpos($ct, 'json') !== false) return 'json';
        if (strpos($ct, 'xml')  !== false) return 'xml';
        if (strpos($ct, 'csv')  !== false || strpos($ct, 'text/plain') !== false) return 'csv';
        $f = strtolower((string)$fallback);
        if (in_array($f, ['csv','json','xml'], true)) return $f;
        return 'json';
    }

    private function parseJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            foreach (['items','products','data','rows'] as $k) {
                if (isset($data[$k]) && is_array($data[$k])) {
                    return $data[$k];
                }
            }
            if (!empty($data) && isset($data[0]) && is_array($data[0])) {
                return $data;
            }
            return [$data];
        }
        return [];
    }

    private function parseCsv(string $raw): array
    {
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = null;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if ($header === null) {
                if (count($row) === 1) {
                    rewind($fh); ftruncate($fh, 0); fwrite($fh, str_replace(';', ',', $raw)); rewind($fh);
                    $row = fgetcsv($fh, 0, ',');
                }
                $header = $row;
                continue;
            }
            $assoc = [];
            foreach ($header as $i=>$h) {
                $assoc[trim((string)$h)] = $row[$i] ?? null;
            }
            $rows[] = $assoc;
            if (count($rows) > 200000) break; // safety cap
        }
        fclose($fh);
        return $rows;
    }

    private function parseXml(string $raw): array
    {
        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOCDATA);
        if (!$xml) { return []; }
        $json = json_decode(json_encode($xml), true);
        $flat = $this->findLargestArray($json);
        return is_array($flat) ? $flat : [];
    }
    private function findLargestArray($node)
    {
        if (is_array($node)) {
            $best = null; $bestCount = 0;
            if ($this->isList($node)) { $best = $node; $bestCount = count($node); }
            foreach ($node as $v) {
                $cand = $this->findLargestArray($v);
                $c = is_array($cand) ? count($cand) : 0;
                if ($c > $bestCount) { $best = $cand; $bestCount = $c; }
            }
            return $best;
        }
        return null;
    }
    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr)-1);
    }

    /* ============================ RESOLVE & CURRENT ============================ */

    private function resolveProduct(?string $ean, ?string $ref, ?string $sref): ?array
    {
        // Jeśli masz własny ProductResolver – użyj go
        if (class_exists('ProductResolver')) {
            try {
                $resolver = new ProductResolver();
                $id = $resolver->resolve(['ean'=>$ean,'reference'=>$ref,'supplier_reference'=>$sref]);
                if ($id) {
                    return ['id_product'=>(int)$id];
                }
            } catch (\Throwable $e) {
                // fallback do raw SQL poniżej
            }
        }

        // Prosty fallback SQL – priorytet: EAN -> REF -> SUPPLIER_REF
        if ($ean) {
            $id = (int)$this->db->getValue('SELECT id_product FROM '._DB_PREFIX_.'product WHERE ean13="'.pSQL($ean).'"');
            if ($id) return ['id_product'=>$id];
        }
        if ($ref) {
            $id = (int)$this->db->getValue('SELECT id_product FROM '._DB_PREFIX_.'product WHERE reference="'.pSQL($ref).'"');
            if ($id) return ['id_product'=>$id];
        }
        if ($sref) {
            $id = (int)$this->db->getValue('SELECT id_product FROM '._DB_PREFIX_.'product WHERE supplier_reference="'.pSQL($sref).'"');
            if ($id) return ['id_product'=>$id];
        }
        return null;
    }

    private function readCurrentProductState(int $idProduct, int $idShop): ?array
    {
        $row = $this->db->getRow('
            SELECT p.active, ps.price, IFNULL(sa.quantity, 0) as quantity
            FROM '._DB_PREFIX_.'product_shop ps
            INNER JOIN '._DB_PREFIX_.'product p ON p.id_product=ps.id_product
            LEFT JOIN '._DB_PREFIX_.'stock_available sa
                ON sa.id_product=ps.id_product AND sa.id_product_attribute=0 AND sa.id_shop='.(int)$idShop.'
            WHERE ps.id_product='.(int)$idProduct.' AND ps.id_shop='.(int)$idShop
        );
        return $row ?: null;
    }

    /* ============================ UTILS ============================ */

    private function val($row, array $keys, $default = null)
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return $default;
    }

    private function num($v)
    {
        if ($v === null || $v === '') return null;
        // zamiana przecinka na kropkę
        $v = str_replace([' ', "\xC2\xA0"], '', (string)$v);
        $v = str_replace(',', '.', $v);
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private function int($v)
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private function differsPrice(float $a, float $b): bool
    {
        return abs($a - $b) >= 0.0005; // epsilon
    }

    private function fmtPrice($v): string
    {
        return $v === null ? '?' : number_format((float)$v, 2, '.', '');
    }

    private function fmtDeltaPct($old, $new): string
    {
        if ($old === null || $old == 0) return 'n/a';
        $pct = (($new - $old) / $old) * 100.0;
        $sign = $pct > 0 ? '+' : '';
        return $sign . number_format($pct, 1, '.', '') . '%';
    }

    private function getSource(int $id): ?array
    {
        $row = $this->db->getRow('SELECT * FROM '._DB_PREFIX_.'pksh_source WHERE id_source='.(int)$id);
        return $row ?: null;
    }
}
