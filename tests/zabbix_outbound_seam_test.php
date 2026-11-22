<?php

// Seam: parsed Zabbix alarm -> Planner rows -> Sender -> ZabbixDriver -> event.acknowledge.
// The outbox writes to entity_key, so for Zabbix it must be the event id (not the host id).
require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities',
    'Driver/Zabbix/ZabbixException', 'Driver/Zabbix/ZabbixApiError', 'Driver/Zabbix/JsonRpc', 'Driver/Zabbix/HostParser',
    'Driver/Zabbix/ProblemParser', 'Driver/Zabbix/CloseRefused', 'Driver/Zabbix/ZabbixDriver',
    'Outbound/OutboxStore', 'Outbound/Planner', 'Outbound/Sender'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Zabbix\ProblemParser;
use GlpiPlugin\Nms\Driver\Zabbix\ZabbixDriver;
use GlpiPlugin\Nms\Outbound\OutboxStore;
use GlpiPlugin\Nms\Outbound\Planner;
use GlpiPlugin\Nms\Outbound\Sender;

if (!class_exists('\GLPIKey', false)) {
    class GLPIKey
    {
        public function encrypt(string $value): string { return base64_encode('enc:' . $value); }
        public function decrypt($value)
        {
            $dec = is_string($value) ? base64_decode($value, true) : false;
            return $dec && str_starts_with($dec, 'enc:') ? substr($dec, 4) : false;
        }
    }
}

final class SeamBox implements OutboxStore
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public function enqueue(array $row, string $now): void
    {
        $id = count($this->rows) + 1;
        $this->rows[$id] = $row + ['id' => $id, 'attempts' => 0, 'next_try_at' => '', 'state' => 'pending'];
    }
    public function hasClear(string $k): bool { return false; }
    public function hasPendingAck(string $k): bool { return false; }
    public function pending(int $limit): array { return array_values(array_filter($this->rows, fn ($r) => $r['state'] === 'pending')); }
    public function markSent(int $id, string $now): void { $this->rows[$id]['state'] = 'sent'; }
    public function markRetry(int $id, int $a, string $n, string $e, string $now): void { $this->rows[$id]['state'] = 'retry'; }
    public function markFailed(int $id, int $a, string $e, string $now): void { $this->rows[$id]['state'] = 'failed'; }
    public function summary(): array { return ['pending' => 0, 'failed' => 0, 'last_error' => '']; }
}

function seamFix(string $ver, string $name): mixed
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/zabbix/{$ver}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

$ver     = '7.0';
$raw     = seamFix($ver, 'host.get');
$events  = array_column(seamFix($ver, 'event.get'), null, 'eventid');
$alarms  = array_column(ProblemParser::parse(seamFix($ver, 'problem.get'), $events, array_column($raw, null, 'hostid')), null, 'alarm_key');
$alarm   = $alarms['9001'];
check('fixture: host id differs from event id', $alarm['entity_key'] !== '' && $alarm['alarm_key'] === '9001');

$active = [['alarm_key' => $alarm['alarm_key'], 'entity_key' => $alarm['entity_key']]];
$rows   = array_merge(
    Planner::forFollowup(7, false, 'checked the link', 'tech', $active, 5),
    Planner::forStatus(7, 2, 5, 'replaced SFP', 'tech', $active, fn ($k) => false, 5)
);

$box = new SeamBox();
foreach ($rows as $r) {
    $box->enqueue($r, '2026-11-22 10:00:00');
}

$sent  = [];
$conn  = new Connection([
    'id' => 1, 'name' => 'zbx', 'brand' => 'zabbix', 'url' => 'https://zbx.example.test',
    'api_key' => (new GLPIKey())->encrypt('tok-seam'), 'username' => 'api', 'password' => '',
    'verify_tls' => 1, 'is_active' => 1, 'settings' => '{}',
]);
$transport = function (string $url, string $body, array $headers) use (&$sent): array {
    $req = json_decode($body, true);
    if ($req['method'] === 'event.acknowledge') {
        $sent[] = $req['params'];
    }
    $result = $req['method'] === 'apiinfo.version' ? '7.0.5' : ['eventids' => $req['params']['eventids'] ?? []];
    return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $req['id']])];
};
$stats = (new Sender($box, new ZabbixDriver($conn, $transport), 10))->run('2026-11-22 10:00:00');

$actions = array_column($rows, 'action');
check('planned note, ack, note, clear', $actions === ['note', 'ack', 'note', 'clear'], implode(',', $actions));
check('all rows sent', $stats['sent'] === 4 && $stats['failed'] === 0 && $stats['retried'] === 0, json_encode($stats));
check('every event.acknowledge targets the event id', count($sent) === 4 && array_unique(array_map(fn ($p) => $p['eventids'], $sent), SORT_REGULAR) === [['9001']],
    json_encode(array_column($sent, 'eventids')));
// action bits: 4 message, 2 ack, 1 close
check('note, ack and clear each reached the event', array_column($sent, 'action') === [4, 2, 4, 1], json_encode(array_column($sent, 'action')));

done();
