<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/Driver/UnsupportedOperation.php';
require OPM_ROOT . '/src/Driver/OpManager/OpManagerDriver.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\OpManager\OpManagerDriver;

$conn = new Connection([
    'id' => 1, 'name' => 'opm.example.test', 'brand' => 'opmanager',
    'entities_id' => 1, 'is_recursive' => 1, 'url' => 'https://opm.example.test',
    'api_key' => '', 'username' => '', 'password' => '', 'verify_tls' => 1,
    'is_active' => 1, 'writeback_enabled' => 0, 'tickets_enabled' => 0,
    'webhook_token' => '', 'settings' => '{}',
    'date_creation' => '2026-11-11 00:00:00', 'date_mod' => '2026-11-11 00:00:00',
]);

$d = DriverFactory::for($conn);
check('for() opmanager returns OpManagerDriver', $d instanceof OpManagerDriver);

$bad = new Connection(array_merge((array) $conn, ['brand' => 'zabbix']));
try {
    DriverFactory::for($bad);
    check('for() zabbix throws InvalidArgumentException', false);
} catch (\InvalidArgumentException $e) {
    check('for() zabbix throws InvalidArgumentException', true);
} catch (\Throwable $e) {
    check('for() zabbix throws InvalidArgumentException', false, 'wrong exception: ' . get_class($e));
}

done();
