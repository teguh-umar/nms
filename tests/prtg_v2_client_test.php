<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Driver/UnsupportedOperation.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgException.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgApiError.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgClient.php';
require OPM_ROOT . '/src/Driver/Prtg/V2Client.php';

use GlpiPlugin\Nms\Driver\Prtg\PrtgApiError;
use GlpiPlugin\Nms\Driver\Prtg\PrtgClient;
use GlpiPlugin\Nms\Driver\Prtg\PrtgException;
use GlpiPlugin\Nms\Driver\Prtg\V2Client;
use GlpiPlugin\Nms\Driver\UnsupportedOperation;

const V2_URL = 'https://prtg.example/';

/** Fake transport: $extra maps a URL fragment to [status, body] or a Throwable. */
function v2_fake(array &$log, array $extra = []): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use (&$log, $extra): array {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        foreach ($extra as $needle => $r) {
            if (str_contains($url, (string) $needle)) {
                if ($r instanceof Throwable) {
                    throw $r;
                }
                return ['status' => $r[0], 'body' => $r[1]];
            }
        }
        if (str_contains($url, '/api/v2/session')) {
            return ['status' => 200, 'body' => json_encode(['token' => 'TOKEN-SECRET-1'])];
        }
        if (str_contains($url, '/experimental/devices')) {
            return ['status' => 200, 'body' => json_encode(['items' => [
                ['id' => 2001, 'name' => 'sw-core-1', 'host' => '192.0.2.10', 'group' => 'Core', 'status' => 'Up', 'tags' => ['net', 'core']],
            ]])];
        }
        if (preg_match('#/experimental/sensors/(\d+)#', $url, $m)) {
            return $m[1] === '9999'
                ? ['status' => 404, 'body' => '{}']
                : ['status' => 200, 'body' => json_encode(['id' => 3001, 'name' => 'Ping', 'deviceId' => 2001, 'deviceName' => 'sw-core-1', 'status' => 'Down', 'message' => '<b>No response</b> ', 'type' => 'ping', 'lastCheck' => '2026-11-22T00:00:00Z'])];
        }
        if (str_contains($url, '/experimental/sensors')) {
            return ['status' => 200, 'body' => json_encode(['items' => [
                ['id' => 3001, 'name' => 'Ping', 'deviceId' => 2001, 'deviceName' => 'sw-core-1', 'group' => 'Core', 'status' => 'Down', 'message' => 'No response', 'type' => 'ping', 'lastCheck' => '2026-11-22T00:00:00Z'],
                ['id' => 3002, 'name' => 'HTTP', 'deviceId' => 2001, 'deviceName' => 'sw-core-1', 'group' => 'Core', 'status' => 'Up', 'message' => 'OK', 'type' => 'http', 'lastCheck' => null],
                ['id' => 3003, 'name' => 'Disk', 'deviceId' => 2001, 'deviceName' => 'sw-core-1', 'group' => 'Core', 'status' => 'Paused by user', 'message' => '', 'type' => 'disk'],
            ]])];
        }
        return ['status' => 404, 'body' => 'nope'];
    };
}

$log = [];
$c = new V2Client(V2_URL, 'KEY-SECRET-9', '', '', v2_fake($log));
check('implements PrtgClient', $c instanceof PrtgClient);
check('key mode', $c->authMode() === 'key');
$devs = $c->devices();
check('devices path', str_contains($log[0]['url'], 'https://prtg.example/api/v2/experimental/devices') && $log[0]['method'] === 'GET');
check('bearer key', ($log[0]['headers']['Authorization'] ?? '') === 'Bearer KEY-SECRET-9');
check('device row', $devs[0] === ['id' => '2001', 'name' => 'sw-core-1', 'host' => '192.0.2.10', 'group' => 'Core', 'status_raw' => 3, 'status' => 'Up', 'tags' => 'net core'], json_encode($devs));

$all = $c->sensors([]);
check('sensors path', str_contains(end($log)['url'], '/api/v2/experimental/sensors'));
check('sensors all', count($all) === 3);
check('sensor row', $all[0]['id'] === '3001' && $all[0]['device_id'] === '2001' && $all[0]['device'] === 'sw-core-1' && $all[0]['status_raw'] === 5 && $all[0]['message'] === 'No response' && $all[0]['type'] === 'ping');
$ole = strtotime('2026-11-22T00:00:00Z') / 86400 + 25569;
check('lastcheck ole', is_float($all[0]['lastcheck']) && abs($all[0]['lastcheck'] - $ole) < 0.001, (string) $all[0]['lastcheck']);
check('lastcheck null', $all[1]['lastcheck'] === null);
check('paused text maps 7', $all[2]['status_raw'] === 7);
$down = $c->sensors([5, 13]);
check('php status filter', count($down) === 1 && $down[0]['id'] === '3001');
$one = $c->sensor('3001');
check('sensor raw message', $one !== null && $one['message'] === '<b>No response</b>');
check('sensor missing null', $c->sensor('9999') === null);

// login mode
$log = [];
$l = new V2Client(V2_URL, '', 'admin', 'PW-SECRET', v2_fake($log));
check('login mode', $l->authMode() === 'login');
$l->devices();
$l->sensors([]);
$posts = array_values(array_filter($log, fn ($e) => $e['method'] === 'POST'));
check('one session post', count($posts) === 1);
check('session body', json_decode((string) $posts[0]['body'], true) === ['username' => 'admin', 'password' => 'PW-SECRET']);
check('token used as bearer', ($log[1]['headers']['Authorization'] ?? '') === 'Bearer TOKEN-SECRET-1');

// failed login cached, no leak
$log = [];
$f = new V2Client(V2_URL, '', 'admin', 'PW-SECRET', v2_fake($log, ['/api/v2/session' => [401, '{"error":"bad PW-SECRET"}']]));
$msgs = [];
for ($i = 0; $i < 2; $i++) {
    try {
        $f->devices();
    } catch (PrtgApiError $e) {
        $msgs[] = $e->getMessage();
        check('no previous', $e->getPrevious() === null);
    }
}
check('login failure api error x2', count($msgs) === 2);
check('one login attempt', count($log) === 1);
check('no password in message', !str_contains(implode('|', $msgs), 'PW-SECRET'), implode('|', $msgs));

// empty credentials
$log = [];
$e0 = new V2Client(V2_URL, '', '', '', v2_fake($log));
try {
    $e0->devices();
    check('empty creds throws', false);
} catch (PrtgException $e) {
    check('empty creds throws', true);
}
check('empty creds sends nothing', $log === []);

// CR/LF key
$log = [];
$crlf = new V2Client(V2_URL, "KEY\r\nX-Evil: 1", '', '', v2_fake($log));
try {
    $crlf->devices();
    check('crlf key rejected', false);
} catch (PrtgException $e) {
    check('crlf key rejected', !str_contains($e->getMessage(), 'KEY'));
}
check('crlf sends nothing', $log === []);

// acknowledge
$log = [];
$a = new V2Client(V2_URL, 'KEY-SECRET-9', '', '', v2_fake($log));
try {
    $a->acknowledge('3001', 'x');
    check('ack unsupported', false);
} catch (UnsupportedOperation $e) {
    check('ack unsupported', str_contains($e->getMessage(), 'acknowledge is not available with PRTG API v2'));
}
check('ack sends nothing', $log === []);

// 404 -> not available
foreach (['version' => fn ($c) => $c->version(), 'devices' => fn ($c) => $c->devices()] as $label => $fn) {
    $log = [];
    $n = new V2Client(V2_URL, 'KEY-SECRET-9', '', '', v2_fake($log, ['/api/v2/' => [404, 'nope']]));
    try {
        $fn($n);
        check("404 $label", false);
    } catch (PrtgException $e) {
        check("404 $label", $e->getMessage() === 'API v2 is not available on this PRTG (needs 24.3 or later)', $e->getMessage());
    }
}

// transport failure leaks nothing
$log = [];
$t = new V2Client(V2_URL, 'KEY-SECRET-9', '', '', v2_fake($log, ['/experimental/devices' => new RuntimeException('boom KEY-SECRET-9')]));
try {
    $t->devices();
    check('transport error', false);
} catch (PrtgException $e) {
    check('transport error redacted', !str_contains($e->getMessage(), 'KEY-SECRET-9') && $e->getPrevious() === null, $e->getMessage());
}

// 401 refusal
$log = [];
$r = new V2Client(V2_URL, 'KEY-SECRET-9', '', '', v2_fake($log, ['/experimental/devices' => [401, '{"message":"bad KEY-SECRET-9"}']]));
try {
    $r->devices();
    check('401', false);
} catch (PrtgApiError $e) {
    check('401 redacted', !str_contains($e->getMessage(), 'KEY-SECRET-9'), $e->getMessage());
}

done();
