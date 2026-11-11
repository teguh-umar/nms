<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'DeviceParser', 'AlarmParser'] as $f) {
    require OPM_ROOT . "/src/Driver/OpManager/{$f}.php";
}

use GlpiPlugin\Nms\Driver\OpManager\ClientException;
use GlpiPlugin\Nms\Driver\OpManager\AlarmParser;
use GlpiPlugin\Nms\Driver\OpManager\DeviceParser;
use GlpiPlugin\Nms\Driver\OpManager\ResponseGuard;

$list = '[{"deviceName":"10.20.1.1","displayName":"core-sw-01","ipaddress":"10.20.1.1","type":"Cisco 9300","category":"Switch","statusStr":"Clear"},'
      . '{"displayName":"no-key"},'
      . '{"deviceName":" vm-contoh-01 ","ipaddress":"10.20.3.3","status":"5"}]';

$rows = DeviceParser::parse($list);
check('rows without an identity are skipped', count($rows) === 2);
check('fields mapped', $rows[0] === [
    'device_key' => '10.20.1.1', 'name' => '10.20.1.1', 'display_name' => 'core-sw-01', 'ip_address' => '10.20.1.1',
    'device_type' => 'Cisco 9300', 'device_category' => 'Switch', 'availability' => 'Clear',
]);
check('values trimmed and fallbacks used', $rows[1]['device_key'] === 'vm-contoh-01' && $rows[1]['display_name'] === 'vm-contoh-01' && $rows[1]['availability'] === '5');
check('wrapped list accepted', count(DeviceParser::parse('{"rows":' . $list . '}')) === 2);
check('empty list is empty, not an error', DeviceParser::parse('[]') === []);

$threw = static function (callable $fn): bool {
    try { $fn(); } catch (ClientException) { return true; }
    return false;
};
check('non-JSON throws', $threw(fn () => DeviceParser::parse('<html>login</html>')));
check('object without a known list throws', $threw(fn () => DeviceParser::parse('{"error":{"code":5000}}')));

// Review Focus 3: the key must never reach a log or an exception message.
check('redact removes apiKey', ResponseGuard::redact('GET https://opm/api?apiKey=S3CRET&x=1 failed') === 'GET https://opm/api?apiKey=***&x=1 failed');

// Production 2026-09-30: OpManager answers HTTP 200 with an error object when the key
// is rejected. The message must reach Setup, not a generic "unexpected shape".
$prod = '{"error":{"code":5000,"message":"Specified apiKey is not valid. Authentication failed."}}';
try {
    DeviceParser::parse($prod);
    check('OpManager error object throws', false);
} catch (ClientException $e) {
    check('OpManager error object throws', true);
    check('OpManager error message surfaced', str_contains($e->getMessage(), 'Specified apiKey is not valid') && str_contains($e->getMessage(), '5000'));
}
try {
    DeviceParser::parse('{"error":"plain text failure"}');
    check('string error surfaced', false);
} catch (ClientException $e) {
    check('string error surfaced', str_contains($e->getMessage(), 'plain text failure'));
}

// AlarmParser: severity mapping and labels
$alarmJson = json_encode([
    ['alarmId' => '1', 'numericSeverity' => 1, 'severityString' => 'Critical', 'message' => 'link down', 'modTimeLong' => '1731283200000'],
    ['alarmId' => '2', 'numericSeverity' => 5, 'severityString' => 'Clear', 'message' => 'cleared', 'modTimeLong' => '1731283200000'],
]);
$alarms = AlarmParser::parse($alarmJson);
check('alarm parser: two rows', count($alarms) === 2);
check('alarm parser: severity labels', $alarms[0]['severity_label'] === 'Critical' && $alarms[1]['severity_label'] === 'Clear');
check('alarm parser: mod_time from ms', $alarms[0]['mod_time'] === '2024-11-11 00:00:00');

done();
