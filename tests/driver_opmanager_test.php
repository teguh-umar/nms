<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/Driver/UnsupportedOperation.php';
require OPM_ROOT . '/src/Driver/OpManager/OpManagerDriver.php';
require OPM_ROOT . '/src/Driver/OpManager/DeviceParser.php';
require OPM_ROOT . '/src/Driver/OpManager/AlarmParser.php';
require OPM_ROOT . '/src/Driver/OpManager/ResponseGuard.php';
require OPM_ROOT . '/src/Driver/OpManager/ClientException.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\OpManager\OpManagerDriver;
use GlpiPlugin\Nms\Driver\UnsupportedOperation;

if (!class_exists('\GLPIKey', false)) {
    class GLPIKey
    {
        public function encrypt(string $value): string { return base64_encode('enc:' . $value); }
        public function decrypt($value)
        {
            if (!is_string($value)) { return false; }
            $dec = base64_decode($value, true);
            return $dec && str_starts_with($dec, 'enc:') ? substr($dec, 4) : false;
        }
    }
}

$transportSeen = null;
$transport = function (string $url, array $query, array $headers, ?array $form = null) use (&$transportSeen): array {
    $transportSeen = [$url, $query, $headers, $form];
    return ['status' => 200, 'body' => '[{"deviceName":"10.0.0.1","displayName":"sw-01","ipaddress":"10.0.0.1","type":"Cisco","category":"Switch","statusStr":"Clear"}]'];
};
$alarmTransport = function (string $url, array $query, array $headers, ?array $form = null) use (&$transportSeen): array {
    $transportSeen = [$url, $query, $headers, $form];
    return ['status' => 200, 'body' => '[{"alarmId":"A1","deviceName":"sw-01","displayName":"sw-01","ipAddress":"10.0.0.1","category":"switch","alarmcode":"down","eventType":"deviceDown","numericSeverity":1,"severityString":"Critical","message":"link down","modTimeLong":"1731283200000"}]'];
};

$conn = new Connection([
    'id'              => 1,
    'name'            => 'opm.example.test',
    'brand'           => 'opmanager',
    'entities_id'     => 1,
    'is_recursive'    => 1,
    'url'             => 'https://opm.example.test',
    'api_key'         => base64_encode('enc:key-xyz'),
    'username'        => 'pg-nms',
    'password'        => '',
    'verify_tls'      => 1,
    'is_active'       => 1,
    'writeback_enabled' => 1,
    'tickets_enabled' => 1,
    'webhook_token'   => base64_encode('enc:token-xyz'),
    'settings'        => '{}',
    'date_creation'   => '2026-11-11 00:00:00',
    'date_mod'        => '2026-11-11 00:00:00',
]);

$driver = new OpManagerDriver($conn, $transport);
$caps = $driver->capabilities();
check('capabilities all true', $caps->devices && $caps->alarms && $caps->availability && $caps->note && $caps->ack && $caps->clear && $caps->webhook);

$devices = $driver->listDevices();
check('listDevices returns parser shape', $devices === [
    ['device_key' => '10.0.0.1', 'name' => '10.0.0.1', 'display_name' => 'sw-01', 'ip_address' => '10.0.0.1', 'device_type' => 'Cisco', 'device_category' => 'Switch', 'availability' => 'Clear'],
]);
check('api key in header not url', ($transportSeen[2]['apiKey'] ?? null) === 'key-xyz' && !array_key_exists('apiKey', $transportSeen[1]));

$driverAlarm = new OpManagerDriver($conn, $alarmTransport);
$alarms = $driverAlarm->listActiveAlarms();
$alarm0 = $alarms[0] ?? [];
check('listActiveAlarms returns parser shape', $alarm0['alarm_key'] === 'A1' && $alarm0['device_name'] === 'sw-01' && $alarm0['severity_label'] === 'Critical');
check('alarmByKey returns null for unknown', $driverAlarm->alarmByKey('NOPE') === null);

// Write methods: addNote/acknowledge/clear send POST form bodies
$writeSeen = null;
$writeTransport = function (string $url, array $query, array $headers, ?array $form = null) use (&$writeSeen): array {
    $writeSeen = [$url, $query, $headers, $form];
    return ['status' => 200, 'body' => '{"result":"ok"}'];
};
$writeDriver = new OpManagerDriver($conn, $writeTransport);
$writeDriver->addNote('A1', 'note text');
check('addNote posts entity and notes', $writeSeen[3] === ['entity' => 'A1', 'notes' => 'note text'] && str_ends_with($writeSeen[0], '/api/json/alarm/addNotes'));
$writeDriver->acknowledge('A1');
check('acknowledge posts entity', $writeSeen[3] === ['entity' => 'A1'] && str_ends_with($writeSeen[0], '/api/json/alarm/acknowledgeAlarm'));
$writeDriver->clear('A1');
check('clear posts entity', $writeSeen[3] === ['entity' => 'A1'] && str_ends_with($writeSeen[0], '/api/json/alarm/clearAlarm'));

// UnsupportedOperation when capability is false
$noCaps = new \GlpiPlugin\Nms\Driver\Capabilities(false, false, false, false, false, false, false);
$nocapDriver = new OpManagerDriver($conn, $transport, $noCaps);
try { $nocapDriver->addNote('A1', 'x'); check('addNote throws UnsupportedOperation', false); }
catch (UnsupportedOperation) { check('addNote throws UnsupportedOperation', true); }
try { $nocapDriver->acknowledge('A1'); check('acknowledge throws UnsupportedOperation', false); }
catch (UnsupportedOperation) { check('acknowledge throws UnsupportedOperation', true); }
try { $nocapDriver->clear('A1'); check('clear throws UnsupportedOperation', false); }
catch (UnsupportedOperation) { check('clear throws UnsupportedOperation', true); }

// ---- Restored from the 1.3.2 client_test (owner review 2026-11-12) ----
use GlpiPlugin\Nms\Driver\OpManager\ClientException;

$driverWith = static fn (callable $t, ?Connection $c = null): OpManagerDriver => new OpManagerDriver($c ?? $conn, $t);
$threwClient = static function (callable $fn): ?ClientException {
    try { $fn(); } catch (ClientException $e) { return $e; }
    return null;
};

// Exact endpoints.
$calls = [];
$rec = function (string $url, array $query, array $headers, ?array $form = null) use (&$calls): array {
    $calls[] = [$url, $query, $form];
    return ['status' => 200, 'body' => json_encode([
        ['alarmId' => '1', 'entity' => 'E1', 'numericSeverity' => 1, 'severityString' => 'Critical'],
        ['alarmId' => '2', 'entity' => 'E2', 'numericSeverity' => 5, 'severityString' => 'Clear'],
    ])];
};
$d = $driverWith($rec);
$d->listActiveAlarms();
check('active alarms use the ActiveAlarms filter', $calls[0][0] === 'https://opm.example.test/api/json/alarm/listAlarms' && $calls[0][1] === ['alertType' => 'ActiveAlarms']);

// Review 2026-11-12: the lookup must see CLEARED alarms (no ActiveAlarms filter), by entity.
$one = $d->alarmByKey('2', 'E2');
check('alarm lookup filters on entity, not on active', $calls[1][1] === ['entity' => 'E2']);
check('alarm lookup returns a cleared alarm', ($one['alarm_key'] ?? null) === '2' && (int) ($one['severity'] ?? 0) === 5);
check('alarm lookup returns null when entity absent', $d->alarmByKey('9', 'E9') === null);

// HTTP and transport errors.
$e = $threwClient(fn () => $driverWith(fn () => ['status' => 401, 'body' => 'nope'])->listDevices());
check('HTTP error throws ClientException with status', $e !== null && str_contains($e->getMessage(), '401'));
check('HTTP error message has no key', $e !== null && !str_contains($e->getMessage(), 'key-xyz'));
$e = $threwClient(fn () => $driverWith(function () { throw new RuntimeException('cURL error 28 for https://opm/api?apiKey=key-xyz'); })->listDevices());
check('transport failure throws ClientException', $e !== null);
check('transport failure message redacted', $e !== null && !str_contains($e->getMessage(), 'key-xyz'));
$blank = new Connection(['name' => 'blank', 'brand' => 'opmanager', 'url' => '', 'api_key' => '']);
check('unconfigured driver refuses', $threwClient(fn () => $driverWith($rec, $blank)->listDevices()) !== null);

// Write answers seen on production.
$ok = $threwClient(fn () => $driverWith(fn () => ['status' => 200, 'body' => '{"ackMessage":"Alarm has been successfully Cleared!","AlarmProperty":{}}'])->clear('E')) === null;
check('clear ackMessage answer is success', $ok);
$e = $threwClient(fn () => $driverWith(fn () => ['status' => 200, 'body' => '{"error":{"message":"You are not authorized to perform this operation","errorcode":"AUTHORIZATION_FAILED"}}'])->addNote('E', 'x'));
check('write error object throws', $e !== null && str_contains($e->getMessage(), 'AUTHORIZATION_FAILED'));
$ok = $threwClient(fn () => $driverWith(fn () => ['status' => 200, 'body' => '{"ackMessage":"The selected Alarm is not Acknowledged","AlarmProperty":{}}'])->acknowledge('E')) === null;
check('repeat acknowledge is not a failure', $ok);

done();
