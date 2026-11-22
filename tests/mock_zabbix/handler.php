<?php

/**
 * Offline mock of the Zabbix JSON-RPC API, for tests and manual runs. Never shipped (tests/ is export-ignored).
 * Answers from tests/fixtures/zabbix/<version>/<method>.json. Synthetic data only.
 *
 * @param array<string, string> $headers request headers (any case)
 * @param string                $version fixture folder: 6.0, 7.0 or 7.4
 */
function nms_mock_zabbix_handle(string $body, array $headers, string $version): string
{
    $req = json_decode($body, true);
    $id  = is_array($req) ? ($req['id'] ?? null) : null;
    $err = fn(int $code, string $msg, string $data) => json_encode(
        ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $msg, 'data' => $data], 'id' => $id],
    );
    if (!is_array($req) || !is_string($req['method'] ?? null)) {
        return $err(-32600, 'Invalid request.', 'Not a JSON-RPC request.');
    }
    if (!preg_match('/^\d+\.\d+$/', $version)) {
        return $err(-32602, 'Invalid params.', 'Unknown mock version.');
    }
    $method = $req['method'];
    $ok     = fn($result) => json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $id]);

    if ($method === 'apiinfo.version') {
        return $ok(nms_mock_zabbix_fixture($version, 'apiinfo.version'));
    }
    if ($method === 'user.login') {
        return $ok('mock-session-0001');
    }

    $auth = is_string($req['auth'] ?? null) ? $req['auth'] : '';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization' && str_starts_with((string) $value, 'Bearer ')) {
            $auth = substr((string) $value, 7);
        }
    }
    if ($auth === '') {
        return $err(-32602, 'Not authorized.', 'Not authorized.');
    }

    if ($method === 'event.acknowledge') {
        $p = is_array($req['params'] ?? null) ? $req['params'] : [];
        return $ok(['eventids' => $p['eventids'] ?? []]);
    }
    if ($method === 'user.logout') {
        return $ok(true);
    }
    $result = nms_mock_zabbix_fixture($version, $method);
    if ($result === null) {
        return $err(-32601, 'Method not found.', "No fixture for {$method}.");
    }
    return $ok($result);
}

function nms_mock_zabbix_fixture(string $version, string $method): mixed
{
    if (!preg_match('/^[a-z]+\.[a-z]+$/', $method)) {
        return null;
    }
    $file = __DIR__ . "/../fixtures/zabbix/{$version}/{$method}.json";
    if (!is_file($file)) {
        return null;
    }
    return json_decode((string) file_get_contents($file), true);
}
