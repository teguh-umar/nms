<?php

// R1 (review 2026-11-12): the outbound hooks work per connection. A ticket with alarms of two
// connections queues rows under each connection, with that connection's service user, and
// sends under the cron's outbox lock. Runs the real Hooks against a fake $DB shaped like the
// nms tables.

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/richtext_stub.php';

// ---- GLPI stubs (global namespace) ----
class Session
{
    public static int $loginUserID = 0;
    public static function getLoginUserID() { return self::$loginUserID; }
    public static function isCron(): bool { return false; }
    public static function getCurrentTime(): string { return '2026-11-12 00:00:00'; }
}
class Toolbox
{
    public static array $log = [];
    public static function logInFile(string $name, string $msg): void { self::$log[] = $msg; }
}
class ITILFollowup
{
    public array $fields = [];
}
class Ticket
{
    public array $fields = [];
    public array $oldvalues = [];
    public array $updates = [];
    public function getID(): int { return (int) ($this->fields['id'] ?? 0); }
}
class ITILSolution
{
    public static function getTable(): string { return 'glpi_itilsolutions'; }
}
function getUserName(int $uid): string { return 'user' . $uid; }
class CommonDBTM
{
    public static function getTable(): string
    {
        return static::class === 'GlpiPlugin\Nms\Alarm' ? 'glpi_plugin_nms_alarms' : 'glpi_' . strtolower(static::class) . 's';
    }
}

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

final class LockResult
{
    public function fetch_assoc(): array { return ['l' => 1]; }
}

final class FakeDB
{
    public array $tables = [];
    public array $locks  = [];
    private int $id = 0;

    public function request(array $c): \ArrayIterator
    {
        $out = [];
        foreach ($this->tables[$c['FROM']] ?? [] as $row) {
            if ($this->matches($row, $c['WHERE'] ?? [])) {
                $out[] = $row;
            }
        }
        if (isset($c['LIMIT'])) {
            $out = array_slice($out, 0, (int) $c['LIMIT']);
        }
        return new \ArrayIterator($out);
    }

    private function matches(array $row, array $where): bool
    {
        foreach ($where as $col => $val) {
            if ($col === 'NOT') {
                foreach ($val as $ncol => $nval) {
                    if (($row[$ncol] ?? null) == $nval) {
                        return false;
                    }
                }
                continue;
            }
            if (($row[$col] ?? null) != $val) {
                return false;
            }
        }
        return true;
    }

    public function insert(string $table, array $fields): bool
    {
        $this->tables[$table][] = $fields + ['id' => ++$this->id];
        return true;
    }

    public function update(string $table, array $fields, array $where): bool
    {
        foreach ($this->tables[$table] ?? [] as $i => $row) {
            if ($this->matches($row, $where)) {
                $this->tables[$table][$i] = array_merge($row, $fields);
            }
        }
        return true;
    }

    public function doQuery(string $sql): LockResult
    {
        if (preg_match("/GET_LOCK\('([^']+)'/", $sql, $m)) {
            $this->locks[] = $m[1];
        }
        return new LockResult();
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Alarm.php';
require OPM_ROOT . '/src/Ticketing/AlarmStore.php';
require OPM_ROOT . '/src/Ticketing/DbAlarmStore.php';
require OPM_ROOT . '/src/Ticketing/SyncLock.php';
require OPM_ROOT . '/src/Ticketing/DbSyncLock.php';
require OPM_ROOT . '/src/Ticketing/TicketGateway.php';
require OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php';
require OPM_ROOT . '/src/Outbound/OutboxStore.php';
require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';
require OPM_ROOT . '/src/Outbound/Planner.php';
require OPM_ROOT . '/src/Outbound/Sender.php';
require OPM_ROOT . '/src/Outbound/Hooks.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\OpManager\OpManagerDriver;
use GlpiPlugin\Nms\Outbound\Hooks;
use GlpiPlugin\Nms\Ticketing\DbSyncLock;

final class RecordingDriver implements Driver
{
    public static array $calls = [];
    public function __construct(private Connection $c, private ?int $timeout) {}
    public function capabilities(): Capabilities { return new Capabilities(true, true, true, true, true, true, true, true); }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function listDevices(): array { return []; }
    public function listActiveAlarms(): array { return []; }
    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array { return null; }
    public function addNote(string $alarm_key, string $text): void { self::$calls[] = [$this->c->id, $this->timeout, 'note', $alarm_key]; }
    public function acknowledge(string $alarm_key): void { self::$calls[] = [$this->c->id, $this->timeout, 'ack', $alarm_key]; }
    public function clear(string $alarm_key): void { self::$calls[] = [$this->c->id, $this->timeout, 'clear', $alarm_key]; }
}
DriverFactory::setTestOverride(fn (Connection $c, ?int $timeout = null) => new RecordingDriver($c, $timeout));

$key = new \GLPIKey();
$connRow = static fn (int $id, int $service_user, array $over = []): array => $over + [
    'id' => $id, 'name' => "conn-{$id}", 'brand' => 'opmanager', 'entities_id' => 0, 'is_recursive' => 1,
    'url' => 'https://opm.example.test', 'api_key' => $key->encrypt('key-xyz'), 'username' => '', 'password' => '',
    'verify_tls' => 1, 'is_active' => 1, 'writeback_enabled' => 1, 'tickets_enabled' => 1,
    'webhook_token' => $key->encrypt('token-xyz'),
    'settings' => json_encode(['service_user' => (string) $service_user, 'outbound_max_attempts' => '10']),
    'date_creation' => '', 'date_mod' => '',
];
$alarmRow = static fn (int $id, int $cid, string $entity): array => [
    'id' => $id, 'connections_id' => $cid, 'alarm_key' => "A{$id}", 'entity_key' => $entity,
    'tickets_id' => 100, 'state' => 'active',
];

$reset = static function (array $conns) use ($alarmRow): FakeDB {
    $db = new FakeDB();
    $db->tables['glpi_plugin_nms_connections'] = $conns;
    $db->tables['glpi_plugin_nms_alarms'] = [$alarmRow(1, 1, 'core-sw-01_Down'), $alarmRow(2, 2, 'vm-app-01_Down')];
    $GLOBALS['DB'] = $db;
    RecordingDriver::$calls = [];
    Toolbox::$log = [];
    return $db;
};
$followup = new ITILFollowup();
$followup->fields = ['itemtype' => 'Ticket', 'items_id' => 100, 'users_id' => 5, 'sourceitems_id' => 0, 'is_private' => 0, 'content' => 'checked the uplink'];
$outboxOf = static fn (FakeDB $db, int $cid): array => array_values(array_filter($db->tables['glpi_plugin_nms_outbox'] ?? [], fn ($r) => (int) $r['connections_id'] === $cid));

// 1. Two connections on one ticket: rows under each, sent through each connection's driver.
$db = $reset([$connRow(1, 10), $connRow(2, 20)]);
Session::$loginUserID = 5;
Hooks::followupAdded($followup);
check('followup queues rows under connection 1', $outboxOf($db, 1) !== [] && $outboxOf($db, 1)[0]['entity_key'] === 'core-sw-01_Down');
check('followup queues rows under connection 2', $outboxOf($db, 2) !== [] && $outboxOf($db, 2)[0]['entity_key'] === 'vm-app-01_Down');
check('no row queued under connection 0', $outboxOf($db, 0) === []);
check('each connection sends only its own alarm', in_array([1, Hooks::SEND_TIMEOUT, 'note', 'core-sw-01_Down'], RecordingDriver::$calls, true) && in_array([2, Hooks::SEND_TIMEOUT, 'note', 'vm-app-01_Down'], RecordingDriver::$calls, true));
check('hook sends under the cron outbox lock of each connection', $db->locks === [DbSyncLock::outboxLockName(1), DbSyncLock::outboxLockName(2)]);
check('outbox lock name is the cron name', DbSyncLock::outboxLockName(7) === 'nms_sync_7_outbox');
check('hook logged no error', Toolbox::$log === [], implode(' | ', Toolbox::$log));

// 2. The followup author is connection 1's service user: nothing goes back to connection 1.
$db = $reset([$connRow(1, 10), $connRow(2, 20)]);
Session::$loginUserID = 10;
$own = clone $followup;
$own->fields['users_id'] = 10;
Hooks::followupAdded($own);
check('service user followup queues nothing for its connection', $outboxOf($db, 1) === []);

$db = $reset([$connRow(1, 10), $connRow(2, 10)]);
Hooks::followupAdded($own);
check('service user of every connection queues nothing at all', ($db->tables['glpi_plugin_nms_outbox'] ?? []) === []);

// 3. Inactive connection or write-back off: skipped, the other one still works.
$db = $reset([$connRow(1, 10), $connRow(2, 20, ['is_active' => 0])]);
Session::$loginUserID = 5;
Hooks::followupAdded($followup);
check('inactive connection gets no row', $outboxOf($db, 2) === [] && $outboxOf($db, 1) !== []);
$db = $reset([$connRow(1, 10, ['writeback_enabled' => 0]), $connRow(2, 20)]);
Hooks::followupAdded($followup);
check('write-back off gets no row', $outboxOf($db, 1) === [] && $outboxOf($db, 2) !== []);

// 4. Solving the ticket clears each connection's alarm under that connection.
$db = $reset([$connRow(1, 10), $connRow(2, 20)]);
$db->tables['glpi_itilsolutions'] = [['id' => 1, 'itemtype' => 'Ticket', 'items_id' => 100, 'users_id' => 5, 'content' => 'replaced the cable']];
$ticket = new Ticket();
$ticket->fields = ['id' => 100, 'status' => 5];
$ticket->oldvalues = ['status' => 2];
$ticket->updates = ['status'];
Hooks::ticketUpdated($ticket);
$actions = static fn (array $rows): array => array_column($rows, 'action');
check('solve queues a clear under connection 1', in_array('clear', $actions($outboxOf($db, 1)), true));
check('solve queues a clear under connection 2', in_array('clear', $actions($outboxOf($db, 2)), true));

// 5. Source hygiene.
$src = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(OPM_ROOT . '/src', FilesystemIterator::SKIP_DOTS)) as $f) {
    $src .= (string) file_get_contents((string) $f);
}
check('no fixture host name in src/', !str_contains($src, 'opm.example.test'));
check('Hooks::OUTBOX_LOCK removed', !str_contains($src, 'OUTBOX_LOCK'));

// 6. The driver keeps a timeout: the factory's value, else the default.
DriverFactory::setTestOverride(null);
$c = new Connection($connRow(1, 10));
$timeoutOf = static fn (OpManagerDriver $d): int => (new ReflectionProperty($d, 'timeout'))->getValue($d);
require_once OPM_ROOT . '/src/Driver/OpManager/ClientException.php';
require_once OPM_ROOT . '/src/Driver/OpManager/ResponseGuard.php';
require_once OPM_ROOT . '/src/Driver/OpManager/DeviceParser.php';
require_once OPM_ROOT . '/src/Driver/OpManager/AlarmParser.php';
require_once OPM_ROOT . '/src/Driver/OpManager/OpManagerDriver.php';
check('factory passes the hook timeout to the driver', $timeoutOf(DriverFactory::for($c, Hooks::SEND_TIMEOUT)) === Hooks::SEND_TIMEOUT);
check('driver timeout defaults to 30 s', $timeoutOf(DriverFactory::for($c)) === OpManagerDriver::DEFAULT_TIMEOUT && OpManagerDriver::DEFAULT_TIMEOUT === 30);

done();
