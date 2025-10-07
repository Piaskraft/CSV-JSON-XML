<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Prosty HTTP GET z obsługą:
 * - auth: none/basic/bearer/header/query
 * - timeout, retry (exponential backoff)
 * - limit rozmiaru (maxBytes)
 * - whitelist MIME
 */
class PkshHttpClient
{
    public function get(string $url, array $opts = []): array
    {
        $timeout  = (int)($opts['timeout']  ?? 10);        // sekundy
        $retries  = (int)($opts['retries']  ?? 2);
        $maxBytes = (int)($opts['maxBytes'] ?? 20 * 1024 * 1024); // 20 MB
        $auth     = (array)($opts['auth']   ?? []);        // ['type'=>'basic|bearer|header|query', ...]
        $headers  = (array)($opts['headers']?? []);        // assoc
        $query    = (array)($opts['query']  ?? []);        // assoc
        $allowCt  = (array)($opts['allowContentTypes'] ?? [
            'text/csv','application/csv',
            'application/json','text/json',
            'application/xml','text/xml'
        ]);

        // auth: header/query helpers (dane z BO mogą przyjść jako JSON-string)
        $headers = $this->normalizeAssoc($headers);
        $query   = $this->normalizeAssoc($query);

        // auth injection
        switch (($auth['type'] ?? 'none')) {
            case 'basic':
                // handled via CURLOPT_USERPWD
                break;
            case 'bearer':
                if (!empty($auth['token'])) {
                    $headers['Authorization'] = 'Bearer '.$auth['token'];
                }
                break;
            case 'header':
                // ex: auth.headers JSON merged into $headers in BO, więc nic więcej
                break;
            case 'query':
                // ex: auth.query_params JSON merged into $query in BO, więc nic więcej
                break;
            case 'none':
            default:
                break;
        }

        // dołącz query do URL
        if (!empty($query)) {
            $url = $this->appendQuery($url, $query);
        }

        $attempt = 0;
        $delayMs = 200;

        do {
            $attempt++;
            $res = $this->curlGet($url, [
                'timeout'   => $timeout,
                'maxBytes'  => $maxBytes,
                'headers'   => $headers,
                'auth_type' => ($auth['type'] ?? 'none'),
                'userpwd'   => (!empty($auth['login']) || !empty($auth['password']))
                                ? (($auth['login'] ?? '').':'.($auth['password'] ?? ''))
                                : null,
            ]);

            // Akceptowalne CT?
            if ($res['status'] >= 200 && $res['status'] < 300) {
                $ct = strtolower($res['contentType'] ?? '');
                // czasem CT zawiera ;charset=...
                $ctBase = trim(explode(';', $ct)[0]);
                if (!in_array($ctBase, $allowCt, true)) {
                    return [
                        'ok' => false,
                        'status' => 415,
                        'error' => 'Unsupported Content-Type: '.$res['contentType'],
                        'contentType' => $res['contentType'],
                        'body' => null,
                    ];
                }
                return [
                    'ok'          => true,
                    'status'      => $res['status'],
                    'body'        => $res['body'],
                    'contentType' => $res['contentType'],
                    'size'        => $res['size'],
                ];
            }

            // retry only on 5xx / timeout / network
            $shouldRetry = ($res['status'] === 0 || ($res['status'] >= 500 && $res['status'] < 600));
            if ($shouldRetry && $attempt <= ($retries + 1)) {
                usleep($delayMs * 1000);
                $delayMs = min(2000, $delayMs * 2);
            } else {
                break;
            }
        } while (true);

        return [
            'ok'          => false,
            'status'      => $res['status'],
            'error'       => $res['error'] ?? 'Request failed',
            'contentType' => $res['contentType'] ?? null,
            'body'        => null,
        ];
    }

    /** ------------ helpers ------------ */

    protected function curlGet(string $url, array $opts): array
    {
        $ch = curl_init();
        $responseBody = '';
        $size = 0;
        $maxBytes = (int)$opts['maxBytes'];

        // write callback to enforce maxBytes
        $writeFn = function ($ch, $data) use (&$responseBody, &$size, $maxBytes) {
            $len = strlen($data);
            $size += $len;
            if ($size > $maxBytes) {
                return 0; // abort transfer
            }
            $responseBody .= $data;
            return $len;
        };

        $httpHeaders = [];
        foreach ((array)$opts['headers'] as $k => $v) {
            $httpHeaders[] = $k.': '.$v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false, // używamy WRITEFUNCTION
            CURLOPT_WRITEFUNCTION  => $writeFn,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => (int)$opts['timeout'],
            CURLOPT_TIMEOUT        => (int)$opts['timeout'],
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_USERAGENT      => 'PKSupplierHub/1.0 (+Prestashop)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => true,
        ]);

        // Basic auth
        if (($opts['auth_type'] ?? 'none') === 'basic' && !empty($opts['userpwd'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $opts['userpwd']);
        }

        $headerStr = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$headerStr) {
            $headerStr .= $header;
            return strlen($header);
        });

        $ok = curl_exec($ch);

        $err     = null;
        $status  = 0;
        $ct      = null;

        if ($ok === false) {
            $err = curl_error($ch);
        } else {
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // parse headers to find content-type
            foreach (preg_split("/\r\n|\n|\r/", $headerStr) as $line) {
                if (stripos($line, 'Content-Type:') === 0) {
                    $ct = trim(substr($line, strlen('Content-Type:')));
                    break;
                }
            }
        }

        curl_close($ch);

        // jeśli przerwane przez maxBytes, ustaw pseudo-błąd 413
        if ($size > $maxBytes) {
            return [
                'status' => 413,
                'error' => 'Payload too large (>'.(int)($maxBytes/1024/1024).' MB)',
                'contentType' => $ct,
                'body' => null,
                'size' => $size,
            ];
        }

        return [
            'status'      => $status,
            'error'       => $err,
            'contentType' => $ct,
            'body'        => $ok === false ? null : $this->ensureUtf8($responseBody),
            'size'        => $size,
        ];
    }

    protected function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query = array_merge($query, $params);
        $parts['query'] = http_build_query($query);

        $scheme   = $parts['scheme'] ?? 'http';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $queryStr = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $frag     = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.'://'.$host.$port.$path.$queryStr.$frag;
    }

    protected function normalizeAssoc($maybeJson): array
    {
        if (is_array($maybeJson)) {
            return $maybeJson;
        }
        if (is_string($maybeJson) && $maybeJson !== '') {
            $decoded = json_decode($maybeJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    protected function ensureUtf8(?string $s): ?string
    {
        if ($s === null) return null;
        // jeśli nie-UTF8, spróbuj konwersji
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = @mb_convert_encoding($s, 'UTF-8', 'auto,ISO-8859-2,Windows-1250,Windows-1252');
        }
        return $s;
    }
}
