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
// Spans every active connection: production ranges and counted states are their union,
// and the data is as old as the oldest connection's last successful device sync.
$connStore = new DbConnectionStore();
$active    = $connStore->active();
$source    = new DbCoverageSource();
$report    = Report::build($source->devices(), $source->assets(Config::assetItemtypes(), Config::coverageStatesOf($active)), [
    'ranges'           => AddressingRanges::load(Config::productionAddressingsOf($active)),
    'production_only'  => $state['production_only'],
    'category'         => $state['category'],
    'last_device_sync' => $connStore->oldestDeviceSync(),
    'stale_minutes'    => max(1, (int) Config::get('stale_warning_minutes')),
]);

if (($_GET['export'] ?? '') === 'csv') {
    $csv = View::csvRows($list, $report['lists'][$list]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="opmanager-coverage-' . $list . '-' . date('Ymd') . '.csv"');
    echo Csv::render($csv['header'], $csv['rows']);
    return;
}

Html::header(Page::getTypeName(), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'coverage');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'coverage');
echo View::render($report, $state, Page::getSearchURL(false));
Html::footer();
