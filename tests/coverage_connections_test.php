<?php

// rc5 staging (2026-11-21): the Coverage page and the dashboard cards read devices, alarms and
// tickets of connection 0, which never exists (front/coverage.php and Cards passed no connection
// since 66b6a0f). Coverage spans every active connection, so both callers pass the active ids and
// DbCoverageSource reads those, and nothing when no connection is active.

namespace GlpiPlugin\Nms {
    if (!class_exists(Device::class, false)) {
        class Device { public static function getTable(): string { return 'glpi_plugin_nms_devices'; } }
    }
    if (!class_exists(Alarm::class, false)) {
        class Alarm { public static function getTable(): string { return 'glpi_plugin_nms_alarms'; } }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';
    require OPM_ROOT . '/src/Coverage/DbCoverageSource.php';

    use GlpiPlugin\Nms\Coverage\DbCoverageSource;

    final class CoverageFakeDB
    {
        /** @var list<array<string, mixed>> */
        public array $criteria = [];
        public array $rows = [];

        public function request(array $c): \ArrayIterator
        {
            $this->criteria[] = $c;
            $want = $c['WHERE']['connections_id'] ?? null;
            $out = [];
            foreach ($this->rows[$c['FROM']] ?? [] as $r) {
                if ($want === null || in_array($r['connections_id'], (array) $want, true)) {
                    $out[] = $r;
                }
            }
            return new \ArrayIterator($out);
        }
    }

    $dev = static fn (int $id, int $cid): array => ['id' => $id, 'connections_id' => $cid, 'device_key' => "d{$id}", 'name' => "core-sw-0{$id}", 'display_name' => "core-sw-0{$id}", 'ip_address' => "10.20.0.{$id}", 'device_category' => 'Switch', 'device_type' => 'Switch', 'itemtype' => '', 'items_id' => 0, 'match_state' => 'unmatched', 'match_source' => '', 'last_seen' => null];

    global $DB;
    $DB = new CoverageFakeDB();
    $DB->rows['glpi_plugin_nms_devices'] = [$dev(1, 1), $dev(2, 1), $dev(3, 2), $dev(4, 3)];
    $DB->rows['glpi_plugin_nms_alarms'] = [
        ['connections_id' => 1, 'severity_label' => 'Critical', 'cpt' => 2],
        ['connections_id' => 3, 'severity_label' => 'Critical', 'cpt' => 9],
    ];

    $keys = static fn (array $devices): array => array_column($devices, 'device_key');

    check('active connections 1 and 2: their devices', $keys((new DbCoverageSource([1, 2]))->devices()) === ['d1', 'd2', 'd3']);
    check('inactive connection 3 is left out', !in_array('d4', $keys((new DbCoverageSource([1, 2]))->devices()), true));
    $before = count($DB->criteria);
    check('no active connection: no devices, no query', (new DbCoverageSource([]))->devices() === [] && count($DB->criteria) === $before);
    check('no active connection: no alarms', (new DbCoverageSource([]))->alarmsBySeverity() === []);
    check('no active connection: no open alarm tickets', (new DbCoverageSource([]))->openAlarmTickets() === 0);
    check('alarms read for the active ids only', (new DbCoverageSource([1, 2]))->alarmsBySeverity() === ['Critical' => 2]);

    // Both callers pass the active connections' ids, never a fixed id.
    $page  = (string) file_get_contents(OPM_ROOT . '/front/coverage.php');
    $cards = (string) file_get_contents(OPM_ROOT . '/src/Coverage/Cards.php');
    foreach (['front/coverage.php' => $page, 'src/Coverage/Cards.php' => $cards] as $file => $src) {
        check("{$file}: source built from the active connections", (bool) preg_match('/new DbCoverageSource\(\s*DbCoverageSource::idsOf\(/', $src));
        check("{$file}: no DbCoverageSource on a fixed or missing connection", !preg_match('/new DbCoverageSource\(\s*(\d+\s*)?\)/', $src));
    }

    done();
}
