<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Driver\Driver;

/**
 * Zabbix brand driver (JSON-RPC). Reads never call event.acknowledge; the
 * write methods map note/ack/clear onto event.acknowledge actions.
 */
final class ZabbixDriver implements Driver
{
    public const DEFAULT_TIMEOUT = 30;

    // event.acknowledge action bit mask
    private const ACTION_CLOSE   = 1;
    private const ACTION_ACK     = 2;
    private const ACTION_MESSAGE = 4;

    private int $timeout;
    private Capabilities $capabilities;
    private JsonRpc $rpc;
    private Settings $settings;

    /** @var callable(string, string, array<string, string>): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private Connection $connection,
        ?callable $transport = null,
        ?int $timeout = null,
    ) {
        $this->timeout      = $timeout ?? self::DEFAULT_TIMEOUT;
        $this->transport    = $transport ?? $this->curlTransport(...);
        $this->capabilities = new Capabilities(true, true, true, true, true, true, false, true);
        $this->settings     = Settings::fromJson($connection->settings);
        $this->rpc          = new JsonRpc(
            $connection->url,
            $connection->secret('api_key'),
            $connection->username,
            $connection->secret('password'),
            $this->transport,
        );
    }

    public function __destruct()
    {
        try {
            $this->rpc->logout();
        } catch (\Throwable $e) {
            // Best effort only.
        }
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
            $version = $this->rpc->version();
            // One authenticated read proves the credential, not just reachability.
            $this->rpc->call('hostgroup.get', ['output' => ['groupid'], 'limit' => 1]);
            return ['ok' => true, 'message' => sprintf('Zabbix %s (%s auth)', $version, $this->rpc->authMode())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public function listDevices(): array
    {
        $this->requireConfigured();
        $groupIds = $this->resolveGroupIds();
        if ($groupIds === []) {
            return [];
        }
        $params = $this->hostParams();
        if ($groupIds !== null) {
            $params['groupids'] = $groupIds;
        }
        return HostParser::parse($this->rows($this->rpc->call('host.get', $params)));
    }

    /** @return list<array<string, mixed>> */
    public function listActiveAlarms(): array
    {
        $this->requireConfigured();
        $groupIds = $this->resolveGroupIds();
        if ($groupIds === []) {
            return [];
        }
        $min    = max(0, min(5, (int) $this->settings->get('zabbix_min_severity')));
        $params = [
            'output'     => 'extend',
            'severities' => range($min, 5),
            'recent'     => false,
            'sortfield'  => ['eventid'],
            'sortorder'  => 'ASC',
        ];
        if ($this->settings->get('zabbix_include_suppressed') !== '1') {
            $params['suppressed'] = false;
        }
        if ($groupIds !== null) {
            $params['groupids'] = $groupIds;
        }
        $problems = $this->rows($this->rpc->call('problem.get', $params));
        if ($problems === []) {
            return [];
        }

        $events   = $this->eventsById($this->eventIds($problems));
        $details  = $this->hostDetails($events);
        return ProblemParser::parse($problems, $events, $details);
    }

    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array
    {
        $this->requireConfigured();
        $events = $this->eventsById([$alarm_key]);
        $event  = $events[$alarm_key] ?? null;
        if ($event === null) {
            return null;
        }
        return ProblemParser::fromEvent($event, $this->hostDetails($events));
    }

    public function addNote(string $alarm_key, string $text): void
    {
        $this->acknowledgeEvent($alarm_key, self::ACTION_MESSAGE, ['message' => $text]);
    }

    public function acknowledge(string $alarm_key): void
    {
        $this->acknowledgeEvent($alarm_key, self::ACTION_ACK);
    }

    public function clear(string $alarm_key): void
    {
        try {
            $this->acknowledgeEvent($alarm_key, self::ACTION_CLOSE);
        } catch (ZabbixException $e) {
            if (stripos($e->getMessage(), 'cannot close') !== false) {
                throw new CloseRefused($e->getMessage());
            }
            throw $e;
        }
    }

    private function acknowledgeEvent(string $eventId, int $action, array $extra = []): void
    {
        $this->requireConfigured();
        $this->rpc->call('event.acknowledge', ['eventids' => [$eventId], 'action' => $action] + $extra);
    }

    /**
     * Host group ids to restrict to. null = no restriction (setting empty);
     * [] = a list was set but none of the names exist (callers return nothing).
     *
     * @return list<string>|null
     */
    private function resolveGroupIds(): ?array
    {
        $names = [];
        foreach (preg_split('/\R/', $this->settings->get('zabbix_host_groups')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return null;
        }
        $ids = [];
        foreach ($this->rows($this->rpc->call('hostgroup.get', ['output' => ['groupid', 'name'], 'filter' => ['name' => $names]])) as $g) {
            if (is_array($g) && isset($g['groupid']) && is_scalar($g['groupid'])) {
                $ids[] = (string) $g['groupid'];
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> host.get params without a group restriction */
    private function hostParams(): array
    {
        $groupSelector = version_compare($this->rpc->version(), '6.2', '>=') ? 'selectHostGroups' : 'selectGroups';
        return [
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => 'extend',
            'selectInventory'  => ['type'],
            $groupSelector     => ['groupid', 'name'],
        ];
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> event.get rows keyed by eventid
     */
    private function eventsById(array $ids): array
    {
        $rows = $this->rows($this->rpc->call('event.get', [
            'eventids'            => $ids,
            'output'              => 'extend',
            'selectHosts'         => ['hostid', 'host', 'name'],
            'selectRelatedObject' => ['triggerid', 'description', 'priority'],
        ]));
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['eventid']) && is_scalar($row['eventid'])) {
                $out[(string) $row['eventid']] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $events
     * @return array<string, array<string, mixed>> host.get rows keyed by hostid
     */
    private function hostDetails(array $events): array
    {
        $hostIds = [];
        foreach ($events as $event) {
            foreach (is_array($event['hosts'] ?? null) ? $event['hosts'] : [] as $h) {
                if (is_array($h) && isset($h['hostid']) && is_scalar($h['hostid'])) {
                    $hostIds[(string) $h['hostid']] = true;
                }
            }
        }
        if ($hostIds === []) {
            return [];
        }
        $params = $this->hostParams();
        $params['hostids'] = array_map('strval', array_keys($hostIds));
        $out = [];
        foreach ($this->rows($this->rpc->call('host.get', $params)) as $host) {
            if (is_array($host) && isset($host['hostid']) && is_scalar($host['hostid'])) {
                $out[(string) $host['hostid']] = $host;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function eventIds(array $problems): array
    {
        $ids = [];
        foreach ($problems as $p) {
            if (is_array($p) && isset($p['eventid']) && is_scalar($p['eventid'])) {
                $ids[] = (string) $p['eventid'];
            }
        }
        return $ids;
    }

    /** @return list<mixed> */
    private function rows(mixed $result): array
    {
        return is_array($result) ? array_values($result) : [];
    }

    private function requireConfigured(): void
    {
        if (trim($this->connection->url) === '') {
            throw new ZabbixException('Zabbix URL is not configured');
        }
        if ($this->rpc->authMode() === 'login' && $this->connection->username === '') {
            throw new ZabbixException('Zabbix API token or username/password is not configured');
        }
    }

    /**
     * Plain ext-curl JSON-RPC transport: POST, no redirects, TLS per the connection.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url, string $body, array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->connection->verify_tls === 1,
            CURLOPT_SSL_VERIFYHOST => $this->connection->verify_tls === 1 ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $response];
    }
}
