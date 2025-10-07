<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * HttpClient – bezpieczne pobieranie feedów (timeout/retry/rate-limit/CT whitelist).
 * Działa na curl, nie wymaga zewnętrznych bibliotek.
 */
class HttpClient
{
    // twarde limity (sekundy)
    private $connectTimeout = 5;
    private $responseTimeout = 20;

    // retry
    private $maxRetries = 2;         // łącznie do 3 prób (1 + 2 retry)
    private $retryDelayBaseMs = 300; // backoff: 300ms, 600ms...

    // rate limit (na host) – max N zapytań na okno czasu
    private static $bucket = [];       // [host] => ['count'=>..,'windowStart'=>..]
    private $rateLimitPerWindow = 6;   // 6 żądań
    private $rateWindowSeconds = 2;    // co 2 sekundy okno

    // whitelist nagłówka Content-Type
    private $allowedContentTypes = [
        'text/csv','application/csv','text/plain',
        'application/json','text/json',
        'application/xml','text/xml',
        'application/octet-stream' // część hurtowni tak zwraca CSV
    ];

    // opcjonalna autoryzacja i nagłówki
    private $authType = 'none'; // none|basic|bearer
    private $authUser;
    private $authPassOrToken;
    private $headers = []; // dodatkowe nagłówki ['Header: value']

    public function __construct(array $opts = [])
    {
        if (isset($opts['connect_timeout'])) $this->connectTimeout = (int)$opts['connect_timeout'];
        if (isset($opts['response_timeout'])) $this->responseTimeout = (int)$opts['response_timeout'];
        if (isset($opts['max_retries'])) $this->maxRetries = (int)$opts['max_retries'];
        if (isset($opts['rate_limit'])) $this->rateLimitPerWindow = (int)$opts['rate_limit'];
        if (isset($opts['rate_window'])) $this->rateWindowSeconds = (int)$opts['rate_window'];
        if (isset($opts['auth_type'])) $this->authType = (string)$opts['auth_type'];
        if (isset($opts['auth_user'])) $this->authUser = (string)$opts['auth_user'];
        if (isset($opts['auth_pass_or_token'])) $this->authPassOrToken = (string)$opts['auth_pass_or_token'];
        if (isset($opts['headers']) && is_array($opts['headers'])) $this->headers = $opts['headers'];
    }

    public function get(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown-host';
        $this->throttle($host);

        $attempt = 0;
        $lastErr = null;

        do {
            try {
                $attempt++;
                $res = $this->request('GET', $url);
                $this->assertContentType($res['headers']);
                return $res;
            } catch (\Throwable $e) {
                $lastErr = $e;
                if ($attempt > $this->maxRetries + 1) {
                    break;
                }
                // proste exponential backoff
                usleep($this->retryDelayBaseMs * 1000 * $attempt);
            }
        } while (true);

        throw new \RuntimeException('HttpClient GET failed: '.$lastErr->getMessage());
    }

    /* ====================== INTERNALS ====================== */

    private function request(string $method, string $url, ?string $body = null): array
    {
        $ch = curl_init();
        $headerBag = $this->buildHeaders();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->responseTimeout,
            CURLOPT_HTTPHEADER     => $headerBag,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method !== 'GET' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // auth
        if ($this->authType === 'basic' && $this->authUser !== null) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->authUser.':'.($this->authPassOrToken ?? ''));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error #'.$code.': '.$err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP status '.$status.' for URL '.$url);
        }

        $headers = $this->parseHeaders($rawHeaders);
        return ['status'=>$status, 'headers'=>$headers, 'body'=>$body];
    }

    private function buildHeaders(): array
    {
        $h = array_values($this->headers);
        // Bearer
        if ($this->authType === 'bearer' && $this->authPassOrToken) {
            $h[] = 'Authorization: Bearer '.$this->authPassOrToken;
        }
        // sensowne UA
        $h[] = 'User-Agent: PKSupplierHub/1.0 (+PrestaShop)';
        return $h;
    }

    private function parseHeaders(string $raw): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($raw));
        $res = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $res[strtolower($k)] = $v;
            }
        }
        return $res;
    }

    private function assertContentType(array $headers): void
    {
        if (!isset($headers['content-type'])) {
            // część serwerów nie wysyła — wtedy przepuszczamy
            return;
        }
        $ct = strtolower(trim(explode(';', $headers['content-type'])[0]));
        if (!in_array($ct, $this->allowedContentTypes, true)) {
            throw new \RuntimeException('Disallowed Content-Type: '.$ct);
        }
    }

    private function throttle(string $host): void
    {
        $now = time();
        if (!isset(self::$bucket[$host])) {
            self::$bucket[$host] = ['count'=>0,'windowStart'=>$now];
        }
        $win = &self::$bucket[$host];
        if (($now - $win['windowStart']) >= $this->rateWindowSeconds) {
            $win['windowStart'] = $now;
            $win['count'] = 0;
        }
        if ($win['count'] >= $this->rateLimitPerWindow) {
            // proste czekanie do końca okna, ale krótkie (max 1s)
            $sleep = max(0, $this->rateWindowSeconds - ($now - $win['windowStart']));
            if ($sleep > 0) {
                usleep(min(1000000, $sleep * 1000000));
            }
            // po „drzemce” reset okna
            $win['windowStart'] = time();
            $win['count'] = 0;
        }
        $win['count']++;
    }
}
