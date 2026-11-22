<?php

// Task 7 (1.1.0): Coverage per connection. Pure Report/View, plus source pins for the page and the source.
require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Coverage/Report.php';
require OPM_ROOT . '/src/Coverage/View.php';

use GlpiPlugin\Nms\Coverage\Report;
use GlpiPlugin\Nms\Coverage\View;

$dev = static fn (int $id, int $conn, string $name, string $ip, string $state, string $itemtype = '', int $items_id = 0, string $source = '', string $seen = '2026-10-02 11:00:00') => [
    'id' => $id, 'connections_id' => $conn, 'device_key' => "k{$id}", 'name' => $name, 'display_name' => $name, 'ip' => $ip, 'category' => 'Server', 'type' => 'Linux',
    'itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => $state, 'match_source' => $source, 'last_seen' => $seen,
];
$asset = static fn (string $t, int $id, string $name, array $ips, int $ent = 0) => ['itemtype' => $t, 'items_id' => $id, 'name' => $name, 'entity' => 'Root', 'entities_id' => $ent, 'state' => 'In use', 'ips' => $ips];
$opt = ['ranges' => [], 'production_only' => false, 'category' => '', 'last_device_sync' => '2026-10-02 11:30:00', 'stale_minutes' => 90];

$devices = [
    $dev(1, 1, 'core-sw-01', '192.0.2.1', 'matched', 'NetworkEquipment', 10, 'name'),
    $dev(2, 1, 'srv-app', '192.0.2.2', 'unmatched'),
    $dev(3, 2, 'zbx-host', '192.0.2.3', 'review'),
    $dev(4, 1, 'old-box', '192.0.2.4', 'unmatched', '', 0, '', '2026-09-01 00:00:00'),
];
$assets = [$asset('NetworkEquipment', 10, 'CORE-SW-01', ['192.0.2.1'], 82), $asset('Computer', 20, 'db-01', ['192.0.2.9'], 82)];
$r = Report::build($devices, $assets, $opt);
check('kpi by_connection counts listed devices', $r['kpi']['by_connection'] === [1 => 3, 2 => 1], json_encode($r['kpi']['by_connection']));

// Equivalence with 1.0.0 (only OpManager, connection 0): kpi minus by_connection, lists minus the added fields.
$old_devices = array_map(static function ($d) { unset($d['connections_id']); return $d; }, $devices);
$old_assets  = array_map(static function ($a) { unset($a['entities_id']); return $a; }, $assets);
$only1       = array_values(array_filter($devices, static fn ($d) => $d['connections_id'] === 1));
$only1_old   = array_values(array_filter($old_devices, static fn ($d, $i) => $devices[$i]['connections_id'] === 1, ARRAY_FILTER_USE_BOTH));
$new = Report::build($only1, $assets, $opt);
$old = Report::build($only1_old, $old_assets, $opt);
$kpi = $new['kpi'];
$old_kpi = $old['kpi'];
unset($kpi['by_connection'], $old_kpi['by_connection']);
$strip = static fn (array $lists): array => array_map(static fn (array $rows): array => array_map(static function ($x) { unset($x['connections_id'], $x['entities_id']); return $x; }, $rows), $lists);
check('equivalence: kpi', $kpi === $old_kpi);
check('equivalence: lists', $strip($new['lists']) === $strip($old['lists']));

// Overlap: an asset linked by devices of two connections is counted once.
$ov = Report::build([$dev(11, 1, 'a', '192.0.2.1', 'matched', 'Computer', 20, 'name'), $dev(12, 2, 'a', '192.0.2.1', 'matched', 'Computer', 20, 'name')], [$asset('Computer', 20, 'a', ['192.0.2.1'], 82)], $opt);
check('overlap: asset linked from two connections counted once', $ov['kpi']['assets'] === 1 && $ov['kpi']['monitored'] === 1 && $ov['kpi']['not_monitored'] === 0);
$page = (string) file_get_contents(OPM_ROOT . '/front/coverage.php');
$all  = substr($page, strpos($page, '} else {'), strpos($page, '$report = Report::build') - strpos($page, '} else {'));
check('overlap: All connections runs one asset query, no scope, no union', substr_count($all, '->assets(') === 1 && !str_contains($all, 'EntityScope') && !str_contains($all, 'forConnection') && (bool) preg_match('/->assets\(Config::assetItemtypes\(\), Config::coverageStatesOf\(\$active\)\);/', $all));

$conns = [
    1 => ['name' => 'OpManager PGN', 'brand' => 'opmanager', 'scope' => null],
    2 => ['name' => 'Zabbix X', 'brand' => 'zabbix', 'scope' => [81, 82]],
];
check('expectedFrom lists connections covering the entity', View::expectedFrom(82, $conns) === 'OpManager PGN, Zabbix X');
check('expectedFrom none', View::expectedFrom(5, [2 => $conns[2]]) === 'none');

$state = ['list' => 'not_in_glpi', 'page' => 1, 'production_only' => false, 'category' => '', 'connection' => 2];
foreach (['not_in_glpi', 'ambiguous', 'name_differs', 'stale'] as $l) {
    check("{$l}: Connection column", in_array('Connection', View::csvRows($l, [], $conns)['header'], true));
}
check('not_monitored: Expected from column', in_array('Expected from', View::csvRows('not_monitored', [], $conns)['header'], true));
$csv = View::csvRows('not_in_glpi', [$devices[1]], $conns);
check('csv cell is plain text', in_array("OpManager PGN \u{00B7} opmanager", $csv['rows'][0], true));
$nm = View::csvRows('not_monitored', [$assets[1]], $conns);
check('csv not monitored expected from', end($nm['rows'][0]) === 'OpManager PGN, Zabbix X');

$conns['1']['name'] = '<b>OpM</b>';
$report = $r;
$report['lists']['not_in_glpi'] = [$devices[1]];
$html = View::render($report, $state, '/x/coverage.php', $conns);
check('html cell escaped', str_contains($html, '&lt;b&gt;OpM&lt;/b&gt; &middot; opmanager') || str_contains($html, '&lt;b&gt;OpM&lt;/b&gt; · opmanager'));
check('hint lists count per connection', str_contains($html, '&lt;b&gt;OpM&lt;/b&gt; 3 · Zabbix X 1'));
check('select with All connections and picked option', str_contains($html, 'name="connection"') && str_contains($html, 'All connections') && (bool) preg_match('/<option value="2" selected>Zabbix X/', $html));
check('links keep connection', substr_count($html, 'connection=2') >= 6);
$one = $r;
$one['kpi']['by_connection'] = [1 => 4];
check('single connection: no per-connection hint', !str_contains(View::render($one, $state, '/x', $conns), 'OpM&lt;/b&gt; 4'));

$front = (string) file_get_contents(OPM_ROOT . '/front/coverage.php');
check('front reads connection', str_contains($front, "(int) (\$_GET['connection'] ?? 0)"));
check('front falls back to 0', str_contains($front, 'isset($byId[$pick])') );
check('front uses the picked connection settings', str_contains($front, 'coverageStatesOf([$conn])') && str_contains($front, 'productionAddressingsOf([$conn])') && str_contains($front, 'EntityScope::forConnection($conn)'));
check('front state carries connection', str_contains($front, "'connection'"));
$src = (string) file_get_contents(OPM_ROOT . '/src/Coverage/DbCoverageSource.php');
check('source assets takes entities', str_contains($src, '?array $entities = null') && str_contains($src, "'connections_id' => (int) \$r['connections_id']") && str_contains($src, "'entities_id'"));

done();
