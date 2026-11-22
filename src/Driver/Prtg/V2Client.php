<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

use GlpiPlugin\Nms\Driver\UnsupportedOperation;

/**
 * PRTG API v2 client (EXPERIMENTAL, read-only). Needs PRTG 24.3.100 or later; Paessler marks the
 * "experimental" endpoints as subject to change between releases.
 *
 * Auth: "Authorization: Bearer <API key or session token>". Without a key, username + password are traded once
 * per instance for a session token (POST /session). acknowledge() is not available: v2 documents no such call.
 *
 * Every endpoint, field and parameter name lives in the constants below. ALL of them are UNVERIFIED against a
 * live PRTG: confirm against Paessler's OpenAPI reference (/support/prtg/api/v2/oas/index.html) on the build date.
 *
 * Transport: callable(string $method, string $url, array<string,string> $headers, ?string $body): array{status: int, body: string}.
 */
final class V2Client implements PrtgClient
{
    // UNVERIFIED endpoint paths (relative to <url>/api/v2).
    public const PATH_SESSION = '/session';
    public const PATH_VERSION = '/experimental/system/version';
    public const PATH_DEVICES = '/experimental/devices';
    public const PATH_SENSORS = '/experimental/sensors';

    // UNVERIFIED response and request field names.
    public const KEY_TOKEN       = 'token';
    public const KEY_VERSION     = 'version';
    public const KEY_ITEMS       = 'items';        // list envelope; a bare JSON list is accepted too
    public const PARAM_LIMIT     = 'limit';
    public const PARAM_OFFSET    = 'offset';
    public const PAGE_SIZE       = 500;
    public const MAX_PAGES       = 200;
    public const F_ID            = 'id';
    public const F_NAME          = 'name';
    public const F_HOST          = 'host';
    public const F_GROUP         = 'group';
    public const F_STATUS        = 'status';       // text ("Up", "Paused by user") or the classic integer
    public const F_TAGS          = 'tags';         // list or string
    public const F_DEVICE_ID     = 'deviceId';
    public const F_DEVICE_NAME   = 'deviceName';
    public const F_MESSAGE       = 'message';
    public const F_TYPE          = 'type';
    public const F_LASTCHECK     = 'lastCheck';    // ISO 8601 string or unix seconds

    // v2 status text (lower case, letters only) to the classic status_raw integers. UNVERIFIED spelling.
    public const STATUS_MAP = [
        'unknown' => 1, 'up' => 3, 'warning' => 4, 'down' => 5,
        'paused' => 7, 'pausedbyuser' => 7, 'pausedbydependency' => 8, 'pausedbyschedule' => 9,
        'unusual' => 10, 'pausedbylicense' => 11, 'pausedbyuntil' => 12, 'pauseduntil' => 12,
        'downacknowledged' => 13, 'acknowledged' => 13, 'downpartial' => 14, 'partialdown' => 14,
    ];

    private string $url;
    private string $apiKey;
    private string $username;
    private string $password;

    /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string} */
    private $transport;

    private ?string $token   = null;
    private ?string $version = null;
    /** @var array{class: class-string<PrtgException>, message: string}|null the one failed login of this instance */
    private ?array $loginFailure = null;

    public function __construct(string $url, #[\SensitiveParameter] string $apiKey, string $username, #[\SensitiveParameter] string $password, callable $transport)
    {
        $this->url       = rtrim(trim($url), '/') . '/api/v2';
        $this->apiKey    = $apiKey;
        $this->username  = $username;
        $this->password  = $password;
        $this->transport = $transport;
    }

    public function authMode(): string
    {
        return $this->apiKey !== '' ? 'key' : 'login';
    }

    public function version(): string
    {
        if ($this->version === null) {
            $data    = $this->decode($this->call('GET', self::PATH_VERSION, [], null, true));
            $version = $data[self::KEY_VERSION] ?? null;
            if (!is_string($version) || $version === '') {
                throw new PrtgException('PRTG returned no version');
            }
            $this->version = $version;
        }
        return $this->version;
    }

    public function devices(): array
    {
        return array_map(static function (array $r): array {
            $tags = $r[self::F_TAGS] ?? '';
            $text = self::statusText($r[self::F_STATUS] ?? null);
            return [
                'id'         => (string) ($r[self::F_ID] ?? ''),
                'name'       => (string) ($r[self::F_NAME] ?? ''),
                'host'       => (string) ($r[self::F_HOST] ?? ''),
                'group'      => (string) ($r[self::F_GROUP] ?? ''),
                'status_raw' => self::statusRaw($r[self::F_STATUS] ?? null),
                'status'     => $text,
                'tags'       => is_array($tags) ? implode(' ', array_filter($tags, 'is_scalar')) : (string) $tags,
            ];
        }, $this->listAll(self::PATH_DEVICES));
    }

    public function sensors(array $statusCodes): array
    {
        $rows = array_map([self::class, 'sensorRow'], $this->listAll(self::PATH_SENSORS));
        if ($statusCodes === []) {
            return $rows;
        }
        // Filtered here: the server-side filter syntax of v2 is unconfirmed.
        $keep = array_map('intval', $statusCodes);
        return array_values(array_filter($rows, static fn (array $r): bool => in_array($r['status_raw'], $keep, true)));
    }

    public function sensor(string $id): ?array
    {
        $path = self::PATH_SENSORS . '/' . rawurlencode($id);
        $body = $this->call('GET', $path, [], null, false, true);
        if ($body === null) {
            return null;
        }
        $data = $this->decode($body);
        return self::sensorRow($data);
    }

    public function acknowledge(string $id, string $message): void
    {
        throw new UnsupportedOperation('acknowledge is not available with PRTG API v2');
    }

    /** @param array<string, mixed> $r */
    private static function sensorRow(array $r): array
    {
        return [
            'id'         => (string) ($r[self::F_ID] ?? ''),
            'name'       => (string) ($r[self::F_NAME] ?? ''),
            'device_id'  => (string) ($r[self::F_DEVICE_ID] ?? ''),
            'device'     => (string) ($r[self::F_DEVICE_NAME] ?? ''),
            'group'      => (string) ($r[self::F_GROUP] ?? ''),
            'status_raw' => self::statusRaw($r[self::F_STATUS] ?? null),
            'status'     => self::statusText($r[self::F_STATUS] ?? null),
            'message'    => trim((string) ($r[self::F_MESSAGE] ?? '')),
            'type'       => (string) ($r[self::F_TYPE] ?? ''),
            'lastcheck'  => self::oleDate($r[self::F_LASTCHECK] ?? null),
        ];
    }

    private static function statusText(mixed $status): string
    {
        return is_scalar($status) ? (string) $status : '';
    }

    private static function statusRaw(mixed $status): int
    {
        if (is_int($status) || (is_string($status) && ctype_digit($status))) {
            return (int) $status;
        }
        if (is_string($status)) {
            $key = strtolower((string) preg_replace('/[^A-Za-z]/', '', $status));
            return self::STATUS_MAP[$key] ?? 0;
        }
        return 0;
    }

    /** ISO 8601 string or unix seconds to PRTG's OLE automation days; null when absent or unparsable. */
    private static function oleDate(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return ((float) $value) / 86400 + 25569;
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            return $ts === false ? null : $ts / 86400 + 25569;
        }
        return null;
    }

    /** @return list<array<string, mixed>> every row of a list endpoint, paged */
    private function listAll(string $path): array
    {
        $all  = [];
        $seen = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = json_decode($this->call('GET', $path, [self::PARAM_LIMIT => (string) self::PAGE_SIZE, self::PARAM_OFFSET => (string) ($page * self::PAGE_SIZE)], null, $page === 0), true);
            if (!is_array($data)) {
                throw new PrtgException('PRTG returned a non-JSON response');
            }
            $items = array_is_list($data) ? $data : ($data[self::KEY_ITEMS] ?? null);
            if (!is_array($items)) {
                throw new PrtgException('PRTG v2 list response has no items');
            }
            $items = array_values(array_filter($items, 'is_array'));
            $fresh = 0;
            foreach ($items as $row) {
                $key = (string) ($row[self::F_ID] ?? '');
                if ($key === '' || !isset($seen[$key])) {
                    $seen[$key] = true;
                    $all[]      = $row;
                    $fresh++;
                }
            }
            // A short page ends the list; a page of only repeats means the server ignores the offset.
            if (count($items) < self::PAGE_SIZE || $fresh === 0) {
                break;
            }
        }
        return $all;
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PrtgException('PRTG returned a non-JSON response');
        }
        return $decoded;
    }

    /**
     * One authenticated request.
     *
     * @param array<string, string> $query
     * @param bool $probe a 404 means API v2 itself is missing
     * @param bool $nullOn404 a 404 returns null (a missing object)
     * @return ($nullOn404 is true ? ?string : string)
     */
    private function call(string $method, string $path, array $query, ?string $body, bool $probe = false, bool $nullOn404 = false): ?string
    {
        $headers = ['Authorization' => 'Bearer ' . $this->bearer(), 'Accept' => 'application/json'];
        $result  = $this->raw($method, $path, $query, $headers, $body, $probe, $nullOn404);
        return $result;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function raw(string $method, string $path, array $query, array $headers, ?string $body, bool $probe, bool $nullOn404): ?string
    {
        $url = $this->url . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = ($this->transport)($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            // No previous exception: its message and trace args hold the raw secrets.
            throw new PrtgException($this->redact('PRTG request failed: ' . $e->getMessage()));
        }

        $status = (int) ($response['status'] ?? 0);
        $text   = (string) ($response['body'] ?? '');
        if ($status >= 200 && $status < 300) {
            return $text;
        }
        if ($status === 404) {
            if ($nullOn404) {
                return null;
            }
            if ($probe) {
                throw new PrtgException('API v2 is not available on this PRTG (needs 24.3 or later)');
            }
        }
        if (in_array($status, [400, 401, 403], true)) {
            $msg = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES)));
            throw new PrtgApiError($this->redact(sprintf('PRTG refused the request (HTTP %d)%s', $status, $msg !== '' ? ': ' . self::clip($msg) : '')));
        }
        throw new PrtgException(sprintf('PRTG HTTP error %d', $status));
    }

    /** First 200 characters, UTF-8 safe, without needing mbstring. */
    private static function clip(string $text): string
    {
        return preg_match('/^.{0,200}/su', $text, $m) ? $m[0] : substr($text, 0, 200);
    }

    private function bearer(): string
    {
        if ($this->apiKey !== '') {
            if (preg_match('/[\r\n]/', $this->apiKey)) {
                throw new PrtgException('PRTG API key contains invalid characters');
            }
            return $this->apiKey;
        }
        if ($this->token === null) {
            // One login attempt per instance (one sync run): a wrong password must not lock the account.
            if ($this->loginFailure !== null) {
                $class = $this->loginFailure['class'];
                throw new $class($this->loginFailure['message']);
            }
            if ($this->username === '' || $this->password === '') {
                throw new PrtgException('PRTG username or password is not configured');
            }
            try {
                $body  = (string) $this->raw('POST', self::PATH_SESSION, [], ['Accept' => 'application/json'],
                    (string) json_encode(['username' => $this->username, 'password' => $this->password]), true, false);
                $data  = json_decode($body, true);
                $token = is_array($data) ? ($data[self::KEY_TOKEN] ?? null) : null;
                if (!is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) {
                    throw new PrtgException('PRTG login returned no session token');
                }
            } catch (PrtgException $e) {
                $this->loginFailure = ['class' => $e::class, 'message' => $this->redact($e->getMessage())];
                throw new $e($this->loginFailure['message']);
            }
            $this->token = $token;
        }
        return $this->token;
    }

    /** Scrub the key, password and session token from any message. */
    private function redact(string $text): string
    {
        foreach ([$this->apiKey, $this->password, (string) $this->token] as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }
        return $text;
    }
}
