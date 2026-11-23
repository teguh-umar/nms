<?php

/**
 * Offline mock of the PRTG classic API and API v2, for tests and manual runs. Never shipped (tests/ is export-ignored).
 * Answers from tests/fixtures/prtg/classic/<line>/ and tests/fixtures/prtg/v2/. Synthetic data only.
 * Routes and field names are the ones ClassicClient and V2Client use.
 */

/** Query string to an array; a repeated name (filter_status=5&filter_status=13) becomes a list. */
function nms_mock_prtg_parse_query(string $qs): array
{
    $out = [];
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $k = urldecode($k);
        $v = urldecode($v);
        if (!isset($out[$k])) {
            $out[$k] = $v;
        } elseif (is_array($out[$k])) {
            $out[$k][] = $v;
        } else {
            $out[$k] = [$out[$k], $v];
        }
    }
    return $out;
}

/**
 * @param array<string, string|list<string>> $query
 * @param array<string, string> $headers request headers (any case)
 * @param string $line classic fixture folder: 26.2, 26.3 or 26.4 (v2 uses the v2 fixtures)
 * @return array{status: int, body: string}
 */
function nms_mock_prtg_handle(string $method, string $path, array $query, array $headers, ?string $body, string $line): array
{
    $json   = fn (int $status, mixed $data): array => ['status' => $status, 'body' => (string) json_encode($data)];
    $bearer = '';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization' && preg_match('/^Bearer\s+(\S+)/i', (string) $value, $m)) {
            $bearer = $m[1];
        }
    }
    $one    = fn (string $k): string => is_array($query[$k] ?? null) ? (string) end($query[$k]) : (string) ($query[$k] ?? '');
    $authed = $bearer !== '' || ($one('username') !== '' && $one('passhash') !== '');

    if (str_starts_with($path, '/api/v2/')) {
        if ($method === 'POST' && $path === '/api/v2/session') {
            $req = json_decode((string) $body, true);
            if (!is_array($req) || ($req['username'] ?? '') === '' || ($req['password'] ?? '') === '') {
                return $json(400, ['error' => 'username and password required']);
            }
            return $json(200, ['token' => 'mock-session-token-0001']);
        }
        if ($bearer === '') {
            return $json(401, ['error' => 'authentication required']);
        }
        $rel = substr($path, strlen('/api/v2'));
        if ($method !== 'GET') {
            return $json(404, ['error' => 'not found']);
        }
        if ($rel === '/experimental/system/version') {
            return $json(200, nms_mock_prtg_fixture('v2/version'));
        }
        if ($rel === '/experimental/devices' || $rel === '/experimental/sensors') {
            $items  = nms_mock_prtg_fixture('v2/' . substr($rel, strlen('/experimental/')))['items'] ?? [];
            $offset = max(0, (int) $one('offset'));
            $limit  = (int) $one('limit');
            return $json(200, ['items' => array_slice($items, $offset, $limit > 0 ? $limit : null)]);
        }
        if (preg_match('#^/experimental/sensors/([^/]+)$#', $rel, $m)) {
            foreach (nms_mock_prtg_fixture('v2/sensors')['items'] ?? [] as $s) {
                if ((string) ($s['id'] ?? '') === rawurldecode($m[1])) {
                    return $json(200, $s);
                }
            }
            return $json(404, ['error' => 'sensor not found']);
        }
        return $json(404, ['error' => 'not found']);
    }

    if (!preg_match('/^\d+\.\d+$/', $line) || !is_dir(__DIR__ . '/../fixtures/prtg/classic/' . $line)) {
        return $json(400, ['error' => 'Unknown mock line']);
    }
    if ($path === '/api/getpasshash.htm') {
        if ($one('username') === '' || $one('password') === '') {
            return ['status' => 400, 'body' => 'username and password required'];
        }
        return ['status' => 200, 'body' => '1234567890'];
    }
    if (!$authed) {
        return $json(401, ['error' => 'authentication required']);
    }
    if ($path === '/api/status.json') {
        return $json(200, nms_mock_prtg_fixture("classic/{$line}/status"));
    }
    if ($path === '/api/table.json') {
        $content = $one('content');
        if ($content === 'devices') {
            return $json(200, nms_mock_prtg_fixture("classic/{$line}/devices"));
        }
        if ($content !== 'sensors') {
            return $json(400, ['error' => 'unsupported content']);
        }
        $env  = nms_mock_prtg_fixture("classic/{$line}/sensors");
        $rows = $env['sensors'] ?? [];
        if (isset($query['filter_objid'])) {
            $id   = $one('filter_objid');
            $rows = array_values(array_filter($rows, fn ($r) => (string) ($r['objid'] ?? '') === $id));
        }
        if (isset($query['filter_status'])) {
            $codes = array_map('intval', (array) $query['filter_status']);
            $rows  = array_values(array_filter($rows, fn ($r) => in_array((int) ($r['status_raw'] ?? 0), $codes, true)));
        }
        $env['sensors']  = $rows;
        $env['treesize'] = count($rows);
        return $json(200, $env);
    }
    if ($path === '/api/acknowledgealarm.htm') {
        if ($one('id') === '' || !ctype_digit($one('id'))) {
            return ['status' => 400, 'body' => '<html><body>Invalid object id</body></html>'];
        }
        return ['status' => 200, 'body' => '<html><body>OK</body></html>'];
    }
    return $json(404, ['error' => 'not found']);
}

function nms_mock_prtg_fixture(string $name): array
{
    $file = __DIR__ . '/../fixtures/prtg/' . $name . '.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}
