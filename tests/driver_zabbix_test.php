<?php

require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities', 'Driver/DriverFactory',
    'Driver/UnsupportedOperation', 'Driver/OpManager/OpManagerDriver', 'Driver/Zabbix/ZabbixException',
    'Driver/Zabbix/JsonRpc', 'Driver/Zabbix/HostParser', 'Driver/Zabbix/ProblemParser',
    'Driver/Zabbix/CloseRefused', 'Driver/Zabbix/ZabbixDriver'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\Zabbix\ZabbixDriver;
use GlpiPlugin\Nms\Driver\Zabbix\CloseRefused;

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

function zfix(string $ver, string $name): mixed
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/zabbix/{$ver}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

const SECRET_TOKEN = 'tok-SECRET-123';

function zconn(array $settings = [], string $token = SECRET_TOKEN): Connection
{
    return new Connection([
        'id' => 1, 'name' => 'zbx', 'brand' => 'zabbix', 'url' => 'https://zbx.example.test',
        'api_key' => $token === '' ? '' : (new GLPIKey())->encrypt($token), 'username' => 'api', 'password' => '',
        'verify_tls' => 1, 'is_active' => 1, 'settings' => json_encode($settings),
    ]);
}

/** Fake transport: answers by method from fixtures; $over[method] = callable(params) overrides. */
function zfake(string $ver, array &$calls, array $over = []): callable
{
    return function (string $url, string $body, array $headers) use ($ver, &$calls, $over): array {
        $req = json_decode($body, true);
        $m = $req['method'];
        $p = is_array($req['params']) ? $req['params'] : [];
        if ($m !== 'apiinfo.version') {
            $calls[] = ['method' => $m, 'params' => $p];
        }
        if (isset($over[$m])) {
            $r = $over[$m]($p);
            if (is_array($r) && isset($r['__error'])) {
                return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32500, 'message' => 'Application error.', 'data' => $r['__error']], 'id' => $req['id']])];
            }
            return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $r, 'id' => $req['id']])];
        }
        $result = match ($m) {
            'apiinfo.version' => zfix($ver, 'apiinfo.version'),
            'host.get', 'hostgroup.get', 'problem.get', 'event.get' => zfix($ver, $m),
            'event.acknowledge' => ['eventids' => $p['eventids'] ?? []],
            default => [],
        };
        return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $req['id']])];
    };
}

function zcalls(array $calls, string $method): array
{
    return array_values(array_filter($calls, fn($c) => $c['method'] === $method));
}

// factory
check('factory lists zabbix', in_array('zabbix', DriverFactory::supportedBrands(), true));
check('factory builds ZabbixDriver', DriverFactory::for(zconn()) instanceof ZabbixDriver);
$cap = (new ZabbixDriver(zconn(), fn() => ['status' => 200, 'body' => '{}']))->capabilities();
check('capabilities', $cap->devices && $cap->alarms && $cap->availability && $cap->note && $cap->ack && $cap->clear && !$cap->webhook && $cap->writeback);

foreach (['6.0', '7.0', '7.4'] as $ver) {
    $v = "[$ver] ";
    $sel = $ver === '6.0' ? 'selectGroups' : 'selectHostGroups';

    // listDevices with groups
    $calls = [];
    $d = new ZabbixDriver(zconn(['zabbix_host_groups' => "Servers\n\n  Switches \n"]), zfake($ver, $calls, [
        'hostgroup.get' => fn($p) => [['groupid' => '101', 'name' => 'Servers'], ['groupid' => '102', 'name' => 'Switches']],
    ]));
    $devs = $d->listDevices();
    $hg = zcalls($calls, 'hostgroup.get');
    $hh = zcalls($calls, 'host.get');
    check($v . 'one hostgroup.get with filter.name', count($hg) === 1 && $hg[0]['params']['filter']['name'] === ['Servers', 'Switches'], json_encode($hg));
    check($v . 'host.get uses resolved groupids', count($hh) === 1 && $hh[0]['params']['groupids'] === ['101', '102'], json_encode($hh));
    check($v . 'host.get selectors', isset($hh[0]['params'][$sel]) && ($hh[0]['params']['selectInventory'] ?? null) === ['type'] && isset($hh[0]['params']['selectInterfaces']), json_encode($hh[0]['params']));
    check($v . 'version-specific selector only', $ver === '6.0' ? !isset($hh[0]['params']['selectHostGroups']) : !isset($hh[0]['params']['selectGroups']));
    check($v . 'devices parsed', count($devs) === 6 && $devs[0]['device_key'] === '10501');

    // no groups setting
    $calls = [];
    $d = new ZabbixDriver(zconn(), zfake($ver, $calls));
    $d->listDevices();
    check($v . 'empty setting: no hostgroup.get, no groupids', zcalls($calls, 'hostgroup.get') === [] && !isset(zcalls($calls, 'host.get')[0]['params']['groupids']));

    // unresolved groups
    $calls = [];
    $d = new ZabbixDriver(zconn(['zabbix_host_groups' => 'Nope']), zfake($ver, $calls, ['hostgroup.get' => fn($p) => []]));
    check($v . 'unresolved groups: devices []', $d->listDevices() === [] && zcalls($calls, 'host.get') === []);
    check($v . 'unresolved groups: alarms []', $d->listActiveAlarms() === [] && zcalls($calls, 'problem.get') === []);

    // alarms
    $calls = [];
    $d = new ZabbixDriver(zconn(), zfake($ver, $calls));
    $alarms = $d->listActiveAlarms();
    $pg = zcalls($calls, 'problem.get');
    check($v . '3 data calls', count($calls) === 3 && count($pg) === 1 && count(zcalls($calls, 'event.get')) === 1 && count(zcalls($calls, 'host.get')) === 1, json_encode(array_column($calls, 'method')));
    check($v . 'problem.get severities/suppressed/recent', $pg[0]['params']['severities'] === [2, 3, 4, 5] && $pg[0]['params']['suppressed'] === false && $pg[0]['params']['recent'] === false, json_encode($pg[0]['params']));
    $eg = zcalls($calls, 'event.get')[0]['params'];
    check($v . 'event.get selectHosts + selectRelatedObject', isset($eg['selectHosts'], $eg['selectRelatedObject']) && count($eg['eventids']) === count(zfix($ver, 'problem.get')));
    check($v . 'alarms parsed', count($alarms) > 0 && $alarms[0]['alarm_key'] === '9001', json_encode($alarms[0] ?? null));
    check($v . 'no ack from reads', zcalls($calls, 'event.acknowledge') === []);

    $calls = [];
    $d = new ZabbixDriver(zconn(['zabbix_min_severity' => '4', 'zabbix_include_suppressed' => '1', 'zabbix_host_groups' => 'Switches']), zfake($ver, $calls, [
        'hostgroup.get' => fn($p) => [['groupid' => '102', 'name' => 'Switches']],
    ]));
    $d->listActiveAlarms();
    $pp = zcalls($calls, 'problem.get')[0]['params'];
    check($v . 'min severity 4, suppressed included, groupids', $pp['severities'] === [4, 5] && !array_key_exists('suppressed', $pp) && $pp['groupids'] === ['102'], json_encode($pp));

    // alarmByKey
    $calls = [];
    $d = new ZabbixDriver(zconn(), zfake($ver, $calls));
    $a = $d->alarmByKey('9001');
    check($v . 'alarmByKey event.get eventids', zcalls($calls, 'event.get')[0]['params']['eventids'] === ['9001'] && $a !== null);
    $calls = [];
    $d = new ZabbixDriver(zconn(), zfake($ver, $calls, ['event.get' => function ($p) use ($ver) {
        $e = zfix($ver, 'event.get')[0];
        $e['eventid'] = '77';
        $e['r_eventid'] = '88';
        return [$e];
    }]));
    $a = $d->alarmByKey('77');
    check($v . 'resolved event -> severity 5', $a !== null && $a['severity'] === 5, json_encode($a));
    $d = new ZabbixDriver(zconn(), zfake($ver, $calls, ['event.get' => fn($p) => []]));
    check($v . 'unknown event -> null', $d->alarmByKey('5') === null);
    check($v . 'alarmByKey never acks', zcalls($calls, 'event.acknowledge') === []);
}

// write ops
$calls = [];
$d = new ZabbixDriver(zconn(), zfake('7.0', $calls));
$d->addNote('77', 'x');
$d->acknowledge('77');
$d->clear('77');
$ack = zcalls($calls, 'event.acknowledge');
check('addNote action 4', $ack[0]['params'] === ['eventids' => ['77'], 'action' => 4, 'message' => 'x'], json_encode($ack[0]['params']));
check('acknowledge action 2', $ack[1]['params'] === ['eventids' => ['77'], 'action' => 2], json_encode($ack[1]['params']));
check('clear action 1', $ack[2]['params'] === ['eventids' => ['77'], 'action' => 1], json_encode($ack[2]['params']));

$d = new ZabbixDriver(zconn(), zfake('7.0', $calls, ['event.acknowledge' => fn($p) => ['__error' => 'Cannot close problem: trigger does not allow manual closing']]));
try {
    $d->clear('77');
    check('clear refused throws', false);
} catch (CloseRefused $e) {
    check('clear refused -> CloseRefused (DomainException)', $e instanceof \DomainException && !str_contains($e->getMessage(), SECRET_TOKEN), $e->getMessage());
}
try {
    $d->acknowledge('77');
    check('ack error propagates', false);
} catch (CloseRefused $e) {
    check('ack error is not CloseRefused', false);
} catch (\Throwable $e) {
    check('ack error propagates as a plain failure', true);
}

// testConnection
$calls = [];
$r = (new ZabbixDriver(zconn(), zfake('7.0', $calls)))->testConnection();
check('testConnection ok with version and token', $r['ok'] === true && str_contains($r['message'], '7.0') && str_contains($r['message'], 'token') && !str_contains($r['message'], SECRET_TOKEN), json_encode($r));
check('testConnection no ack', zcalls($calls, 'event.acknowledge') === []);
$login = new ZabbixDriver(zconn([], ''), zfake('6.0', $calls, ['user.login' => fn($p) => 'sess']));
$r = $login->testConnection();
check('testConnection login mode', $r['ok'] === true && str_contains($r['message'], 'login'), json_encode($r));
$r = (new ZabbixDriver(zconn(), function () { throw new RuntimeException('boom ' . SECRET_TOKEN); }))->testConnection();
check('testConnection failure safe', $r['ok'] === false && !str_contains($r['message'], SECRET_TOKEN) && $r['message'] !== '', json_encode($r));

done();
