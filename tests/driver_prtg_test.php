<?php

require __DIR__ . '/bootstrap.php';
foreach (['Connection/Connection', 'Connection/Settings', 'Driver/Driver', 'Driver/Capabilities', 'Driver/DriverFactory',
    'Driver/UnsupportedOperation', 'Driver/OpManager/OpManagerDriver', 'Driver/Zabbix/ZabbixDriver',
    'Driver/Prtg/PrtgException', 'Driver/Prtg/PrtgApiError', 'Driver/Prtg/PrtgClient', 'Driver/Prtg/ClassicClient',
    'Driver/Prtg/V2Client', 'Driver/Prtg/DeviceParser', 'Driver/Prtg/SensorParser', 'Driver/Prtg/AckRefused',
    'Driver/Prtg/PrtgDriver'] as $f) {
    require OPM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\Prtg\AckRefused;
use GlpiPlugin\Nms\Driver\Prtg\PrtgApiError;
use GlpiPlugin\Nms\Driver\Prtg\PrtgClient;
use GlpiPlugin\Nms\Driver\Prtg\PrtgDriver;
use GlpiPlugin\Nms\Driver\Prtg\PrtgException;
use GlpiPlugin\Nms\Driver\Prtg\SensorParser;
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

const PRTG_SECRET = 'key-SECRET-xyz';

function pconn(array $settings = [], string $key = PRTG_SECRET): Connection
{
    return new Connection([
        'id' => 1, 'name' => 'prtg', 'brand' => 'prtg', 'url' => 'https://prtg.example.test',
        'api_key' => $key === '' ? '' : (new GLPIKey())->encrypt($key), 'username' => 'api', 'password' => '',
        'verify_tls' => 1, 'is_active' => 1, 'settings' => json_encode($settings),
    ]);
}

function pfix(string $name): array
{
    $j = json_decode((string) file_get_contents(__DIR__ . "/fixtures/prtg/classic/26.4/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    return $j;
}

/** Fake PrtgClient: normalised rows, records every call. */
final class FakeClient implements PrtgClient
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];
    public ?\Throwable $ackError = null;
    public ?\Throwable $versionError = null;
    public string $versionText = '26.4.126';

    /** @param list<array<string, mixed>> $devices @param list<array<string, mixed>> $sensors */
    public function __construct(public array $devices, public array $sensors) {}

    public function version(): string
    {
        $this->calls[] = ['version', null];
        if ($this->versionError) { throw $this->versionError; }
        return $this->versionText;
    }
    public function authMode(): string { return 'key'; }
    public function devices(): array { $this->calls[] = ['devices', null]; return $this->devices; }
    public function sensors(array $statusCodes): array
    {
        $this->calls[] = ['sensors', $statusCodes];
        return array_values(array_filter($this->sensors, fn ($s) => $statusCodes === [] || in_array($s['status_raw'], $statusCodes, true)));
    }
    public function sensor(string $id): ?array
    {
        $this->calls[] = ['sensor', $id];
        foreach ($this->sensors as $s) {
            if ($s['id'] === $id) { return $s; }
        }
        return null;
    }
    public function acknowledge(string $id, string $message): void
    {
        $this->calls[] = ['acknowledge', [$id, $message]];
        if ($this->ackError) { throw $this->ackError; }
    }
    public function count(string $name): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $name));
    }
}

function dev(string $id, string $name, string $host, string $group, int $raw = 3): array
{
    return ['id' => $id, 'name' => $name, 'host' => $host, 'group' => $group, 'status_raw' => $raw, 'status' => 'Up', 'tags' => ''];
}
function sens(string $id, string $dev, string $devId, string $group, int $raw): array
{
    return ['id' => $id, 'name' => 'Ping', 'device_id' => $devId, 'device' => $dev, 'group' => $group, 'status_raw' => $raw,
        'status' => 'S', 'message' => 'msg', 'type' => 'ping', 'lastcheck' => 46000.5];
}
function mk(array $settings = [], array $sensors = null, array $devices = null): array
{
    $client = new FakeClient(
        $devices ?? [dev('1001', 'sw-core-1', '192.0.2.10', 'Core'), dev('1002', 'srv-app-1', '192.0.2.20', 'Servers')],
        $sensors ?? [sens('2001', 'sw-core-1', '1001', 'Core', 5), sens('2002', 'srv-app-1', '1002', 'Servers', 4),
            sens('2003', 'srv-app-1', '1002', 'Servers', 10), sens('2004', 'sw-core-1', '1001', 'Core', 13),
            sens('2005', 'srv-app-1', '1002', 'Servers', 3)]
    );
    return [new PrtgDriver(pconn($settings), $client), $client];
}

// --- Factory ---
check('factory lists prtg', in_array('prtg', DriverFactory::supportedBrands(), true));
check('factory builds a PrtgDriver', DriverFactory::for(pconn()) instanceof PrtgDriver);
try {
    new PrtgDriver(pconn(['prtg_api' => 'soap']));
    check('unknown prtg_api throws PrtgException', false);
} catch (PrtgException $e) {
    check('unknown prtg_api throws PrtgException', true);
}

// --- Capabilities ---
$c = (new PrtgDriver(pconn(), new FakeClient([], [])))->capabilities();
check('classic capabilities', $c->devices && $c->alarms && $c->availability && !$c->note && $c->ack && !$c->clear && !$c->webhook && $c->writeback);
$c = (new PrtgDriver(pconn(['prtg_api' => 'v2']), new FakeClient([], [])))->capabilities();
check('v2 capabilities: read only', $c->devices && $c->alarms && $c->availability && !$c->note && !$c->ack && !$c->clear && !$c->webhook && !$c->writeback);

// --- listDevices ---
[$d, $cl] = mk();
check('listDevices keeps all with empty groups', count($d->listDevices()) === 2);
[$d] = mk(['prtg_groups' => "Core\n"]);
$rows = $d->listDevices();
check('listDevices filters by group', count($rows) === 1 && $rows[0]['device_key'] === '1001');
[$d] = mk(['prtg_groups' => 'core']);
check('group match is case-sensitive', $d->listDevices() === []);

// --- listActiveAlarms ---
[$d, $cl] = mk();
$alarms = $d->listActiveAlarms();
$keys   = array_column($alarms, 'alarm_key');
check('default states: down+warning', $keys === ['2001', '2002', '2004'], json_encode($keys));
check('sensors() got the down+warning codes', $cl->calls[0] === ['sensors', SensorParser::ALARM_CODES['down+warning']]);
check('one devices() call per run', $cl->count('devices') === 1);
check('IP from the same run devices', $alarms[0]['ip_address'] === '192.0.2.10' && $alarms[1]['ip_address'] === '192.0.2.20');
[$d, $cl] = mk(['prtg_alarm_states' => 'down']);
$d->listActiveAlarms();
check('down codes passed', $cl->calls[0] === ['sensors', [5, 13, 14]]);
[$d, $cl] = mk(['prtg_alarm_states' => 'down+warning+unusual']);
check('unusual included', array_column($d->listActiveAlarms(), 'alarm_key') === ['2001', '2002', '2003', '2004']);
[$d, $cl] = mk(['prtg_alarm_states' => 'bogus']);
$d->listActiveAlarms();
check('unknown state falls back to down+warning', $cl->calls[0] === ['sensors', [5, 13, 14, 4]]);
[$d, $cl] = mk(['prtg_groups' => 'Servers']);
check('alarms filtered by group', array_column($d->listActiveAlarms(), 'alarm_key') === ['2002']);

// --- alarmByKey ---
[$d, $cl] = mk(['prtg_groups' => 'Servers']);
$row = $d->alarmByKey('2001');
check('alarmByKey ignores group filter', $row !== null && $row['alarm_key'] === '2001' && $row['severity'] === 1 && $row['ip_address'] === '192.0.2.10');
check('alarmByKey uses sensor()', $cl->count('sensor') === 1 && $cl->count('sensors') === 0);
$row = $d->alarmByKey('2005');
check('alarmByKey status 3 is Clear', $row !== null && $row['severity'] === 5 && $row['severity_label'] === 'Clear');
check('alarmByKey missing sensor is null', $d->alarmByKey('9999') === null);

// --- alarmByKey honours prtg_alarm_states (R5) ---
[$d] = mk(['prtg_alarm_states' => 'down']);
$row = $d->alarmByKey('2002');
check('down setting: warning (4) is Clear', $row !== null && $row['severity'] === 5 && $row['severity_label'] === 'Clear', json_encode($row));
check('down setting: unusual (10) is Clear', $d->alarmByKey('2003')['severity'] === 5);
check('down setting: down (5) is severity 1', $d->alarmByKey('2001')['severity'] === 1);
[$d] = mk(['prtg_alarm_states' => 'down+warning']);
check('down+warning: status 4 is severity 3', $d->alarmByKey('2002')['severity'] === 3);
check('down+warning: status 10 is Clear', $d->alarmByKey('2003')['severity'] === 5);
[$d] = mk(['prtg_alarm_states' => 'down+warning+unusual']);
check('down+warning+unusual: status 10 is severity 3', $d->alarmByKey('2003')['severity'] === 3);
[$d] = mk(['prtg_alarm_states' => 'bogus']);
check('unknown state falls back to down+warning in alarmByKey', $d->alarmByKey('2002')['severity'] === 3 && $d->alarmByKey('2003')['severity'] === 5);

// --- devices table fetched once per driver instance (no N+1 in AlarmSync) ---
[$d, $cl] = mk();
$d->alarmByKey('2001');
$d->alarmByKey('2002');
check('two alarmByKey calls make one devices() call', $cl->count('devices') === 1 && $cl->count('sensor') === 2, (string) $cl->count('devices'));

// --- acknowledge ---
[$d, $cl] = mk();
$d->acknowledge('2001');
check('ack reads the sensor then writes', array_column($cl->calls, 0) === ['sensor', 'acknowledge'] && $cl->calls[1][1] === ['2001', 'Acknowledged in GLPI']);
foreach (['2004' => 13, '2002' => 4, '2003' => 10, '2005' => 3] as $id => $st) {
    [$d, $cl] = mk();
    $d->acknowledge($id);
    check("ack of status {$st} is a no-op success", $cl->count('acknowledge') === 0);
}
[$d, $cl] = mk(sensors: [sens('2010', 'x', '1001', 'Core', 14)]);
$d->acknowledge('2010');
check('ack of status 14 writes', $cl->count('acknowledge') === 1);
[$d, $cl] = mk();
$cl->ackError = new PrtgApiError('PRTG refused the request (HTTP 403)');
try { $d->acknowledge('2001'); check('PrtgApiError becomes AckRefused', false); } catch (AckRefused $e) {
    check('PrtgApiError becomes AckRefused', $e instanceof \DomainException);
}
$cl->ackError = new PrtgException('PRTG HTTP error 502');
try { $d->acknowledge('2001'); check('plain PrtgException propagates', false); } catch (AckRefused $e) {
    check('plain PrtgException propagates', false, 'was converted');
} catch (PrtgException $e) {
    check('plain PrtgException propagates', true);
}
try { $d->acknowledge('9999'); check('ack of missing sensor refused', false); } catch (AckRefused $e) { check('ack of missing sensor refused', true); }

// --- unsupported ---
foreach (['addNote' => fn ($d) => $d->addNote('2001', 'x'), 'clear' => fn ($d) => $d->clear('2001')] as $name => $fn) {
    [$d] = mk();
    try { $fn($d); check("{$name} unsupported", false); } catch (UnsupportedOperation $e) { check("{$name} unsupported", true); }
}

// --- v2: no write ---
$cl = new FakeClient([], [sens('2001', 'x', '1', 'Core', 5)]);
$d  = new PrtgDriver(pconn(['prtg_api' => 'v2']), $cl);
try { $d->acknowledge('2001'); check('v2 acknowledge unsupported', false); } catch (UnsupportedOperation $e) {
    check('v2 acknowledge unsupported', true);
}
check('v2 acknowledge made no client call', $cl->calls === []);

// --- reads never write ---
[$d, $cl] = mk();
$d->listDevices(); $d->listActiveAlarms(); $d->alarmByKey('2001'); $d->testConnection();
check('reads never call acknowledge', $cl->count('acknowledge') === 0);

// --- testConnection ---
[$d, $cl] = mk();
$r = $d->testConnection();
check('test: ok with version and auth mode', $r['ok'] && str_contains($r['message'], '26.4.126') && str_contains($r['message'], 'key auth'), $r['message']);
check('test: never the secret', !str_contains(json_encode($r), PRTG_SECRET));
$cl = new FakeClient([], []);
$cl->versionText = 'unknown';
$r = (new PrtgDriver(pconn(['prtg_api' => 'v2']), $cl))->testConnection();
check('test v2: names experimental read-only API v2', $r['ok'] && str_contains($r['message'], 'API v2 (experimental, read-only)') && str_contains($r['message'], 'unknown'), $r['message']);
check('test v2: proves availability with a list endpoint', $cl->count('devices') === 1);
$cl->versionError = new PrtgApiError('PRTG refused the request (HTTP 401)');
$r = (new PrtgDriver(pconn(), $cl))->testConnection();
check('test: failure reported, not thrown', $r['ok'] === false && str_contains($r['message'], '401'));
$r = (new PrtgDriver(new Connection(array_merge((array) pconn(), ['url' => ''])), new FakeClient([], [])))->testConnection();
check('test: unconfigured URL', $r['ok'] === false && str_contains($r['message'], 'URL'));

done();
