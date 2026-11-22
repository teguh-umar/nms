<?php

namespace GlpiPlugin\Nms\Coverage;

/**
 * Pure HTML for the Coverage page (spec §15.1): KPI cards, filters, one tab per follow-up
 * list, a paged table and a CSV link. Every value goes through htmlescape(); links are
 * built only from a class name and an int id.
 */
final class View
{
    public const LISTS = ['not_in_glpi', 'not_monitored', 'ambiguous', 'name_differs', 'stale'];

    public const PER_PAGE = 50;

    /**
     * @param array{kpi: array<string, int>, lists: array<string, list<array<string, mixed>>>, categories: list<string>} $report
     * @param array{list: string, page: int, production_only: bool, category: string, connection?: int} $state
     * @param array<int, array{name: string, brand: string, scope: ?list<int>}> $connections active connections
     */
    public static function render(array $report, array $state, string $base_url, array $connections = []): string
    {
        $k        = $report['kpi'];
        $dev_hint = sprintf(__('%d matched to a GLPI asset', 'nms'), $k['matched']);
        $per      = array_filter((array) ($k['by_connection'] ?? []), static fn ($n) => $n > 0);
        if (count($per) > 1) {
            ksort($per);
            $parts = [];
            foreach ($per as $id => $n) {
                $parts[] = ($connections[$id]['name'] ?? '#' . $id) . ' ' . $n;
            }
            $dev_hint .= ' — ' . implode(' · ', $parts);
        }
        $html = '<div class="container-fluid">';

        $cards = [
            [__('Coverage', 'nms'), $k['coverage_pct'] . '%', sprintf(__('%1$d of %2$d GLPI assets monitored', 'nms'), $k['monitored'], $k['assets'])],
            [__('GLPI assets in scope', 'nms'), (string) $k['assets'], __('selected types, status, with an IP', 'nms')],
            [__('NMS devices', 'nms'), (string) $k['devices'], $dev_hint],
            [__('Not in GLPI', 'nms'), (string) $k['not_in_glpi'], __('monitored but not registered', 'nms')],
            [__('Not monitored', 'nms'), (string) $k['not_monitored'], __('registered but not in any NMS', 'nms')],
            [__('Ambiguous', 'nms'), (string) $k['ambiguous'], __('needs a manual pairing', 'nms')],
        ];
        $html .= '<div class="row row-cards mb-3">';
        foreach ($cards as [$label, $value, $hint]) {
            $html .= '<div class="col-6 col-md-4 col-xl-2"><div class="card"><div class="card-body">'
                . '<div class="subheader">' . htmlescape($label) . '</div>'
                . '<div class="h1 mb-0">' . htmlescape($value) . '</div>'
                . '<div class="text-muted small">' . htmlescape($hint) . '</div>'
                . '</div></div></div>';
        }
        $html .= '</div>';

        // Filters (GET, keeps the current list)
        $html .= '<form method="get" action="' . htmlescape($base_url) . '" class="d-flex flex-wrap align-items-center gap-3 mb-3">'
            . '<input type="hidden" name="list" value="' . htmlescape($state['list']) . '">'
            . '<select class="form-select w-auto" name="connection"><option value="0">' . htmlescape(__('All connections', 'nms')) . '</option>'
            . self::connectionOptions($connections, (int) ($state['connection'] ?? 0))
            . '</select>'
            . '<label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="production_only" value="1"' . ($state['production_only'] ? ' checked' : '') . '>'
            . '<span class="form-check-label">' . htmlescape(__('Production IP ranges only', 'nms')) . '</span></label>'
            . '<select class="form-select w-auto" name="category"><option value="">' . htmlescape(__('All categories', 'nms')) . '</option>';
        foreach ($report['categories'] as $cat) {
            $html .= '<option value="' . htmlescape($cat) . '"' . ($cat === $state['category'] ? ' selected' : '') . '>' . htmlescape($cat) . '</option>';
        }
        $html .= '</select><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'nms')) . '</button></form>';

        // Tabs
        $titles = self::titles();
        $html .= '<ul class="nav nav-tabs">';
        foreach (self::LISTS as $list) {
            $html .= '<li class="nav-item"><a class="nav-link' . ($list === $state['list'] ? ' active' : '') . '" href="'
                . htmlescape(self::url($base_url, ['list' => $list, 'page' => 1] + $state)) . '">'
                . htmlescape($titles[$list]) . ' (' . count($report['lists'][$list]) . ')</a></li>';
        }
        $html .= '</ul>';

        // Table of the current list, one page
        $rows  = $report['lists'][$state['list']] ?? [];
        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page  = min(max(1, $state['page']), $pages);
        $cols  = self::columns($state['list']);
        $html .= '<div class="card border-top-0"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>';
        foreach ($cols as $c) {
            $html .= '<th>' . htmlescape($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $row) {
            $html .= '<tr>';
            foreach (self::cells($state['list'], $row, true, $connections) as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($cols) . '" class="text-muted">' . htmlescape(__('Nothing to follow up', 'nms')) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div><div class="card-footer d-flex justify-content-between align-items-center">';
        $html .= '<div>';
        if ($page > 1) {
            $html .= '<a class="btn btn-sm btn-outline-secondary me-1" href="' . htmlescape(self::url($base_url, ['page' => $page - 1] + $state)) . '">&lsaquo;</a>';
        }
        $html .= '<span class="mx-2">' . htmlescape(sprintf(__('Page %1$d of %2$d', 'nms'), $page, $pages)) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="btn btn-sm btn-outline-secondary ms-1" href="' . htmlescape(self::url($base_url, ['page' => $page + 1] + $state)) . '">&rsaquo;</a>';
        }
        $html .= '</div><a class="btn btn-sm btn-outline-primary" href="' . htmlescape(self::url($base_url, ['export' => 'csv'] + $state)) . '">'
            . '<i class="ti ti-file-spreadsheet me-1"></i>' . htmlescape(__('Export CSV', 'nms')) . '</a></div></div></div>';

        return $html;
    }

    /**
     * Same columns as the table, without links: for the CSV export of the whole list.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public static function csvRows(string $list, array $rows, array $connections = []): array
    {
        return [
            'header' => self::columns($list),
            'rows'   => array_map(static fn (array $r) => self::cells($list, $r, false, $connections), $rows),
        ];
    }

    /**
     * Pure: the connections that may have monitored an asset of this entity.
     *
     * @param array<int, array{name: string, brand: string, scope: ?list<int>}> $connections
     */
    public static function expectedFrom(int $entities_id, array $connections): string
    {
        $names = [];
        foreach ($connections as $c) {
            if ($c['scope'] === null || in_array($entities_id, $c['scope'], true)) {
                $names[] = $c['name'];
            }
        }
        return $names === [] ? __('none', 'nms') : implode(', ', $names);
    }

    /** @param array<int, array{name: string, brand: string, scope: ?list<int>}> $connections */
    private static function connectionOptions(array $connections, int $picked): string
    {
        $out = '';
        foreach ($connections as $id => $c) {
            $out .= '<option value="' . (int) $id . '"' . ($id === $picked ? ' selected' : '') . '>' . htmlescape($c['name']) . '</option>';
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function titles(): array
    {
        return [
            'not_in_glpi'   => __('Not in GLPI', 'nms'),
            'not_monitored' => __('Not monitored', 'nms'),
            'ambiguous'     => __('Ambiguous', 'nms'),
            'name_differs'  => __('Name differs', 'nms'),
            'stale'         => __('Stale', 'nms'),
        ];
    }

    /** @return list<string> */
    private static function columns(string $list): array
    {
        return match ($list) {
            'not_monitored' => [__('GLPI asset', 'nms'), __('Type', 'nms'), __('Entity', 'nms'), __('IP addresses', 'nms'), __('Status', 'nms'), __('Expected from', 'nms')],
            'ambiguous'     => [__('Device', 'nms'), __('IP address', 'nms'), __('Category', 'nms'), __('Connection', 'nms')],
            'name_differs'  => [__('Device name', 'nms'), __('GLPI asset name', 'nms'), __('IP address', 'nms'), __('Connection', 'nms')],
            'stale'         => [__('Device', 'nms'), __('IP address', 'nms'), __('Last seen', 'nms'), __('Connection', 'nms')],
            default         => [__('Device', 'nms'), __('IP address', 'nms'), __('Category', 'nms'), __('Type', 'nms'), __('Last seen', 'nms'), __('Connection', 'nms')],
        };
    }

    /**
     * @param array<string, mixed> $r
     * @param array<int, array{name: string, brand: string, scope: ?list<int>}> $connections
     * @return list<string> HTML-escaped cells when $html, plain text otherwise
     */
    private static function cells(string $list, array $r, bool $html, array $connections = []): array
    {
        $c      = $connections[(int) ($r['connections_id'] ?? 0)] ?? null;
        $conn   = $c === null ? '' : $c['name'] . " \u{00B7} " . $c['brand'];
        $esc    = static fn ($v) => $html ? htmlescape((string) $v) : (string) $v;
        $device = (string) (($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['name'] ?? ''));
        $dev    = $html ? self::link('GlpiPlugin\\Nms\\Device', (int) ($r['id'] ?? 0), $device) : $device;
        return match ($list) {
            'not_monitored' => [
                $html ? self::link((string) $r['itemtype'], (int) $r['items_id'], (string) $r['name']) : (string) $r['name'],
                $esc(self::typeName((string) $r['itemtype'])),
                $esc($r['entity']),
                $esc(implode(', ', (array) $r['ips'])),
                $esc($r['state']),
                $esc(self::expectedFrom((int) ($r['entities_id'] ?? 0), $connections)),
            ],
            'ambiguous'    => [$dev, $esc($r['ip']), $esc($r['category']), $esc($conn)],
            'name_differs' => [$dev, $esc($r['asset_name'] ?? ''), $esc($r['ip']), $esc($conn)],
            'stale'        => [$dev, $esc($r['ip']), $esc($r['last_seen']), $esc($conn)],
            default        => [$dev, $esc($r['ip']), $esc($r['category']), $esc($r['type']), $esc($r['last_seen']), $esc($conn)],
        };
    }

    private static function link(string $class, int $id, string $label): string
    {
        if ($id > 0 && class_exists($class) && method_exists($class, 'getFormURLWithID')) {
            return '<a href="' . htmlescape($class::getFormURLWithID($id)) . '">' . htmlescape($label) . '</a>';
        }
        return htmlescape($label);
    }

    private static function typeName(string $itemtype): string
    {
        return class_exists($itemtype) && method_exists($itemtype, 'getTypeName') ? (string) $itemtype::getTypeName(1) : $itemtype;
    }

    /** @param array<string, mixed> $state */
    private static function url(string $base, array $state): string
    {
        $q = [
            'list'            => $state['list'],
            'page'            => (int) ($state['page'] ?? 1),
            'production_only' => !empty($state['production_only']) ? 1 : 0,
            'category'        => (string) ($state['category'] ?? ''),
            'connection'      => (int) ($state['connection'] ?? 0),
        ];
        if (isset($state['export'])) {
            $q['export'] = $state['export'];
            unset($q['page']);
        }
        return $base . '?' . http_build_query($q);
    }
}
