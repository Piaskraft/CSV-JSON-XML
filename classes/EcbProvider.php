<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Dostarcza kurs PLN dla 1 EUR (ECB daily).
 * - cache 24h w Configuration (JSON)
 * - fallback: fixed_rate ze źródła
 */
class PkshEcbProvider
{
    const CFG_KEY = 'PKSH_ECB_CACHE_V1';
    const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** Zwraca kurs PLN/EUR (ile PLN za 1 EUR) + info o źródle. */
    public function getPlnPerEur(float $fallbackFixed = null): array
    {
        // 1) Cache (Configuration)
        $cached = Configuration::get(self::CFG_KEY);
        if ($cached) {
            $obj = json_decode($cached, true);
            if (is_array($obj) && isset($obj['rate'], $obj['ts'])) {
                // ważny 24h
                if (time() - (int)$obj['ts'] < 24 * 3600 && $obj['rate'] > 0) {
                    return ['ok'=>true,'rate'=>(float)$obj['rate'],'mode'=>'ecb-cache'];
                }
            }
        }

        // 2) Pobierz z ECB (HttpClient)
        try {
            require_once __DIR__.'/HttpClient.php';
            $http = new PkshHttpClient();
            $res = $http->get(self::ECB_URL, [
                'timeout'=>8,'retries'=>1,'maxBytes'=>1024*1024,
                'allowContentTypes'=>['application/xml','text/xml']
            ]);
            if ($res['ok']) {
                $pln = $this->parseEcbPln($res['body']);
                if ($pln > 0) {
                    Configuration::updateValue(self::CFG_KEY, json_encode([
                        'rate'=>$pln, 'ts'=>time()
                    ]));
                    return ['ok'=>true,'rate'=>$pln,'mode'=>'ecb-live'];
                }
            }
        } catch (Exception $e) {
            // ignore; fallback niżej
        }

        // 3) Fallback: fixed
        if ($fallbackFixed && $fallbackFixed > 0) {
            return ['ok'=>true,'rate'=>(float)$fallbackFixed,'mode'=>'fixed'];
        }

        return ['ok'=>false,'rate'=>0.0,'mode'=>'none','error'=>'no rate'];
    }

    protected function parseEcbPln(string $xml): float
    {
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
        if (!$sx) return 0.0;

        // ECB ma strukturę: Cube/Cube/Cube currency="PLN" rate="x.xx"
        $nodes = $sx->xpath('//Cube/Cube/Cube[@currency="PLN"]');
        if ($nodes && isset($nodes[0]['rate'])) {
            return (float)$nodes[0]['rate'];
        }
        return 0.0;
    }
}
