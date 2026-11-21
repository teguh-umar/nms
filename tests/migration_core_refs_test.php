<?php

// rc5: core GLPI rows that name opmanager (column choices, saved searches, default searches,
// dashboard widgets) carried over to nms, GenericObject-style. See src/Migration/CoreReferences.php.

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Migration/CoreReferences.php';

use GlpiPlugin\Nms\Migration\CoreReferences;

/** Core tables with the GLPI 11 unique keys that matter here. */
final class CoreFakeDB
{
    public array $tables = [];
    public int $insertId = 100;
    /** @var list<string> */
    public array $writes = [];

    public function request(array $c): \ArrayIterator
    {
        $out = [];
        foreach ($this->tables[$c['FROM']] ?? [] as $row) {
            $ok = true;
            foreach ($c['WHERE'] ?? [] as $col => $val) {
                $rv = $row[$col] ?? null;
                if (is_array($val) ? !in_array($rv, $val, true) : ($val === null ? $rv !== null : (string) $rv !== (string) $val)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return new \ArrayIterator(isset($c['LIMIT']) ? array_slice($out, 0, (int) $c['LIMIT']) : $out);
    }

    public function insert(string $t, array $f): bool
    {
        $this->writes[] = $t;
        // MySQL would reject a duplicate on these unique keys.
        $uniq = ['glpi_displaypreferences' => ['users_id', 'itemtype', 'num', 'interface'], 'glpi_savedsearches_users' => ['users_id', 'itemtype']][$t] ?? null;
        if ($uniq !== null) {
            foreach ($this->tables[$t] ?? [] as $row) {
                if (array_intersect_key($row, array_flip($uniq)) == array_intersect_key($f, array_flip($uniq))) {
                    throw new \RuntimeException("duplicate key in {$t}");
                }
            }
        }
        $this->tables[$t][] = $f + ['id' => ++$this->insertId];
        return true;
    }

    public function update(string $t, array $f, array $w): bool
    {
        $this->writes[] = $t;
        foreach ($this->tables[$t] ?? [] as $i => $row) {
            if ((int) $row['id'] === (int) $w['id']) {
                $this->tables[$t][$i] = array_merge($row, $f);
            }
        }
        return true;
    }

    public function tableExists(string $t): bool { return isset($this->tables[$t]); }
    public function insertId(): int { return $this->insertId; }
}

$OD = 'GlpiPlugin\\Opmanager\\Device';
$OA = 'GlpiPlugin\\Opmanager\\Alarm';
$ND = 'GlpiPlugin\\Nms\\Device';
$NA = 'GlpiPlugin\\Nms\\Alarm';

$db = new CoreFakeDB();
$db->tables['glpi_plugins'] = [['id' => 1, 'directory' => 'opmanager', 'state' => 1]];
$db->tables['glpi_displaypreferences'] = [
    ['id' => 1, 'itemtype' => $OD, 'num' => 3, 'rank' => 1, 'users_id' => 0, 'interface' => 'central'],
    ['id' => 2, 'itemtype' => $OD, 'num' => 6, 'rank' => 2, 'users_id' => 0, 'interface' => 'central'],
    ['id' => 3, 'itemtype' => $OA, 'num' => 5, 'rank' => 1, 'users_id' => 7, 'interface' => 'central'],
    ['id' => 4, 'itemtype' => $OD, 'num' => 99, 'rank' => 3, 'users_id' => 0, 'interface' => 'central'],
    ['id' => 5, 'itemtype' => 'Computer', 'num' => 3, 'rank' => 1, 'users_id' => 0, 'interface' => 'central'],
];
$q = http_build_query([
    'itemtype' => $OD,
    'criteria' => [
        ['link' => 'AND', 'field' => '6', 'searchtype' => 'equals', 'value' => 'ambiguous'],
        ['link' => 'AND', 'criteria' => [['field' => '3', 'searchtype' => 'contains', 'value' => '10.20.']]],
        ['link' => 'AND', 'field' => 'view', 'searchtype' => 'contains', 'value' => 'core'],
    ],
    'metacriteria' => [],
    'sort' => ['1'],
    'order' => ['ASC'],
]);
$db->tables['glpi_savedsearches'] = [
    ['id' => 10, 'name' => 'Ambiguous devices', 'type' => 1, 'itemtype' => $OD, 'users_id' => 7, 'is_private' => 1, 'entities_id' => 0, 'is_recursive' => 0, 'query' => $q, 'do_count' => 2, 'counter' => 40],
    ['id' => 11, 'name' => 'My computers', 'type' => 1, 'itemtype' => 'Computer', 'users_id' => 7, 'is_private' => 1, 'entities_id' => 0, 'is_recursive' => 0, 'query' => 'itemtype=Computer', 'do_count' => 2, 'counter' => 1],
];
$db->tables['glpi_savedsearches_users'] = [['id' => 1, 'users_id' => 7, 'itemtype' => $OD, 'savedsearches_id' => 10]];
$db->tables['glpi_dashboards_items'] = [
    ['id' => 1, 'dashboards_dashboards_id' => 1, 'gridstack_id' => 'plugin_opmanager_coverage5d1c7a2e-0000-4000-8000-000000000001', 'card_id' => 'plugin_opmanager_coverage', 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2, 'card_options' => '{"color":"#e1d4f1","widgettype":"bigNumber","card_id":"plugin_opmanager_coverage"}'],
    ['id' => 2, 'dashboards_dashboards_id' => 1, 'gridstack_id' => 'bn_count_Computer4f0e', 'card_id' => 'bn_count_Computer', 'x' => 3, 'y' => 0, 'width' => 3, 'height' => 2, 'card_options' => '{}'],
];

// 1. opmanager still active (install time): copies, but dashboards wait.
$r1 = CoreReferences::copy($db);
check('active opmanager: counts', $r1 === ['displaypreferences' => 3, 'savedsearches' => 1, 'default_searches' => 1, 'dashboard_cards' => 0, 'dashboard_cards_waiting' => 1], json_encode($r1));

$prefs = array_values(array_filter($db->tables['glpi_displaypreferences'], static fn ($r) => in_array($r['itemtype'], [$ND, $NA], true)));
$got = array_map(static fn ($r) => [$r['itemtype'], $r['num'], $r['rank'], $r['users_id']], $prefs);
check('column choices mapped by field (ip 3->8382, match 6->8385, alarm state 5->8384)', $got === [[$ND, 8382, 1, 0], [$ND, 8385, 2, 0], [$NA, 8384, 1, 7]], json_encode($got));
check('an option nms lacks is left out', !in_array(99, array_column($prefs, 'num'), true));
check('opmanager column choices untouched', count(array_filter($db->tables['glpi_displaypreferences'], static fn ($r) => $r['itemtype'] === $OD)) === 3);

$ss = array_values(array_filter($db->tables['glpi_savedsearches'], static fn ($r) => $r['itemtype'] === $ND));
check('one saved search copied, same owner and name', count($ss) === 1 && $ss[0]['name'] === 'Ambiguous devices' && $ss[0]['users_id'] === 7 && $ss[0]['counter'] === 0);
parse_str($ss[0]['query'], $nq);
check('query itemtype is nms', $nq['itemtype'] === $ND);
check('query criterion field mapped (6 -> 8385)', $nq['criteria'][0]['field'] === '8385');
check('nested group field mapped (3 -> 8382)', $nq['criteria'][1]['criteria'][0]['field'] === '8382');
check('non-numeric field kept', $nq['criteria'][2]['field'] === 'view');
check('sort mapped (1 -> 8380)', $nq['sort'] === ['8380']);
check('values kept', $nq['criteria'][0]['value'] === 'ambiguous' && $nq['criteria'][1]['criteria'][0]['value'] === '10.20.');
check('other itemtypes not copied', count(array_filter($db->tables['glpi_savedsearches'], static fn ($r) => $r['itemtype'] === 'Computer')) === 1);

$def = array_values(array_filter($db->tables['glpi_savedsearches_users'], static fn ($r) => $r['itemtype'] === $ND));
check('default search points at the copied search', count($def) === 1 && $def[0]['savedsearches_id'] === $ss[0]['id'] && $def[0]['users_id'] === 7);

check('dashboard untouched while opmanager is active', $db->tables['glpi_dashboards_items'][0]['card_id'] === 'plugin_opmanager_coverage');

// 2. Second run with opmanager still active: nothing new.
$r2 = CoreReferences::copy($db);
check('second run copies nothing', $r2['displaypreferences'] + $r2['savedsearches'] + $r2['default_searches'] === 0, json_encode($r2));

// 3. Cutover: opmanager deactivated, run again: widgets switch in place.
$db->tables['glpi_plugins'][0]['state'] = 4; // \Plugin::NOTACTIVATED
$r3 = CoreReferences::copy($db);
check('after deactivation: one widget switched', $r3['dashboard_cards'] === 1 && $r3['dashboard_cards_waiting'] === 0, json_encode($r3));
$w = $db->tables['glpi_dashboards_items'][0];
check('widget card is the nms card', $w['card_id'] === 'plugin_nms_coverage');
check('widget grid id keeps its uuid', $w['gridstack_id'] === 'plugin_nms_coverage5d1c7a2e-0000-4000-8000-000000000001');
check('widget options name the nms card, colour kept', $w['card_options'] === '{"color":"#e1d4f1","widgettype":"bigNumber","card_id":"plugin_nms_coverage"}');
check('widget position kept', [$w['x'], $w['y'], $w['width'], $w['height']] === [0, 0, 3, 2]);
check('core widget untouched', $db->tables['glpi_dashboards_items'][1]['card_id'] === 'bn_count_Computer');

$r4 = CoreReferences::copy($db);
check('fourth run: nothing left', array_sum($r4) === 0, json_encode($r4));

// 4. Uninstalled opmanager (no glpi_plugins row) counts as not active.
$db2 = new CoreFakeDB();
$db2->tables['glpi_plugins'] = [];
$db2->tables['glpi_dashboards_items'] = [['id' => 1, 'gridstack_id' => 'plugin_opmanager_devicesx', 'card_id' => 'plugin_opmanager_devices', 'card_options' => null]];
check('no opmanager row: widgets switch', CoreReferences::copy($db2)['dashboard_cards'] === 1 && $db2->tables['glpi_dashboards_items'][0]['card_id'] === 'plugin_nms_devices');

check('never writes an opmanager table or glpi_configs', array_filter(array_merge($db->writes, $db2->writes), static fn ($t) => str_starts_with($t, 'glpi_plugin_opmanager_') || $t === 'glpi_configs') === []);

// The maps must match the real code, field by field.
$opt = static function (string $src): array {
    preg_match_all("/'id'\\s*=>\\s*'?(\\d+)'?[^]]*?'field'\\s*=>\\s*'(\\w+)'/s", $src, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as [, $id, $field]) {
        $o[(int) $id] = $field;
    }
    return $o;
};
$opmFields = [
    // opmanager 1.3.2 (tree 9f217b2) search options, by number.
    'GlpiPlugin\\Opmanager\\Device' => [1 => 'display_name', 2 => 'id', 3 => 'ip_address', 4 => 'device_category', 5 => 'availability', 6 => 'match_state', 7 => 'items_id', 8 => 'last_seen', 9 => 'is_manual', 10 => 'hygiene_state', 11 => 'liveness_state', 12 => 'liveness_detail', 13 => 'liveness_checked_at'],
    'GlpiPlugin\\Opmanager\\Alarm'  => [1 => 'alarm_key', 2 => 'display_name', 3 => 'severity_label', 4 => 'message', 5 => 'state', 6 => 'tickets_id', 7 => 'last_change', 8 => 'ip_address', 9 => 'ticket_pending'],
];
foreach (CoreReferences::ITEMTYPES as $old => [$new, $map]) {
    $nmsFields = $opt((string) file_get_contents(OPM_ROOT . '/src/' . substr($new, strlen('GlpiPlugin\\Nms\\')) . '.php'));
    $bad = [];
    foreach ($map as $o => $n) {
        if (($opmFields[$old][$o] ?? '?') !== ($nmsFields[$n] ?? '!')) {
            $bad[] = "{$o}->{$n}";
        }
    }
    check("{$new}: every mapped option keeps its field", $bad === [] && count($map) === count($opmFields[$old]), implode(', ', $bad));
}
preg_match_all("/'(\\w+)'\\s*=>\\s*\\[\\[/", (string) file_get_contents(OPM_ROOT . '/src/Coverage/Cards.php'), $cm);
check('card list matches Cards.php', array_map('strtolower', $cm[1]) === CoreReferences::CARDS, json_encode($cm[1]));

done();
