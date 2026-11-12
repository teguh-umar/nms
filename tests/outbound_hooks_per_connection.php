<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {
    public static function getTable($classname = null): string {
        $class = $classname ?? static::class;
        $short = (string) end(explode('\\', $class));
        if ($short === 'Alarm')    return 'glpi_plugin_nms_alarms';
        if ($short === 'Device')   return 'glpi_plugin_nms_devices';
        return 'glpi_' . strtolower($short) . 's';
    }
}
class CommonDBTM extends CommonGLPI {}

// ---- GLPI stubs ----
if (!class_exists('\Session', false)) {
    class Session {
        public static $rights = [];
        public static $loginUserID = 0;
        public static $isCron = false;
        public static function haveRight($name, $mask) {
            return ((int)(self::$rights[$name] ?? 0) & $mask) === $mask;
        }
        public static function getLoginUserID() { return self::$loginUserID; }
        public static function isCron() { return self::$isCron; }
        public static function getCurrentTime(): string { return '2026-11-12 00:00:00'; }
    }
}
if (!function_exists('getUserName')) {
    function getUserName(int $uid): string { return 'user' . $uid; }
}
if (!class_exists('\RichText', false)) {
    class RichText {
        public static function getTextFromHtml(string $html): string { return $html; }
    }
}
if (!class_exists('\Toolbox', false)) {
    class Toolbox {
        public static function logInFile(string $plugin, string $msg): void {}
    }
}
if (!class_exists('\ITILFollowup', false)) {
    class ITILFollowup {
        public $fields = [];
    }
}
if (!class_exists('\Ticket', false)) {
    class Ticket {
        public static $updates = [];
        public $fields = [];
        public $oldvalues = [];
        public function getID() { return 0; }
    }
}

// ---- Fake $DB (pattern from tests/connection_test.php) ----
final class FakeDB {
    public array $tables = [];
    public int $insertId = 0;
    public function request(array $criteria): \ArrayIterator {
        $table = $criteria['FROM'] ?? '';
        $where = $criteria['WHERE'] ?? [];
        $out = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if ($col === 'NOT') {
                    foreach ($val as $ncol => $nval) {
                        $rv = $row[$ncol] ?? null;
                        if (is_array($nval)) {
                            foreach ($nval as $op => $v) {
                                if ($op === 'default' || $op === '<>') {
                                    $ok = $ok && $rv !== $v;
                                }
                            }
                        } else {
                            $ok = $ok && $rv !== $nval;
                        }
                    }
                } elseif (is_array($val)) {
                    foreach ($val as $op => $v) {
                        $rv = $row[$col] ?? null;
                        match ($op) {
                            '>'    => $ok = $ok && $rv > $v,
                            '<>'   => $ok = $ok && $rv !== $v,
                            default => $ok = $ok && $rv === $v,
                        };
                    }
                } elseif (!isset($row[$col]) || $row[$col] !== $val) {
                    $ok = false;
                }
            }
            if ($ok) $out[] = $row;
        }
        return new \ArrayIterator($out);
    }
    public function insert(string $table, array $fields): int {
        $this->insertId++;
        $this->tables[$table][] = $fields + ['id' => $this->insertId];
        return $this->insertId;
    }
    public function update(string $table, array $fields, array $where): void {
        if (!isset($this->tables[$table])) return;
        foreach ($this->tables[$table] as $i => $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || $row[$col] !== $val) { $ok = false; break; }
            }
            if ($ok) $this->tables[$table][$i] = array_merge($row, $fields);
        }
    }
    public function delete(string $table, array $where): void {
        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            fn(array $row) => !self::matches($row, $where)
        ));
    }
    public function tableExists(string $table): bool { return isset($this->tables[$table]); }
    public function fieldExists(string $table, string $field): bool { return false; }
    public function insertId(): int { return $this->insertId; }
    public function numrows(): int { return 0; }
    private static function matches(array $row, array $where): bool {
        foreach ($where as $col => $val) {
            if (!isset($row[$col]) || $row[$col] !== $val) return false;
        }
        return true;
    }
}
$GLOBALS['DB'] = new FakeDB();

// ---- Seed connections + settings ----
require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/RightDefinition.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;

if (!class_exists('\GLPIKey', false)) {
    class GLPIKey {
        public function encrypt(string $value): string { return base64_encode('enc:' . $value); }
        public function decrypt($value) {
            if (!is_string($value)) return false;
            $dec = base64_decode($value, true);
            if ($dec === false || !str_starts_with($dec, 'enc:')) return false;
            return substr($dec, 4);
        }
    }
}

$enc = new \GLPIKey();
$conn1 = new Connection([
    'id'             => 1, 'name' => 'conn-1', 'brand' => 'opmanager', 'entities_id' => 1,
    'is_recursive'   => 1, 'url' => 'https://opm.example.test', 'api_key' => $enc->encrypt('key-xyz'),
    'username'       => '', 'password' => '', 'verify_tls' => 1, 'is_active' => 1,
    'writeback_enabled'=> 1, 'tickets_enabled' => 1,
    'webhook_token'  => $enc->encrypt('token-xyz'),
    'settings'       => json_encode(['service_user' => '10', 'outbound_max_attempts' => '10']),
    'last_device_sync' => '', 'last_alarm_sync' => '', 'last_status' => '',
    'last_error'     => '', 'date_creation' => '2026-11-12 00:00:00', 'date_mod' => '2026-11-12 00:00:00',
]);
$conn2 = new Connection([
    'id'             => 2, 'name' => 'conn-2', 'brand' => 'opmanager', 'entities_id' => 1,
    'is_recursive'   => 1, 'url' => 'https://opm.example.test', 'api_key' => $enc->encrypt('key-xyz'),
    'username'       => '', 'password' => '', 'verify_tls' => 1, 'is_active' => 1,
    'writeback_enabled'=> 1, 'tickets_enabled' => 1,
    'webhook_token'  => $enc->encrypt('token-xyz'),
    'settings'       => json_encode(['service_user' => '20', 'outbound_max_attempts' => '10']),
    'last_device_sync' => '', 'last_alarm_sync' => '', 'last_status' => '',
    'last_error'     => '', 'date_creation' => '2026-11-12 00:00:00', 'date_mod' => '2026-11-12 00:00:00',
]);
$GLOBALS['DB']->tables['glpi_plugin_nms_connections'] = [(array) $conn1, (array) $conn2];

// ---- Seed alarms for ticket 100: one on conn 1, one on conn 2 ----
$GLOBALS['DB']->tables['glpi_plugin_nms_alarms'] = [
    [
        'id'             => 1, 'alarm_key' => 'ALM-1', 'entity_key' => 'E1',
        'device_name'    => 'core-sw-01', 'display_name' => 'core-sw-01',
        'ip_address'     => '10.20.x.x', 'category' => 'Network',
        'alarm_code'     => 'DOWN', 'event_type' => 'DeviceDown', 'severity' => 3,
        'severity_label' => 'Critical', 'message' => 'down', 'state' => 'active',
        'plugin_nms_devices_id' => 1, 'itemtype' => 'Computer', 'items_id' => 1,
        'tickets_id'     => 100, 'ticket_role' => 'own', 'ticket_pending' => 0,
        'ticket_created_at' => '', 'last_followup_at' => '', 'mod_time' => '',
        'first_seen' => '', 'last_change' => '', 'cleared_at' => '',
        'connections_id' => 1, 'date_creation' => '', 'date_mod' => '',
    ],
    [
        'id'             => 2, 'alarm_key' => 'ALM-2', 'entity_key' => 'E2',
        'device_name'    => 'vm-app-01', 'display_name' => 'vm-app-01',
        'ip_address'     => '10.20.x.y', 'category' => 'Server',
        'alarm_code'     => 'CPU', 'event_type' => 'DeviceDown', 'severity' => 2,
        'severity_label' => 'Trouble', 'message' => 'cpu', 'state' => 'active',
        'plugin_nms_devices_id' => 2, 'itemtype' => 'Computer', 'items_id' => 2,
        'tickets_id'     => 100, 'ticket_role' => 'own', 'ticket_pending' => 0,
        'ticket_created_at' => '', 'last_followup_at' => '', 'mod_time' => '',
        'first_seen' => '', 'last_change' => '', 'cleared_at' => '',
        'connections_id' => 2, 'date_creation' => '', 'date_mod' => '',
    ],
];

// ---- Driver / Sender stubs ----
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
if (!class_exists('GlpiPlugin\Nms\Driver\DriverFactory', false)) {
    require OPM_ROOT . '/src/Driver/DriverFactory.php';
}
if (!class_exists('GlpiPlugin\Nms\Driver\Capabilities', false)) {
    require OPM_ROOT . '/src/Driver/Capabilities.php';
}
if (!class_exists('GlpiPlugin\Nms\Outbound\Hooks', false)) {
    require OPM_ROOT . '/src/Outbound/Hooks.php';
}
if (!class_exists('GlpiPlugin\Nms\Outbound\Planner', false)) {
    require OPM_ROOT . '/src/Outbound/Planner.php';
}
if (!class_exists('GlpiPlugin\Nms\Ticketing\DbAlarmStore', false)) {
    require OPM_ROOT . '/src/Ticketing/AlarmStore.php';
    require OPM_ROOT . '/src/Ticketing/DbAlarmStore.php';
    require OPM_ROOT . '/src/Alarm.php';
}
if (!class_exists('GlpiPlugin\Nms\Outbound\OutboxStore', false)) {
    require OPM_ROOT . '/src/Outbound/OutboxStore.php';
    require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';
}

use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Outbound\Hooks;
use GlpiPlugin\Nms\Outbound\Planner;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Outbound\DbOutboxStore;

$enqueuedRows = [];
class TestDriver implements Driver {
    public function __construct(private Connection $conn, private ?int $timeout = null) {}
    public function capabilities(): Capabilities { return new Capabilities(true, true, true, true, true, true, true, true); }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function listDevices(): array { return []; }
    public function listActiveAlarms(): array { return []; }
    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array { return null; }
    public function addNote(string $alarm_key, string $text): void {}
    public function acknowledge(string $alarm_key): void {}
    public function clear(string $alarm_key): void {}
}
DriverFactory::setTestOverride(function (Connection $c, ?int $timeout = null) {
    return new TestDriver($c);
});

// ---- Reset between scenarios ----
function resetOutbox(): void {
    $GLOBALS['DB']->tables['glpi_plugin_nms_outbox'] = [];
}

// Scenario 1: followup on ticket 100 with alarms on conn 1 and conn 2 → rows under both.
resetOutbox();
Session::$loginUserID = 5;  // not a service user → human
Session::$isCron = false;
Session::$rights = ['plugin_nms_config' => READ | UPDATE];

$fu = new ITILFollowup();
$fu->fields = ['itemtype' => \Ticket::class, 'items_id' => 100, 'users_id' => 5, 'sourceitems_id' => 0, 'is_private' => 0, 'content' => 'test followup'];
$alarms = (new DbAlarmStore())->activeForTicket(100);
fwrite(STDERR, "DEBUG activeForTicket: " . json_encode($alarms) . "\n");
$manual = $GLOBALS['DB']->request([
    'SELECT' => ['alarm_key', 'entity_key', 'connections_id'],
    'FROM'   => 'glpi_plugin_nms_alarms',
    'WHERE'  => ['tickets_id' => 100, 'state' => 'active', 'NOT' => ['entity_key' => '']],
    'ORDER'  => 'id',
]);
fwrite(STDERR, "DEBUG manual request: " . json_encode(iterator_to_array($manual)) . "\n");

// Test planner directly
$store1 = new DbOutboxStore(1);
$plannerRows = Planner::forFollowup(
    100, false, 'test followup', 'user5', $alarms, 5, [$store1, 'hasPendingAck']
);
fwrite(STDERR, "DEBUG planner rows: " . json_encode($plannerRows) . "\n");
fwrite(STDERR, "DEBUG store1 pending: " . json_encode($store1->pending(1000)) . "\n");
try {
    Hooks::followupAdded($fu);
    fwrite(STDERR, "DEBUG followupAdded completed without exception\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "DEBUG followupAdded exception: " . $e->getMessage() . "\n");
}
$outboxRows = $GLOBALS['DB']->tables['glpi_plugin_nms_outbox'] ?? [];
fwrite(STDERR, "DEBUG outbox rows: " . json_encode($outboxRows) . "\n");
$conn1Rows = array_filter($outboxRows, fn ($r) => (int) $r['connections_id'] === 1);
$conn2Rows = array_filter($outboxRows, fn ($r) => (int) $r['connections_id'] === 2);
check('followup enqueues rows under conn 1', $conn1Rows !== []);
check('followup enqueues rows under conn 2', $conn2Rows !== []);

// Scenario 2: followup by conn 1's service_user (uid === service_user) → not human → nothing enqueued.
resetOutbox();
Session::$loginUserID = 10;  // conn 1's service_user
Hooks::followupAdded($fu);
check('followup by service user enqueues nothing', $GLOBALS['DB']->tables['glpi_plugin_nms_outbox'] === []);

// Scenario 3: inactive connection's alarm is not enqueued.
// (already only conn 1 and conn 2 alarms exist; make conn 2 inactive)
resetOutbox();
$GLOBALS['DB']->update('glpi_plugin_nms_connections', ['is_active' => 0], ['id' => 2]);
Session::$loginUserID = 5;
Hooks::followupAdded($fu);
$outboxRows = $GLOBALS['DB']->tables['glpi_plugin_nms_outbox'] ?? [];
$conn1Rows = array_filter($outboxRows, fn ($r) => (int) $r['connections_id'] === 1);
$conn2Rows = array_filter($outboxRows, fn ($r) => (int) $r['connections_id'] === 2);
check('inactive conn 2 alarm not enqueued', $conn2Rows === []);
check('active conn 1 alarm still enqueued', $conn1Rows !== []);

// Restore conn 2 active.
$GLOBALS['DB']->update('glpi_plugin_nms_connections', ['is_active' => 1], ['id' => 2]);

done();
