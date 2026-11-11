<?php

namespace GlpiPlugin\Nms\Coverage;

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\RightDefinition;

/**
 * Native GLPI dashboard cards (D28 addendum). Core renders card providers WITHOUT a right
 * check (integrasipaman lesson, hub §7): every provider and the card list check
 * plugin_nms_monitoring READ themselves. Figures come from the same Report as the
 * Coverage page, cached per user and entity set for five minutes.
 */
final class Cards
{
    private const TTL = 300;

    private const GROUP = 'OpManager';

    /**
     * Hook target: $PLUGIN_HOOKS['dashboard_cards']['nms'].
     *
     * @param array<string, mixed>|null $cards
     * @return array<string, mixed>
     */
    public static function cards($cards = []): array
    {
        $cards = is_array($cards) ? $cards : [];
        if (!self::allowed()) {
            return $cards;
        }
        $defs = [
            'coverage'         => [['bigNumber'], __('OpManager coverage of GLPI assets (%)', 'nms')],
            'devices'          => [['bigNumber'], __('OpManager devices', 'nms')],
            'notMonitored'     => [['bigNumber'], __('GLPI assets not monitored', 'nms')],
            'notInGlpi'        => [['bigNumber'], __('OpManager devices not in GLPI', 'nms')],
            'matching'         => [['pie', 'donut', 'halfpie'], __('OpManager device matching', 'nms')],
            'byCategory'       => [['stackedbars', 'stackedHBars', 'bars'], __('OpManager devices by category', 'nms')],
            'alarmsBySeverity' => [['pie', 'donut', 'halfpie', 'bar'], __('Active OpManager alarms by severity', 'nms')],
            'openAlarmTickets' => [['bigNumber'], __('Open tickets from OpManager alarms', 'nms')],
        ];
        foreach ($defs as $method => [$types, $label]) {
            $cards['plugin_nms_' . strtolower($method)] = [
                'widgettype' => $types,
                'group'      => self::GROUP,
                'label'      => $label,
                'provider'   => self::class . '::' . $method,
            ];
        }
        return $cards;
    }

    public static function coverage(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['coverage_pct' => 0];
        return self::big(__('Coverage %', 'nms'), $kpi['coverage_pct'], 'ti ti-chart-pie', 'not_monitored');
    }

    public static function devices(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['devices' => 0];
        return self::big(__('OpManager devices', 'nms'), $kpi['devices'], 'ti ti-activity', 'not_in_glpi');
    }

    public static function notMonitored(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['not_monitored' => 0];
        return self::big(__('GLPI assets not monitored', 'nms'), $kpi['not_monitored'], 'ti ti-eye-off', 'not_monitored');
    }

    public static function notInGlpi(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['not_in_glpi' => 0];
        return self::big(__('OpManager devices not in GLPI', 'nms'), $kpi['not_in_glpi'], 'ti ti-database-off', 'not_in_glpi');
    }

    public static function matching(array $params = []): array
    {
        $out = ['data' => [], 'label' => __('OpManager device matching', 'nms'), 'icon' => 'ti ti-link'];
        if (!self::allowed()) {
            return $out;
        }
        $k = self::summary()['kpi'];
        $slices = [
            [__('Matched', 'nms'), $k['matched'], 'not_monitored'],
            [__('Not in GLPI', 'nms'), $k['not_in_glpi'], 'not_in_glpi'],
            [__('Ambiguous', 'nms'), $k['ambiguous'], 'ambiguous'],
        ];
        foreach ($slices as [$label, $n, $list]) {
            $out['data'][] = ['number' => $n, 'label' => $label, 'url' => self::url($list)];
        }
        return $out;
    }

    public static function byCategory(array $params = []): array
    {
        return [
            'data'  => self::allowed() ? self::categoryBars(self::summary()['by_category']) : ['labels' => [], 'series' => []],
            'label' => __('OpManager devices by category', 'nms'),
            'icon'  => 'ti ti-category',
        ];
    }

    public static function alarmsBySeverity(array $params = []): array
    {
        $out = ['data' => [], 'label' => __('Active OpManager alarms by severity', 'nms'), 'icon' => 'ti ti-bell'];
        if (!self::allowed()) {
            return $out;
        }
        foreach (self::summary()['alarms'] as $label => $n) {
            $out['data'][] = ['number' => $n, 'label' => $label, 'url' => \GlpiPlugin\Nms\Alarm::getSearchURL()];
        }
        return $out;
    }

    public static function openAlarmTickets(array $params = []): array
    {
        $n = self::allowed() ? self::summary()['open_tickets'] : 0;
        return ['number' => $n, 'label' => __('Open tickets from OpManager alarms', 'nms'), 'icon' => 'ti ti-ticket'];
    }

    /**
     * @param array<string, array{matched: int, unmatched: int, review: int}> $by_category
     * @return array{labels: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    public static function categoryBars(array $by_category): array
    {
        $series = [];
        foreach (['matched' => __('Matched', 'nms'), 'unmatched' => __('Not in GLPI', 'nms'), 'review' => __('Ambiguous', 'nms')] as $key => $name) {
            $series[] = ['name' => $name, 'data' => array_values(array_map(static fn ($c) => $c[$key], $by_category))];
        }
        return ['labels' => array_map('strval', array_keys($by_category)), 'series' => $series];
    }

    /** @param array<string, array{at: int, value: mixed}> $store */
    public static function cached(array &$store, string $key, int $ttl, int $now, callable $compute): mixed
    {
        if (!isset($store[$key]) || $now - $store[$key]['at'] >= $ttl) {
            $store[$key] = ['at' => $now, 'value' => $compute()];
        }
        return $store[$key]['value'];
    }

    private static function allowed(): bool
    {
        return \Session::haveRight(RightDefinition::MONITORING, READ);
    }

    /** @return array{kpi: array<string, int>, by_category: array, alarms: array<string, int>, open_tickets: int} */
    private static function summary(): array
    {
        $_SESSION['plugin_nms_cards'] ??= [];
        $key = md5((string) json_encode($_SESSION['glpiactiveentities'] ?? []));
        return self::cached($_SESSION['plugin_nms_cards'], $key, self::TTL, time(), static function (): array {
            $src     = new DbCoverageSource();
            $devices = $src->devices();
            $report  = Report::build($devices, $src->assets(Config::assetItemtypes(), Config::coverageStates()), [
                'ranges'           => [],
                'production_only'  => false,
                'category'         => '',
                'last_device_sync' => Config::get('last_device_sync'),
                'stale_minutes'    => max(1, (int) Config::get('stale_warning_minutes')),
            ]);
            return [
                'kpi'          => $report['kpi'],
                'by_category'  => Report::byCategory($devices),
                'alarms'       => $src->alarmsBySeverity(),
                'open_tickets' => $src->openAlarmTickets(),
            ];
        });
    }

    private static function big(string $label, int $number, string $icon, string $list): array
    {
        return ['number' => $number, 'label' => $label, 'icon' => $icon, 'url' => self::url($list)];
    }

    private static function url(string $list): string
    {
        return Page::getSearchURL(false) . '?list=' . rawurlencode($list);
    }
}
