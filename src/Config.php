<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\Settings;

final class Config
{
    public const CONTEXT = 'plugin:nms';

    /**
     * Every accepted match mode, in dropdown order. The ONLY list: the dropdown,
     * Matcher and front/config.php all read it (integrasipaman v2.6.0 shipped a
     * mode its save controller silently rejected - hub §4).
     */
    public const MATCH_MODES = ['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual'];

    /** Encrypted at rest by core via $PLUGIN_HOOKS['secured_configs'] (GLPIKey::getConfigs).
     * Only global config keys that exist in DEFAULTS; per-connection secrets are
     * encrypted/decrypted by the plugin via GLPIKey, not by core. */
    public const SECURED = [];

    public const WEBHOOK_HEADER = 'X-NMS-Token';

    public const WEBHOOK_PATH = '/plugins/nms/front/webhook.php';

    public const BASE_ASSET_ITEMTYPES = ['Computer', 'NetworkEquipment'];

    /**
     * DeviceSync frequency. The stale warning default must stay above it, or every
     * Monitoring section warns for part of each cycle (final review, 2026-09-29).
     */
    public const DEVICE_SYNC_MINUTES = 60;

    /** AlarmSync frequency (spec §3). */
    public const ALARM_SYNC_MINUTES = 10;

    /** OpManager numericSeverity values a ticket may be created for (D19); 5 = Clear never is. */
    public const SEVERITIES = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down'];

    public const DEFAULT_TITLE_PATTERN = '[OPM-{alarm_key}] {severity}: {message} - {device}';

    /**
     * "Category: {category}" carries OpManager's device category (Server, Switch,
     * Firewall, ...) so GLPI's own ticket business rules can map it to an ITIL
     * category (user decision 2026-10-01, rule criterion: Description contains
     * "Category: Server").
     */
    public const DEFAULT_CONTENT_PATTERN = "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nCategory: {category}\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}";

    /** Earlier defaults; an install still using one of them is upgraded, a customised pattern never is. */
    public const PREVIOUS_CONTENT_PATTERNS = [
        "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}",
    ];

    /** Alarm filter modes; same list as Ticketing\AlarmFilter::MODES (first = default). */
    public const FILTER_MODES = ['exclude', 'include'];

    /** D32: who decides that an alarm becomes a ticket (first = default, also on upgrade). */
    public const TICKET_SOURCES = ['webhook', 'rules'];

    public const FILTER_KEYWORDS_MAX = 2000;

    public const DEFAULTS = [
        // Config keeps only these global keys (spec Plan 1 Task 5); every other
        // 1.3.2 default moved per connection lives in Connection\Settings::DEFAULTS.
        'asset_itemtypes'        => '["Computer","NetworkEquipment"]',
        'stale_warning_minutes'  => '90',
        // Sync status lives on each connection row (last_device_sync, last_alarm_sync,
        // last_status, last_error), never here.
    ];

    /** HygieneCheck frequency: daily; tickets follow hygiene_frequency_days (spec §7). */
    public const HYGIENE_CRON_MINUTES = 1440;

    public static function get(string $key): string
    {
        $values = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return (string) ($values[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** @param array<string, string> $values */
    public static function set(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function newWebhookToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function setDefaults(): void
    {
        $current = \Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /** Pure: CRLF / CR -> LF (a browser textarea posts CRLF). */
    public static function normaliseLines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** Pure: the pattern to store on upgrade, or null to keep the current one. */
    public static function upgradedContentPattern(string $current): ?string
    {
        return in_array(self::normaliseLines($current), self::PREVIOUS_CONTENT_PATTERNS, true) ? self::DEFAULT_CONTENT_PATTERN : null;
    }

    /** @return array<string, string> mode => label, keys identical to MATCH_MODES */
    public static function matchModeLabels(): array
    {
        return [
            'ip_only'     => __('IP only', 'nms'),
            'name_only'   => __('Name only', 'nms'),
            'hybrid'      => __('Hybrid - IP first, then name', 'nms'),
            'hybrid_name' => __('Hybrid - name first, then IP', 'nms'),
            'manual'      => __('Manual only - no automatic matching', 'nms'),
        ];
    }

    /**
     * Validate a POST from the Setup form. Pure. A key missing from `values`
     * means "keep what is stored" (e.g. a blank API key field).
     *
     * @param array<string, mixed> $post
     * @param string[]             $allowed_itemtypes itemtypes the form offered
     * @return array{values: array<string, string>, errors: list<string>}
     */
    /**
     * The global Setup keys only (asset_itemtypes, stale_warning_minutes).
     *
     * @param list<string> $allowed_itemtypes
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeGlobal(array $post, array $allowed_itemtypes): array
    {
        $values = [];
        $errors = [];
        $posted = array_filter((array) ($post['asset_itemtypes'] ?? []), 'is_string');
        $values['asset_itemtypes'] = (string) json_encode(array_values(array_intersect($posted, $allowed_itemtypes)));
        $stale = (int) ($post['stale_warning_minutes'] ?? 0);
        if ($stale >= 5 && $stale <= 1440) {
            $values['stale_warning_minutes'] = (string) $stale;
        } else {
            $errors[] = __('Stale warning must be between 5 and 1440 minutes', 'nms');
        }
        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Validate the ticketing part of the Setup form. Pure; a key missing from
     * `values` means "keep what is stored".
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeTicketing(array $post): array
    {
        $values = [];
        $errors = [];

        $source = (string) ($post['ticket_source'] ?? '');
        $values['ticket_source'] = in_array($source, self::TICKET_SOURCES, true) ? $source : self::TICKET_SOURCES[0];

        $sev = array_values(array_intersect(
            array_map('intval', (array) ($post['ticket_severities'] ?? [])),
            array_keys(self::SEVERITIES)
        ));
        sort($sev);
        $values['ticket_severities'] = (string) json_encode($sev);

        foreach (['ticket_template', 'service_user', 'ticket_entity_default'] as $key) {
            $values[$key] = (string) max(0, (int) ($post[$key] ?? 0));
        }

        foreach (['ticket_title_pattern' => 250, 'ticket_content_pattern' => 5000] as $key => $max) {
            $v = trim(self::normaliseLines((string) ($post[$key] ?? '')));
            if ($v === '' || strlen($v) > $max) {
                $errors[] = sprintf(__('%s must be 1 to %d characters', 'nms'), $key, $max);
            } else {
                $values[$key] = $v;
            }
        }

        $values['ticket_use_ipnetwork_entity'] = empty($post['ticket_use_ipnetwork_entity']) ? '0' : '1';

        $ranges = array_values(array_filter(array_map('intval', (array) ($post['production_addressings'] ?? [])), static fn (int $id) => $id > 0));
        $values['production_addressings'] = (string) json_encode($ranges);

        $mode = (string) ($post['ticket_filter_mode'] ?? '');
        $values['ticket_filter_mode'] = in_array($mode, self::FILTER_MODES, true) ? $mode : self::FILTER_MODES[0];
        $types = [];
        foreach ((array) ($post['ticket_filter_event_types'] ?? []) as $type) {
            $type = trim((string) $type);
            if ($type !== '' && strlen($type) <= 255) {
                $types[$type] = true;
            }
        }
        $values['ticket_filter_event_types'] = (string) json_encode(array_keys($types));
        $keywords = implode("\n", self::lines((string) ($post['ticket_filter_keywords'] ?? '')));
        if (strlen($keywords) > self::FILTER_KEYWORDS_MAX) {
            $errors[] = sprintf(__('%s must be at most %d characters', 'nms'), 'ticket_filter_keywords', self::FILTER_KEYWORDS_MAX);
        } else {
            $values['ticket_filter_keywords'] = $keywords;
        }

        foreach (['relink_window_hours' => [0, 720], 'storm_threshold' => [0, 1000], 'storm_window_minutes' => [1, 120], 'outbound_max_attempts' => [1, 50]] as $key => [$min, $max]) {
            $n = (int) ($post[$key] ?? -1);
            if ($n < $min || $n > $max) {
                $errors[] = sprintf(__('%s must be between %d and %d', 'nms'), $key, $min, $max);
            } else {
                $values[$key] = (string) $n;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Validate the NMS hygiene part of the Setup form (D34, spec §8.4). Pure; an
     * invalid field is an error and keeps its stored value.
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeHygiene(array $post): array
    {
        $values = [];
        $errors = [];

        foreach (['hygiene_frequency_days' => [1, 90], 'hygiene_agent_stale_days' => [1, 365], 'hygiene_mist_stale_days' => [1, 365], 'hygiene_probe_timeout_ms' => [100, 5000]] as $key => [$min, $max]) {
            $n = (int) ($post[$key] ?? -1);
            if ($n < $min || $n > $max) {
                $errors[] = sprintf(__('%s must be between %d and %d', 'nms'), $key, $min, $max);
            } else {
                $values[$key] = (string) $n;
            }
        }

        $unmanaged = self::lines((string) ($post['hygiene_unmanaged_availabilities'] ?? ''));
        if ($unmanaged === []) {
            $errors[] = __('List at least one OpManager availability that means "not monitored"', 'nms');
        } else {
            $values['hygiene_unmanaged_availabilities'] = (string) json_encode($unmanaged);
        }

        $cidrs = self::lines((string) ($post['hygiene_probe_cidrs'] ?? ''));
        $bad   = array_values(array_filter($cidrs, static fn (string $c): bool => !Hygiene\Cidr::isValid($c)));
        if ($bad !== []) {
            $errors[] = sprintf(__('Invalid IPv4 CIDR: %s', 'nms'), implode(', ', $bad));
        } else {
            $values['hygiene_probe_cidrs'] = (string) json_encode($cidrs, JSON_UNESCAPED_SLASHES);
        }

        $ports = [];
        $ok    = true;
        foreach (preg_split('/[\s,;]+/', (string) ($post['hygiene_probe_tcp_ports'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (!ctype_digit($p) || (int) $p < 1 || (int) $p > 65535) {
                $ok = false;
                break;
            }
            $ports[(int) $p] = true;
        }
        if (!$ok || count($ports) > 10) {
            $errors[] = __('Probe ports: up to 10 TCP ports between 1 and 65535', 'nms');
        } else {
            $values['hygiene_probe_tcp_ports'] = (string) json_encode(array_keys($ports));
        }

        $values['hygiene_ticket_template'] = (string) max(0, (int) ($post['hygiene_ticket_template'] ?? 0));
        $groups = array_values(array_unique(array_filter(array_map('intval', (array) ($post['hygiene_glpi_groups'] ?? [])), static fn (int $id) => $id > 0)));
        $values['hygiene_glpi_groups'] = (string) json_encode($groups);
        // rc4: the technician(s) responsible for asset input and checks (rc7: the ticket's owners).
        $users = array_values(array_unique(array_filter(array_map('intval', (array) ($post['hygiene_glpi_users'] ?? [])), static fn (int $id) => $id > 0)));
        $values['hygiene_glpi_users'] = (string) json_encode($users);
        // rc5: tickets skip the business rules, so the ITIL category comes from here (0 = none).
        $values['hygiene_itil_category'] = (string) max(0, (int) ($post['hygiene_itil_category'] ?? 0));
        $values['hygiene_nms_group']   = (string) max(0, (int) ($post['hygiene_nms_group'] ?? 0));

        $enabled = !empty($post['hygiene_enabled']);
        // rc7 (option A): the asset technician(s) own the "not in GLPI" ticket; the groups only watch it.
        if ($enabled && ($users === [] || $values['hygiene_nms_group'] === '0')) {
            $errors[] = __('NMS hygiene tickets need at least one asset technician and the NMS team group; tickets stay off', 'nms');
            $enabled  = false;
        }
        $values['hygiene_enabled'] = $enabled ? '1' : '0';

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Validate the per-connection settings posted by the connection form, with the 1.3.2
     * rules. Values are keyed by Settings::DEFAULTS keys.
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeConnectionSettings(array $post): array
    {
        $values = [];
        $errors = [];

        $mode = (string) ($post['match_mode'] ?? '');
        if (in_array($mode, self::MATCH_MODES, true)) {
            $values['match_mode'] = $mode;
        } else {
            $errors[] = __('Unknown match mode', 'nms');
        }
        $states = array_values(array_unique(array_filter(array_map('intval', (array) ($post['coverage_states'] ?? [])), static fn (int $id) => $id > 0)));
        $values['coverage_states'] = (string) json_encode($states);

        $tickets = self::sanitizeTicketing($post);
        $hygiene = self::sanitizeHygiene($post);
        return [
            'values' => $values + $tickets['values'] + $hygiene['values'],
            'errors' => array_merge($errors, $tickets['errors'], $hygiene['errors']),
        ];
    }

    /**
     * Production ranges of several connections together (pages that span connections).
     *
     * @param list<Connection\Connection> $connections
     * @return list<int>
     */
    public static function productionAddressingsOf(array $connections): array
    {
        $ids = [];
        foreach ($connections as $c) {
            $ids = array_merge($ids, self::ticketingConfigFromSettings(Settings::fromJson((string) $c->settings))['production_addressings']);
        }
        return array_values(array_unique($ids));
    }

    /**
     * GLPI states counted as "in use" over several connections; [] = every state (D28).
     * One connection counting every state makes the union every state.
     *
     * @param list<Connection\Connection> $connections
     * @return list<int>
     */
    public static function coverageStatesOf(array $connections): array
    {
        $ids = [];
        foreach ($connections as $c) {
            $own = self::coverageStatesFromSettings(Settings::fromJson((string) $c->settings));
            if ($own === []) {
                return [];
            }
            $ids = array_merge($ids, $own);
        }
        return array_values(array_unique($ids));
    }

    /**
     * A storm must survive the gap between two polls: every alarm of one poll shares
     * one timestamp, so a window shorter than the poll interval ends the storm at
     * every run (final review, 2026-09-30).
     */
    public static function stormIdleMinutes(int $window): int
    {
        return max($window, self::ALARM_SYNC_MINUTES + 1);
    }

    /** @return array{filter_mode: string, filter_event_types: list<string>, filter_keywords: list<string>} */
    public static function filterSettings(string $mode, string $types_json, string $keywords): array
    {
        $types = json_decode($types_json, true);
        return [
            'filter_mode'        => in_array($mode, self::FILTER_MODES, true) ? $mode : self::FILTER_MODES[0],
            'filter_event_types' => is_array($types) ? array_values(array_map('strval', $types)) : [],
            'filter_keywords'    => self::lines($keywords),
        ];
    }

    /** Ticketing config resolved from a per-connection Settings (Task 5). */
    public static function ticketingConfigFromSettings(Settings $s): array
    {
        $sev = json_decode($s->get('ticket_severities'), true);
        return [
            'ticket_severities'    => is_array($sev) ? array_values(array_map('intval', $sev)) : [1],
            'ticket_source'        => in_array($s->get('ticket_source'), self::TICKET_SOURCES, true) ? $s->get('ticket_source') : self::TICKET_SOURCES[0],
            'relink_window_hours'  => (int) $s->get('relink_window_hours'),
            'storm_threshold'      => (int) $s->get('storm_threshold'),
            'storm_window_minutes' => max(1, (int) $s->get('storm_window_minutes')),
            'storm_idle_minutes'   => self::stormIdleMinutes(max(1, (int) $s->get('storm_window_minutes'))),
            'service_user'         => (int) $s->get('service_user'),
            'template_id'          => (int) $s->get('ticket_template'),
            'title_pattern'        => $s->get('ticket_title_pattern'),
            'content_pattern'      => $s->get('ticket_content_pattern'),
            'entity_default'       => (int) $s->get('ticket_entity_default'),
            'use_ipnetwork'        => $s->get('ticket_use_ipnetwork_entity') === '1',
            'outbound_max_attempts' => max(1, (int) $s->get('outbound_max_attempts')),
            'production_addressings' => array_values(array_map('intval', (array) (json_decode($s->get('production_addressings'), true) ?: []))),
        ] + self::filterSettings($s->get('ticket_filter_mode'), $s->get('ticket_filter_event_types'), $s->get('ticket_filter_keywords'));
    }

    /** Hygiene config resolved from a per-connection Settings (Task 5). */
    public static function hygieneConfigFromSettings(Settings $s): array
    {
        $list = static fn (string $key): array => array_values((array) (json_decode($s->get($key), true) ?: []));
        return [
            'enabled'          => $s->get('hygiene_enabled') === '1',
            'frequency_days'   => max(1, (int) $s->get('hygiene_frequency_days')),
            'unmanaged_values' => array_map('strval', $list('hygiene_unmanaged_availabilities')),
            'agent_stale_days' => max(1, (int) $s->get('hygiene_agent_stale_days')),
            'mist_stale_days'  => max(1, (int) $s->get('hygiene_mist_stale_days')),
            'probe_cidrs'      => array_map('strval', $list('hygiene_probe_cidrs')),
            'probe_ports'      => array_map('intval', $list('hygiene_probe_tcp_ports')),
            'probe_timeout_ms' => max(100, (int) $s->get('hygiene_probe_timeout_ms')),
            'template_id'      => (int) $s->get('hygiene_ticket_template'),
            'glpi_groups'      => array_map('intval', $list('hygiene_glpi_groups')),
            'glpi_users'       => array_map('intval', $list('hygiene_glpi_users')),
            'itil_category'    => max(0, (int) $s->get('hygiene_itil_category')),
            'nms_group'        => (int) $s->get('hygiene_nms_group'),
        ];
    }

    /** Coverage states from a per-connection Settings (Task 5). */
    public static function coverageStatesFromSettings(Settings $s): array
    {
        return array_values(array_map('intval', (array) (json_decode($s->get('coverage_states'), true) ?: [])));
    }

    /** @return list<string> trimmed, non-empty, unique lines */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", self::normaliseLines($text)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[$line] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * @param string[] $offered
     * @return list<string>
     */
    public static function filterItemtypes(string $json, array $offered): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_intersect(array_filter($decoded, 'is_string'), $offered));
    }

    /** @return list<string> configured asset itemtypes still on offer */
    public static function assetItemtypes(): array
    {
        return self::filterItemtypes(self::get('asset_itemtypes'), array_keys(self::offeredItemtypes()));
    }

    /** @return array<string, string> itemtype => label (base types labelled by class name) */
    public static function offeredItemtypes(): array
    {
        $out = [];
        foreach (self::BASE_ASSET_ITEMTYPES as $itemtype) {
            $out[$itemtype] = $itemtype;
        }
        return $out + CustomAssets::active();
    }
}
