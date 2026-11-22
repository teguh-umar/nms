<?php

require __DIR__ . '/bootstrap.php';

require OPM_ROOT . '/src/Connection/Settings.php';

use GlpiPlugin\Nms\Connection\Settings;

// Keys moved per connection (spec Plan 1 Task 5), listed literally.
$MOVED_KEYS = [
    'match_mode'             => 'hybrid_name',
    'coverage_states'        => '[]',
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
    'zabbix_host_groups'               => '',
    'zabbix_min_severity'              => '2',
    'zabbix_include_suppressed'        => '0',
];

check('Settings::DEFAULTS holds every moved key', $MOVED_KEYS === Settings::DEFAULTS);

$s = Settings::fromJson('');
foreach ($MOVED_KEYS as $k => $v) {
    check("fromJson('') returns default for {$k}", $s->get($k) === $v);
}
check("fromJson('') returns all defaults", !array_reduce(array_keys($MOVED_KEYS), static fn ($carry, $k) => $carry || $s->get($k) !== $MOVED_KEYS[$k], false));

// Unknown keys in JSON are dropped.
$s2 = Settings::fromJson('{"match_mode":"manual","bogus_key":"should be dropped","ticket_severities":"[2,3]"}');
check('unknown keys dropped', $s2->get('match_mode') === 'manual');
check('unknown key not readable', $s2->get('bogus_key') === '');
check('known keys still parsed', $s2->get('ticket_severities') === '[2,3]');

// with() does not mutate.
$s3 = Settings::fromJson('{}');
$before = $s3->get('match_mode');
$copy   = $s3->with(['match_mode' => 'manual']);
check('with() returns changed value', $copy->get('match_mode') === 'manual');
check('with() does not mutate original', $s3->get('match_mode') === $before);
check('with() drops unknown keys', $copy->with(['nope' => 'x'])->get('nope') === '');

// toJson() round-trips.
$s4 = Settings::fromJson('{"match_mode":"ip_only","storm_threshold":"7"}');
check('toJson() round-trips known keys', json_decode($s4->toJson(), true)['match_mode'] === 'ip_only');
check('toJson() round-trips numeric', json_decode($s4->toJson(), true)['storm_threshold'] === '7');
check('toJson() contains no unknown keys', !str_contains($s4->toJson(), 'nope'));

// Config::DEFAULTS no longer contains any moved key.
require OPM_ROOT . '/src/Config.php';
foreach (array_keys(Settings::DEFAULTS) as $k) {
    check("Config::DEFAULTS no longer has moved key {$k}", !array_key_exists($k, \GlpiPlugin\Nms\Config::DEFAULTS));
}

// Config keeps its global keys.
check('Config keeps asset_itemtypes', array_key_exists('asset_itemtypes', \GlpiPlugin\Nms\Config::DEFAULTS));
check('Config keeps stale_warning_minutes', array_key_exists('stale_warning_minutes', \GlpiPlugin\Nms\Config::DEFAULTS));

done();
