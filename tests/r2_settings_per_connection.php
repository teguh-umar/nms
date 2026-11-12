<?php

require __DIR__ . '/bootstrap.php';

require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/SuiteConfig.php';

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Connection\Settings;

// ---- R2.1: Config::DEFAULTS holds no moved key and no opm_* / webhook_token key ----
$moved_keys = [
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
foreach ($moved_keys as $k) {
    check("Config::DEFAULTS no longer has moved key {$k}", !array_key_exists($k, Config::DEFAULTS));
}
check('Config::DEFAULTS has no opm_url key', !array_key_exists('opm_url', Config::DEFAULTS));
check('Config::DEFAULTS has no opm_api_key key', !array_key_exists('opm_api_key', Config::DEFAULTS));
check('Config::DEFAULTS has no opm_verify_ssl key', !array_key_exists('opm_verify_ssl', Config::DEFAULTS));
check('Config::DEFAULTS has no webhook_token key', !array_key_exists('webhook_token', Config::DEFAULTS));
check('Config::DEFAULTS keeps asset_itemtypes', array_key_exists('asset_itemtypes', Config::DEFAULTS));
check('Config::DEFAULTS keeps stale_warning_minutes', array_key_exists('stale_warning_minutes', Config::DEFAULTS));

// ---- R2.2: Settings::DEFAULTS holds every moved key ----
foreach ($moved_keys as $k) {
    check("Settings::DEFAULTS holds moved key {$k}", array_key_exists($k, Settings::DEFAULTS));
}

// ---- R2.3: no Config::get('<moved key>') and no Config::ticketingConfig() outside src/Migration/ ----
$moved_key_pattern = '/Config::get\([\'"](' . implode('|', array_map(fn ($k) => preg_quote($k, '/'), $moved_keys)) . ')[\'"]\)/';
$src_files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OPM_ROOT . '/src'));
$migration_dir = OPM_ROOT . '/src/Migration';
$found_bad = [];
foreach ($src_files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_starts_with($path, $migration_dir)) {
        continue;
    }
    $content = (string) @file_get_contents($path);
    if (preg_match($moved_key_pattern, $content)) {
        $found_bad[] = $path;
    }
    if (str_contains($content, 'Config::ticketingConfig()')) {
        $found_bad[] = $path . ' (Config::ticketingConfig())';
    }
}
check('no Config::get(\'<moved key>\') outside src/Migration/', $found_bad === [], implode("\n  ", $found_bad));

// ---- R2.4: no opm_* / webhook_token reads outside src/Migration/ ----
$opm_pattern = '/Config::get\([\'"]opm_(?:url|api_key|verify_ssl)[\'"]\)/';
$found_opm = [];
foreach ($src_files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_starts_with($path, $migration_dir)) {
        continue;
    }
    $content = (string) @file_get_contents($path);
    if (preg_match($opm_pattern, $content)) {
        $found_opm[] = $path;
    }
}
check('no Config::get(\'opm_*\') outside src/Migration/', $found_opm === [], implode("\n  ", $found_opm));

// ---- R2.5: Settings::fromJson round-trips every moved key ----
$s = Settings::fromJson('{}');
foreach ($moved_keys as $k) {
    check("Settings::fromJson('{}') returns default for {$k}", $s->get($k) === Settings::DEFAULTS[$k]);
}

// ---- R2.6: Settings::with() does not mutate ----
$orig = Settings::fromJson('{}');
$copy = $orig->with(['match_mode' => 'manual']);
check('with() returns changed value', $copy->get('match_mode') === 'manual');
check('with() does not mutate original', $orig->get('match_mode') === Settings::DEFAULTS['match_mode']);

done();
