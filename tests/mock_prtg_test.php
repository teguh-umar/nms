<?php

// Mock PRTG endpoint: the real PrtgDriver, through an in-process transport into the mock's handler,
// must see exactly what the parsers produce from the same fixtures; no credentials means refusal.
require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities', 'Driver/UnsupportedOperation',
    'Driver/Prtg/PrtgException', 'Driver/Prtg/PrtgApiError', 'Driver/Prtg/PrtgClient', 'Driver/Prtg/ClassicClient',
    'Driver/Prtg/V2Client', 'Driver/Prtg/DeviceParser', 'Driver/Prtg/SensorParser', 'Driver/Prtg/AckRefused',
    'Driver/Prtg/PrtgDriver'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}
require __DIR__ . '/mock_prtg/handler.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Prtg\ClassicClient;
use GlpiPlugin\Nms\Driver\Prtg\DeviceParser;
use GlpiPlugin\Nms\Driver\Prtg\PrtgDriver;
use GlpiPlugin\Nms\Driver\Prtg\SensorParser;
use GlpiPlugin\Nms\Driver\Prtg\V2Client;

function mfix(string $path): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/prtg/{$path}.json"), true, 512, JSON_THROW_ON_ERROR);
}

function mconn(array $settings): Connection
{
    return new Connection([
        'id' => 1, 'name' => 'prtg', 'brand' => 'prtg', 'url' => 'https://prtg.example.test',
        'api_key' => '', 'username' => 'api', 'password' => '',
        'verify_tls' => 1, 'is_active' => 1, 'settings' => json_encode($settings),
    ]);
}

/** Splits a URL into [path, query array] the way a web server would. */
function msplit(string $url): array
{
    $parts = parse_url($url);
    return [$parts['path'] ?? '', nms_mock_prtg_parse_query($parts['query'] ?? '')];
}

/** Classic transport into the mock; $log records every request path and query. */
function mclassic(string $line, array &$log = []): callable
{
    return function (string $url, array $headers) use ($line, &$log): array {
        [$path, $q] = msplit($url);
        $log[] = [$path, $q];
        return nms_mock_prtg_handle('GET', $path, $q, $headers, null, $line);
    };
}

function mv2(array &$log = []): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use (&$log): array {
        [$path, $q] = msplit($url);
        $log[] = [$method, $path];
        return nms_mock_prtg_handle($method, $path, $q, $headers, $body, '26.4');
    };
}

/** Devices as the classic client normalises them, straight from a fixture. */
function mclassic_devices(string $line): array
{
    return array_map(
        fn ($r) => ['id' => (string) $r['objid'], 'name' => $r['device'], 'host' => $r['host'], 'group' => $r['group'],
            'status_raw' => (int) $r['status_raw'], 'status' => $r['status'], 'tags' => (string) ($r['tags'] ?? '')],
        mfix("classic/{$line}/devices")['devices']
    );
}

foreach (['26.4', '26.3', '26.2'] as $line) {
    $v   = "[classic $line] ";
    $log = [];
    $d   = new PrtgDriver(mconn(['prtg_api' => 'classic', 'prtg_alarm_states' => 'down+warning']), new ClassicClient('https://prtg.example.test', 'mock-key', '', '', mclassic($line, $log)));
    $t   = $d->testConnection();
    check($v . 'testConnection ok through mock', $t['ok'] === true && str_contains($t['message'], '26.'), json_encode($t));
    check($v . 'devices equal DeviceParser on the fixture', $d->listDevices() === DeviceParser::parse(mclassic_devices($line)));
    $alarms = $d->listActiveAlarms();
    check($v . 'alarms are non-empty', count($alarms) > 0);
    $sensorsReq = array_filter($log, fn ($r) => $r[0] === ClassicClient::PATH_TABLE && ($r[1]['content'] ?? '') === 'sensors');
    check($v . 'alarm query reached the mock as table.json content=sensors', count($sensorsReq) >= 1);
}

// Acknowledge reaches the mock with the sensor id (classic 26.4: sensor 2001 is Down).
$log = [];
$d   = new PrtgDriver(mconn(['prtg_api' => 'classic']), new ClassicClient('https://prtg.example.test', 'mock-key', '', '', mclassic('26.4', $log)));
$d->acknowledge('2001');
$acks = array_values(array_filter($log, fn ($r) => $r[0] === ClassicClient::PATH_ACK));
check('acknowledge reaches the mock with the sensor id', count($acks) === 1 && ($acks[0][1]['id'] ?? '') === '2001', json_encode($acks));

// Login mode (username + passhash) works against the mock.
$login = new PrtgDriver(mconn(['prtg_api' => 'classic']), new ClassicClient('https://prtg.example.test', '', 'api', 'pw-mock', mclassic('26.4')));
check('classic login mode: testConnection ok', $login->testConnection()['ok'] === true);

// Refusals: no credentials delivered.
$bare = new PrtgDriver(mconn(['prtg_api' => 'classic']), new ClassicClient('https://prtg.example.test', 'mock-key', '', '', function (string $u, array $h): array {
    [$p, $q] = msplit($u);
    return nms_mock_prtg_handle('GET', $p, $q, [], null, '26.4'); // headers dropped
}));
check('classic without delivered credentials is refused', $bare->testConnection()['ok'] === false);
$r = nms_mock_prtg_handle('GET', '/api/table.json', ['content' => 'devices'], [], null, '26.4');
check('classic no auth: HTTP 401 with an error body', $r['status'] === 401 && isset(json_decode($r['body'], true)['error']), json_encode($r));
$r = nms_mock_prtg_handle('GET', '/api/status.json', [], [], null, '26.4');
check('classic status.json also needs auth', $r['status'] === 401);
$r = nms_mock_prtg_handle('GET', '/api/getpasshash.htm', ['username' => 'a', 'password' => 'b'], [], null, '26.4');
check('getpasshash needs no auth', $r['status'] === 200 && trim($r['body']) !== '');
$r = nms_mock_prtg_handle('GET', '/api/table.json', ['content' => 'devices', 'username' => 'a', 'passhash' => 'x'], [], null, '26.4');
check('username+passhash accepted', $r['status'] === 200);
$r = nms_mock_prtg_handle('GET', '/api/nope.json', [], ['Authorization' => 'Bearer x'], null, '26.4');
check('unknown classic route: 404', $r['status'] === 404);
$r = nms_mock_prtg_handle('GET', '/api/status.json', [], ['Authorization' => 'Bearer x'], null, '99.9');
check('unknown line: 400', $r['status'] === 400);

// API v2.
$log = [];
$v2  = new PrtgDriver(mconn(['prtg_api' => 'v2', 'prtg_alarm_states' => 'down+warning']), new V2Client('https://prtg.example.test', 'mock-key', '', '', mv2($log)));
$t   = $v2->testConnection();
check('[v2] testConnection ok through mock', $t['ok'] === true && str_contains($t['message'], '26.4'), json_encode($t));
$ref = new V2Client('https://prtg.example.test', 'k', '', '', function (string $m, string $u): array {
    [$p, $q] = msplit($u);
    $f = str_contains($p, '/devices') ? 'devices' : 'sensors';
    $items = (int) ($q['offset'] ?? 0) > 0 ? [] : mfix("v2/{$f}")['items'];
    return ['status' => 200, 'body' => json_encode(['items' => $items])];
});
check('[v2] devices equal DeviceParser on the fixture', $v2->listDevices() === DeviceParser::parse($ref->devices()));
$codes  = SensorParser::ALARM_CODES['down+warning'];
$expect = SensorParser::parse(
    array_values(array_filter($ref->sensors([]), fn ($s) => in_array($s['status_raw'], $codes, true))),
    array_column($ref->devices(), null, 'id')
);
check('[v2] alarms equal SensorParser on the fixture', count($expect) > 0 && $v2->listActiveAlarms() === $expect);
$v2login = new PrtgDriver(mconn(['prtg_api' => 'v2']), new V2Client('https://prtg.example.test', '', 'api', 'pw-mock', mv2($log)));
check('[v2] login mode: POST /session then devices', $v2login->testConnection()['ok'] === true && in_array(['POST', '/api/v2/session'], $log, true));
$bare = new PrtgDriver(mconn(['prtg_api' => 'v2']), new V2Client('https://prtg.example.test', 'mock-key', '', '', function (string $m, string $u, array $h, ?string $b): array {
    [$p, $q] = msplit($u);
    return nms_mock_prtg_handle($m, $p, $q, [], $b, '26.4');
}));
check('[v2] without delivered credentials is refused', $bare->testConnection()['ok'] === false);
$r = nms_mock_prtg_handle('GET', '/api/v2/experimental/devices', [], [], null, '26.4');
check('[v2] no auth: HTTP 401 with an error body', $r['status'] === 401 && isset(json_decode($r['body'], true)['error']));
$r = nms_mock_prtg_handle('POST', '/api/v2/session', [], [], json_encode(['username' => 'a', 'password' => 'b']), '26.4');
check('[v2] POST /session needs no auth and returns a token', $r['status'] === 200 && is_string(json_decode($r['body'], true)['token'] ?? null));
$r = nms_mock_prtg_handle('GET', '/api/v2/experimental/sensors/2001', [], ['Authorization' => 'Bearer x'], null, '26.4');
check('[v2] single sensor by id', $r['status'] === 200 && (json_decode($r['body'], true)['id'] ?? '') === '2001');
$r = nms_mock_prtg_handle('GET', '/api/v2/experimental/sensors/9999', [], ['Authorization' => 'Bearer x'], null, '26.4');
check('[v2] missing sensor: 404', $r['status'] === 404);

done();
