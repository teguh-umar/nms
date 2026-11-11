<?php

require __DIR__ . '/bootstrap.php';

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;

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

/** Fake $DB stub for the connections table. */
final class FakeDB
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    public int $insertId = 0;
    /** @var list<string> */
    public array $queries = [];

    public function request(array $criteria): \ArrayIterator
    {
        $this->queries[] = json_encode($criteria);
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
        $row = $fields + ['id' => $this->insertId];
        $this->tables[$table][] = $row;
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

$conn = DbConnectionStore::class;

check('Connection::BRANDS lists release-1 brands', Connection::BRANDS === ['opmanager', 'prtg', 'zabbix', 'librenms', 'solarwinds']);

$fields = [
    'name'             => 'opm.example.test',
    'brand'            => 'opmanager',
    'entities_id'      => 1,
    'is_recursive'     => 1,
    'url'              => 'https://opm.example.test',
    'api_key'          => (new \GLPIKey())->encrypt('key-xyz'),
    'username'         => 'pg-nms',
    'password'         => (new \GLPIKey())->encrypt('token-xyz'),
    'verify_tls'       => 1,
    'is_active'        => 1,
    'writeback_enabled'=> 0,
    'tickets_enabled'  => 1,
    'webhook_token'    => (new \GLPIKey())->encrypt('token-xyz'),
    'settings'         => '{}',
    'last_device_sync' => '',
    'last_alarm_sync'  => '',
    'last_status'      => '',
    'last_error'       => '',
    'date_creation'    => '2026-11-11 00:00:00',
    'date_mod'         => '2026-11-11 00:00:00',
];

$c = new Connection($fields);
check('Connection readonly props', $c->name === 'opm.example.test' && $c->brand === 'opmanager' && $c->is_active === 1);
check('secret round-trips api_key', $c->secret('api_key') === 'key-xyz');
check('secret round-trips password', $c->secret('password') === 'token-xyz');
check('secret round-trips webhook_token', $c->secret('webhook_token') === 'token-xyz');
check('secret unknown field returns empty', $c->secret('not_a_secret') === '');
check('secret empty field returns empty', (new Connection(['api_key' => '']))->secret('api_key') === '');

$str = (string) $c;
check('toString contains no secret', strpos($str, 'key-xyz') === false && strpos($str, 'token-xyz') === false);
$exp = var_export($c, true);
check('var_export contains no secret', strpos($exp, 'key-xyz') === false && strpos($exp, 'token-xyz') === false);

$store = new DbConnectionStore();

// save: insert opm.example.test (active, has webhook token)
$id1 = $store->save(array_merge($fields, [
    'name' => 'opm.example.test',
]));
check('save returns new id', $id1 === 1);

// save: insert core-sw-01 (active, zabbix)
$id2 = $store->save([
    'name'             => 'core-sw-01',
    'brand'            => 'zabbix',
    'entities_id'      => 2,
    'is_recursive'     => 1,
    'url'              => 'https://core-sw-01.example.test',
    'api_key'          => '',
    'username'         => '',
    'password'         => '',
    'verify_tls'       => 1,
    'is_active'        => 1,
    'writeback_enabled'=> 1,
    'tickets_enabled'  => 0,
    'webhook_token'    => '',
    'settings'         => '{}',
    'date_creation'    => '2026-11-11 00:00:00',
    'date_mod'         => '2026-11-11 00:00:00',
]);
check('save second returns id 2', $id2 === 2);

// save: reject brand outside BRANDS
try {
    $store->save(array_merge($fields, ['name' => 'bad', 'brand' => 'nagios']));
    check('save rejects unknown brand', false);
} catch (\InvalidArgumentException $e) {
    check('save rejects unknown brand', true);
} catch (\Throwable $e) {
    check('save rejects unknown brand', false, 'wrong exception: ' . get_class($e));
}

// save: inactive connection
$store->save(array_merge($fields, ['name' => 'inactive', 'is_active' => 0, 'date_creation' => '2026-11-11 00:01:00', 'date_mod' => '2026-11-11 00:01:00']));

// active(): only active rows
$active = $store->active();
check('active() returns only active', count($active) === 2);
check('active() no inactive', array_filter($active, fn (Connection $c) => !$c->is_active) === []);

// get()
$got = $store->get(1);
check('get() returns connection by id', $got !== null && $got->name === 'opm.example.test');
check('get() missing returns null', $store->get(999) === null);

// byWebhookToken(): finds active connection by decrypted token
$found = $store->byWebhookToken('token-xyz');
check('byWebhookToken() finds active', $found !== null && $found->name === 'opm.example.test');
check('byWebhookToken() ignores inactive', $store->byWebhookToken('token-xyz') !== null);

// Unknown token returns null
check('byWebhookToken() unknown returns null', $store->byWebhookToken('nope') === null);

// recordStatus: device kind
$store->recordStatus(1, 'device', 'error', 'connection failed', '2026-11-11 00:05:00');
$row1 = $store->get(1);
check('recordStatus device sets last_status', $row1->last_status === 'error');
check('recordStatus device sets last_error', $row1->last_error === 'connection failed');
check('recordStatus device sets last_device_sync', $row1->last_device_sync === '2026-11-11 00:05:00');
check('recordStatus device does not touch last_alarm_sync', $row1->last_alarm_sync === '');

// recordStatus: alarm kind
$store->recordStatus(1, 'alarm', 'ok', '', '2026-11-11 00:06:00');
$row1 = $store->get(1);
check('recordStatus alarm sets last_alarm_sync', $row1->last_alarm_sync === '2026-11-11 00:06:00');

done();
