<?php

// Thin web wrapper, loopback only:
//   php -S 127.0.0.1:8098 tests/mock_prtg/index.php      then  http://127.0.0.1:8098/api/status.json?line=26.4
// The fixture line comes from ?line= (default 26.4). Never bind this to a public interface.
require __DIR__ . '/handler.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    echo 'loopback only';
    return;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}
$path  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$query = nms_mock_prtg_parse_query((string) ($_SERVER['QUERY_STRING'] ?? ''));
$line  = (string) ($query['line'] ?? '26.4');
unset($query['line']);

$res = nms_mock_prtg_handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $query, $headers, file_get_contents('php://input') ?: null, $line);
http_response_code($res['status']);
header(str_ends_with($path, '.htm') ? 'Content-Type: text/html' : 'Content-Type: application/json');
echo $res['body'];
