<?php

namespace GlpiPlugin\Nms;

/**
 * "Monitoring" section of the shared PGN Suite asset tab (hub §5, spec §9).
 * A summary, never a browser: no pager (the tab loads by AJAX, hub §4), newest
 * MAX_ROWS rows, and an explicit "Showing N of M" line when truncated.
 */
final class AssetSections
{
    public const KEY      = 'monitoring';
    public const ORDER = 65;
    public const MAX_ROWS = 20;

    /** @return list<array{key: string, title: string, icon: string, order: int, render: string}> */
    public static function sections(\CommonDBTM $item): array
    {
        if (!\Session::haveRight(RightDefinition::MONITORING, READ)) {
            return [];
        }
        // Validate the itemtype HERE, at render time - custom asset classes do not
        // exist yet at plugin_init (hub §5).
        if (!in_array($item::class, Config::assetItemtypes(), true)) {
            return [];
        }
        $id = (int) $item->getID();
        if (Device::findForAsset($item::class, $id) === [] && Alarm::countForAsset($item::class, $id) === 0) {
            return [];
        }
        return [[
            'key'    => self::KEY,
            'title'  => __('Monitoring', 'nms'),
            'icon'   => 'ti ti-activity',
            'order'  => self::ORDER,
            'render' => self::class . '::render',
        ]];
    }

    public static function render(\CommonDBTM $item): void
    {
        $devices = Device::findForAsset($item::class, (int) $item->getID());
        foreach ($devices as &$device) {
            $device['url'] = Device::getFormURLWithID((int) $device['id']);
        }
        unset($device);

        $id = (int) $item->getID();
        echo self::html(self::viewModel(
            $devices,
            (int) strtotime((string) \Session::getCurrentTime()),
            (int) Config::get('stale_warning_minutes'),
            Config::get('last_device_sync'),
            Alarm::forAsset($item::class, $id, self::MAX_ROWS),
            Alarm::countForAsset($item::class, $id)
        ));
    }

    /**
     * Pure.
     *
     * @param list<array<string, mixed>> $devices
     * @return array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>}
     */
    public static function viewModel(array $devices, int $now, int $stale_minutes, string $last_sync, array $alarms = [], int $alarms_total = 0): array
    {
        $synced = $last_sync === '' ? false : strtotime($last_sync);
        return [
            'stale'         => $synced === false || $synced < $now - $stale_minutes * 60,
            'last_sync'     => $last_sync,
            'stale_minutes' => $stale_minutes,
            'total'         => count($devices),
            'rows'          => array_slice($devices, 0, self::MAX_ROWS),
            'alarms'        => array_slice($alarms, 0, self::MAX_ROWS),
            'alarms_total'  => max($alarms_total, count($alarms)),
        ];
    }

    /** Pure. @param array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>} $model */
    public static function html(array $model): string
    {
        $out = '<div class="p-3" id="opmanager-monitoring">';

        if ($model['stale']) {
            $out .= '<div class="alert alert-warning">'
                . htmlescape($model['last_sync'] === ''
                    ? __('OpManager data has never been synced.', 'nms')
                    : sprintf(__('OpManager data is older than %d minutes (last sync: %s).', 'nms'), $model['stale_minutes'], $model['last_sync']))
                . '</div>';
        }

        $sources = ['name' => __('by name', 'nms'), 'ip' => __('by IP', 'nms'), 'manual' => __('manual pin', 'nms')];
        $out .= '<table class="table table-sm table-hover mb-2"><thead><tr>'
            . '<th>' . __s('OpManager device', 'nms') . '</th>'
            . '<th>' . __s('IP address') . '</th>'
            . '<th>' . __s('Availability', 'nms') . '</th>'
            . '<th>' . __s('Last seen', 'nms') . '</th>'
            . '<th>' . __s('Matched', 'nms') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($model['rows'] as $row) {
            $name = (string) (($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['name'] ?? ''));
            $link = isset($row['url']) ? '<a href="' . htmlescape((string) $row['url']) . '">' . htmlescape($name) . '</a>' : htmlescape($name);
            $out .= '<tr data-opm-device="' . (int) ($row['id'] ?? 0) . '">'
                . '<td>' . $link . '</td>'
                . '<td>' . htmlescape((string) ($row['ip_address'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['availability'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['last_seen'] ?? '')) . '</td>'
                . '<td>' . htmlescape($sources[$row['match_source'] ?? ''] ?? '') . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';

        if ($model['total'] > count($model['rows'])) {
            $out .= '<div class="text-muted">' . htmlescape(sprintf(__('Showing %d of %d', 'nms'), count($model['rows']), $model['total'])) . '</div>';
        }

        $alarms = $model['alarms'] ?? [];
        if ($alarms !== []) {
            $out .= '<h4 class="mt-3">' . __s('OpManager alarms', 'nms') . '</h4>'
                . '<table class="table table-sm table-hover mb-2"><thead><tr>'
                . '<th>' . __s('Severity', 'nms') . '</th>'
                . '<th>' . __s('Message', 'nms') . '</th>'
                . '<th>' . __s('State', 'nms') . '</th>'
                . '<th>' . __s('Ticket') . '</th>'
                . '<th>' . __s('Last change', 'nms') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($alarms as $a) {
                $tid    = (int) ($a['tickets_id'] ?? 0);
                $ticket = $tid > 0
                    ? '<a href="' . htmlescape(\Ticket::getFormURLWithID($tid)) . '">#' . $tid . '</a>'
                    : (!empty($a['ticket_pending']) ? __s('pending', 'nms') : '-');
                $out .= '<tr data-opm-alarm="' . htmlescape((string) ($a['alarm_key'] ?? '')) . '">'
                    . '<td>' . htmlescape((string) ($a['severity_label'] ?? '')) . '</td>'
                    . '<td>' . htmlescape((string) ($a['message'] ?? '')) . '</td>'
                    . '<td>' . htmlescape((string) ($a['state'] ?? '')) . '</td>'
                    . '<td>' . $ticket . '</td>'
                    . '<td>' . htmlescape((string) ($a['last_change'] ?? '')) . '</td>'
                    . '</tr>';
            }
            $out .= '</tbody></table>';
            if ($model['alarms_total'] > count($alarms)) {
                $out .= '<div class="text-muted">' . htmlescape(sprintf(__('Showing %d of %d alarms', 'nms'), count($alarms), $model['alarms_total'])) . '</div>';
            }
        }

        return $out . '</div>';
    }
}
