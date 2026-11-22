<?php

// Thin web wrapper: php -S 127.0.0.1:8099 -t tests/mock_zabbix   then  http://127.0.0.1:8099/index.php?v=7.0
require __DIR__ . '/handler.php';

header('Content-Type: application/json');
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}
echo nms_mock_zabbix_handle((string) file_get_contents('php://input'), $headers, (string) ($_GET['v'] ?? '7.0'));
