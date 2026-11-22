<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Driver/Zabbix/ZabbixException.php';
require OPM_ROOT . '/src/Driver/Zabbix/JsonRpc.php';

use GlpiPlugin\Nms\Driver\Zabbix\JsonRpc;
use GlpiPlugin\Nms\Driver\Zabbix\ZabbixException;

/** Recording fake transport; $extra maps method => body (string) or [status, body]. */
function zbx_fake(array &$log, string $version, array $extra = []): callable
{
    return function (string $url, string $body, array $headers) use (&$log, $version, $extra): array {
        $req   = json_decode($body, true);
        $log[] = ['url' => $url, 'req' => $req, 'headers' => $headers, 'raw' => $body];
        $m     = $req['method'];
        if (isset($extra[$m])) {
            $r = $extra[$m];
            return is_array($r) ? ['status' => $r[0], 'body' => $r[1]] : ['status' => 200, 'body' => $r];
        }
        $result = match ($m) {
            'apiinfo.version' => $version,
            'user.login'      => 'sess123',
            'user.logout'     => true,
            default           => ['ok' => $m],
        };
        return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $req['id']])];
    };
}

$base = 'https://zbx.example/zabbix/api_jsonrpc.php';
check('endpoint appends', JsonRpc::endpoint('https://zbx.example/zabbix') === $base);
check('endpoint strips slash then appends', JsonRpc::endpoint('https://zbx.example/zabbix/') === $base);
check('endpoint unchanged', JsonRpc::endpoint($base) === $base);
check('endpoint strips trailing slash after file', JsonRpc::endpoint($base . '/') === $base);

// 6.0 + token: body auth.
$log = [];
$c   = new JsonRpc('https://zbx.example/zabbix', 'tok', '', '', zbx_fake($log, '6.0.30'));
check('authMode token', $c->authMode() === 'token');
$res = $c->call('host.get', ['output' => ['hostid']]);
check('result returned', $res === ['ok' => 'host.get']);
check('version cached', $c->version() === '6.0.30' && count($log) === 2);
check('posts to endpoint', $log[0]['url'] === $base);
check('apiinfo no auth key', !array_key_exists('auth', $log[0]['req']));
check('apiinfo no auth header', !isset($log[0]['headers']['Authorization']));
check('6.0 body auth', ($log[1]['req']['auth'] ?? null) === 'tok');
check('6.0 no Authorization header', !isset($log[1]['headers']['Authorization']));
check('content type', ($log[1]['headers']['Content-Type'] ?? '') === 'application/json-rpc');
check('ids increase', $log[1]['req']['id'] > $log[0]['req']['id']);
check('jsonrpc 2.0', $log[1]['req']['jsonrpc'] === '2.0');
$c->logout();
check('token logout no-op', count($log) === 2);

// 7.0 + token: header.
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '7.0.5'));
$c->call('host.get', []);
check('7.0 bearer header', ($log[1]['headers']['Authorization'] ?? '') === 'Bearer tok');
check('7.0 no auth key', !array_key_exists('auth', $log[1]['req']));
check('7.0 apiinfo bare', !isset($log[0]['headers']['Authorization']) && !array_key_exists('auth', $log[0]['req']));

// 6.4 boundary.
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '6.4.0'));
$c->call('host.get', []);
check('6.4 uses header', ($log[1]['headers']['Authorization'] ?? '') === 'Bearer tok');
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '6.2.9'));
$c->call('host.get', []);
check('6.2 uses body', ($log[1]['req']['auth'] ?? null) === 'tok');

// Login mode, 7.0.
$log = [];
$c   = new JsonRpc($base, '', 'api-user', 'pw-secret-1', zbx_fake($log, '7.0.5'));
check('authMode login', $c->authMode() === 'login');
$c->call('host.get', []);
$c->call('item.get', []);
$logins = array_values(array_filter($log, fn($l) => $l['req']['method'] === 'user.login'));
check('login: apiinfo.version first', $log[0]['req']['method'] === 'apiinfo.version');
check('login: user.login second', $log[1]['req']['method'] === 'user.login');
check('login once', count($logins) === 1);
check('login params', $logins[0]['req']['params'] === ['username' => 'api-user', 'password' => 'pw-secret-1']);
check('login sent without auth', !isset($logins[0]['headers']['Authorization']) && !array_key_exists('auth', $logins[0]['req']));
$calls = array_values(array_filter($log, fn($l) => in_array($l['req']['method'], ['host.get', 'item.get'], true)));
check('session as bearer', ($calls[0]['headers']['Authorization'] ?? '') === 'Bearer sess123' && ($calls[1]['headers']['Authorization'] ?? '') === 'Bearer sess123');
$c->logout();
check('logout calls user.logout', end($log)['req']['method'] === 'user.logout');
check('logout carries session', (end($log)['headers']['Authorization'] ?? '') === 'Bearer sess123');
$n = count($log);
$c->logout();
check('second logout no-op', count($log) === $n);

// Login mode, 6.0: session in body.
$log = [];
$c   = new JsonRpc($base, '', 'u', 'pw', zbx_fake($log, '6.0.1'));
$c->call('host.get', []);
check('6.0 login session in body', ($log[2]['req']['auth'] ?? null) === 'sess123' && $log[0]['req']['method'] === 'apiinfo.version');

// JSON-RPC error with HTTP 200.
$err = json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32602, 'message' => 'Invalid params.', 'data' => 'No permissions to referred object.'], 'id' => 2]);
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '7.0.5', ['host.get' => $err]));
try {
    $c->call('host.get', []);
    check('error raises', false);
} catch (ZabbixException $e) {
    check('error has data', str_contains($e->getMessage(), 'No permissions to referred object.'));
    check('error has no token', !str_contains($e->getMessage(), 'tok'));
}
$errLeak = json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32500, 'message' => 'Application error.', 'data' => 'bad pw pw-secret-1 tok'], 'id' => 2]);
$log = [];
$c   = new JsonRpc($base, '', 'u', 'pw-secret-1', zbx_fake($log, '7.0.5', ['user.login' => $errLeak]));
try {
    $c->call('host.get', []);
    check('login error raises', false);
} catch (ZabbixException $e) {
    check('password redacted', !str_contains($e->getMessage(), 'pw-secret-1'));
}

// Secrets never reach the exception text, string form or previous chain.
function zbx_leaks(\Throwable $e, array $secrets): bool
{
    $all = '';
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        $all .= $x->getMessage() . (string) $x;
    }
    foreach ($secrets as $s) {
        if (str_contains($all, $s)) {
            return true;
        }
    }
    return false;
}
$T = 'tok-SECRET-7731';
$P = 'pw-SECRET-4482';
$S = 'sess-SECRET-9915';
ini_set('zend.exception_ignore_args', '0');
$leak = json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32500, 'message' => 'Application error.', 'data' => "echo $T and $S"], 'id' => 3]);
$log = [];
$c   = new JsonRpc($base, $T, '', '', zbx_fake($log, '7.0.5', ['host.get' => $leak]));
try {
    $c->call('host.get', []);
    check('leak token raises', false);
} catch (ZabbixException $e) {
    check('token in error data redacted everywhere', !zbx_leaks($e, [$T]));
}
$log = [];
$c   = new JsonRpc($base, '', 'u', $P, zbx_fake($log, '7.0.5', ['host.get' => $leak, 'user.login' => json_encode(['jsonrpc' => '2.0', 'result' => $S, 'id' => 2])]));
try {
    $c->call('host.get', []);
    check('leak session raises', false);
} catch (ZabbixException $e) {
    check('session in error data redacted everywhere', !zbx_leaks($e, [$S, $P]));
}
$throwing = function (string $url, string $body, array $headers) use ($T): array {
    throw new \RuntimeException('connect failed with Authorization: Bearer ' . $T);
};
$c = new JsonRpc($base, $T, '', '', function (string $url, string $body, array $headers) use ($throwing, $T): array {
    $req = json_decode($body, true);
    if ($req['method'] === 'apiinfo.version') {
        return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => '7.0.5', 'id' => $req['id']])];
    }
    return $throwing($url, $body, $headers);
});
try {
    $c->call('host.get', []);
    check('transport throw raises', false);
} catch (ZabbixException $e) {
    check('transport throw: token not in message/string/previous', !zbx_leaks($e, [$T]));
    check('transport throw: no previous', $e->getPrevious() === null);
}

// HTTP 500.
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '7.0.5', ['apiinfo.version' => [500, 'boom tok']]));
try {
    $c->version();
    check('http 500 raises', false);
} catch (ZabbixException $e) {
    check('http 500 names status', str_contains($e->getMessage(), '500'));
    check('http 500 no token', !str_contains($e->getMessage(), 'tok'));
}

// Non-JSON body.
$log = [];
$c   = new JsonRpc($base, 'tok', '', '', zbx_fake($log, '7.0.5', ['apiinfo.version' => '<html>']));
try {
    $c->version();
    check('non-json raises', false);
} catch (ZabbixException $e) {
    check('non-json raises', true);
}

done();
