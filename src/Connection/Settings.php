<?php

namespace GlpiPlugin\Nms\Connection;

/**
 * Per-connection settings, stored as JSON in `connections.settings`.
 *
 * Holds every 1.3.2 `Config::DEFAULTS` key that moves per connection
 * (spec §5.5 / §5.10): match_mode, ticket_*, service_user, relink_window_hours,
 * storm_*, outbound_max_attempts, production_addressings, coverage_states,
 * every hygiene_* key. Config keeps only the global keys (asset_itemtypes,
 * stale_warning_minutes, plus the opm_* / last_* connection-level keys).
 */
final class Settings
{
    public const DEFAULTS = [
        // Matching (moved from Config)
        'match_mode'             => 'hybrid_name',
        // Coverage page (D28): GLPI states counted as in use; [] = every state.
        'coverage_states'        => '[]',
        // Ticketing (spec §5.5)
        'ticket_severities'           => '[1]',
        'ticket_source'               => 'webhook',
        'ticket_template'             => '0',
        'ticket_title_pattern'        => '[OPM-{alarm_key}] {severity}: {message} - {device}',
        'ticket_content_pattern'      => "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nCategory: {category}\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}",
        'service_user'                => '0',
        'ticket_entity_default'       => '0',
        'ticket_use_ipnetwork_entity' => '1',
        'relink_window_hours'         => '24',
        'storm_threshold'             => '10',
        'storm_window_minutes'        => '5',
        'outbound_max_attempts'       => '10',
        'production_addressings'      => '[]',
        'ticket_filter_mode'          => 'exclude',
        'ticket_filter_event_types'   => '[]',
        'ticket_filter_keywords'      => '',
        // NMS hygiene (spec §8.4, D34)
        'hygiene_enabled'                  => '0',
        'hygiene_frequency_days'           => '7',
        'hygiene_unmanaged_availabilities' => '["UnManaged","Device Not Monitored."]',
        'hygiene_agent_stale_days'         => '7',
        'hygiene_mist_stale_days'          => '7',
        'hygiene_probe_cidrs'              => '[]',
        'hygiene_probe_tcp_ports'          => '[22,443,80,3389,161]',
        'hygiene_probe_timeout_ms'         => '1000',
        'hygiene_ticket_template'          => '0',
        'hygiene_glpi_groups'              => '[]',
        'hygiene_glpi_users'               => '[]',
        'hygiene_itil_category'            => '0',
        'hygiene_nms_group'                => '0',
        'last_hygiene_run'                 => '',
        'last_hygiene_ticket_run'          => '',
        'last_hygiene_error'               => '',
        // Zabbix (brand-specific)
        'zabbix_host_groups'               => '',
        'zabbix_min_severity'              => '2',
        'zabbix_include_suppressed'        => '0',
        // PRTG (brand-specific)
        'prtg_api'                         => 'classic',
        'prtg_groups'                      => '',
        'prtg_alarm_states'                => 'down+warning',
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * Settings of a connection being created. OpManager keeps DEFAULTS unchanged; Zabbix
     * overrides the ticket patterns and source, the entity and the "not monitored" values.
     */
    public static function forNewConnection(string $brand, int $entities_id): self
    {
        if ($brand !== 'zabbix') {
            return new self(self::DEFAULTS);
        }
        return new self([
            'ticket_title_pattern'             => '[ZBX-{alarm_key}] {severity}: {message} - {device}',
            'ticket_content_pattern'           => str_replace('OpManager alarm', 'Zabbix problem', self::DEFAULTS['ticket_content_pattern']),
            'ticket_source'                    => 'rules',
            'ticket_entity_default'            => (string) $entities_id,
            'hygiene_unmanaged_availabilities' => '["Disabled"]',
        ] + self::DEFAULTS);
    }

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Parse the JSON stored in a connection row. Unknown keys are dropped
     * (forward compatibility when a newer release adds settings). Missing keys
     * fall back to DEFAULTS.
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded = array_intersect_key($decoded, self::DEFAULTS);
        return new self($decoded + self::DEFAULTS);
    }

    /** @return string the stored value for $key, or the default when unset */
    public function get(string $key): string
    {
        return $this->values[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    /** @param array<string, string> $values @return self a copy with $values merged (never mutates $this) */
    public function with(array $values): self
    {
        $merged = array_merge($this->values, array_intersect_key($values, self::DEFAULTS));
        return new self($merged);
    }

    /** @return string JSON suitable for `connections.settings` */
    public function toJson(): string
    {
        return json_encode($this->values);
    }
}
