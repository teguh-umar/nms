<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/Hygiene/Classifier.php';
require OPM_ROOT . '/src/Hygiene/Report.php';

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Hygiene\Report;

// forNewConnection
$z = Settings::forNewConnection('zabbix', 7);
check('zabbix title pattern', $z->get('ticket_title_pattern') === '[ZBX-{alarm_key}] {severity}: {message} - {device}');
check('zabbix content pattern says Zabbix problem', str_starts_with($z->get('ticket_content_pattern'), 'Zabbix problem {alarm_key}') && !str_contains($z->get('ticket_content_pattern'), 'OpManager'));
check('zabbix content keeps the rest of the pattern', str_replace('Zabbix problem', 'OpManager alarm', $z->get('ticket_content_pattern')) === Settings::DEFAULTS['ticket_content_pattern']);
check('zabbix ticket source rules', $z->get('ticket_source') === 'rules');
check('zabbix default entity is the connection entity', $z->get('ticket_entity_default') === '7');
check('zabbix unmanaged availabilities', $z->get('hygiene_unmanaged_availabilities') === '["Disabled"]');
$changed = array_keys(array_filter(Settings::DEFAULTS, static fn ($v, $k) => $z->get($k) !== $v, ARRAY_FILTER_USE_BOTH));
sort($changed);
check('zabbix overrides exactly five keys', $changed === ['hygiene_unmanaged_availabilities', 'ticket_content_pattern', 'ticket_entity_default', 'ticket_source', 'ticket_title_pattern'], implode(',', $changed));
check('opmanager new connection is DEFAULTS', Settings::forNewConnection('opmanager', 5)->toJson() === Settings::fromJson('{}')->toJson());

// Hygiene titles
check('opmanager glpi title unchanged', Report::title('glpi', '2026-11-06 12:00:00') === '[OPM-HYGIENE] Monitored in OpManager, not in GLPI - 2026-11-06');
check('opmanager nms title unchanged', Report::title('nms', '2026-11-06 12:00:00', 'opmanager') === '[OPM-HYGIENE] Unmanaged in OpManager: remove or check - 2026-11-06');
check('zabbix glpi title', Report::title('glpi', '2026-11-06 12:00:00', 'zabbix') === '[ZBX-HYGIENE] Monitored in Zabbix, not in GLPI - 2026-11-06');
check('zabbix nms title', Report::title('nms', '2026-11-06 12:00:00', 'zabbix') === '[ZBX-HYGIENE] Unmanaged in Zabbix: remove or check - 2026-11-06');
$row = ['device_key' => 'k', 'name' => 'srv-01', 'ip' => '192.0.2.10', 'category' => 'Server', 'availability' => 'Disabled', 'asset' => '', 'state' => 'nms_dead', 'liveness' => 'dead', 'detail' => 'x', 'last_seen' => ''];
$zb  = Report::body('nms', [$row], 'https://glpi.example.test/list', 'zabbix');
check('zabbix body names Zabbix, never OpManager', str_contains($zb, 'Zabbix') && !str_contains($zb, 'OpManager'));
check('opmanager body default brand unchanged', str_contains(Report::body('nms', [$row], 'u'), 'Dead - candidate for removal from OpManager'));

// Sanitiser
$s = Config::sanitizeZabbix(['zabbix_host_groups' => " Linux servers \r\n\r\n  Network\n \n", 'zabbix_min_severity' => '9', 'zabbix_include_suppressed' => '1']);
check('groups trimmed, empty lines dropped, newline-joined', $s['zabbix_host_groups'] === "Linux servers\nNetwork", json_encode($s['zabbix_host_groups']));
check('severity clamped high', $s['zabbix_min_severity'] === '5');
check('severity clamped low', Config::sanitizeZabbix(['zabbix_min_severity' => '-3'])['zabbix_min_severity'] === '0');
check('severity garbage becomes 0', Config::sanitizeZabbix(['zabbix_min_severity' => 'abc'])['zabbix_min_severity'] === '0');
check('suppressed 1', $s['zabbix_include_suppressed'] === '1');
check('suppressed anything else is 0', Config::sanitizeZabbix(['zabbix_include_suppressed' => ''])['zabbix_include_suppressed'] === '0');
check('empty groups stay empty', Config::sanitizeZabbix([])['zabbix_host_groups'] === '');

// Form source pins
$src = (string) file_get_contents(OPM_ROOT . '/src/Connection/ConnectionForm.php');
check('zabbix block rendered only for brand zabbix', (bool) preg_match('/if \(\$conn->brand === \'zabbix\'\) \{\s*self::zabbixBlock/', $src));
check('min severity select has 0..5', str_contains($src, '[0 => ') && str_contains($src, "5 => 'Disaster'") && str_contains($src, 'name="zabbix_min_severity"'));
check('secret inputs keep value=""', str_contains($src, 'name="\' . $name . \'" value=""'));
check('host groups help: exact match, empty = all, no match = empty sync',
    str_contains($src, 'matched exactly') && str_contains($src, 'nested subgroups are not included') && str_contains($src, 'Empty = every host the API token can read') && str_contains($src, 'match no group give an empty sync'));
preg_match('/\$id === 0\) \{(.*?)\} else \{/s', $src, $m);
check('forNewConnection only in the id-0 branch', isset($m[1]) && str_contains($m[1], 'forNewConnection') && substr_count($src, 'Settings::forNewConnection(') === 1);
check('hygiene passes the brand', str_contains((string) file_get_contents(OPM_ROOT . '/src/Hygiene/HygieneRun.php'), "\$this->cfg['brand']"));

done();
