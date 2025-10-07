<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * PreviewService – pobiera feed źródła HttpClientem, parsuje (ParserFactory lub fallback),
 * normalizuje i waliduje (jeśli klasy istnieją), zwraca pierwsze N rekordów + metryki.
 *
 * Działa „na sucho”: jeśli ParserFactory/FeedNormalizer/FeedValidator nie istnieją,
 * ma własne proste fallbacki dla CSV/JSON/XML.
 */
class PreviewService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * @return array ['items'=>[...], 'metrics'=>[...], 'content_type'=>string]
     */
    public function preview(int $idSource, int $limit = 50): array
    {
        $src = $this->getSource($idSource);
        if (!$src) {
            return ['items'=>[], 'metrics'=>['error'=>'source_not_found'], 'content_type'=>null];
        }
        if (empty($src['active'])) {
            return ['items'=>[], 'metrics'=>['error'=>'source_inactive'], 'content_type'=>null];
        }

        // HttpClient z konfiguracją źródła
        $http = new HttpClient([
            'connect_timeout' => 5,
            'response_timeout'=> 20,
            'max_retries'     => 2,
            'rate_limit'      => 6,
            'rate_window'     => 2,
            'auth_type'       => $src['auth_type'] ?: 'none',
            'auth_user'       => $src['auth_user'] ?: null,
            'auth_pass_or_token' => $this->resolveSecret($src),
            'headers'         => $this->decodeHeaders($src['headers_json'] ?? null),
        ]);

        // 1) pobierz
        $res = $http->get($src['url']);
        $ct = $this->resolveContentType($res['headers'], $src['type']); // content-type → csv/json/xml
        $raw = $res['body'];

        // 2) parsuj (fabryka lub fallback)
        $items = $this->parse($ct, $raw);

        // 3) normalizuj, waliduj (jeśli klasy istnieją)
        if (class_exists('FeedNormalizer')) {
            $norm = new FeedNormalizer();
            $items = $norm->normalize($items, $src); // np. ceny netto, mapowanie pól
        }
        $metrics = $this->buildMetrics($items);
        if (class_exists('FeedValidator')) {
            $val = new FeedValidator();
            $report = $val->validateSample($items);
            $metrics['validation'] = $report;
        }

        // 4) przytnij do limitu
        $slice = array_slice($items, 0, max(1, (int)$limit));

        return [
            'items' => $slice,
            'metrics' => array_merge($metrics, [
                'content_type' => $ct,
                'total_in_feed'=> count($items),
            ]),
            'content_type' => $ct,
        ];
    }

    /* ====================== INTERNALS ====================== */

    private function getSource(int $id): ?array
    {
        $row = $this->db->getRow('SELECT * FROM '._DB_PREFIX_.'pksh_source WHERE id_source='.(int)$id);
        return $row ?: null;
    }

    private function resolveSecret(array $src)
    {
        // preferuj pass dla basic, token dla bearer
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

    /**
     * Mapa nagłówka/typu źródła do parsera: csv|json|xml
     */
    private function resolveContentType(array $headers, ?string $fallback): string
    {
        $ct = isset($headers['content-type']) ? strtolower(trim(explode(';', $headers['content-type'])[0])) : '';
        if (strpos($ct, 'json') !== false) return 'json';
        if (strpos($ct, 'xml')  !== false) return 'xml';
        if (strpos($ct, 'csv')  !== false || strpos($ct, 'text/plain') !== false) return 'csv';
        // fallback z definicji źródła
        $f = strtolower((string)$fallback);
        if (in_array($f, ['csv','json','xml'], true)) return $f;
        // domyślnie: json
        return 'json';
        }

    /**
     * Główna logika parsowania – używa ParserFactory jeśli istnieje,
     * inaczej proste fallbacki dla CSV/JSON/XML.
     * @return array of associative arrays
     */
    private function parse(string $type, string $raw): array
    {
        if (class_exists('ParserFactory')) {
            $factory = new ParserFactory();
            $parser = $factory->make($type); // zakładamy signaturę make($type)
            if (is_object($parser) && method_exists($parser, 'parse')) {
                $out = $parser->parse($raw);
                return is_array($out) ? $out : [];
            }
        }

        // fallback własny
        switch ($type) {
            case 'csv':
                return $this->parseCsv($raw);
            case 'xml':
                return $this->parseXml($raw);
            case 'json':
            default:
                return $this->parseJson($raw);
        }
    }

    private function parseJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            // jeśli root ma klucz 'items' lub 'products' itp.
            foreach (['items','products','data','rows'] as $k) {
                if (isset($data[$k]) && is_array($data[$k])) {
                    return $data[$k];
                }
            }
            // jeśli tablica tablic
            if (!empty($data) && isset($data[0]) && is_array($data[0])) {
                return $data;
            }
            // jeśli obiekty proste – opakuj
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
                // wykryj separator (próba , ;)
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
            if (count($rows) > 50000) break; // twardy limit sanity
        }
        fclose($fh);
        return $rows;
    }

    private function parseXml(string $raw): array
    {
        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOCDATA);
        if (!$xml) { return []; }
        $json = json_decode(json_encode($xml), true);
        // heurystyka: znajdź największą tablicę w drzewie
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

    private function buildMetrics(array $items): array
    {
        $total = count($items);
        $keys = [];
        $nulls = 0;
        foreach (array_slice($items, 0, 200) as $row) {
            if (is_array($row)) {
                foreach ($row as $k=>$v) { $keys[$k] = true; if ($v === null || $v === '') { $nulls++; } }
            }
        }
        return [
            'total_sampled' => min(200, $total),
            'unique_keys'   => count($keys),
            'null_fields_in_sample' => $nulls,
        ];
    }
}
