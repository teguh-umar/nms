<?php

// Mock Zabbix endpoint: the driver, through an in-process transport into the mock's handler,
// must see exactly what the parsers produce from the same fixtures; no auth means refusal.
require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities',
    'Driver/Zabbix/ZabbixException', 'Driver/Zabbix/ZabbixApiError', 'Driver/Zabbix/JsonRpc', 'Driver/Zabbix/HostParser',
    'Driver/Zabbix/ProblemParser', 'Driver/Zabbix/CloseRefused', 'Driver/Zabbix/ZabbixDriver'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}
require __DIR__ . '/mock_zabbix/handler.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Zabbix\HostParser;
use GlpiPlugin\Nms\Driver\Zabbix\ZabbixDriver;

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

function mfix(string $ver, string $name): mixed
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/zabbix/{$ver}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

function mconn(string $token): Connection
{
    return new Connection([
        'id' => 1, 'name' => 'zbx', 'brand' => 'zabbix', 'url' => 'https://zbx.example.test',
        'api_key' => $token === '' ? '' : (new GLPIKey())->encrypt($token), 'username' => 'api', 'password' => '',
        'verify_tls' => 1, 'is_active' => 1, 'settings' => '{}',
    ]);
}

function mtransport(string $ver): callable
{
    return fn(string $url, string $body, array $headers): array => ['status' => 200, 'body' => nms_mock_zabbix_handle($body, $headers, $ver)];
}

foreach (['6.0', '7.0'] as $ver) {
    $v = "[$ver] ";
    $d = new ZabbixDriver(mconn('tok-mock'), mtransport($ver));
    $t = $d->testConnection();
    check($v . 'testConnection ok through mock', $t['ok'] === true && str_contains($t['message'], mfix($ver, 'apiinfo.version')), json_encode($t));
    check($v . 'devices equal HostParser on the fixture', $d->listDevices() === HostParser::parse(mfix($ver, 'host.get')));

    $ref = new ZabbixDriver(mconn('tok-mock'), function (string $u, string $b) use ($ver): array {
        $req = json_decode($b, true);
        $m = $req['method'];
        $r = in_array($m, ['apiinfo.version', 'host.get', 'problem.get', 'event.get', 'hostgroup.get'], true) ? mfix($ver, $m) : [];
        return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $r, 'id' => $req['id']])];
    });
    $alarms = $d->listActiveAlarms();
    check($v . 'alarms equal the fixture-direct driver', count($alarms) > 0 && $alarms === $ref->listActiveAlarms());
}

// refusals
$noauth = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'host.get', 'params' => new stdClass(), 'id' => 5]), [], '7.0'), true);
check('no auth: JSON-RPC error object', isset($noauth['error']['code'], $noauth['error']['message']) && !isset($noauth['result']) && $noauth['id'] === 5, json_encode($noauth));
$ver = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'apiinfo.version', 'params' => [], 'id' => 1]), [], '6.0'), true);
check('apiinfo.version needs no auth', $ver['result'] === mfix('6.0', 'apiinfo.version'));
$login = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'user.login', 'params' => ['username' => 'a', 'password' => 'b'], 'id' => 2]), [], '6.0'), true);
check('user.login needs no auth', is_string($login['result'] ?? null) && $login['result'] !== '');
$body = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'host.get', 'params' => new stdClass(), 'auth' => 'x', 'id' => 3]), [], '6.0'), true);
check('body auth accepted', is_array($body['result'] ?? null));
$ack = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'event.acknowledge', 'params' => ['eventids' => ['9001'], 'action' => 2], 'id' => 4]), ['Authorization' => 'Bearer x'], '7.0'), true);
check('event.acknowledge echoes ids', ($ack['result']['eventids'] ?? null) === ['9001']);
$unk = json_decode(nms_mock_zabbix_handle(json_encode(['jsonrpc' => '2.0', 'method' => 'nope.get', 'params' => [], 'id' => 6]), ['Authorization' => 'Bearer x'], '7.0'), true);
check('unknown method: error', isset($unk['error']));
$drv = new ZabbixDriver(mconn('tok-mock'), function (string $u, string $b, array $h): array {
    return ['status' => 200, 'body' => nms_mock_zabbix_handle($b, [], '7.0')]; // headers dropped
});
check('driver without delivered auth fails', $drv->testConnection()['ok'] === false);

done();
