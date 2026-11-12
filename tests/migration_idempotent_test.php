<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI
{
    public static function getTable($classname = null): string
    {
        $class = $classname ?? static::class;
        $parts = explode('\\', $class);
        $short = end($parts);
        return 'glpi_' . strtolower($short) . 's';
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/Migration/FromOpmanager.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Migration\FromOpmanager;

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

/** Fake $DB stub (pattern from tests/connection_test.php). */
final class FakeDB
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    public int $insertId = 0;
    /** @var list<string> every DML query, for asserting no writes to opmanager tables */
    public array $dml = [];

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
        $this->dml[] = 'INSERT INTO ' . $table;
        $this->insertId++;
        $this->tables[$table][] = $fields + ['id' => $this->insertId];
        return $this->insertId;
    }

    public function update(string $table, array $fields, array $where): void
    {
        $this->dml[] = 'UPDATE ' . $table;
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
        $this->dml[] = 'DELETE FROM ' . $table;
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

$enc = new \GLPIKey();
$now = '2026-11-11 00:00:00';

function opmRow(string $table, array $fields): array
{
    return $fields;
}

// --- Seed opmanager tables ---
$GLOBALS['DB']->tables['glpi_configs'] = [
    ['name' => 'opm_url', 'value' => 'https://opm.example.test', 'context' => 'plugin:opmanager'],
    ['name' => 'opm_api_key', 'value' => $enc->encrypt('key-xyz'), 'context' => 'plugin:opmanager'],
    ['name' => 'opm_verify_ssl', 'value' => '1', 'context' => 'plugin:opmanager'],
    ['name' => 'webhook_token', 'value' => $enc->encrypt('token-xyz'), 'context' => 'plugin:opmanager'],
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_devices'] = [
    opmRow('devices', [
        'device_key' => 'dev-1', 'name' => 'core-sw-01', 'display_name' => 'core-sw-01',
        'ip_address' => '10.20.x.x', 'device_type' => 'Switch', 'device_category' => 'Network',
        'availability' => 'Available', 'items_id' => 0, 'itemtype' => '', 'entities_id' => 1,
    ]),
    opmRow('devices', [
        'device_key' => 'dev-2', 'name' => 'vm-app-01', 'display_name' => 'vm-app-01',
        'ip_address' => '10.20.x.y', 'device_type' => 'Server', 'device_category' => 'Server',
        'availability' => 'Available', 'items_id' => 0, 'itemtype' => '', 'entities_id' => 1,
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_alarms'] = [
    opmRow('alarms', [
        'alarm_key' => 'A1', 'entity_key' => 'E1', 'device_name' => 'core-sw-01',
        'display_name' => 'core-sw-01', 'ip_address' => '10.20.x.x', 'category' => 'Network',
        'alarm_code' => 'DOWN', 'event_type' => 'DeviceDown', 'severity' => 3,
        'severity_label' => 'Critical', 'message' => 'Device is down',
        'mod_time' => '', 'state' => 'active',
    ]),
    opmRow('alarms', [
        'alarm_key' => 'A2', 'entity_key' => 'E1', 'device_name' => 'vm-app-01',
        'display_name' => 'vm-app-01', 'ip_address' => '10.20.x.y', 'category' => 'Server',
        'alarm_code' => 'CPU', 'event_type' => 'DeviceHighCpu', 'severity' => 2,
        'severity_label' => 'Trouble', 'message' => 'High CPU',
        'mod_time' => '', 'state' => 'active',
    ]),
    opmRow('alarms', [
        'alarm_key' => 'A3', 'entity_key' => 'E1', 'device_name' => 'core-sw-01',
        'display_name' => 'core-sw-01', 'ip_address' => '10.20.x.x', 'category' => 'Network',
        'alarm_code' => 'UP', 'event_type' => 'DeviceUp', 'severity' => 5,
        'severity_label' => 'Clear', 'message' => 'Device is up',
        'mod_time' => '', 'state' => 'active',
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_outbox'] = [
    opmRow('outbox', [
        'alarm_key' => 'A1', 'entity_key' => 'E1', 'tickets_id' => 0, 'users_id' => 0,
        'action' => 'clear', 'payload' => '{}', 'state' => 'pending',
        'attempts' => 0, 'next_try_at' => '', 'last_error' => '',
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_storms'] = [
    opmRow('storms', [
        'tickets_id' => 0, 'alarm_count' => 1, 'started_at' => $now,
        'last_hit_at' => $now, 'ended_at' => null,
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_ticketrequests'] = [
    opmRow('ticketrequests', [
        'alarm_key' => 'A1', 'entity_key' => 'E1',
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_deviceexceptions'] = [
    opmRow('deviceexceptions', [
        'device_key' => 'dev-1', 'reason' => 'testing', 'users_id' => 0,
        'is_active' => 1, 'revoked_by' => 0, 'revoked_at' => null,
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_hygienetickets'] = [
    opmRow('hygienetickets', [
        'flow' => 'glpi', 'tickets_id' => 0, 'last_report_at' => '',
    ]),
];

$GLOBALS['DB']->tables['glpi_plugin_opmanager_hygieneitems'] = [
    opmRow('hygieneitems', [
        'plugin_nms_hygienetickets_id' => 0, 'device_key' => 'dev-1',
        'hygiene_state' => 'nms_dead', 'reported_at' => '',
    ]),
    opmRow('hygieneitems', [
        'plugin_nms_hygienetickets_id' => 0, 'device_key' => 'dev-2',
        'hygiene_state' => 'nms_dead', 'reported_at' => '',
    ]),
];

// --- Profiles for rights copying ---
$GLOBALS['DB']->tables['glpi_profiles'] = [
    ['id' => 1, 'name' => 'Super-admin', 'is_system' => 1],
    ['id' => 2, 'name' => 'Technician', 'is_system' => 0],
];
$GLOBALS['DB']->tables['glpi_profilerights'] = [
    ['profiles_id' => 1, 'name' => 'config', 'rights' => 3], // READ|UPDATE
    ['profiles_id' => 1, 'name' => 'monitoring', 'rights' => 1],
    ['profiles_id' => 1, 'name' => 'hygiene', 'rights' => 3],
    ['profiles_id' => 2, 'name' => 'config', 'rights' => 1],
    ['profiles_id' => 2, 'name' => 'monitoring', 'rights' => 0],
    ['profiles_id' => 2, 'name' => 'hygiene', 'rights' => 0],
];

// --- nms tables are empty initially (created by install, but here we just need them to exist) ---
foreach (['glpi_plugin_nms_devices', 'glpi_plugin_nms_alarms', 'glpi_plugin_nms_outbox',
          'glpi_plugin_nms_storms', 'glpi_plugin_nms_ticketrequests', 'glpi_plugin_nms_deviceexceptions',
          'glpi_plugin_nms_hygienetickets', 'glpi_plugin_nms_hygieneitems',
          'glpi_plugin_nms_connections'] as $t) {
    $GLOBALS['DB']->tables[$t] = [];
}

// --- First run: copy everything ---
$result1 = FromOpmanager::run($GLOBALS['DB'], $now);
check('first run returns connections_id', is_int($result1['connections_id']) && $result1['connections_id'] > 0);
$cid = $result1['connections_id'];

// Connection stays disabled
$store = new DbConnectionStore();
$conn = $store->get($cid);
check('connection stays disabled', $conn !== null && $conn->is_active === 0);
check('connection name is OpManager (migrated)', $conn !== null && $conn->name === 'OpManager (migrated)');
check('connection brand is opmanager', $conn !== null && $conn->brand === 'opmanager');
check('connection tickets_enabled = 1', $conn !== null && $conn->tickets_enabled === 1);
check('connection writeback_enabled = 1', $conn !== null && $conn->writeback_enabled === 1);

// Decrypted API key equals the source
check('decrypted api key equals source', $conn !== null && $conn->secret('api_key') === 'key-xyz');

// Counts: 2 devices, 3 alarms, 1 outbox, 1 storm, 1 ticketrequest, 1 exception, 1 hygieneticket, 2 hygieneitems
check('devices count', $result1['counts']['devices'] === 2);
check('alarms count', $result1['counts']['alarms'] === 3);
check('outbox count', $result1['counts']['outbox'] === 1);
check('storms count', $result1['counts']['storms'] === 1);
check('ticketrequests count', $result1['counts']['ticketrequests'] === 1);
check('deviceexceptions count', $result1['counts']['deviceexceptions'] === 1);
check('hygienetickets count', $result1['counts']['hygienetickets'] === 1);
check('hygieneitems count', $result1['counts']['hygieneitems'] === 2);

// Settings copied
check('settings contain match_mode', (string) ($conn->settings !== '' ? json_decode($conn->settings, true)['match_mode'] ?? '' : '') === 'hybrid_name');

// No writes to opmanager tables
$opmanWrites = array_filter($GLOBALS['DB']->dml, fn (string $q) => str_starts_with($q, 'INSERT INTO glpi_plugin_opmanager_') || str_starts_with($q, 'UPDATE glpi_plugin_opmanager_') || str_starts_with($q, 'DELETE FROM glpi_plugin_opmanager_'));
check('no writes to opmanager tables', $opmanWrites === []);

// --- Second run: idempotent, adds 0 rows ---
$result2 = FromOpmanager::run($GLOBALS['DB'], $now);
check('second run adds 0 rows', array_sum(array_filter($result2['counts'], fn ($v) => is_int($v))) === 0);

// Count rows per nms table after second run (should be unchanged)
$countAfter2 = [];
foreach (['glpi_plugin_nms_devices', 'glpi_plugin_nms_alarms', 'glpi_plugin_nms_outbox',
          'glpi_plugin_nms_storms', 'glpi_plugin_nms_ticketrequests', 'glpi_plugin_nms_deviceexceptions',
          'glpi_plugin_nms_hygienetickets', 'glpi_plugin_nms_hygieneitems'] as $t) {
    $countAfter2[$t] = count($GLOBALS['DB']->tables[$t] ?? []);
}
fwrite(STDERR, "Row counts after run 2: " . json_encode($countAfter2) . "\n");

// --- Third run: change one alarm state, add one alarm ---
$GLOBALS['DB']->tables['glpi_plugin_opmanager_alarms'][1]['severity'] = 4; // change A2
$GLOBALS['DB']->tables['glpi_plugin_opmanager_alarms'][] = opmRow('alarms', [
    'alarm_key' => 'A4', 'entity_key' => 'E1', 'device_name' => 'vm-app-01',
    'display_name' => 'vm-app-01', 'ip_address' => '10.20.x.y', 'category' => 'Server',
    'alarm_code' => 'MEM', 'event_type' => 'DeviceHighMem', 'severity' => 3,
    'severity_label' => 'Critical', 'message' => 'High memory',
    'mod_time' => '', 'state' => 'active',
]);

$result3 = FromOpmanager::run($GLOBALS['DB'], $now);
check('third run updates 1 and inserts 1 (changed + new alarm)', $result3['counts']['alarms'] === 2);

done();
