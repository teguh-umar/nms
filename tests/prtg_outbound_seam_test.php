<?php

// Seam: parsed PRTG alarm -> Planner rows -> outbox -> Sender -> PrtgDriver -> ClassicClient -> acknowledgealarm.htm.
// The outbox writes to entity_key, so for PRTG it must be the sensor id (not the device id).
require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities', 'Driver/UnsupportedOperation',
    'Driver/Prtg/PrtgException', 'Driver/Prtg/PrtgApiError', 'Driver/Prtg/PrtgClient', 'Driver/Prtg/ClassicClient',
    'Driver/Prtg/V2Client', 'Driver/Prtg/DeviceParser', 'Driver/Prtg/SensorParser', 'Driver/Prtg/AckRefused',
    'Driver/Prtg/PrtgDriver', 'Outbound/OutboxStore', 'Outbound/Planner', 'Outbound/Sender'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Prtg\ClassicClient;
use GlpiPlugin\Nms\Driver\Prtg\DeviceParser;
use GlpiPlugin\Nms\Driver\Prtg\PrtgDriver;
use GlpiPlugin\Nms\Driver\Prtg\SensorParser;
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

function sfix(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/prtg/classic/26.4/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

// Parse the fixture through the real classic client (recording transport) and the real parsers.
$urls      = [];
$transport = function (string $url, array $headers) use (&$urls): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $urls[] = ['path' => $path, 'query' => $q];
    if ($path === ClassicClient::PATH_ACK) {
        return ['status' => 200, 'body' => '<html>OK</html>'];
    }
    if ($path === ClassicClient::PATH_TABLE && ($q['content'] ?? '') === 'sensors') {
        // A read of one sensor (filter_objid) returns the 2001 fixture; anything else the full list.
        return ['status' => 200, 'body' => json_encode(isset($q['filter_objid']) ? sfix('sensor-2001') : sfix('sensors'))];
    }
    if ($path === ClassicClient::PATH_TABLE) {
        return ['status' => 200, 'body' => json_encode(sfix('devices'))];
    }
    return ['status' => 200, 'body' => json_encode(sfix('status'))];
};
$client  = new ClassicClient('https://prtg.example.test', 'tok-seam', '', '', $transport);
$devices = array_column($client->devices(), null, 'id');
$alarms  = array_column(SensorParser::parse($client->sensors([5, 13, 14, 4]), $devices), null, 'alarm_key');
$alarm   = $alarms['2001'];
check('fixture: sensor id differs from device id', $alarm['entity_key'] === $alarm['alarm_key'] && $alarm['entity_key'] === '2001' && isset($devices['1001']));

$active = [['alarm_key' => $alarm['alarm_key'], 'entity_key' => $alarm['entity_key']]];
$rows   = Planner::forFollowup(7, false, 'checked the link', 'tech', $active, 5);
$actions = array_column($rows, 'action');
check('planner emits note then ack', $actions === ['note', 'ack'], implode(',', $actions));

$conn = new Connection([
    'id' => 1, 'name' => 'prtg', 'brand' => 'prtg', 'url' => 'https://prtg.example.test',
    'api_key' => (new GLPIKey())->encrypt('tok-seam'), 'username' => '', 'password' => '',
    'verify_tls' => 1, 'is_active' => 1, 'settings' => '{}',
]);
// PRTG has no notes (capability false) and Planner::forFollowup always plans a note first, so the
// note row is not driven here (see the Task 4 report: Hooks/Planner are not capability-aware yet).
// The ack row goes through the real Sender and the real PrtgDriver.
$box = new SeamBox();
foreach ($rows as $r) {
    if ($r['action'] === 'ack') {
        $box->enqueue($r, '2026-11-22 10:00:00');
    }
}
$urls   = [];
$driver = new PrtgDriver($conn, new ClassicClient('https://prtg.example.test', 'tok-seam', '', '', $transport));
$stats  = (new Sender($box, $driver, 10))->run('2026-11-22 10:00:00');
$acks   = array_values(array_filter($urls, fn ($u) => $u['path'] === ClassicClient::PATH_ACK));
check('ack row sent', $stats['sent'] === 1 && $stats['failed'] === 0 && $stats['retried'] === 0, json_encode($stats));
check('sensor read before the write', $urls[0]['path'] === ClassicClient::PATH_TABLE && ($urls[0]['query']['filter_objid'] ?? '') === '2001');
check('acknowledgealarm.htm got id=<sensor id>', count($acks) === 1 && ($acks[0]['query']['id'] ?? '') === '2001', json_encode($acks));
check('outbox entity_key === alarm_key', $rows[1]['entity_key'] === $rows[1]['alarm_key'] && $rows[1]['entity_key'] === '2001');

done();
