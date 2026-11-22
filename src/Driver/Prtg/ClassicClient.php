<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/**
 * PRTG classic HTTP API client (all calls are GET).
 *
 * Auth: an API key goes in "Authorization: Bearer <key>" (Paessler: the classic API accepts it and the header has
 * priority over query credentials), so it never appears in a URL. Without a key, username + password are traded
 * once per instance for a passhash (getpasshash.htm), then sent as username=/passhash= query parameters.
 *
 * Every API name the manual might change lives in the constants below.
 *
 * The transport is injected: callable(string $url, array<string,string> $headers): array{status: int, body: string}.
 */
final class ClassicClient implements PrtgClient
{
    // Endpoint and parameter names (classic API). UNVERIFIED against a live PRTG: confirm on the build date.
    public const PATH_STATUS     = '/api/status.json';
    public const PATH_TABLE      = '/api/table.json';
    public const PATH_ACK        = '/api/acknowledgealarm.htm';
    public const PATH_PASSHASH   = '/api/getpasshash.htm';
    public const KEY_VERSION     = 'Version';          // status.json
    public const KEY_ENVELOPE    = 'prtg-version';     // table.json envelope
    public const COLUMNS_DEVICES = 'objid,device,host,group,probe,status,status_raw,tags';
    public const COLUMNS_SENSORS = 'objid,sensor,device,parentid,group,probe,status,status_raw,message,lastcheck,lastcheck_raw,type';

    private string $url;
    private string $apiKey;
    private string $username;
    private string $password;

    /** @var callable(string, array<string, string>): array{status: int, body: string} */
    private $transport;

    private ?string $version  = null;
    private ?string $passhash = null;
    /** @var array{class: class-string<PrtgException>, message: string}|null the one failed login of this instance */
    private ?array $loginFailure = null;

    public function __construct(string $url, #[\SensitiveParameter] string $apiKey, string $username, #[\SensitiveParameter] string $password, callable $transport)
    {
        $this->url       = self::base($url);
        $this->apiKey    = $apiKey;
        $this->username  = $username;
        $this->password  = $password;
        $this->transport = $transport;
    }

    public static function base(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public function authMode(): string
    {
        return $this->apiKey !== '' ? 'key' : 'login';
    }

    public function version(): string
    {
        if ($this->version === null) {
            $status  = $this->decode($this->get(self::PATH_STATUS, []));
            $version = $status[self::KEY_VERSION] ?? null;
            if (!is_string($version) || $version === '') {
                // Some lines omit Version from status.json: use the table envelope.
                $table   = $this->decode($this->get(self::PATH_TABLE, [
                    ['content', 'devices'], ['columns', 'objid'], ['count', '1'],
                ]));
                $version = $table[self::KEY_ENVELOPE] ?? null;
            }
            if (!is_string($version) || $version === '') {
                throw new PrtgException('PRTG returned no version');
            }
            $this->version = $version;
        }
        return $this->version;
    }

    public function devices(): array
    {
        $rows = $this->table('devices', self::COLUMNS_DEVICES, []);
        return array_map(static fn (array $r): array => [
            'id'         => (string) ($r['objid'] ?? ''),
            'name'       => (string) ($r['device'] ?? ''),
            'host'       => (string) ($r['host'] ?? ''),
            'group'      => (string) ($r['group'] ?? ''),
            'status_raw' => (int) ($r['status_raw'] ?? 0),
            'status'     => (string) ($r['status'] ?? ''),
            'tags'       => (string) ($r['tags'] ?? ''),
        ], $rows);
    }

    public function sensors(array $statusCodes): array
    {
        $filters = [];
        foreach ($statusCodes as $code) {
            $filters[] = ['filter_status', (string) (int) $code];
        }
        return array_map([self::class, 'sensorRow'], $this->table('sensors', self::COLUMNS_SENSORS, $filters));
    }

    public function sensor(string $id): ?array
    {
        $rows = $this->table('sensors', self::COLUMNS_SENSORS, [['filter_objid', $id]]);
        return $rows === [] ? null : self::sensorRow($rows[0]);
    }

    public function acknowledge(string $id, string $message): void
    {
        // The response is an HTML page; success is the HTTP status alone.
        $this->get(self::PATH_ACK, [['id', $id], ['ackmsg', $message]]);
    }

    /** @param array<string, mixed> $r */
    private static function sensorRow(array $r): array
    {
        $raw  = $r['lastcheck_raw'] ?? null;
        $text = html_entity_decode(strip_tags((string) ($r['message'] ?? '')), ENT_QUOTES);
        return [
            'id'         => (string) ($r['objid'] ?? ''),
            'name'       => (string) ($r['sensor'] ?? ''),
            'device_id'  => (string) ($r['parentid'] ?? ''),
            'device'     => (string) ($r['device'] ?? ''),
            'group'      => (string) ($r['group'] ?? ''),
            'status_raw' => (int) ($r['status_raw'] ?? 0),
            'status'     => (string) ($r['status'] ?? ''),
            'message'    => trim($text),
            'type'       => (string) ($r['type'] ?? ''),
            'lastcheck'  => is_numeric($raw) ? (float) $raw : null,
        ];
    }

    /**
     * @param list<array{0: string, 1: string}> $extra
     * @return list<array<string, mixed>>
     */
    private function table(string $content, string $columns, array $extra): array
    {
        $params = array_merge([['content', $content], ['columns', $columns], ['count', '*']], $extra);
        $data   = $this->decode($this->get(self::PATH_TABLE, $params));
        $rows   = $data[$content] ?? null;
        if (!is_array($rows)) {
            throw new PrtgException('PRTG table response has no ' . $content);
        }
        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PrtgException('PRTG returned a non-JSON response');
        }
        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            throw new PrtgApiError($this->redact('PRTG error: ' . (is_scalar($err) ? (string) $err : 'unknown')));
        }
        return $decoded;
    }

    /** One authenticated GET. @param list<array{0: string, 1: string}> $params */
    private function get(string $path, array $params): string
    {
        $headers = [];
        if ($this->authMode() === 'key') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        } else {
            $params[] = ['username', $this->username];
            $params[] = ['passhash', $this->passhash()];
        }
        return $this->raw($path, $params, $headers);
    }

    /**
     * @param list<array{0: string, 1: string}> $params
     * @param array<string, string> $headers
     */
    private function raw(string $path, array $params, array $headers): string
    {
        $pairs = [];
        foreach ($params as [$name, $value]) {
            // Commas and the "*" of count=* stay readable; everything else is percent-encoded.
            $pairs[] = $name . '=' . str_replace(['%2C', '%2A'], [',', '*'], rawurlencode($value));
        }
        $url = $this->url . $path . ($pairs === [] ? '' : '?' . implode('&', $pairs));

        try {
            $response = ($this->transport)($url, $headers);
        } catch (\Throwable $e) {
            // No previous exception: its message and trace args hold the raw secrets.
            throw new PrtgException($this->redact('PRTG request failed: ' . $e->getMessage()));
        }

        $status = (int) ($response['status'] ?? 0);
        $body   = (string) ($response['body'] ?? '');
        if ($status >= 200 && $status < 300) {
            return $body;
        }
        if (in_array($status, [400, 401, 403], true)) {
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES)));
            throw new PrtgApiError($this->redact(sprintf('PRTG refused the request (HTTP %d)%s', $status, $text !== '' ? ': ' . self::clip($text) : '')));
        }
        throw new PrtgException(sprintf('PRTG HTTP error %d', $status));
    }

    /** First 200 characters, UTF-8 safe, without needing mbstring. */
    private static function clip(string $text): string
    {
        return preg_match('/^.{0,200}/su', $text, $m) ? $m[0] : substr($text, 0, 200);
    }

    private function passhash(): string
    {
        if ($this->passhash === null) {
            // One login attempt per instance (one sync run): a wrong password must not lock the account.
            if ($this->loginFailure !== null) {
                $class = $this->loginFailure['class'];
                throw new $class($this->loginFailure['message']);
            }
            if ($this->username === '' || $this->password === '') {
                throw new PrtgException('PRTG username or password is not configured');
            }
            try {
                $body = trim($this->raw(self::PATH_PASSHASH, [['username', $this->username], ['password', $this->password]], []));
                if ($body === '' || preg_match('/[<>\s]/', $body)) {
                    throw new PrtgException('PRTG login returned no passhash');
                }
            } catch (PrtgException $e) {
                $this->loginFailure = ['class' => $e::class, 'message' => $this->redact($e->getMessage())];
                throw new $e($this->loginFailure['message']);
            }
            $this->passhash = $body;
        }
        return $this->passhash;
    }

    /** Scrub the key, password and passhash, and credential query parameters inside any URL in a message. */
    private function redact(string $text): string
    {
        foreach ([$this->apiKey, $this->password, (string) $this->passhash] as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }
        return (string) preg_replace('/(?:passhash|password|apitoken|username)=[^&\s"\']*/i', '***', $text);
    }
}
