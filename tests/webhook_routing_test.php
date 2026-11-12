<?php

require __DIR__ . '/bootstrap.php';
foreach (['Driver/Driver', 'Ticketing/SyncLock', 'Ticketing/TicketRequestStore', 'Webhook/Receiver', 'Connection/Connection', 'Connection/ConnectionStore', 'Connection/DbConnectionStore'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Ticketing\SyncLock;
use GlpiPlugin\Nms\Ticketing\TicketRequestStore;
use GlpiPlugin\Nms\Webhook\Receiver;

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

/** Driver that records which connection id it was built for and what alarm it saw. */
final class RoutingDriver implements Driver
{
    public int $connId;
    public ?string $sawAlarm = null;
    public function __construct(int $connId) { $this->connId = $connId; }
    public function capabilities(): \GlpiPlugin\Nms\Driver\Capabilities { return new \GlpiPlugin\Nms\Driver\Capabilities(true, true, true, true, true, true, true); }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function listDevices(): array { return []; }
    public function listActiveAlarms(): array { return []; }
    public function alarmByKey(string $k, string $entity_key = ''): ?array { $this->sawAlarm = $k; return ['alarm_key' => $k, 'entity_key' => 'E1', 'severity' => 1]; }
    public function addNote(string $alarm_key, string $text): void {}
    public function acknowledge(string $alarm_key): void {}
    public function clear(string $alarm_key): void {}
}

/** SyncLock stub: always free. */
final class FreeLock implements SyncLock
{
    public function acquire(int $t): bool { return true; }
    public function release(): void { }
}

/** TicketRequestStore stub: records recorded keys. */
final class RecStore implements TicketRequestStore
{
    /** @var list<string> */
    public array $rec = [];
    public function record(string $k, string $e): void { $this->rec[] = "{$k}@{$e}"; }
    public function keys(): array { return []; }
    public function delete(string $k): void { }
    public function purgeBefore(string $b): void { }
}

function mkConn(int $id, string $name, string $token, bool $active = true): Connection
{
    return new Connection([
        'id'             => $id,
        'name'           => $name,
        'brand'          => 'opmanager',
        'entities_id'    => 1,
        'is_recursive'   => 1,
        'url'            => 'https://opm.example.test',
        'api_key'        => '',
        'username'       => '',
        'password'       => '',
        'verify_tls'     => 1,
        'is_active'      => $active ? 1 : 0,
        'writeback_enabled' => 1,
        'tickets_enabled' => 1,
        'webhook_token'   => $token === '' ? '' : (new \GLPIKey())->encrypt($token),
        'settings'        => '{}',
        'date_creation'   => '2026-11-11 00:00:00',
        'date_mod'        => '2026-11-11 00:00:00',
    ]);
}

$body = json_encode(['alarm_id' => 'ALM-1', 'entity' => 'E1']);
$runner = static function (array $alarm) use (&$calls): void { $calls[] = $alarm['alarm_key']; };

// --- Seed two active connections and one inactive one in the fake DB.
$enc = new \GLPIKey();
$GLOBALS['DB'] = new class {
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
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
    public function insert(string $table, array $fields): int { return 0; }
    public function update(string $table, array $fields, array $where): void { }
    public function delete(string $table, array $where): void { }
    public function tableExists(string $table): bool { return isset($this->tables[$table]); }
    public function fieldExists(string $table, string $field): bool { return false; }
    public function insertId(): int { return 0; }
    public function numrows(): int { return 0; }
};
$GLOBALS['DB']->tables['glpi_plugin_nms_connections'] = [
    [
        'id'             => 1,
        'name'           => 'conn-1',
        'brand'          => 'opmanager',
        'entities_id'    => 1,
        'is_recursive'   => 1,
        'url'            => 'https://opm.example.test',
        'api_key'        => '',
        'username'       => '',
        'password'       => '',
        'verify_tls'     => 1,
        'is_active'      => 1,
        'writeback_enabled'=> 1,
        'tickets_enabled' => 1,
        'webhook_token'   => $enc->encrypt('token-1'),
        'settings'        => '{}',
        'date_creation'   => '2026-11-11 00:00:00',
        'date_mod'        => '2026-11-11 00:00:00',
    ],
    [
        'id'             => 2,
        'name'           => 'conn-2',
        'brand'          => 'opmanager',
        'entities_id'    => 1,
        'is_recursive'   => 1,
        'url'            => 'https://opm.example.test',
        'api_key'        => '',
        'username'       => '',
        'password'       => '',
        'verify_tls'     => 1,
        'is_active'      => 1,
        'writeback_enabled'=> 1,
        'tickets_enabled' => 1,
        'webhook_token'   => $enc->encrypt('token-2'),
        'settings'        => '{}',
        'date_creation'   => '2026-11-11 00:00:00',
        'date_mod'        => '2026-11-11 00:00:00',
    ],
    [
        'id'             => 3,
        'name'           => 'conn-3',
        'brand'          => 'opmanager',
        'entities_id'    => 1,
        'is_recursive'   => 1,
        'url'            => 'https://opm.example.test',
        'api_key'        => '',
        'username'       => '',
        'password'       => '',
        'verify_tls'     => 1,
        'is_active'      => 0,
        'writeback_enabled'=> 1,
        'tickets_enabled' => 1,
        'webhook_token'   => $enc->encrypt('token-3'),
        'settings'        => '{}',
        'date_creation'   => '2026-11-11 00:00:00',
        'date_mod'        => '2026-11-11 00:00:00',
    ],
];

$store = new DbConnectionStore();
$c1 = $store->get(1);
$c2 = $store->get(2);
$c3 = $store->get(3);
check('conn 1 resolved by token', $c1 !== null && $c1->name === 'conn-1');
check('conn 2 resolved by token', $c2 !== null && $c2->name === 'conn-2');
check('conn 3 (inactive) resolved by token', $c3 !== null && $c3->name === 'conn-3');
check('byWebhookToken finds conn 1', $store->byWebhookToken('token-1') !== null && $store->byWebhookToken('token-1')->id === 1);
check('byWebhookToken finds conn 2', $store->byWebhookToken('token-2') !== null && $store->byWebhookToken('token-2')->id === 2);
check('byWebhookToken ignores inactive conn 3', $store->byWebhookToken('token-3') === null);
check('byWebhookToken unknown returns null', $store->byWebhookToken('nope') === null);

// --- Token for connection 2 writes only connection 2's rows.
$calls = [];
$d2 = new RoutingDriver(2);
$r2 = new Receiver($c2, $d2, new FreeLock(), static function (array $a) use (&$calls) { $calls[] = $a['alarm_key']; }, new RecStore());
$res = $r2->handle('POST', 'token-2', $body);
check('token-2 processed on conn 2 driver', $res['status'] === 200 && $d2->sawAlarm === 'ALM-1' && $calls === ['ALM-1']);

// --- Token for connection 1 writes only connection 1's rows (different driver instance).
$calls = [];
$d1 = new RoutingDriver(1);
$r1 = new Receiver($c1, $d1, new FreeLock(), static function (array $a) use (&$calls) { $calls[] = $a['alarm_key']; }, new RecStore());
$res = $r1->handle('POST', 'token-1', $body);
check('token-1 processed on conn 1 driver', $res['status'] === 200 && $d1->sawAlarm === 'ALM-1' && $calls === ['ALM-1']);

// --- Unknown token -> 403, nothing written.
$rec = new RecStore();
$rUnk = new Receiver($c1, new RoutingDriver(1), new FreeLock(), $runner, $rec);
$res = $rUnk->handle('POST', 'nope', $body);
check('unknown token -> 403', $res['status'] === 403);
check('unknown token writes nothing', $rec->rec === []);

// --- Inactive connection token -> 403.
$res = $rUnk->handle('POST', 'token-3', $body);
check('inactive token -> 403', $res['status'] === 403);

// --- X-OPM-Token works the same as X-NMS-Token (header name is handled by front/webhook.php;
//    here we verify the receiver accepts the same token string regardless of header name).
$calls = [];
$d2b = new RoutingDriver(2);
$r2b = new Receiver($c2, $d2b, new FreeLock(), static function (array $a) use (&$calls) { $calls[] = $a['alarm_key']; }, new RecStore());
$res = $r2b->handle('POST', 'token-2', $body);
check('X-OPM-Token works like X-NMS-Token', $res['status'] === 200 && $d2b->sawAlarm === 'ALM-1');

// --- Missing token -> 403.
$res = $rUnk->handle('POST', '', $body);
check('missing token -> 403', $res['status'] === 403);

done();
