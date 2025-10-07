<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once __DIR__.'/HttpClient.php';
require_once __DIR__.'/parsers/ParserFactory.php';
require_once __DIR__.'/FeedNormalizer.php';
require_once __DIR__.'/FeedValidator.php';
require_once __DIR__.'/EcbProvider.php';
require_once __DIR__.'/Pricing.php';

class PkshPreviewService
{
    public function run(PkshSource $source, int $limit = 50): array
    {
        $t0 = microtime(true);

        // 1) Pobierz plik
        $http = new PkshHttpClient();
        $res = $http->get((string)$source->url, [
            'timeout' => 10,
            'retries' => 2,
            'maxBytes' => 20 * 1024 * 1024,
            'auth' => [
                'type'     => (string)$source->auth_type,
                'login'    => (string)$source->auth_login,
                'password' => (string)$source->auth_password,
                'token'    => (string)$source->auth_token,
            ],
            'headers' => (string)$source->headers,
            'query'   => (string)$source->query_params,
        ]);

        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => 'HTTP '.$res['status'].': '.($res['error'] ?? 'download failed'),
            ];
        }

        // 2) Parser
        $parser = PkshParserFactory::make((string)$source->file_type);
        $cfg = [
            'delimiter'  => (string)$source->delimiter,
            'enclosure'  => (string)$source->enclosure,
            'items_path' => (string)$source->items_path,
            'item_xpath' => (string)$source->item_xpath,
        ];
        $parsed = $parser->parse($res['body'], $cfg);

        $normalizer = new PkshFeedNormalizer();
        $validator  = new PkshFeedValidator();
        $rates      = new PkshEcbProvider();
        $pricing    = new PkshPricing();

        $rows = [];
        $metrics = [
            'total_in_feed' => (int)$parsed['total'],
            'scanned'       => 0,
            'valid'         => 0,
            'with_warnings' => 0,
            'errors'        => 0,
            'rate_mode'     => (string)$source->rate_mode,
            'rate_used'     => null,
        ];

        $sourceCfg = [
            'map_col_key'       => (string)$source->map_col_key,
            'map_col_price'     => (string)$source->map_col_price,
            'map_col_qty'       => (string)$source->map_col_qty,
            'map_col_variant'   => (string)$source->map_col_variant,
            'price_currency'    => (string)$source->price_currency,
            'rate_mode'         => (string)$source->rate_mode,
            'fixed_rate'        => (float)$source->fixed_rate,
            'margin_mode'       => (string)$source->margin_mode,
            'margin_fixed_pct'  => (float)$source->margin_fixed_pct,
            'margin_tiers'      => (string)$source->margin_tiers,
            'ending_mode'       => (string)$source->ending_mode,
            'ending_value'      => (string)$source->ending_value,
            'min_margin_pct'    => (float)$source->min_margin_pct,
        ];

        foreach ($parsed['records'] as $raw) {
            $metrics['scanned']++;

            // a) normalize
            $norm = $normalizer->normalize($raw, $sourceCfg);

            // b) validate
            $val  = $validator->validate($norm, $sourceCfg);
            $ok   = $val['ok'];
            $errs = $val['errors'];
            $warn = array_merge($norm['warnings'] ?? [], $val['warnings']);

            if (!$ok) {
                $metrics['errors']++;
            }

            // c) pricing – tylko jeśli brak błędów kluczowych
            $priceRes = ['price_eur'=>null,'price_target'=>null,'rate_used'=>null,'warnings'=>[]];
            if ($ok) {
                $priceRes = $pricing->compute($val['row'], $sourceCfg, $rates);
                if (!empty($priceRes['error'])) {
                    $ok = false;
                    $errs[] = $priceRes['error'];
                } else {
                    if ($metrics['rate_used'] === null && isset($priceRes['rate_used'])) {
                        $metrics['rate_used'] = $priceRes['rate_used'];
                    }
                    if (!empty($priceRes['warnings'])) {
                        $warn = array_merge($warn, $priceRes['warnings']);
                    }
                }
            }

            if ($ok) $metrics['valid']++;
            if (!empty($warn)) $metrics['with_warnings']++;

            // d) do tabeli (limit 50)
            if (count($rows) < $limit) {
                $rows[] = [
                    'key'          => $val['row']['key'],
                    'price_raw'    => $val['row']['price_raw'],
                    'qty_raw'      => $val['row']['qty_raw'],
                    'price_eur'    => $priceRes['price_eur'],
                    'price_target' => $priceRes['price_target'],
                    'warnings'     => $warn,
                    'errors'       => $errs,
                ];
            }

            // prosty bezpiecznik na ogromne feedy
            if ($metrics['scanned'] > 100000) {
                break;
            }
        }

        $metrics['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);

        return [
            'ok'      => true,
            'rows'    => $rows,
            'metrics' => $metrics,
        ];
    }
}
