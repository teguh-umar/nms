<?php

// Task 8 + review R3 (2026-11-12): the opmanager -> nms migration on data shaped exactly like
// a real GLPI: opmanager 1.3.2 tables (columns frozen in fixtures/opmanager_1.3.2_columns.json),
// its config in glpi_configs context plugin:opmanager (secrets GLPIKey-encrypted), assets in
// their own tables, the entity tree in glpi_entities. Every write into an nms table must use a
// column that exists in install/install.php.

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI
{
    public static function getTable($classname = null): string
    {
        $class = $classname ?? static::class;
        $parts = explode('\\', $class);
        return 'glpi_' . strtolower(end($parts)) . 's';
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/Migration/FromOpmanager.php';

use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Migration\FromOpmanager;

if (!class_exists('\GLPIKey', false)) {
    class GLPIKey
    {
        public function encrypt(string $value): string { return base64_encode('enc:' . $value); }
        public function decrypt($value)
        {
            if (!is_string($value)) { return false; }
            $dec = base64_decode($value, true);
            return $dec !== false && str_starts_with($dec, 'enc:') ? substr($dec, 4) : false;
        }
    }
}

/** Columns of every nms table, read from install/install.php. @return array<string, list<string>> */
function nmsSchema(): array
{
    $src = (string) file_get_contents(OPM_ROOT . '/install/install.php');
    preg_match_all("/\\\$table\\s*=\\s*'(glpi_plugin_nms_\\w+)'.*?CREATE TABLE `\\\$table` \\((.*?)\\)\\s*ENGINE/s", $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $t) {
        preg_match_all('/^\s*`(\w+)`/m', $t[2], $cols);
        $out[$t[1]] = $cols[1];
    }
    return $out;
}

final class FakeDB
{
    public array $tables = [];
    public int $insertId = 0;
    /** @var list<string> */
    public array $dml = [];
    /** @var list<string> tables read */
    public array $reads = [];
    /** @var list<string> writes to an nms column that does not exist */
    public array $badColumns = [];

    public function __construct(private array $schema) {}

    public function request(array $criteria): \ArrayIterator
    {
        $table = $criteria['FROM'] ?? '';
        $this->reads[] = $table;
        $out = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            if (self::matches($row, $criteria['WHERE'] ?? [])) {
                $out[] = $row;
            }
        }
        if (isset($criteria['LIMIT'])) {
            $out = array_slice($out, 0, (int) $criteria['LIMIT']);
        }
        return new \ArrayIterator($out);
    }

    private function checkColumns(string $table, array $fields): void
    {
        if (!isset($this->schema[$table])) {
            return;
        }
        foreach (array_keys($fields) as $col) {
            if (!in_array($col, $this->schema[$table], true)) {
                $this->badColumns[] = "{$table}.{$col}";
            }
        }
    }

    public function insert(string $table, array $fields): bool
    {
        $this->dml[] = 'INSERT INTO ' . $table;
        $this->checkColumns($table, $fields);
        $this->insertId++;
        $this->tables[$table][] = $fields + ['id' => $this->insertId];
        return true;
    }

    public function update(string $table, array $fields, array $where): bool
    {
        $this->dml[] = 'UPDATE ' . $table;
        $this->checkColumns($table, $fields);
        foreach ($this->tables[$table] ?? [] as $i => $row) {
            if (self::matches($row, $where)) {
                $this->tables[$table][$i] = array_merge($row, $fields);
            }
        }
        return true;
    }

    public function tableExists(string $table): bool { return isset($this->tables[$table]); }
    public function fieldExists(string $table, string $field): bool { return in_array($field, $this->schema[$table] ?? [], true); }
    public function insertId(): int { return $this->insertId; }

    private static function matches(array $row, array $where): bool
    {
        foreach ($where as $col => $val) {
            $rv = $row[$col] ?? null;
            if (is_array($val) && array_is_list($val) && !in_array($val[0] ?? null, ['>', '<', '<>'], true)) {
                if (!in_array($rv, $val, false)) {
                    return false;
                }
            } elseif (is_array($val)) {
                [$op, $v] = $val;
                $ok = match ($op) { '>' => $rv > $v, '<' => $rv < $v, '<>' => $rv != $v };
                if (!$ok) {
                    return false;
                }
            } elseif ((string) $rv !== (string) $val) {
                return false;
            }
        }
        return true;
    }
}

$schema = nmsSchema();
$db = new FakeDB($schema);
$GLOBALS['DB'] = $db;
$enc = new \GLPIKey();
$now = '2026-11-11 00:00:00';

// ---- opmanager 1.3.2, exactly its columns ----
$opmColumns = json_decode((string) file_get_contents(__DIR__ . '/fixtures/opmanager_1.3.2_columns.json'), true);
$opm = static function (string $table, array $row) use ($opmColumns): array {
    $unknown = array_diff(array_keys($row), $opmColumns[$table]);
    if ($unknown !== []) {
        throw new \LogicException("fixture {$table} uses columns opmanager 1.3.2 does not have: " . implode(', ', $unknown));
    }
    return $row;
};

// Config: glpi_configs rows, context plugin:opmanager. There is NO glpi_plugin_opmanager_config table.
$db->tables['glpi_configs'] = [
    ['id' => 1, 'context' => 'plugin:opmanager', 'name' => 'opm_url', 'value' => 'https://opm.example.test/'],
    ['id' => 2, 'context' => 'plugin:opmanager', 'name' => 'opm_api_key', 'value' => $enc->encrypt('key-xyz')],
    ['id' => 3, 'context' => 'plugin:opmanager', 'name' => 'opm_verify_ssl', 'value' => '0'],
    ['id' => 4, 'context' => 'plugin:opmanager', 'name' => 'webhook_token', 'value' => $enc->encrypt('token-xyz')],
    ['id' => 5, 'context' => 'plugin:opmanager', 'name' => 'service_user', 'value' => '12'],
    ['id' => 6, 'context' => 'plugin:opmanager', 'name' => 'ticket_source', 'value' => 'rules'],
    ['id' => 7, 'context' => 'plugin:opmanager', 'name' => 'production_addressings', 'value' => '[7]'],
    ['id' => 8, 'context' => 'plugin:opmanager', 'name' => 'match_mode', 'value' => 'ip_only'],
    // Another plugin's key with the same name must not leak in.
    ['id' => 9, 'context' => 'plugin:other', 'name' => 'service_user', 'value' => '99'],
];

// Entity tree: 9 (under root) > 2 > {3, 5}. The answer is 2, not max() of the common set (9).
$db->tables['glpi_entities'] = [
    ['id' => 0, 'entities_id' => 0], ['id' => 9, 'entities_id' => 0], ['id' => 2, 'entities_id' => 9],
    ['id' => 3, 'entities_id' => 2], ['id' => 5, 'entities_id' => 2],
];
$db->tables['glpi_computers'] = [['id' => 10, 'entities_id' => 3, 'name' => 'vm-app-01']];
$db->tables['glpi_networkequipments'] = [['id' => 20, 'entities_id' => 5, 'name' => 'core-sw-01']];

$deviceRow = static fn (int $id, string $key, string $name, string $itemtype, int $items_id): array => $opm('devices', [
    'id' => $id, 'device_key' => $key, 'name' => $name, 'display_name' => $name, 'ip_address' => '10.20.0.' . $id,
    'device_type' => 'Switch', 'device_category' => 'Network', 'availability' => 'Clear',
    'itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => $items_id > 0 ? 'matched' : 'unmatched',
    'date_creation' => $now, 'date_mod' => $now,
]);
$db->tables['glpi_plugin_opmanager_devices'] = [
    $deviceRow(41, 'dev-1', 'core-sw-01', 'NetworkEquipment', 20),
    $deviceRow(42, 'dev-2', 'vm-app-01', 'Computer', 10),
];
$alarmRow = static fn (int $id, string $key, int $device, int $severity): array => $opm('alarms', [
    'id' => $id, 'alarm_key' => $key, 'entity_key' => 'E' . $id, 'device_name' => 'core-sw-01', 'display_name' => 'core-sw-01',
    'ip_address' => '10.20.0.41', 'category' => 'Switch', 'alarm_code' => 'DOWN', 'event_type' => 'deviceDown',
    'severity' => $severity, 'severity_label' => 'Critical', 'message' => 'link down', 'state' => 'active',
    'plugin_opmanager_devices_id' => $device, 'itemtype' => 'NetworkEquipment', 'items_id' => 20, 'tickets_id' => 0,
    'date_creation' => $now, 'date_mod' => $now,
]);
$db->tables['glpi_plugin_opmanager_alarms'] = [$alarmRow(71, 'A1', 41, 1), $alarmRow(72, 'A2', 42, 2), $alarmRow(73, 'A3', 41, 5)];
$db->tables['glpi_plugin_opmanager_outbox'] = [$opm('outbox', [
    'id' => 1, 'alarm_key' => 'A1', 'entity_key' => 'E71', 'tickets_id' => 0, 'users_id' => 0, 'action' => 'clear',
    'payload' => '', 'state' => 'pending', 'attempts' => 0, 'next_try_at' => $now, 'last_error' => '', 'date_creation' => $now, 'date_mod' => $now,
])];
$db->tables['glpi_plugin_opmanager_storms'] = [$opm('storms', ['id' => 1, 'tickets_id' => 500, 'alarm_count' => 3, 'started_at' => $now, 'last_hit_at' => $now, 'ended_at' => null])];
$db->tables['glpi_plugin_opmanager_ticketrequests'] = [$opm('ticketrequests', ['id' => 1, 'alarm_key' => 'A1', 'entity_key' => 'E71', 'date_creation' => $now, 'date_mod' => $now])];
$db->tables['glpi_plugin_opmanager_deviceexceptions'] = [$opm('deviceexceptions', [
    'id' => 1, 'device_key' => 'dev-1', 'reason' => 'lab', 'users_id' => 0, 'is_active' => 1, 'revoked_by' => 0, 'revoked_at' => null, 'date_creation' => $now, 'date_mod' => $now,
])];
$db->tables['glpi_plugin_opmanager_hygienetickets'] = [$opm('hygienetickets', ['id' => 61, 'flow' => 'glpi', 'tickets_id' => 600, 'last_report_at' => $now, 'date_creation' => $now, 'date_mod' => $now])];
$db->tables['glpi_plugin_opmanager_hygieneitems'] = [
    $opm('hygieneitems', ['id' => 1, 'plugin_opmanager_hygienetickets_id' => 61, 'device_key' => 'dev-1', 'hygiene_state' => 'not_in_glpi', 'reported_at' => $now]),
    $opm('hygieneitems', ['id' => 2, 'plugin_opmanager_hygienetickets_id' => 61, 'device_key' => 'dev-2', 'hygiene_state' => 'not_in_glpi', 'reported_at' => $now]),
];

// Profiles: opmanager's own rights are copied; the core "config" right is never touched.
$db->tables['glpi_profiles'] = [['id' => 1], ['id' => 2]];
$db->tables['glpi_profilerights'] = [
    ['profiles_id' => 1, 'name' => 'config', 'rights' => 3],
    ['profiles_id' => 1, 'name' => 'plugin_opmanager_config', 'rights' => 3],
    ['profiles_id' => 1, 'name' => 'plugin_opmanager_monitoring', 'rights' => 1],
    ['profiles_id' => 1, 'name' => 'plugin_nms_config', 'rights' => 0],
    ['profiles_id' => 2, 'name' => 'config', 'rights' => 3],
    ['profiles_id' => 2, 'name' => 'plugin_opmanager_monitoring', 'rights' => 1],
    ['profiles_id' => 2, 'name' => 'plugin_nms_monitoring', 'rights' => 1],
];

foreach (array_keys($schema) as $t) {
    $db->tables[$t] ??= [];
}

// ---- First run ----
$r1  = FromOpmanager::run($db, $now);
$cid = $r1['connections_id'];
$conn = (new DbConnectionStore())->get($cid);
$s    = Settings::fromJson($conn->settings);

check('no glpi_plugin_opmanager_config read (the table does not exist)', !in_array('glpi_plugin_opmanager_config', $db->reads, true));
check('every nms write uses a real column', $db->badColumns === [], implode(', ', array_unique($db->badColumns)));
check('connection disabled, named, opmanager', $conn->is_active === 0 && $conn->name === 'OpManager (migrated)' && $conn->brand === 'opmanager');
check('URL arrives without trailing slash', $conn->url === 'https://opm.example.test');
check('verify_ssl 0 arrives as verify_tls 0', $conn->verify_tls === 0);
check('API key arrives, re-encrypted', $conn->secret('api_key') === 'key-xyz' && $conn->api_key !== 'key-xyz');
check('webhook token arrives, re-encrypted', $conn->secret('webhook_token') === 'token-xyz' && $conn->webhook_token !== 'token-xyz');
check('moved settings arrive', $s->get('service_user') === '12' && $s->get('ticket_source') === 'rules' && $s->get('production_addressings') === '[7]' && $s->get('match_mode') === 'ip_only');
check('entity = lowest common ancestor of the linked assets (3, 5 under 2)', $conn->entities_id === 2);

check('counts', $r1['counts'] === ['devices' => 2, 'alarms' => 3, 'outbox' => 1, 'storms' => 1, 'ticketrequests' => 1, 'deviceexceptions' => 1, 'hygienetickets' => 1, 'hygieneitems' => 2], json_encode($r1['counts']));

$nmsDevice = [];
foreach ($db->tables['glpi_plugin_nms_devices'] as $d) {
    $nmsDevice[$d['device_key']] = (int) $d['id'];
}
$alarmDev = [];
foreach ($db->tables['glpi_plugin_nms_alarms'] as $a) {
    $alarmDev[$a['alarm_key']] = (int) ($a['plugin_nms_devices_id'] ?? -1);
}
check('alarm device ids point at the copied nms devices', $alarmDev === ['A1' => $nmsDevice['dev-1'], 'A2' => $nmsDevice['dev-2'], 'A3' => $nmsDevice['dev-1']], json_encode($alarmDev));
$hyTicket = (int) $db->tables['glpi_plugin_nms_hygienetickets'][0]['id'];
$itemTickets = array_unique(array_map(static fn ($i) => (int) ($i['plugin_nms_hygienetickets_id'] ?? -1), $db->tables['glpi_plugin_nms_hygieneitems']));
check('hygiene items point at the copied nms hygiene ticket', array_values($itemTickets) === [$hyTicket]);
$scoped = array_filter(array_keys($schema), static fn ($t) => $t !== 'glpi_plugin_nms_connections' && in_array('connections_id', $schema[$t], true));
check('every copied row of a scoped table carries the connection', array_reduce($scoped, static fn ($ok, $t) => $ok && $db->tables[$t] !== [] && array_reduce($db->tables[$t], static fn ($o, $r) => $o && (int) $r['connections_id'] === $cid, true), true));

$right = static function (int $profile, string $name) use ($db): ?int {
    foreach ($db->tables['glpi_profilerights'] as $r) {
        if ($r['profiles_id'] === $profile && $r['name'] === $name) {
            return (int) $r['rights'];
        }
    }
    return null;
};
check('opmanager config right copied to nms (where still 0)', $right(1, 'plugin_nms_config') === 3);
check('missing nms right row is inserted', $right(1, 'plugin_nms_monitoring') === 1);
check('core config right never copied: profile 2 gets no nms config', $right(2, 'plugin_nms_config') === null);
check('core config right untouched', $right(1, 'config') === 3 && $right(2, 'config') === 3);

$opmWrites = array_filter($db->dml, static fn (string $q) => str_contains($q, 'glpi_plugin_opmanager_') || str_contains($q, 'glpi_configs'));
check('no write to opmanager tables or glpi_configs', $opmWrites === []);

// ---- Second run: idempotent ----
$r2 = FromOpmanager::run($db, $now);
check('second run changes nothing', array_sum($r2['counts']) === 0 && $r2['connections_id'] === $cid, json_encode($r2['counts']));
check('second run keeps alarm device ids', $db->tables['glpi_plugin_nms_alarms'][0]['plugin_nms_devices_id'] === $nmsDevice['dev-1']);

// ---- Third run: one alarm changed, one new ----
$db->tables['glpi_plugin_opmanager_alarms'][1]['severity'] = 4;
$db->tables['glpi_plugin_opmanager_alarms'][] = $alarmRow(74, 'A4', 42, 3);
$r3 = FromOpmanager::run($db, $now);
check('third run updates 1 and inserts 1 alarm', $r3['counts']['alarms'] === 2);
check('new alarm points at its nms device', end($db->tables['glpi_plugin_nms_alarms'])['plugin_nms_devices_id'] === $nmsDevice['dev-2']);

// ---- Pure entity helper ----
$tree = [9 => 0, 2 => 9, 3 => 2, 5 => 2, 8 => 0];
check('LCA of siblings is their parent', FromOpmanager::commonAncestor([3, 5], $tree) === 2);
check('LCA of one entity is itself', FromOpmanager::commonAncestor([5], $tree) === 5);
check('LCA of an entity and its ancestor is the ancestor', FromOpmanager::commonAncestor([3, 9], $tree) === 9);
check('LCA across branches is root', FromOpmanager::commonAncestor([3, 8], $tree) === 0);

// ---- No opmanager config at all: no URL, no secret marked as stored ----
$db2 = new FakeDB($schema);
foreach (array_keys($schema) as $t) {
    $db2->tables[$t] = [];
}
$db2->tables['glpi_plugin_opmanager_devices'] = [];
$db2->tables['glpi_configs'] = [];
$db2->tables['glpi_profiles'] = [];
$GLOBALS['DB'] = $db2;
$r = FromOpmanager::run($db2, $now);
$c = (new DbConnectionStore())->get($r['connections_id']);
check('empty secrets stay empty (not an encrypted empty string)', $c->api_key === '' && $c->webhook_token === '');
check('no linked asset: root entity', $c->entities_id === 0);

done();
