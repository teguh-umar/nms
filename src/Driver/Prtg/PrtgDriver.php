<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\UnsupportedOperation;

/**
 * PRTG brand driver. Classic API: read plus acknowledge. API v2 (experimental): read only.
 * Reads never call acknowledge; addNote() and clear() do not exist in PRTG.
 */
final class PrtgDriver implements Driver
{
    public const DEFAULT_TIMEOUT = 30;
    public const ACK_MESSAGE     = 'Acknowledged in GLPI';

    /** status_raw values that still need an acknowledge: Down, Down (partial). */
    private const NEEDS_ACK = [5, 14];

    private int $timeout;
    private Settings $settings;
    private PrtgClient $client;
    private Capabilities $capabilities;
    private bool $v2;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $devicesById = null;

    public function __construct(private Connection $connection, ?PrtgClient $client = null, ?int $timeout = null)
    {
        $this->timeout  = $timeout ?? self::DEFAULT_TIMEOUT;
        $this->settings = Settings::fromJson($connection->settings);
        $api            = $this->settings->get('prtg_api');
        if ($api !== 'classic' && $api !== 'v2') {
            throw new PrtgException('Unknown PRTG API setting');
        }
        $this->v2           = $api === 'v2';
        $this->capabilities = $this->v2
            ? new Capabilities(true, true, true, false, false, false, false, false)
            : new Capabilities(true, true, true, false, true, false, false, true);
        $this->client = $client ?? $this->buildClient();
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        try {
            $this->requireConfigured();
            $version = $this->client->version();
            if ($this->v2) {
                // v2 availability is decided by a list endpoint, never by the (guessed) version path.
                $this->client->devices();
            }
            return ['ok' => true, 'message' => sprintf(
                '%s %s (%s auth)',
                $this->v2 ? 'PRTG API v2 (experimental, read-only)' : 'PRTG',
                $version === '' ? 'unknown' : $version,
                $this->client->authMode()
            )];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public function listDevices(): array
    {
        $this->requireConfigured();
        $groups = $this->groups();
        $rows   = array_values(array_filter(
            $this->client->devices(),
            fn (array $d): bool => $this->inGroups((string) ($d['group'] ?? ''), $groups)
        ));
        return DeviceParser::parse($rows);
    }

    /** @return list<array<string, mixed>> */
    public function listActiveAlarms(): array
    {
        $this->requireConfigured();
        $groups  = $this->groups();
        $codes   = $this->alarmCodes();
        $sensors = array_values(array_filter(
            $this->client->sensors($codes),
            fn (array $s): bool => $this->inGroups((string) ($s['group'] ?? ''), $groups)
        ));
        if ($sensors === []) {
            return [];
        }
        return SensorParser::parse($sensors, $this->devicesById());
    }

    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array
    {
        $this->requireConfigured();
        $sensor = $this->client->sensor($alarm_key);
        if ($sensor === null) {
            return null;
        }
        // The group filter does not apply: AlarmSync must still see a Clear.
        return SensorParser::current($sensor, $this->devicesById(), $this->alarmCodes());
    }

    public function addNote(string $alarm_key, string $text): void
    {
        throw new UnsupportedOperation('PRTG has no alarm notes');
    }

    public function acknowledge(string $alarm_key): void
    {
        if (!$this->capabilities->ack) {
            throw new UnsupportedOperation('acknowledge is not available with PRTG API v2');
        }
        $this->requireConfigured();
        // A PrtgApiError on this read is deliberately NOT turned into AckRefused: it is retried (only a refusal
        // of the acknowledge write itself is permanent).
        $sensor = $this->client->sensor($alarm_key);
        if ($sensor === null) {
            throw new AckRefused('PRTG sensor ' . $alarm_key . ' no longer exists');
        }
        // Ruling R6: a sensor that is not Down (Warning, Unusual, already acknowledged, recovered) is not
        // acknowledged in PRTG: nothing is written and the outbox row is recorded as sent (idempotent success).
        if (!in_array((int) ($sensor['status_raw'] ?? 0), self::NEEDS_ACK, true)) {
            return;
        }
        try {
            $this->client->acknowledge($alarm_key, self::ACK_MESSAGE);
        } catch (PrtgApiError $e) {
            // PRTG answered and refused: permanent. Transport failures stay plain PrtgException (retryable).
            throw new AckRefused($e->getMessage());
        }
    }

    public function clear(string $alarm_key): void
    {
        throw new UnsupportedOperation('PRTG alarms clear themselves');
    }

    /** @return list<int> status codes that count as an alarm (unknown setting -> down+warning) */
    private function alarmCodes(): array
    {
        return SensorParser::ALARM_CODES[$this->settings->get('prtg_alarm_states')] ?? SensorParser::ALARM_CODES['down+warning'];
    }

    /** @return array<string, array<string, mixed>> normalised devices keyed by id (one devices() call per driver instance) */
    private function devicesById(): array
    {
        if ($this->devicesById !== null) {
            return $this->devicesById;
        }
        $out = [];
        foreach ($this->client->devices() as $d) {
            if (is_array($d) && isset($d['id']) && (string) $d['id'] !== '') {
                $out[(string) $d['id']] = $d;
            }
        }
        return $this->devicesById = $out;
    }

    /** @return list<string> empty = no restriction */
    private function groups(): array
    {
        $out = [];
        foreach (preg_split('/\R/', $this->settings->get('prtg_groups')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param list<string> $groups */
    private function inGroups(string $group, array $groups): bool
    {
        return $groups === [] || in_array($group, $groups, true);
    }

    private function requireConfigured(): void
    {
        if (trim($this->connection->url) === '') {
            throw new PrtgException('PRTG URL is not configured');
        }
        if ($this->client->authMode() === 'login' && $this->connection->username === '') {
            throw new PrtgException('PRTG API key or username/password is not configured');
        }
    }

    private function buildClient(): PrtgClient
    {
        $url    = $this->connection->url;
        $key    = $this->connection->secret('api_key');
        $user   = $this->connection->username;
        $secret = $this->connection->secret('password');
        return $this->v2
            ? new V2Client($url, $key, $user, $secret, $this->curlV2(...))
            : new ClassicClient($url, $key, $user, $secret, $this->curlClassic(...));
    }

    /**
     * Plain ext-curl GET for the classic API. The URL can carry a passhash or password, so every
     * secret-bearing parameter is sensitive and no message here repeats the URL.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function curlClassic(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers): array
    {
        return $this->curl('GET', $url, $headers, null);
    }

    /**
     * Plain ext-curl transport for API v2 (method + optional JSON body).
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function curlV2(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body): array
    {
        return $this->curl($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function curl(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch  = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->connection->verify_tls === 1,
            CURLOPT_SSL_VERIFYHOST => $this->connection->verify_tls === 1 ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opt);
        $response = curl_exec($ch);
        if ($response === false) {
            // curl's own text (host and reason), never the request URL, headers or body.
            $error = sprintf('curl error %d: %s', curl_errno($ch), curl_error($ch));
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $response];
    }
}
