<?php

/**
 * CLI one-shot migration from opmanager into nms.
 * On the VM:
 *   sudo -u nginx php /data/www/glpi/plugins/nms/tools/migrate_from_opmanager.php
 */

global $DB, $CFG_GLPI;

$glpiRoot = null;
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 2)] as $candidate) {
    if (is_file($candidate . '/vendor/autoload.php') && is_file($candidate . '/src/Glpi/Kernel/Kernel.php')) {
        $glpiRoot = $candidate;
        break;
    }
}
if ($glpiRoot === null) {
    fwrite(STDERR, "migrate_from_opmanager: cannot locate GLPI root from " . __DIR__ . "\n");
    exit(2);
}

// Same bootstrap as core front/cron.php (11.0.5).
require_once $glpiRoot . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();

use GlpiPlugin\Nms\Migration\FromOpmanager;

$now = date('Y-m-d H:i:s');
$result = FromOpmanager::run($DB, $now);

if (array_key_exists('skipped', $result)) {
    echo "opmanager not installed: " . $result['skipped'] . "\n";
    exit(0);
}

echo "NMS migration: connection id {$result['connections_id']}\n";
foreach ($result['counts'] as $table => $n) {
    echo "  {$table}: {$n}\n";
}
echo "  rights_copied: {$result['rights_copied']}\n";
foreach ($result['core_refs'] as $what => $n) {
    echo "  {$what}: {$n}\n";
}
if ($result['core_refs']['dashboard_cards_waiting'] > 0) {
    echo "  (dashboard widgets switch to nms when this runs again after opmanager is deactivated, runbook step 5)\n";
}
echo "done\n";
