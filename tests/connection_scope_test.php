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
        return 'glpi_plugin_nms_' . strtolower($short) . 's';
    }
}

require OPM_ROOT . '/src/Ticketing/AlarmStore.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Ticketing/DbAlarmStore.php';
require OPM_ROOT . '/src/Alarm.php';
require OPM_ROOT . '/src/Outbound/OutboxStore.php';
require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';
require OPM_ROOT . '/src/Ticketing/DeviceIndex.php';
require OPM_ROOT . '/src/Ticketing/DbDeviceIndex.php';
require OPM_ROOT . '/src/Hygiene/HygieneStore.php';
require OPM_ROOT . '/src/Hygiene/DbHygieneStore.php';
require OPM_ROOT . '/src/DeviceStore.php';
require OPM_ROOT . '/src/DbDeviceStore.php';
require OPM_ROOT . '/src/Ticketing/TicketRequestStore.php';
require OPM_ROOT . '/src/Ticketing/DbTicketRequestStore.php';

use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Outbound\DbOutboxStore;

/** Minimal fake $DB: rows per table, WHERE as column => value (array => op => v). */
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

$alarm = [
    'alarm_key'     => 'A1',
    'entity_key'    => 'E1',
    'device_name'   => 'core-sw-01',
    'display_name'  => 'core-sw-01',
    'ip_address'    => '10.20.x.x',
    'category'      => 'Network',
    'alarm_code'    => 'DOWN',
    'event_type'    => 'DeviceDown',
    'severity'      => 3,
    'severity_label'=> 'Critical',
    'message'       => 'Device is down',
    'mod_time'      => '',
];
$asset = ['devices_id' => 1, 'itemtype' => 'Computer', 'items_id' => 1];
$now = '2026-11-11 00:00:00';

$store1 = new DbAlarmStore(1);
$store2 = new DbAlarmStore(2);

$id1 = $store1->save($alarm, $asset, 'active', $now);
$id2 = $store2->save($alarm, $asset, 'active', $now);

check('same alarm_key under two connections is two rows', $id1 !== $id2);
check('store ids differ', $id1 === 1 && $id2 === 2);

$found1 = $store1->find('A1');
$found2 = $store2->find('A1');

check('find on store 1 returns connection 1 row', $found1 !== null && (int) $found1['id'] === $id1);
check('find on store 2 returns connection 2 row', $found2 !== null && (int) $found2['id'] === $id2);
check('find on store 1 never returns store 2 row', $found1 !== null && (int) $found1['id'] !== $id2);

// Outbox: pending() scoped by connection.
$out1 = new DbOutboxStore(1);
$out2 = new DbOutboxStore(2);

$row = [
    'alarm_key' => 'A1',
    'entity_key' => 'E1',
    'tickets_id' => 0,
    'users_id' => 0,
    'action' => 'clear',
    'payload' => '{}',
];
$out1->enqueue($row, $now);
$out2->enqueue($row, $now);

$pending1 = $out1->pending(10);
$pending2 = $out2->pending(10);

check('pending on outbox store 1 has one row', count($pending1) === 1);
check('pending on outbox store 2 has one row', count($pending2) === 1);
check('pending() on store 1 returns only its own row', count($pending1) === 1 && (int) $pending1[0]['id'] !== (int) $pending2[0]['id']);
check('pending() on store 2 returns only its own row', count($pending2) === 1 && (int) $pending2[0]['id'] !== (int) $pending1[0]['id']);

done();
