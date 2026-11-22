<?php

require __DIR__ . '/bootstrap.php';
foreach (['PrtgException', 'PrtgApiError', 'PrtgClient', 'UnsupportedOperation', 'ClassicClient', 'V2Client', 'DeviceParser', 'SensorParser'] as $f) {
    if (is_file(OPM_ROOT . "/src/Driver/Prtg/{$f}.php")) {
        require_once OPM_ROOT . "/src/Driver/Prtg/{$f}.php";
    }
}

use GlpiPlugin\Nms\Driver\Prtg\ClassicClient;
use GlpiPlugin\Nms\Driver\Prtg\DeviceParser;
use GlpiPlugin\Nms\Driver\Prtg\SensorParser;
use GlpiPlugin\Nms\Driver\Prtg\V2Client;

date_default_timezone_set('Asia/Jakarta');
$fx = OPM_ROOT . '/tests/fixtures/prtg';

/** Classic client fed from a fixture line. */
function classic_rows(string $line): array
{
    $dir = OPM_ROOT . "/tests/fixtures/prtg/classic/{$line}";
    $c = new ClassicClient('https://prtg.example', 'k', '', '', function (string $url) use ($dir): array {
        $f = str_contains($url, 'content=devices') ? 'devices.json' : 'sensors.json';
        return ['status' => 200, 'body' => file_get_contents("{$dir}/{$f}")];
    });
    return [$c->devices(), $c->sensors([])];
}

$byId = static function (array $devs): array {
    $m = [];
    foreach ($devs as $d) {
        $m[$d['id']] = $d;
    }
    return $m;
};

foreach (['26.4', '26.3', '26.2'] as $line) {
    [$devs, $sens] = classic_rows($line);
    $devices = DeviceParser::parse($devs);
    check("{$line}: 4 devices", count($devices) === 4);
    check("{$line}: device key order", array_keys($devices[0]) === ['device_key', 'name', 'display_name', 'ip_address', 'device_type', 'device_category', 'availability']);
    check("{$line}: device 1 mapped", $devices[0] === [
        'device_key' => '1001', 'name' => 'sw-core-1', 'display_name' => 'sw-core-1', 'ip_address' => '192.0.2.10',
        'device_type' => 'net', 'device_category' => 'Core', 'availability' => 'Up',
    ], json_encode($devices[0]));
    check("{$line}: DNS host kept", $devices[1]['ip_address'] === 'app1.example.test');
    check("{$line}: paused and no tags", $devices[2]['availability'] === 'Paused' && $devices[2]['device_type'] === '');
    $alarms = SensorParser::parse($sens, $byId($devs));
    check("{$line}: 5 alarm rows (Up dropped)", count($alarms) === 5);
}

[$devs, $sens] = classic_rows('26.4');
$map = $byId($devs);
$a = SensorParser::parse($sens, $map);
check('alarm key order', array_keys($a[0]) === ['alarm_key', 'entity_key', 'device_name', 'display_name', 'ip_address', 'category', 'alarm_code', 'event_type', 'severity', 'severity_label', 'message', 'mod_time']);
check('down sensor row', $a[0]['alarm_key'] === '2001' && $a[0]['entity_key'] === '2001' && $a[0]['alarm_code'] === '2001'
    && $a[0]['device_name'] === 'sw-core-1' && $a[0]['ip_address'] === '192.0.2.10' && $a[0]['category'] === 'Core'
    && $a[0]['event_type'] === 'ping' && $a[0]['severity'] === 1 && $a[0]['severity_label'] === 'Down'
    && $a[0]['message'] === 'Ping: No response (code: PE018)', json_encode($a[0]));
check('warning severity 3, tags stripped, entities decoded', $a[1]['severity'] === 3 && $a[1]['message'] === 'Disk Free C:: Free space < 10%', $a[1]['message']);
check('comparison text kept, decoded once', $a[2]['message'] === 'HTTP Load: Value & trend: value < 5 and > 3', $a[2]['message']);
check('ack and partial are severity 1', $a[3]['severity'] === 1 && $a[4]['severity'] === 1);
check('OLE 46000.5 (UTC noon 2025-12-09) in Asia/Jakarta', $a[0]['mod_time'] === '2025-12-09 19:00:00', $a[0]['mod_time']);
check('null lastcheck gives now', abs(strtotime($a[3]['mod_time']) - time()) < 5, $a[3]['mod_time']);
check('missing device gives empty ip', SensorParser::parse($sens, [])[0]['ip_address'] === '');
check('double-encoded entity decoded once', SensorParser::parse([['id' => '1', 'name' => 'n', 'device_id' => '1', 'device' => 'd', 'group' => 'g', 'status_raw' => 5, 'status' => 'Down', 'message' => '&amp;lt;b&amp;gt;', 'type' => 't', 'lastcheck' => 46000.5]], [])[0]['message'] === 'n: &lt;b&gt;');

check('Up sensor yields no row in parse()', SensorParser::parse([$sens[5]], $map) === []);
check('Paused and Unknown yield no row', SensorParser::parse([['id' => '9', 'status_raw' => 7], ['id' => '8', 'status_raw' => 1]], $map) === []);
$nodev = $sens[0];
$nodev['device'] = '';
check('device name falls back to devicesById', SensorParser::parse([$nodev], $map)[0]['device_name'] === 'sw-core-1' && SensorParser::parse([$nodev], $map)[0]['display_name'] === 'sw-core-1');

$cur = SensorParser::current($sens[5], $map);
check('current() of Up is severity 5 Clear', $cur['severity'] === 5 && $cur['severity_label'] === 'Clear' && $cur['alarm_key'] === '2006', json_encode($cur));
check('current() of Down is the alarm row', SensorParser::current($sens[0], $map)['severity'] === 1);

check('ALARM_CODES', SensorParser::ALARM_CODES === ['down' => [5, 13, 14], 'down+warning' => [5, 13, 14, 4], 'down+warning+unusual' => [5, 13, 14, 4, 10]]);
check('SEVERITY', SensorParser::SEVERITY === [5 => 1, 13 => 1, 14 => 1, 4 => 3, 10 => 3]);
check('PAUSED', DeviceParser::PAUSED === [7, 8, 9, 11, 12]);

// v2 fixtures through V2Client
$c = new V2Client('https://prtg.example', 'k', '', '', function (string $m, string $url) use ($fx): array {
    $f = str_contains($url, '/sensors') ? 'sensors.json' : (str_contains($url, '/devices') ? 'devices.json' : 'version.json');
    return ['status' => 200, 'body' => str_contains($url, 'offset=0') || $f === 'version.json' ? file_get_contents("{$fx}/v2/{$f}") : '{"items":[]}'];
});
$vd = $c->devices();
$vs = $c->sensors([]);
$vdev = DeviceParser::parse($vd);
check('v2: 4 devices, tags list -> first tag', count($vdev) === 4 && $vdev[3]['device_type'] === 'firewall' && $vdev[2]['availability'] === 'Paused', json_encode($vdev[2] ?? null));
$va = SensorParser::parse($vs, $byId($vd));
check('v2: sensors map', count($va) === 5 && $va[0]['ip_address'] === '192.0.2.10' && $va[0]['severity'] === 1 && $va[2]['message'] === 'HTTP Load: Value & trend: value < 5 and > 3', json_encode($va[2] ?? null));
check('v2: ISO date round-trips to the same wall clock', $va[0]['mod_time'] === '2025-12-09 19:00:00', $va[0]['mod_time']);
check('v2 version fixture', $c->version() === '26.4.126.1824');

done();
