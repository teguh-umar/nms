<?php

namespace GlpiPlugin\Nms {
    /** Config stub (in the plugin namespace, as Cron.php resolves Config:: there). */
    if (!class_exists('Config', false)) {
        class Config
        {
            public static function getConfigurationValues(string $context, array $keys = []): array { return []; }
            public static function setConfigurationValues(string $context, array $values): void { }
            public static function assetItemtypes(): array
            {
                return ['Computer', 'NetworkEquipment'];
            }
        }
    }
}

namespace {

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI
{
    public static function getTable($classname = null): string
    {
        $class = $classname ?? static::class;
        $parts = explode('\\', $class);
        $short = end($parts);
        if ($short === 'Device') {
            return 'glpi_plugin_nms_devices';
        }
        return 'glpi_' . strtolower($short) . 's';
    }
}

/** CronTask stub (must be defined before Cron.php is loaded). */
if (!class_exists('\CronTask', false)) {
    class CronTask
    {
        public function log(string $msg): void { }
        public function addVolume(int $n): void { }
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
require OPM_ROOT . '/src/DeviceStore.php';
require OPM_ROOT . '/src/Device.php';
require OPM_ROOT . '/src/Cron.php';
require OPM_ROOT . '/src/DeviceSync.php';
require OPM_ROOT . '/src/DbDeviceStore.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/DbAssetLookup.php';
require OPM_ROOT . '/src/Matching/Matcher.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Cron;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\Capabilities;

/** Minimal GLPIKey stub: encrypt/decrypt are reversible, no real crypto. */
if (!class_exists('\GLPIKey', false)) {
    class GLPIKey
    {
        public function encrypt(string $value): string
        {
            return base64_encode('enc:' . $value);
        }
        public function decrypt($value)
        {
            if (!is_string($value)) {
                return false;
            }
            $dec = base64_decode($value, true);
            if ($dec === false || !str_starts_with($dec, 'enc:')) {
                return false;
            }
            return substr($dec, 4);
        }
    }
}

/** Session stub. */
if (!class_exists('\Session', false)) {
    class Session
    {
        public static function getCurrentTime(): string
        {
            return '2026-11-11 00:00:00';
        }
    }
}

/** Global \Config stub (Cron::cronDeviceSync calls \Config::set()). */
if (!class_exists('\Config', false)) {
    class Config
    {
        public static function set(array $values): void { }
    }
}

/** GLPI helper: stub for DbAssetLookup / Matcher. */
if (!function_exists('getItemForItemtype')) {
    function getItemForItemtype(string $itemtype)
    {
        return null;
    }
}

/** Toolbox stub. */
if (!class_exists('\Toolbox', false)) {
    class Toolbox
    {
        public static function logInFile(string $plugin, string $msg): void
        {
        }
    }
}

/** CronTask stub (extends the real stub defined above). */
final class FakeCronTask extends \CronTask
{
    /** @var list<string> */
    public array $logs = [];
    public int $volume = 0;
    public function log(string $msg): void { $this->logs[] = $msg; }
    public function addVolume(int $n): void { $this->volume += $n; }
}

/** Fake $DB stub (pattern from tests/connection_test.php). */
final class FakeDB
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    public int $insertId = 0;

    public function request(array $criteria): \ArrayIterator
    {
        $table = $criteria['FROM'] ?? '';
        $where = $criteria['WHERE'] ?? [];
        $out = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if (is_array($val)) {
                    foreach ($val as $op => $v) {
                        $rv = $row[$col] ?? null;
                        match ($op) {
                            '>'    => $ok = $ok && $rv > $v,
                            '<>'   => $ok = $ok && $rv !== $v,
                            'NOT'  => $ok = $ok && !in_array($rv, (array) $v, true),
                            default => $ok = $ok && $rv === $v,
                        };
                    }
                } elseif (!isset($row[$col]) || $row[$col] !== $val) {
                    $ok = false;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return new \ArrayIterator($out);
    }

    public function insert(string $table, array $fields): int
    {
        $this->insertId++;
        $this->tables[$table][] = $fields + ['id' => $this->insertId];
        return $this->insertId;
    }

    public function update(string $table, array $fields, array $where): void
    {
        if (!isset($this->tables[$table])) {
            return;
        }
        foreach ($this->tables[$table] as $i => $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || $row[$col] !== $val) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $this->tables[$table][$i] = array_merge($row, $fields);
            }
        }
    }

    public function delete(string $table, array $where): void
    {
        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            fn (array $row) => !self::matches($row, $where)
        ));
    }

    public function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function fieldExists(string $table, string $field): bool
    {
        foreach ($this->tables[$table] ?? [] as $row) {
            if (isset($row[$field])) {
                return true;
            }
        }
        return false;
    }

    public function insertId(): int
    {
        return $this->insertId;
    }

    public function numrows(): int
    {
        return 0;
    }

    private static function matches(array $row, array $where): bool
    {
        foreach ($where as $col => $val) {
            if (!isset($row[$col]) || $row[$col] !== $val) {
                return false;
            }
        }
        return true;
    }
}

$GLOBALS['DB'] = new FakeDB();

/** Driver that throws on listDevices for a chosen connection id, else returns two devices. */
final class IsolationDriver implements Driver
{
    public function __construct(private int $connId)
    {
    }
    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, true, true, true, true, true);
    }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function listDevices(): array
    {
        if ($this->connId === 1) {
            throw new \RuntimeException('conn1 boom with key-xyz');
        }
        return [
            [
                'device_key'   => 'dev-1',
                'name'         => 'core-sw-01',
                'display_name' => 'core-sw-01',
                'ip_address'   => '10.20.x.x',
                'device_type'  => 'Switch',
                'device_category' => 'Network',
                'availability' => 'Available',
            ],
            [
                'device_key'   => 'dev-2',
                'name'         => 'vm-app-01',
                'display_name' => 'vm-app-01',
                'ip_address'   => '10.20.x.y',
                'device_type'  => 'Server',
                'device_category' => 'Server',
                'availability' => 'Available',
            ],
        ];
    }
    public function alarmByKey(string $key): ?array { return null; }
    public function listActiveAlarms(): array { return []; }
    public function addNote(string $alarm_key, string $text): void {}
    public function acknowledge(string $alarm_key): void {}
    public function clear(string $alarm_key): void {}
}

DriverFactory::setTestOverride(fn (Connection $c) => new IsolationDriver((int) $c->id));

$enc = new \GLPIKey();
$connFields = [
    'brand'            => 'opmanager',
    'entities_id'      => 1,
    'is_recursive'     => 1,
    'url'              => 'https://opm.example.test',
    'api_key'          => $enc->encrypt('key-xyz'),
    'username'         => 'pg-nms',
    'password'         => '',
    'verify_tls'       => 1,
    'settings'         => '{}',
    'date_creation'    => '2026-11-11 00:00:00',
    'date_mod'         => '2026-11-11 00:00:00',
];

// Connection 1: active, will throw on listDevices.
$store = new DbConnectionStore();
$id1 = $store->save(array_merge($connFields, [
    'name'           => 'conn-1',
    'is_active'      => 1,
    'tickets_enabled'=> 1,
    'writeback_enabled'=> 1,
]));
check('conn 1 created', $id1 === 1);

// Connection 2: active, will succeed.
$id2 = $store->save(array_merge($connFields, [
    'name'           => 'conn-2',
    'is_active'      => 1,
    'tickets_enabled'=> 1,
    'writeback_enabled'=> 1,
]));
check('conn 2 created', $id2 === 2);

// Connection 3: inactive, must never be called.
$id3 = $store->save(array_merge($connFields, [
    'name'           => 'conn-3',
    'is_active'      => 0,
    'tickets_enabled'=> 1,
    'writeback_enabled'=> 1,
]));
check('conn 3 (inactive) created', $id3 === 3);

$task = new FakeCronTask();
$code = Cron::cronDeviceSync($task);
check('cronDeviceSync returns 1 (some devices fetched)', $code === 1);

// Conn 1: status error, error text contains no api_key value.
$c1 = $store->get(1);
check('conn 1 status error', $c1 !== null && $c1->last_status === 'error');
check('conn 1 error has no secret', $c1 !== null && strpos($c1->last_error, 'key-xyz') === false);

// Conn 2: status ok.
$c2 = $store->get(2);
check('conn 2 status ok', $c2 !== null && $c2->last_status === 'ok');

// Conn 2: two devices stored, both scoped to connections_id = 2.
$devs2 = array_filter($GLOBALS['DB']->tables['glpi_plugin_nms_devices'] ?? [], fn (array $r) => (int) $r['connections_id'] === 2);
check('conn 2 has two devices', count($devs2) === 2);

// Conn 1: no devices stored (driver threw before any upsert).
$devs1 = array_filter($GLOBALS['DB']->tables['glpi_plugin_nms_devices'] ?? [], fn (array $r) => (int) $r['connections_id'] === 1);
check('conn 1 has no devices', count($devs1) === 0);

// Conn 3 (inactive): no devices, never called.
$devs3 = array_filter($GLOBALS['DB']->tables['glpi_plugin_nms_devices'] ?? [], fn (array $r) => (int) $r['connections_id'] === 3);
check('conn 3 (inactive) has no devices', count($devs3) === 0);

done();

}
