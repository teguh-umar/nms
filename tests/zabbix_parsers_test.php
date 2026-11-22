<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Driver/Zabbix/HostParser.php';
require OPM_ROOT . '/src/Driver/Zabbix/ProblemParser.php';

use GlpiPlugin\Nms\Driver\Zabbix\HostParser;
use GlpiPlugin\Nms\Driver\Zabbix\ProblemParser;

function zfx(string $ver, string $name): array|string
{
    return json_decode((string) file_get_contents(__DIR__ . "/fixtures/zabbix/{$ver}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

$deviceKeys = ['device_key', 'name', 'display_name', 'ip_address', 'device_type', 'device_category', 'availability'];
$alarmKeys  = ['alarm_key', 'entity_key', 'device_name', 'display_name', 'ip_address', 'category', 'alarm_code',
    'event_type', 'severity', 'severity_label', 'message', 'mod_time'];

foreach (['6.0', '7.0', '7.4'] as $ver) {
    $v = "[{$ver}] ";
    check($v . 'apiinfo.version fixture matches folder', str_starts_with(zfx($ver, 'apiinfo.version'), $ver . '.'));
    check($v . 'hostgroup.get fixture present', count(zfx($ver, 'hostgroup.get')) > 0);

    $raw = zfx($ver, 'host.get');
    check($v . 'fixture uses ' . ($ver === '6.0' ? 'groups' : 'hostgroups'),
        isset($raw[0][$ver === '6.0' ? 'groups' : 'hostgroups']) && !isset($raw[0][$ver === '6.0' ? 'hostgroups' : 'groups']));

    $rows = HostParser::parse($raw);
    $by   = array_column($rows, null, 'device_key');
    check($v . 'all hosts listed', count($rows) === 6, (string) count($rows));
    check($v . 'device row keys', array_keys($rows[0]) === $deviceKeys);

    $a = $by['10501'];
    check($v . 'name/display', $a['name'] === 'core-sw-01.example.test' && $a['display_name'] === 'Core Switch 01');
    check($v . 'agent interface wins over SNMP', $a['ip_address'] === '192.0.2.10', $a['ip_address']);
    check($v . 'category is first sorted group', $a['device_category'] === 'Datacenter A', $a['device_category']);
    check($v . 'agent iface unavailable', $a['availability'] === 'Unavailable', $a['availability']);
    check($v . 'dns when useip=0', $by['10502']['ip_address'] === 'app-01.example.test');
    check($v . 'available', $by['10502']['availability'] === 'Available');
    check($v . 'disabled beats available', $by['10503']['availability'] === 'Disabled');
    check($v . 'snmp ip', $by['10503']['ip_address'] === '198.51.100.5');
    check($v . 'maintenance', $by['10504']['availability'] === 'Maintenance');
    check($v . 'ipmi unknown', $by['10505']['availability'] === 'Unknown' && $by['10505']['ip_address'] === '203.0.113.30');
    $b = $by['10506'];
    check($v . 'bare host', $b['ip_address'] === '' && $b['device_category'] === '' && $b['availability'] === 'Unknown');

    // Problems
    $problems = zfx($ver, 'problem.get');
    $events   = array_column(zfx($ver, 'event.get'), null, 'eventid');
    $details  = array_column($raw, null, 'hostid');
    $alarms   = ProblemParser::parse($problems, $events, $details);
    $ab       = array_column($alarms, null, 'alarm_key');
    check($v . 'alarm count', count($alarms) === 3);
    check($v . 'alarm row keys', array_keys($alarms[0]) === $alarmKeys);
    $p = $ab['9001'];
    check($v . 'entity/code', $p['entity_key'] === '10501' && $p['alarm_code'] === '20001');
    check($v . 'device fields', $p['device_name'] === 'core-sw-01.example.test' && $p['display_name'] === 'Core Switch 01'
        && $p['ip_address'] === '192.0.2.10' && $p['category'] === 'Datacenter A');
    check($v . 'High -> 1 / High', $p['severity'] === 1 && $p['severity_label'] === 'High');
    check($v . 'event_type unexpanded', $p['event_type'] === 'Interface {#IFNAME}: Link down', $p['event_type']);
    check($v . 'message plain', $p['message'] === 'Interface Gi0/1: Link down');
    check($v . 'mod_time', $p['mod_time'] === date('Y-m-d H:i:s', 1763800000));
    check($v . 'opdata appended', $ab['9002']['message'] === 'High CPU utilization (Current: 97 %)');
    check($v . 'Warning -> 3', $ab['9002']['severity'] === 3 && $ab['9002']['severity_label'] === 'Warning');
    check($v . 'Disaster -> 1', $ab['9003']['severity'] === 1 && $ab['9003']['severity_label'] === 'Disaster');

    $e = ProblemParser::fromEvent($events['9001'], $details);
    check($v . 'fromEvent open', $e !== null && $e['severity'] === 1 && $e['alarm_key'] === '9001' && $e['ip_address'] === '192.0.2.10');
    $rec = $events['9001'];
    $rec['r_eventid'] = '9100';
    $e = ProblemParser::fromEvent($rec, $details);
    check($v . 'fromEvent recovered is Clear', $e !== null && $e['severity'] === 5 && $e['severity_label'] === 'Clear');
    check($v . 'fromEvent empty is null', ProblemParser::fromEvent([], $details) === null);
}

check('severity map', ProblemParser::SEVERITY_MAP === [5 => 1, 4 => 1, 3 => 2, 2 => 3, 1 => 3, 0 => 3]);
check('labels', ProblemParser::LABELS === [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster']);
check('problem without host data still listed', count(ProblemParser::parse([['eventid' => '1', 'objectid' => '2', 'name' => 'x', 'severity' => '3', 'clock' => '1763800000']], [], [])) === 1);
check('garbage rows skipped', ProblemParser::parse(['x', ['severity' => '3']], [], []) === [] && HostParser::parse(['x', []]) === []);

done();
