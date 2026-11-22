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
$p = Settings::forNewConnection('prtg', 7);
check('prtg title pattern', $p->get('ticket_title_pattern') === '[PRTG-{alarm_key}] {severity}: {message} - {device}');
check('prtg content pattern says PRTG alarm', str_starts_with($p->get('ticket_content_pattern'), 'PRTG alarm {alarm_key}') && !str_contains($p->get('ticket_content_pattern'), 'OpManager'));
check('prtg content keeps the rest of the pattern', str_replace('PRTG alarm', 'OpManager alarm', $p->get('ticket_content_pattern')) === Settings::DEFAULTS['ticket_content_pattern']);
check('prtg ticket source rules', $p->get('ticket_source') === 'rules');
check('prtg default entity is the connection entity', $p->get('ticket_entity_default') === '7');
check('prtg unmanaged availabilities', $p->get('hygiene_unmanaged_availabilities') === '["Paused"]');
check('prtg api/groups/states defaults', $p->get('prtg_api') === 'classic' && $p->get('prtg_groups') === '' && $p->get('prtg_alarm_states') === 'down+warning');
$changed = array_keys(array_filter(Settings::DEFAULTS, static fn ($v, $k) => $p->get($k) !== $v, ARRAY_FILTER_USE_BOTH));
sort($changed);
check('prtg overrides exactly five keys', $changed === ['hygiene_unmanaged_availabilities', 'ticket_content_pattern', 'ticket_entity_default', 'ticket_source', 'ticket_title_pattern'], implode(',', $changed));
check('opmanager new connection is DEFAULTS', Settings::forNewConnection('opmanager', 5)->toJson() === Settings::fromJson('{}')->toJson());
check('zabbix new connection unchanged', Settings::forNewConnection('zabbix', 7)->get('ticket_title_pattern') === '[ZBX-{alarm_key}] {severity}: {message} - {device}' && Settings::forNewConnection('zabbix', 7)->get('hygiene_unmanaged_availabilities') === '["Disabled"]');

// Sanitiser
$s = Config::sanitizePrtg(['prtg_api' => 'v2', 'prtg_alarm_states' => 'down+warning+unusual', 'prtg_groups' => " Core \r\n\r\n  Branch\n \n"]);
check('api v2 kept', $s['prtg_api'] === 'v2');
check('states kept', $s['prtg_alarm_states'] === 'down+warning+unusual');
check('groups trimmed, empty dropped, newline-joined', $s['prtg_groups'] === "Core\nBranch", json_encode($s['prtg_groups']));
check('api garbage becomes classic', Config::sanitizePrtg(['prtg_api' => 'v3'])['prtg_api'] === 'classic');
check('states garbage becomes down+warning', Config::sanitizePrtg(['prtg_alarm_states' => 'all'])['prtg_alarm_states'] === 'down+warning');
check('down state accepted', Config::sanitizePrtg(['prtg_alarm_states' => 'down'])['prtg_alarm_states'] === 'down');
$e = Config::sanitizePrtg([]);
check('empty post gives defaults', $e === ['prtg_api' => 'classic', 'prtg_alarm_states' => 'down+warning', 'prtg_groups' => ''], json_encode($e));

// Hygiene titles
check('opmanager glpi title unchanged', Report::title('glpi', '2026-11-06 12:00:00') === '[OPM-HYGIENE] Monitored in OpManager, not in GLPI - 2026-11-06');
check('zabbix nms title unchanged', Report::title('nms', '2026-11-06 12:00:00', 'zabbix') === '[ZBX-HYGIENE] Unmanaged in Zabbix: remove or check - 2026-11-06');
check('prtg glpi title', Report::title('glpi', '2026-11-06 12:00:00', 'prtg') === '[PRTG-HYGIENE] Monitored in PRTG, not in GLPI - 2026-11-06');
check('prtg nms title', Report::title('nms', '2026-11-06 12:00:00', 'prtg') === '[PRTG-HYGIENE] Unmanaged in PRTG: remove or check - 2026-11-06');
$row = ['device_key' => 'k', 'name' => 'sw-01', 'ip' => '192.0.2.10', 'category' => 'Network', 'availability' => 'Paused', 'asset' => '', 'state' => 'nms_dead', 'liveness' => 'dead', 'detail' => 'x', 'last_seen' => ''];
foreach (['nms', 'glpi'] as $flow) {
    $row['state'] = $flow === 'nms' ? 'nms_dead' : 'nms_only';
    $b = Report::body($flow, [$row], 'https://glpi.example.test/list', 'prtg');
    check("prtg $flow body names PRTG, never OpManager/Zabbix", str_contains($b, 'PRTG') && !str_contains($b, 'OpManager') && !str_contains($b, 'Zabbix'), $b);
}

// Form
$src = (string) file_get_contents(OPM_ROOT . '/src/Connection/ConnectionForm.php');
check('prtg block rendered only for brand prtg', (bool) preg_match('/if \(\$conn->brand === \'prtg\'\) \{\s*self::prtgBlock/', $src));
check('api select options', str_contains($src, 'Classic API') && str_contains($src, 'API v2 (experimental, read-only)') && str_contains($src, "'classic'") && str_contains($src, "'v2'") && str_contains($src, 'name="prtg_api"'));
check('alarm states select has the three keys', str_contains($src, 'name="prtg_alarm_states"') && str_contains($src, 'down+warning+unusual'));
check('groups help: exact, immediate group, case-sensitive, empty = all',
    str_contains($src, 'matched exactly') && str_contains($src, 'immediate group') && str_contains($src, 'case-sensitive') && str_contains($src, 'Empty = every group'));
check('credentials help: API key preferred, header, or username + password',
    str_contains($src, 'API key is preferred') && str_contains($src, 'sent as a header') && str_contains($src, 'username and password'));
check('secret inputs keep value=""', str_contains($src, 'name="\' . $name . \'" value=""'));
check('save only for brand prtg with the block posted', str_contains($src, "\$brand === 'prtg' && isset(\$post['prtg_api'])") && str_contains($src, 'Config::sanitizePrtg($post)'));

done();
