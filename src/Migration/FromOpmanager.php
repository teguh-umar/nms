<?php

namespace GlpiPlugin\Nms\Migration;

use GlpiPlugin\Nms\Connection\ConnectionStore;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;

/**
 * One-shot, idempotent migration from opmanager into a single disabled OpManager
 * connection. Never writes to an opmanager table or config key.
 *
 * @param object $db DB-like connection (request/insert/update/tableExists)
 * @param string $now 'Y-m-d H:i:s'
 * @return array{connections_id: int, counts: array<string, int>, rights_copied: int}
 *         or ['skipped' => 'opmanager not installed']
 */
final class FromOpmanager
{
    public const CONNECTION_NAME = 'OpManager (migrated)';

    /** Check whether opmanager tables exist (used by UI to show the migration button). */
    public static function isInstalled($db): bool
    {
        return $db->tableExists('glpi_plugin_opmanager_devices');
    }

    /** src table => natural key column(s) (string or list) */
    private const TABLES = [
        'devices'             => 'device_key',
        'alarms'              => 'alarm_key',
        'outbox'              => ['alarm_key', 'action', 'date_creation'],
        'storms'              => 'tickets_id',
        'ticketrequests'      => 'request_key',
        'deviceexceptions'    => ['device_key', 'date_creation'],
        'hygienetickets'      => 'tickets_id',
        'hygieneitems'        => ['ticket', 'device_key'],
    ];

    /** Config keys moved per connection (Task 5) that are copied into settings. */
    private const MOVED_KEYS = [
        'match_mode', 'coverage_states',
        'ticket_severities', 'ticket_source', 'ticket_template', 'ticket_title_pattern', 'ticket_content_pattern',
        'service_user', 'ticket_entity_default', 'ticket_use_ipnetwork_entity',
        'relink_window_hours', 'storm_threshold', 'storm_window_minutes',
        'outbound_max_attempts', 'production_addressings',
        'ticket_filter_mode', 'ticket_filter_event_types', 'ticket_filter_keywords',
        'hygiene_enabled', 'hygiene_frequency_days', 'hygiene_unmanaged_availabilities',
        'hygiene_agent_stale_days', 'hygiene_mist_stale_days', 'hygiene_probe_cidrs',
        'hygiene_probe_tcp_ports', 'hygiene_probe_timeout_ms', 'hygiene_ticket_template',
        'hygiene_glpi_groups', 'hygiene_glpi_users', 'hygiene_itil_category', 'hygiene_nms_group',
        'last_hygiene_run', 'last_hygiene_ticket_run', 'last_hygiene_error',
    ];

    /** opmanager profile right => nms profile right */
    private const RIGHT_MAP = [
        'config'     => 'plugin_nms_config',
        'monitoring' => 'plugin_nms_monitoring',
        'hygiene'    => 'plugin_nms_hygiene',
    ];

    /** Columns managed by DeviceSync/Hygiene/cron - never compared for changes. */
    private const SKIP_COLS = [
        'date_mod', 'date_creation', 'connections_id',
        'last_seen', 'liveness_state', 'liveness_source', 'liveness_detail',
        'liveness_checked_at', 'hygiene_state', 'match_state', 'match_source',
        'is_manual', 'itemtype', 'items_id',
    ];

    /** @var array<string, array<string, mixed>> opmanager config row (key => value) */
    private static array $configRow = [];

    public static function run($db, string $now): array
    {
        if (!$db->tableExists('glpi_plugin_opmanager_devices')) {
            return ['skipped' => 'opmanager not installed'];
        }

        self::$configRow = self::fetchConfigRow($db);

        $store = new DbConnectionStore();
        $conn  = self::findOrCreateConnection($db, $store, $now);
        $cid   = (int) $conn->id;

        // Copy per-connection settings into the connection row.
        $settings = Settings::fromJson((string) $conn->settings)->with(self::movedSettings());
        $store->save(array_merge((array) $conn, ['settings' => $settings->toJson()]));

        // Upsert every opmanager table into the nms tables, scoped by connections_id.
        $counts = [];
        foreach (self::TABLES as $srcTable => $keyCols) {
            $dstTable = 'glpi_plugin_nms_' . $srcTable;
            if (!$db->tableExists($dstTable)) {
                $counts[$srcTable] = 0;
                continue;
            }
            $counts[$srcTable] = self::upsertTable($db, $srcTable, $dstTable, $keyCols, $cid, $now);
        }

        // Rights: copy opmanager profile rights into nms rights, only where still 0.
        $rightsCopied = self::copyProfileRights($db);

        return [
            'connections_id' => $cid,
            'counts'         => $counts,
            'rights_copied'  => $rightsCopied,
        ];
    }

    /** @return array|null existing migration connection row, or null */
    private static function findConnection($db): ?array
    {
        $row = $db->request([
            'SELECT' => ['*'],
            'FROM'   => 'glpi_plugin_nms_connections',
            'WHERE'  => ['name' => self::CONNECTION_NAME, 'brand' => 'opmanager'],
            'LIMIT'  => 1,
        ])->current();
        return $row ? (array) $row : null;
    }

    /**
     * Find the migration connection or create it (disabled). Entity = lowest common
     * ancestor of the entities of linked assets; root entity (0) if there are none.
     */
    private static function findOrCreateConnection($db, ConnectionStore $store, string $now): \GlpiPlugin\Nms\Connection\Connection
    {
        $existing = self::findConnection($db);
        if ($existing !== null) {
            return new \GlpiPlugin\Nms\Connection\Connection($existing);
        }

        $entity = self::lowestCommonAncestorEntity($db);
        $enc    = new \GLPIKey();

        $fields = [
            'name'             => self::CONNECTION_NAME,
            'brand'            => 'opmanager',
            'entities_id'      => (int) $entity,
            'is_recursive'     => 1,
            'url'              => rtrim((string) (self::$configRow['opm_url'] ?? ''), '/'),
            'api_key'          => $enc->encrypt(self::decrypt($enc, (string) (self::$configRow['opm_api_key'] ?? ''))),
            'username'         => '',
            'password'         => '',
            'verify_tls'       => ((int) (self::$configRow['opm_verify_ssl'] ?? 1) === 1) ? 1 : 0,
            'is_active'      => 0,
            'writeback_enabled'=> 1,
            'tickets_enabled' => 1,
            'webhook_token'   => $enc->encrypt(self::decrypt($enc, (string) (self::$configRow['webhook_token'] ?? ''))),
            'settings'        => '{}',
            'date_creation'   => $now,
            'date_mod'        => $now,
        ];

        $id = $store->save($fields);
        $got = $store->get($id);
        if ($got === null) {
            throw new \RuntimeException('migration connection not created');
        }
        return $got;
    }

    /** Entity: lowest configured entities_id among linked devices; 0 if none. */
    private static function lowestCommonAncestorEntity($db): int
    {
        $entities = [];
        foreach ($db->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_plugin_opmanager_devices',
            'WHERE'  => ['items_id' => ['>', 0]],
            'GROUPBY'=> 'entities_id',
        ]) as $row) {
            $e = (int) $row['entities_id'];
            if ($e > 0) {
                $entities[] = $e;
            }
        }
        return $entities === [] ? 0 : min($entities);
    }

    /** @return array<string, string> the opmanager config row (single row) */
    private static function fetchConfigRow($db): array
    {
        $row = $db->request(['SELECT' => ['*'], 'FROM' => 'glpi_plugin_opmanager_config', 'LIMIT' => 1])->current();
        return $row ? (array) $row : [];
    }

    /** Decrypt a possibly-empty value; returns '' on failure. */
    private static function decrypt(\GLPIKey $enc, string $v): string
    {
        if ($v === '') {
            return '';
        }
        $d = $enc->decrypt($v);
        return $d === false || $d === null ? '' : (string) $d;
    }

    /** @return array<string, string> moved settings present in the opmanager config row */
    private static function movedSettings(): array
    {
        $out = [];
        foreach (self::MOVED_KEYS as $k) {
            if (array_key_exists($k, self::$configRow) && self::$configRow[$k] !== '') {
                $out[$k] = (string) self::$configRow[$k];
            }
        }
        return $out;
    }

    /**
     * Upsert one opmanager table into its nms counterpart, scoped by connections_id.
     * Natural key: $keyCols (string or [col1, col2, ...]). Insert if absent, update if changed.
     * @return int rows inserted or updated
     */
    private static function upsertTable($db, string $srcTable, string $dstTable, $keyCols, int $cid, string $now): int
    {
        $keys = is_array($keyCols) ? $keyCols : [$keyCols];

        $rows = [];
        $res = $db->request(['SELECT' => ['*'], 'FROM' => 'glpi_plugin_opmanager_' . $srcTable]);
        foreach ($res as $r) {
            $rows[] = $r;
        }

        $count = 0;
        foreach ($rows as $row) {
            $where = ['connections_id' => $cid];
            foreach ($keys as $k) {
                $where[$k] = $row[$k] ?? '';
            }

            $existing = $db->request([
                'SELECT' => ['id'],
                'FROM'   => $dstTable,
                'WHERE'  => $where,
                'LIMIT'  => 1,
            ])->current();

            $fields = [];
            foreach ($row as $col => $val) {
                if ($col === 'id' || in_array($col, $keys, true)) {
                    continue;
                }
                $fields[$col] = $val;
            }
            $fields['connections_id'] = $cid;
            $fields['date_mod'] = $now;

            if (!is_array($existing)) {
                // Insert: natural keys are used in WHERE, so add them to the row too.
                foreach ($keys as $k) {
                    $fields[$k] = $row[$k] ?? '';
                }
                // date_creation is only a system column when it's not part of the natural key.
                if (!in_array('date_creation', $keys, true)) {
                    $fields['date_creation'] = $now;
                }
                $db->insert($dstTable, $fields);
                $count++;
            } else {
                // Only update when something actually changed (idempotency).
                $existingRow = $db->request([
                    'SELECT' => ['*'],
                    'FROM'   => $dstTable,
                    'WHERE'  => ['id' => (int) $existing['id'], 'connections_id' => $cid],
                    'LIMIT'  => 1,
                ])->current();
                $changed = false;
                if (is_array($existingRow)) {
                    foreach ($fields as $col => $val) {
                        if (in_array($col, self::SKIP_COLS, true)) {
                            continue;
                        }
                        // Only compare columns that exist in the nms row.
                        if (!array_key_exists($col, $existingRow)) {
                            continue;
                        }
                        $cur = $existingRow[$col] ?? null;
                        if ((string) $cur !== (string) $val) {
                            $changed = true;
                            break;
                        }
                    }
                } else {
                    $changed = true;
                }
                if ($changed) {
                    $db->update($dstTable, $fields, array_merge(['id' => (int) $existing['id']], ['connections_id' => $cid]));
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Copy opmanager profile rights into nms rights, only where the nms right is still 0.
     * @return int number of right grants written
     */
    private static function copyProfileRights($db): int
    {
        $updated = 0;
        $profiles = $db->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']);
        foreach ($profiles as $p) {
            $pid = (int) $p['id'];
            foreach (self::RIGHT_MAP as $srcRight => $dstRight) {
                $src = $db->request([
                    'SELECT' => ['rights'],
                    'FROM'   => 'glpi_profilerights',
                    'WHERE'  => ['profiles_id' => $pid, 'name' => $srcRight],
                    'LIMIT'  => 1,
                ])->current();
                $srcMask = is_array($src) ? (int) ($src['rights'] ?? 0) : 0;
                if ($srcMask === 0) {
                    continue;
                }
                $dst = $db->request([
                    'SELECT' => ['rights'],
                    'FROM'   => 'glpi_profilerights',
                    'WHERE'  => ['profiles_id' => $pid, 'name' => $dstRight],
                    'LIMIT'  => 1,
                ])->current();
                $dstMask = is_array($dst) ? (int) ($dst['rights'] ?? 0) : 0;
                if ($dstMask !== 0) {
                    continue; // only where the nms right is still 0
                }
                $data = ['profiles_id' => $pid, 'name' => $dstRight, 'rights' => $srcMask];
                if ($dst === false) {
                    $db->insert('glpi_profilerights', $data);
                } else {
                    $db->update('glpi_profilerights', ['rights' => $srcMask], ['profiles_id' => $pid, 'name' => $dstRight]);
                }
                $updated++;
            }
        }
        return $updated;
    }
}
