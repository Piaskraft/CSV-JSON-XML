<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * EcbProvider – kursy EUR z ECB z cache 24h + bezpieczny fallback 1.0
 * - używa HttpClient (timeout/retry/ratelimit)
 * - cache w Configuration (PKSH_ECB_CACHE_JSON, PKSH_ECB_CACHE_TS)
 */
class EcbProvider
{
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    private const CFG_JSON = 'PKSH_ECB_CACHE_JSON';
    private const CFG_TS   = 'PKSH_ECB_CACHE_TS';
    private const TTL_SEC  = 86400; // 24h

    /** @var array|null */
    private $rates; // ['USD'=>1.093, 'PLN'=>4.33, ...]

    public function __construct() {}

    /**
     * Zwraca kurs przeliczeniowy z $from do $to.
     * Obsługiwane waluty: wszystko co daje ECB (bazą jest EUR).
     * Fallback: gdy cokolwiek nie wyjdzie – zwracamy 1.0 (bezpiecznie).
     */
    public function getRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));
        if ($from === $to) {
            return 1.0;
        }

        try {
            $this->ensureRates();
            // ECB baza to EUR
            if ($from === 'EUR') {
                return $this->rates[$to] ?? 1.0;
            }
            if ($to === 'EUR') {
                $r = $this->rates[$from] ?? null;
                return $r ? 1.0 / $r : 1.0;
            }
            // przelicz przez EUR
            $rFrom = $this->rates[$from] ?? null;
            $rTo   = $this->rates[$to] ?? null;
            if (!$rFrom || !$rTo) {
                return 1.0;
            }
            return $rTo * (1.0 / $rFrom);
        } catch (\Throwable $e) {
            // fallback safe
            return 1.0;
        }
    }

    private function ensureRates(): void
    {
        if ($this->rates !== null) {
            return;
        }
        $rates = $this->readCache();
        if ($rates !== null) {
            $this->rates = $rates;
            return;
        }
        $fresh = $this->fetchRates();
        if ($fresh) {
            $this->rates = $fresh;
            $this->writeCache($fresh);
            return;
        }
        // ostateczny fallback (EUR only)
        $this->rates = ['EUR' => 1.0];
    }

    /** @return array|null */
    private function readCache()
    {
        if (!class_exists('Configuration')) {
            return null;
        }
        $ts  = (int)Configuration::get(self::CFG_TS);
        $now = time();
        if ($ts && ($now - $ts) < self::TTL_SEC) {
            $json = (string)Configuration::get(self::CFG_JSON);
            if ($json) {
                $arr = json_decode($json, true);
                if (is_array($arr)) {
                    return $arr;
                }
            }
        }
        return null;
    }

    private function writeCache(array $rates): void
    {
        if (!class_exists('Configuration')) {
            return;
        }
        Configuration::updateValue(self::CFG_JSON, json_encode($rates));
        Configuration::updateValue(self::CFG_TS, time());
    }

    /** @return array|null  ['USD'=>1.09, 'PLN'=>4.33, ...] */
    private function fetchRates()
    {
        $http = new HttpClient([
            'connect_timeout' => 5,
            'response_timeout'=> 15,
            'max_retries'     => 2,
            'rate_limit'      => 6,
            'rate_window'     => 2,
        ]);
        $res = $http->get(self::ECB_URL);
        $xml = @simplexml_load_string($res['body']);
        if (!$xml) { return null; }

        // Struktura eurofxref-daily: Cube->Cube time=... -> Cube currency=X rate=Y
        $ns = $xml->xpath('/*/*/*'); // luźny xpath by nie kruszyć się na zmiany
        $out = ['EUR' => 1.0];
        if ($ns) {
            foreach ($ns as $cube) {
                $attrs = $cube->attributes();
                if (isset($attrs['currency']) && isset($attrs['rate'])) {
                    $cur = (string)$attrs['currency'];
                    $rate = (float)$attrs['rate'];
                    if ($cur && $rate > 0) {
                        $out[$cur] = $rate;
                    }
                }
            }
        }
        return count($out) > 1 ? $out : null;
    }
}
