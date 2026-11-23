<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgException.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgApiError.php';
require OPM_ROOT . '/src/Driver/Prtg/PrtgClient.php';
require OPM_ROOT . '/src/Driver/Prtg/ClassicClient.php';

use GlpiPlugin\Nms\Driver\Prtg\ClassicClient;
use GlpiPlugin\Nms\Driver\Prtg\PrtgApiError;
use GlpiPlugin\Nms\Driver\Prtg\PrtgClient;
use GlpiPlugin\Nms\Driver\Prtg\PrtgException;

const PRTG_URL = 'https://prtg.example/';

/** Recording fake transport; $extra maps a URL fragment to [status, body] or a Throwable to throw. */
function prtg_fake(array &$log, array $extra = []): callable
{
    return function (string $url, array $headers) use (&$log, $extra): array {
        $log[] = ['url' => $url, 'headers' => $headers];
        foreach ($extra as $needle => $r) {
            if (str_contains($url, (string) $needle)) {
                if ($r instanceof Throwable) {
                    throw $r;
                }
                return ['status' => $r[0], 'body' => $r[1]];
            }
        }
        if (str_contains($url, '/api/status.json')) {
            return ['status' => 200, 'body' => json_encode(['Version' => '24.1.92.1234+'])];
        }
        if (str_contains($url, '/api/getpasshash.htm')) {
            return ['status' => 200, 'body' => '1234567890'];
        }
        if (str_contains($url, '/api/acknowledgealarm.htm')) {
            return ['status' => 200, 'body' => '<html>OK</html>'];
        }
        if (str_contains($url, 'content=devices')) {
            return ['status' => 200, 'body' => json_encode(['prtg-version' => '24.1', 'treesize' => 1, 'devices' => [
                ['objid' => 2001, 'device' => 'sw-core-1', 'host' => '192.0.2.10', 'group' => 'Core', 'probe' => 'Local', 'status' => 'Up', 'status_raw' => 3, 'tags' => 'net core'],
            ]])];
        }
        if (str_contains($url, 'content=sensors')) {
            if (str_contains($url, 'filter_objid=9999')) {
                return ['status' => 200, 'body' => json_encode(['prtg-version' => '24.1', 'treesize' => 0, 'sensors' => []])];
            }
            return ['status' => 200, 'body' => json_encode(['prtg-version' => '24.1', 'treesize' => 1, 'sensors' => [
                ['objid' => 3001, 'sensor' => 'Ping', 'device' => 'sw-core-1', 'parentid' => 2001, 'group' => 'Core', 'probe' => 'Local',
                 'status' => 'Down', 'status_raw' => 5, 'message' => 'No response', 'lastcheck' => '1 s ago', 'lastcheck_raw' => 45000.25, 'type' => 'ping'],
            ]])];
        }
        return ['status' => 404, 'body' => 'nope'];
    };
}

function prtg_urls(array $log): string
{
    return implode("\n", array_column($log, 'url'));
}

function prtg_count(array $log, string $needle): int
{
    return count(array_filter($log, fn ($l) => str_contains($l['url'], $needle)));
}

function prtg_throws(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

// --- base
check('base strips trailing slashes', ClassicClient::base(' https://prtg.example/// ') === 'https://prtg.example');
check('implements PrtgClient', (new ClassicClient(PRTG_URL, 'k', '', '', fn () => [])) instanceof PrtgClient);

// --- key mode
$log = [];
$c = new ClassicClient(PRTG_URL, 'k-123', '', '', prtg_fake($log));
check('key: authMode key', $c->authMode() === 'key');
$c->version();
$c->devices();
$c->sensors([5]);
$c->sensor('3001');
$c->acknowledge('3001', 'Acknowledged in GLPI');
$allHeader = true;
foreach ($log as $l) {
    $allHeader = $allHeader && ($l['headers']['Authorization'] ?? '') === 'Bearer k-123';
}
check('key: every request carries Bearer header', $allHeader && count($log) === 5, (string) count($log));
$urls = prtg_urls($log);
check('key: no secret in any URL', !str_contains($urls, 'apitoken') && !str_contains($urls, 'k-123')
    && !str_contains($urls, 'passhash') && !str_contains($urls, 'password'), $urls);
check('key: no getpasshash call', prtg_count($log, 'getpasshash') === 0);

// --- version
$log = [];
$c = new ClassicClient(PRTG_URL, 'k-123', '', '', prtg_fake($log));
check('version reads status.json Version', $c->version() === '24.1.92.1234+');
$c->version();
check('version cached', count($log) === 1 && str_contains($log[0]['url'], 'https://prtg.example/api/status.json'));

$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['/api/status.json' => [200, '{}']]));
check('version falls back to table envelope prtg-version', $c->version() === '24.1');

// --- login mode
$log = [];
$c = new ClassicClient(PRTG_URL, '', 'prtgadmin', 'S3cretPw', prtg_fake($log));
check('login: authMode login', $c->authMode() === 'login');
$c->devices();
$c->devices();
check('login: getpasshash once', prtg_count($log, 'getpasshash.htm') === 1, prtg_urls($log));
check('login: getpasshash carries username/password', str_contains($log[0]['url'], 'getpasshash.htm')
    && str_contains($log[0]['url'], 'username=prtgadmin') && str_contains($log[0]['url'], 'password=S3cretPw'), $log[0]['url']);
check('login: later calls carry username and passhash', str_contains($log[1]['url'], 'username=prtgadmin')
    && str_contains($log[1]['url'], 'passhash=1234567890'), $log[1]['url']);
check('login: no Authorization header', !isset($log[1]['headers']['Authorization']));

$log = [];
$c = new ClassicClient(PRTG_URL, '', 'prtgadmin', '', prtg_fake($log));
$e = prtg_throws(fn () => $c->devices());
check('login: empty password refused', $e instanceof PrtgException && str_contains($e->getMessage(), 'PRTG username or password is not configured'));
check('login: empty password sends no request', $log === []);

$log = [];
$c = new ClassicClient(PRTG_URL, '', 'prtgadmin', 'S3cretPw', prtg_fake($log, ['getpasshash.htm' => [401, '<div class="errormsg">Your login has failed. S3cretPw</div>']]));
$e1 = prtg_throws(fn () => $c->devices());
$e2 = prtg_throws(fn () => $c->devices());
check('login failure: first is PrtgApiError', $e1 instanceof PrtgApiError);
check('login failure: second rethrows same class without new request', $e2 instanceof PrtgApiError && count($log) === 1, (string) count($log));
check('login failure: password redacted', !str_contains($e1->getMessage(), 'S3cretPw') && !str_contains($e2->getMessage(), 'S3cretPw'), $e1->getMessage());

// --- devices
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log));
$devices = $c->devices();
$u = $log[0]['url'];
check('devices: request shape', str_contains($u, '/api/table.json?') && str_contains($u, 'content=devices')
    && str_contains($u, 'columns=objid,device,host,group,probe,status,status_raw,tags') && str_contains($u, 'count=*'), $u);
check('devices: normalised', $devices === [['id' => '2001', 'name' => 'sw-core-1', 'host' => '192.0.2.10', 'group' => 'Core',
    'status_raw' => 3, 'status' => 'Up', 'tags' => 'net core']], json_encode($devices));

// --- sensors
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log));
$sensors = $c->sensors([5, 13, 14]);
$u = $log[0]['url'];
check('sensors: one filter_status per code', substr_count($u, 'filter_status=') === 3
    && str_contains($u, 'filter_status=5') && str_contains($u, 'filter_status=13') && str_contains($u, 'filter_status=14'), $u);
check('sensors: columns and count', str_contains($u, 'content=sensors') && str_contains($u, 'parentid') && str_contains($u, 'lastcheck_raw')
    && str_contains($u, 'status_raw') && str_contains($u, 'type') && str_contains($u, 'count=*'), $u);
check('sensors: normalised', $sensors === [['id' => '3001', 'name' => 'Ping', 'device_id' => '2001', 'device' => 'sw-core-1',
    'group' => 'Core', 'status_raw' => 5, 'status' => 'Down', 'message' => 'No response', 'type' => 'ping', 'lastcheck' => 45000.25]], json_encode($sensors));
check('sensors: lastcheck is float', is_float($sensors[0]['lastcheck']));
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log));
$c->sensors([]);
check('sensors([]) sends no status filter', !str_contains($log[0]['url'], 'filter_status'));

// --- sensor
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log));
$s = $c->sensor('3001');
check('sensor: filter_objid, no status filter', str_contains($log[0]['url'], 'filter_objid=3001') && !str_contains($log[0]['url'], 'filter_status'));
check('sensor: returns row', is_array($s) && $s['id'] === '3001');
check('sensor: null on empty table', $c->sensor('9999') === null);

// --- acknowledge
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log));
$c->acknowledge('2001', 'Acknowledged in GLPI');
check('acknowledge: call shape', str_contains($log[0]['url'], '/api/acknowledgealarm.htm?')
    && str_contains($log[0]['url'], 'id=2001') && str_contains($log[0]['url'], 'ackmsg=Acknowledged%20in%20GLPI'), $log[0]['url']);
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['acknowledgealarm' => [400, '<div class="errormsg">Sensor is not down</div>']]));
check('acknowledge: refusal is PrtgApiError', prtg_throws(fn () => $c->acknowledge('2001', 'x')) instanceof PrtgApiError);

// --- errors
$c = new ClassicClient(PRTG_URL, 'k-123', '', '', prtg_fake($log, ['content=devices' => [401, '<div class="errormsg">You are not authorized k-123</div>']]));
$e = prtg_throws(fn () => $c->devices());
check('HTTP 401 -> PrtgApiError', $e instanceof PrtgApiError);
check('401 message redacted', $e !== null && !str_contains($e->getMessage(), 'k-123'), $e ? $e->getMessage() : 'none');

$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['content=devices' => [500, 'boom']]));
$e = prtg_throws(fn () => $c->devices());
check('HTTP 500 -> plain PrtgException', $e instanceof PrtgException && !($e instanceof PrtgApiError), $e ? $e::class : 'none');

$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['content=devices' => [200, '{"error":"bad column k"}']]));
check('{"error"} -> PrtgApiError', prtg_throws(fn () => $c->devices()) instanceof PrtgApiError);

$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['content=devices' => [200, '<html>login</html>']]));
$e = prtg_throws(fn () => $c->devices());
check('non-JSON -> plain PrtgException', $e instanceof PrtgException && !($e instanceof PrtgApiError));

$log = [];
$c = new ClassicClient(PRTG_URL, '', 'u', 'pw', prtg_fake($log, ['table.json' => new RuntimeException('Operation timed out for https://prtg.example/api/table.json?username=u&passhash=SECRETHASH')]));
$e = prtg_throws(fn () => $c->devices());
check('transport throw: PrtgException', $e instanceof PrtgException && !($e instanceof PrtgApiError));
check('transport throw: no SECRETHASH / passhash=', $e !== null && !str_contains($e->getMessage(), 'SECRETHASH') && !str_contains($e->getMessage(), 'passhash='), $e ? $e->getMessage() : 'none');
check('transport throw: no previous', $e !== null && $e->getPrevious() === null);

// --- message passes through raw (sanitising is the parser's job)
$rawMsg = '<div>a &amp;lt;b&amp;gt; value < 5 and > 3</div>';
$log = [];
$c = new ClassicClient(PRTG_URL, 'k', '', '', prtg_fake($log, ['content=sensors' => [200, json_encode(['sensors' => [['objid' => 1, 'message' => $rawMsg]]])]]));
check('sensor message passes through unchanged', $c->sensor('1')['message'] === $rawMsg, (string) $c->sensor('1')['message']);

// --- key header injection
$log = [];
$c = new ClassicClient(PRTG_URL, "k-1
X-Evil: 1", '', '', prtg_fake($log));
$e = prtg_throws(fn () => $c->devices());
check('key with CR/LF rejected, no request', $e instanceof PrtgException && $log === [] && !str_contains($e->getMessage(), 'X-Evil'));

// --- CR-only key
$log = [];
$c = new ClassicClient(PRTG_URL, "k-1\rX-Evil: 1", '', '', prtg_fake($log));
$e = prtg_throws(fn () => $c->devices());
check('key with CR only rejected, no request', $e instanceof PrtgException && $log === []);

// --- trace args (getTrace, not getTraceAsString) never hold a secret
function prtg_trace_args(Throwable $e): string
{
    $out = '';
    foreach ($e->getTrace() as $frame) {
        $out .= var_export($frame['args'] ?? [], true) . "
";
    }
    return $out;
}
ini_set('zend.exception_ignore_args', '0');
$traceCases = [
    'key 401'          => [new ClassicClient(PRTG_URL, 'KEY-SECRET-7', '', '', prtg_fake($log, ['content=devices' => [401, 'denied']])), ['KEY-SECRET-7']],
    'key transport'    => [new ClassicClient(PRTG_URL, 'KEY-SECRET-7', '', '', prtg_fake($log, ['content=devices' => new RuntimeException('boom')])), ['KEY-SECRET-7']],
    'login 401 table'  => [new ClassicClient(PRTG_URL, '', 'u', 'PW-SECRET-7', prtg_fake($log, ['content=devices' => [401, 'denied']])), ['PW-SECRET-7', '1234567890']],
    'login pw refused' => [new ClassicClient(PRTG_URL, '', 'u', 'PW-SECRET-7', prtg_fake($log, ['getpasshash' => [401, 'denied']])), ['PW-SECRET-7']],
];
foreach ($traceCases as $label => [$cc, $needles]) {
    $e = prtg_throws(fn () => $cc->devices());
    $dump = $e === null ? '' : prtg_trace_args($e);
    $bad = '';
    foreach ($needles as $n) {
        if (str_contains($dump, $n)) {
            $bad .= $n . ';';
        }
    }
    check('trace args clean: ' . $label, $e !== null && $e->getTrace() !== [] && $bad === '', $bad);
}
ini_set('zend.exception_ignore_args', '1');

// --- positive redaction over a variety of failures
$secrets = ['k-123', 'S3cretPw', '1234567890'];
$cases = [
    new ClassicClient(PRTG_URL, 'k-123', '', '', prtg_fake($log, ['content=devices' => new RuntimeException('fail Bearer k-123 and k-123')])),
    new ClassicClient(PRTG_URL, '', 'u', 'S3cretPw', prtg_fake($log, ['content=devices' => new RuntimeException('fail passhash=1234567890 S3cretPw 1234567890')])),
    new ClassicClient(PRTG_URL, '', 'u', 'S3cretPw', prtg_fake($log, ['content=devices' => [403, '<b>denied 1234567890 S3cretPw</b>']])),
];
$leak = '';
foreach ($cases as $cc) {
    $e = prtg_throws(fn () => $cc->devices());
    if ($e === null) {
        $leak .= 'no-exception;';
        continue;
    }
    foreach ($secrets as $secret) {
        if (str_contains($e->getMessage(), $secret) || str_contains((string) $e, $secret)) {
            $leak .= $secret . ';';
        }
    }
}
check('secrets never in message or (string) $e', $leak === '', $leak);

done();
