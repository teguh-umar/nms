<?php

namespace GlpiPlugin\Nms\Migration;

/**
 * Core GLPI rows that name opmanager's itemtypes or dashboard cards, carried over to nms the way
 * GLPI 11 moved GenericObject types to Asset definitions: without this, column choices, saved
 * searches and dashboard widgets break once opmanager is gone.
 *
 * - Column choices (glpi_displaypreferences), saved searches (glpi_savedsearches) and default
 *   searches (glpi_savedsearches_users) are COPIED to the nms itemtypes, with opmanager's search
 *   option numbers mapped to nms's. opmanager's own rows stay as they are.
 * - Dashboard widgets (glpi_dashboards_items) are switched IN PLACE from plugin_opmanager_* cards to
 *   the same plugin_nms_* card, and only while opmanager is not active: an active opmanager still
 *   draws its own cards, and a dashboard cannot show both in one grid slot. Runbook step 5 deactivates
 *   opmanager and runs the copy again, which switches them then.
 *
 * Idempotent: a second run copies nothing and finds no opmanager card left.
 * Never touches glpi_plugin_opmanager_* or glpi_configs.
 */
final class CoreReferences
{
    /** opmanager itemtype => [nms itemtype, opmanager search option => nms search option] */
    public const ITEMTYPES = [
        'GlpiPlugin\\Opmanager\\Device' => ['GlpiPlugin\\Nms\\Device', [
            1 => 8380, 2 => 8381, 3 => 8382, 4 => 8383, 5 => 8384, 6 => 8385, 7 => 8386,
            8 => 8387, 9 => 8388, 10 => 8389, 11 => 8390, 12 => 8391, 13 => 8392,
        ]],
        'GlpiPlugin\\Opmanager\\Alarm' => ['GlpiPlugin\\Nms\\Alarm', [
            1 => 8380, 2 => 8381, 3 => 8382, 4 => 8383, 5 => 8384, 6 => 8385, 7 => 8386,
            8 => 8387, 9 => 8388,
        ]],
    ];

    /** Dashboard card suffixes; opmanager's key is plugin_opmanager_<x>, nms's plugin_nms_<x>. */
    public const CARDS = [
        'coverage', 'devices', 'notmonitored', 'notinglpi', 'matching', 'bycategory',
        'alarmsbyseverity', 'openalarmtickets',
    ];

    /**
     * @return array{displaypreferences: int, savedsearches: int, default_searches: int, dashboard_cards: int, dashboard_cards_waiting: int}
     */
    public static function copy($db): array
    {
        $out = ['displaypreferences' => 0, 'savedsearches' => 0, 'default_searches' => 0, 'dashboard_cards' => 0, 'dashboard_cards_waiting' => 0];

        if ($db->tableExists('glpi_displaypreferences')) {
            $out['displaypreferences'] = self::displayPreferences($db);
        }
        $idMap = [];
        if ($db->tableExists('glpi_savedsearches')) {
            $out['savedsearches'] = self::savedSearches($db, $idMap);
        }
        if ($db->tableExists('glpi_savedsearches_users')) {
            $out['default_searches'] = self::defaultSearches($db, $idMap);
        }
        if ($db->tableExists('glpi_dashboards_items')) {
            $cards = self::dashboardCards($db, !self::opmanagerActive($db));
            $out[$cards[0] ? 'dashboard_cards' : 'dashboard_cards_waiting'] = $cards[1];
        }
        return $out;
    }

    private static function displayPreferences($db): int
    {
        $n = 0;
        foreach (self::ITEMTYPES as $old => [$new, $map]) {
            foreach ($db->request(['FROM' => 'glpi_displaypreferences', 'WHERE' => ['itemtype' => $old]]) as $row) {
                $num = $map[(int) $row['num']] ?? null;
                if ($num === null) {
                    continue; // an option nms does not have: leave it out
                }
                $key = [
                    'users_id'  => (int) $row['users_id'],
                    'itemtype'  => $new,
                    'num'       => $num,
                    'interface' => (string) ($row['interface'] ?? 'central'),
                ];
                if (count($db->request(['FROM' => 'glpi_displaypreferences', 'WHERE' => $key, 'LIMIT' => 1])) > 0) {
                    continue;
                }
                $db->insert('glpi_displaypreferences', $key + ['rank' => (int) $row['rank']]);
                $n++;
            }
        }
        return $n;
    }

    /** @param array<int, int> $idMap filled: opmanager saved search id => nms saved search id */
    private static function savedSearches($db, array &$idMap): int
    {
        $n = 0;
        foreach (self::ITEMTYPES as $old => [$new, $map]) {
            foreach ($db->request(['FROM' => 'glpi_savedsearches', 'WHERE' => ['itemtype' => $old]]) as $row) {
                $key = [
                    'users_id'    => (int) $row['users_id'],
                    'itemtype'    => $new,
                    'name'        => $row['name'],
                    'is_private'  => (int) $row['is_private'],
                    'entities_id' => (int) $row['entities_id'],
                ];
                $existing = $db->request(['FROM' => 'glpi_savedsearches', 'WHERE' => $key, 'LIMIT' => 1])->current();
                if ($existing) {
                    $idMap[(int) $row['id']] = (int) $existing['id'];
                    continue;
                }
                $db->insert('glpi_savedsearches', $key + [
                    'type'         => (int) $row['type'],
                    'is_recursive' => (int) $row['is_recursive'],
                    'query'        => self::mapQuery((string) ($row['query'] ?? '')),
                    'do_count'     => (int) $row['do_count'],
                    'counter'      => 0,
                ]);
                $idMap[(int) $row['id']] = (int) $db->insertId();
                $n++;
            }
        }
        return $n;
    }

    /** @param array<int, int> $idMap */
    private static function defaultSearches($db, array $idMap): int
    {
        $n = 0;
        foreach (self::ITEMTYPES as $old => [$new]) {
            foreach ($db->request(['FROM' => 'glpi_savedsearches_users', 'WHERE' => ['itemtype' => $old]]) as $row) {
                $target = $idMap[(int) $row['savedsearches_id']] ?? null;
                if ($target === null) {
                    continue;
                }
                $key = ['users_id' => (int) $row['users_id'], 'itemtype' => $new];
                if (count($db->request(['FROM' => 'glpi_savedsearches_users', 'WHERE' => $key, 'LIMIT' => 1])) > 0) {
                    continue; // the user already chose a default for the nms list
                }
                $db->insert('glpi_savedsearches_users', $key + ['savedsearches_id' => $target]);
                $n++;
            }
        }
        return $n;
    }

    /** @return array{0: bool, 1: int} [switched, number of widgets switched or waiting] */
    private static function dashboardCards($db, bool $switch): array
    {
        $oldKeys = array_map(static fn (string $c): string => 'plugin_opmanager_' . $c, self::CARDS);
        $n = 0;
        foreach ($db->request(['FROM' => 'glpi_dashboards_items', 'WHERE' => ['card_id' => $oldKeys]]) as $row) {
            $n++;
            if (!$switch) {
                continue;
            }
            $from = (string) $row['card_id'];
            $to   = 'plugin_nms_' . substr($from, strlen('plugin_opmanager_'));
            $db->update('glpi_dashboards_items', [
                'card_id'      => $to,
                'gridstack_id' => str_starts_with((string) $row['gridstack_id'], $from)
                    ? $to . substr((string) $row['gridstack_id'], strlen($from))
                    : (string) $row['gridstack_id'],
                'card_options' => str_replace('"' . $from . '"', '"' . $to . '"', (string) ($row['card_options'] ?? '')),
            ], ['id' => (int) $row['id']]);
        }
        return [$switch, $n];
    }

    private static function opmanagerActive($db): bool
    {
        if (!$db->tableExists('glpi_plugins')) {
            return false;
        }
        // \Plugin::ACTIVATED = 1
        return count($db->request(['FROM' => 'glpi_plugins', 'WHERE' => ['directory' => 'opmanager', 'state' => 1], 'LIMIT' => 1])) > 0;
    }

    /**
     * A saved search query string with opmanager's itemtypes and search option numbers turned into
     * nms's: itemtype, criteria (nested groups and meta criteria too) and sort.
     */
    public static function mapQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $q);
        $main = (string) ($q['itemtype'] ?? '');
        $mainMap = self::ITEMTYPES[$main][1] ?? [];
        if (isset(self::ITEMTYPES[$main])) {
            $q['itemtype'] = self::ITEMTYPES[$main][0];
        }
        if (isset($q['criteria']) && is_array($q['criteria'])) {
            $q['criteria'] = self::mapCriteria($q['criteria'], $mainMap);
        }
        if (isset($q['sort'])) {
            $q['sort'] = is_array($q['sort'])
                ? array_map(static fn ($s) => self::mapField($s, $mainMap), $q['sort'])
                : self::mapField($q['sort'], $mainMap);
        }
        return http_build_query($q);
    }

    /** @param array<int, int> $map */
    private static function mapCriteria(array $criteria, array $map): array
    {
        foreach ($criteria as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $m = $map;
            if (isset($c['itemtype'])) {
                // Meta criterion on another itemtype: map only when that itemtype is opmanager's.
                $m = self::ITEMTYPES[$c['itemtype']][1] ?? [];
                if (isset(self::ITEMTYPES[$c['itemtype']])) {
                    $c['itemtype'] = self::ITEMTYPES[$c['itemtype']][0];
                }
            }
            if (isset($c['criteria']) && is_array($c['criteria'])) {
                $c['criteria'] = self::mapCriteria($c['criteria'], $m);
            }
            if (isset($c['field'])) {
                $c['field'] = self::mapField($c['field'], $m);
            }
            $criteria[$i] = $c;
        }
        return $criteria;
    }

    /** A numeric option opmanager had becomes nms's number; 'view', 'all' and others stay. */
    private static function mapField($field, array $map)
    {
        return (is_numeric($field) && isset($map[(int) $field])) ? (string) $map[(int) $field] : $field;
    }
}
