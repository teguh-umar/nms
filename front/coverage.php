<?php

/**
 * Coverage page (v0.6.0, D28): OpManager devices vs GLPI assets, five follow-up lists,
 * CSV export of a whole list.
 */

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Coverage\Csv;
use GlpiPlugin\Nms\Coverage\DbCoverageSource;
use GlpiPlugin\Nms\Coverage\Page;
use GlpiPlugin\Nms\Coverage\Report;
use GlpiPlugin\Nms\Coverage\View;
use GlpiPlugin\Nms\Matching\EntityScope;
use GlpiPlugin\Nms\RightDefinition;
use GlpiPlugin\Nms\Ticketing\AddressingRanges;

Session::checkRight(RightDefinition::MONITORING, READ);

$list  = in_array($_GET['list'] ?? '', View::LISTS, true) ? (string) $_GET['list'] : View::LISTS[0];
$state = [
    'list'            => $list,
    'page'            => max(1, (int) ($_GET['page'] ?? 1)),
    'production_only' => !empty($_GET['production_only']),
    'category'        => (string) ($_GET['category'] ?? ''),
];
// "All connections" (0) spans every active connection: production ranges and counted states are
// their union. One picked connection narrows the devices to it, the assets to its entity scope,
// and uses its own counted states and production ranges. The data is as old as the oldest
// (picked) connection's last successful device sync.
$connStore = new DbConnectionStore();
$active    = $connStore->active();
$byId      = [];
foreach ($active as $c) {
    $byId[(int) $c->id] = $c;
}
$pick = (int) ($_GET['connection'] ?? 0);
if (!isset($byId[$pick])) {
    $pick = 0; // not an active connection: all of them
}
$state['connection'] = $pick;
if ($pick > 0) {
    $conn      = $byId[$pick];
    $source    = new DbCoverageSource([$pick]);
    $assets    = $source->assets(Config::assetItemtypes(), Config::coverageStatesOf([$conn]), EntityScope::forConnection($conn));
    $ranges    = AddressingRanges::load(Config::productionAddressingsOf([$conn]));
    $last_sync = $connStore->oldestDeviceSync([$pick]);
} else {
    $source    = new DbCoverageSource(DbCoverageSource::idsOf($active));
    $assets    = $source->assets(Config::assetItemtypes(), Config::coverageStatesOf($active));
    $ranges    = AddressingRanges::load(Config::productionAddressingsOf($active));
    $last_sync = $connStore->oldestDeviceSync();
}
$report = Report::build($source->devices(), $assets, [
    'ranges'           => $ranges,
    'production_only'  => $state['production_only'],
    'category'         => $state['category'],
    'last_device_sync' => $last_sync,
    'stale_minutes'    => max(1, (int) Config::get('stale_warning_minutes')),
]);
$connections = [];
foreach ($byId as $id => $c) {
    $connections[$id] = ['name' => (string) $c->name, 'brand' => (string) $c->brand, 'scope' => EntityScope::forConnection($c)];
}

if (($_GET['export'] ?? '') === 'csv') {
    $csv = View::csvRows($list, $report['lists'][$list], $connections);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nms-coverage-' . $list . '-' . date('Ymd') . '.csv"');
    echo Csv::render($csv['header'], $csv['rows']);
    return;
}

Html::header(Page::getTypeName(), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'coverage');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'coverage');
echo View::render($report, $state, Page::getSearchURL(false), $connections);
Html::footer();
