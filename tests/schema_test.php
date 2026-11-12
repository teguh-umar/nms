<?php

require __DIR__ . '/bootstrap.php';

// Suite schema rules (hub §4 rules 1, 2c, 2d) checked against the literal DDL.
$src = (string) @file_get_contents(OPM_ROOT . '/install/install.php');
// DDL is written as CREATE TABLE `$table` (...), so take the name from the
// `$table = '...'` assignment that precedes each block.
preg_match_all(
    '/\$table = \'(\w+)\';\s*if \(!\$DB->tableExists\(\$table\)\) \{\s*\$DB->doQuery\("CREATE TABLE `\$table` \((.*?)\) ENGINE/s',
    $src,
    $tables,
    PREG_SET_ORDER
);
check('install creates the devices table', in_array('glpi_plugin_nms_devices', array_column($tables, 1), true));

foreach ($tables as [, $table, $body]) {
    check("{$table}: no DATETIME column", stripos($body, 'DATETIME') === false);
    preg_match_all('/^\s*`(\w+)`\s+([^,\n]+)/m', $body, $cols, PREG_SET_ORDER);
    foreach ($cols as [, $col, $type]) {
        if ($col === 'id' || str_ends_with($col, '_id')) {
            check("{$table}.{$col} is INT UNSIGNED", (bool) preg_match('/^INT UNSIGNED\b/i', trim($type)), trim($type));
        }
        if (str_ends_with($col, '_key')) {
            check("{$table}.{$col} external id is VARCHAR", stripos($type, 'VARCHAR') === 0);
        }
    }
}

foreach (['glpi_plugin_nms_alarms', 'glpi_plugin_nms_storms'] as $t) {
    check("install creates {$t}", in_array($t, array_column($tables, 1), true));
}
check('alarms.alarm_key is UNIQUE per connection (D3)', (bool) preg_match("/'glpi_plugin_nms_alarms'.*?UNIQUE KEY `alarm_key` \(`connections_id`, `alarm_key`\)/s", $src));
check('alarms keep entity_key for notes/clear', str_contains($src, '`entity_key` VARCHAR(255)'));
check('uninstall drops the new tables', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_nms_alarms')
    && str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_nms_storms'));

check('alarms track last followup time', str_contains($src, '`last_followup_at` TIMESTAMP'));

// Task 4: every data table is scoped by connections_id.
foreach (['glpi_plugin_nms_devices', 'glpi_plugin_nms_alarms', 'glpi_plugin_nms_outbox', 'glpi_plugin_nms_storms', 'glpi_plugin_nms_ticketrequests', 'glpi_plugin_nms_deviceexceptions', 'glpi_plugin_nms_hygienetickets'] as $t) {
    check("{$t} has connections_id column", str_contains($src, "`connections_id` INT UNSIGNED NOT NULL DEFAULT 0"));
    check("{$t} has connections_id key", (bool) preg_match("/KEY `connections_id` \(`connections_id`\)/", $src));
}
check('devices unique key is (connections_id, device_key)', (bool) preg_match('/UNIQUE KEY `device_key` \(`connections_id`, `device_key`\)/', $src));
check('alarms unique key is (connections_id, alarm_key)', (bool) preg_match('/UNIQUE KEY `alarm_key` \(`connections_id`, `alarm_key`\)/', $src));

// v1.1.0 (D32): deferred webhook requests.
check('install creates glpi_plugin_nms_ticketrequests', in_array('glpi_plugin_nms_ticketrequests', array_column($tables, 1), true));
check('ticketrequests.alarm_key is UNIQUE per connection', (bool) preg_match('/ticketrequests.*?UNIQUE KEY `alarm_key` \(`connections_id`, `alarm_key`\)/s', $src));
check('uninstall drops ticketrequests', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_nms_ticketrequests'));

// v1.3.0 (D34): NMS hygiene.
foreach (['glpi_plugin_nms_deviceexceptions', 'glpi_plugin_nms_hygienetickets', 'glpi_plugin_nms_hygieneitems'] as $t) {
    check("install creates {$t}", in_array($t, array_column($tables, 1), true));
    check("uninstall drops {$t}", str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), $t));
}
$devices_ddl = '';
foreach ($tables as [, $table, $body]) {
    if ($table === 'glpi_plugin_nms_devices') {
        $devices_ddl = $body;
    }
}
foreach (['liveness_state', 'liveness_source', 'liveness_detail', 'liveness_checked_at', 'hygiene_state'] as $col) {
    check("devices CREATE has {$col}", str_contains($devices_ddl, "`{$col}`"));
    check("devices upgrade adds {$col}", (bool) preg_match("/'{$col}'\s+=> '/", $src));
}
check('upgrade ALTER is guarded by fieldExists', str_contains($src, 'if (!$DB->fieldExists($table, $col))'));
check('liveness_source holds a 32-char provider name', str_contains($src, '`liveness_source` VARCHAR(32)'));
check('hygieneitems one row per ticket and device', (bool) preg_match('/hygieneitems.*?UNIQUE KEY `ticket_device` \(`plugin_nms_hygienetickets_id`, `device_key`\)/s', $src));
check('HygieneCheck cron registered in CLI mode', str_contains($src, "'HygieneCheck', Config::HYGIENE_CRON_MINUTES * MINUTE_TIMESTAMP"));

done();
