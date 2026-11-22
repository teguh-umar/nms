<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/DbAssetLookup.php';
require OPM_ROOT . '/src/Matching/EntityScope.php';

use GlpiPlugin\Nms\Matching\DbAssetLookup;
use GlpiPlugin\Nms\Matching\EntityScope;

// Root + recursive is "every entity" (the production OpManager connection): no filter at all.
$calls = 0;
$sons  = static function (int $id) use (&$calls): array {
    $calls++;
    return [$id];
};
check('root + recursive = every entity (null)', EntityScope::of(0, 1, $sons) === null);
check('root + recursive never asks for sons', $calls === 0);
check('root, not recursive = [0]', EntityScope::of(0, 0, $sons) === [0]);
check('subsidiary recursive = its sons', EntityScope::of(81, 1, static fn (int $id): array => [81, 82, 90]) === [81, 82, 90]);
check('subsidiary not recursive = itself', EntityScope::of(81, 0, $sons) === [81]);
check('sons keyed by id are normalised to a list', EntityScope::of(81, 1, static fn (int $id): array => [81 => 81, 82 => 82]) === [81, 82]);

check('null scope accepts any entity', DbAssetLookup::inScope(null, 5) === true);
check('entity inside the scope', DbAssetLookup::inScope([81, 82], 82) === true);
check('entity outside the scope', DbAssetLookup::inScope([81], 0) === false);

// Source pins: every connection-driven lookup is scoped; SelfCheck stays unscoped.
$pin = '/new DbAssetLookup\(Config::assetItemtypes\(\), EntityScope::forConnection\(/';
check('Cron scopes both lookups', preg_match_all($pin, (string) file_get_contents(OPM_ROOT . '/src/Cron.php')) === 2);
$webhookSrc = (string) file_get_contents(OPM_ROOT . '/front/webhook.php');
check('webhook scopes its lookup once, from the connection',
    preg_match_all('/new DbAssetLookup\(Config::assetItemtypes\(\), \$scope\)/', $webhookSrc) === 1
    && preg_match_all('/\$scope = EntityScope::forConnection\(\$conn\);/', $webhookSrc) === 1);

// The text pin alone passed while the webhook closure used $conn without importing it.
// Scope check: the variable handed to forConnection() must be defined where the lookup is built.
$webhook = (string) file_get_contents(OPM_ROOT . '/front/webhook.php');
$at      = strpos($webhook, 'new DbAssetLookup(');
$before  = substr($webhook, 0, (int) $at);
preg_match_all('/function\s*\([^)]*\)\s*use\s*\(([^)]*)\)\s*(?::\s*\??\w+\s*)?\{/', $before, $uses, PREG_OFFSET_CAPTURE);
$last    = $uses[1] ? end($uses[1]) : null;
$closureStart = $uses[0] ? end($uses[0])[1] : -1;
preg_match('/new DbAssetLookup\(Config::assetItemtypes\(\), (\$\w+)\)/', substr($webhook, (int) $at), $m);
$var = $m[1] ?? '';
$imported = $last !== null ? array_map('trim', explode(',', $last[0])) : [];
// Inside a closure the variable must be imported; outside one it must be assigned earlier.
$inClosure = $last !== null && substr_count(substr($webhook, $closureStart, (int) $at - $closureStart), '}') < substr_count(substr($webhook, $closureStart, (int) $at - $closureStart), '{');
check('webhook: scope variable is defined where the lookup is built',
    $var !== '' && ($inClosure ? in_array($var, $imported, true) : strpos($before, $var . ' =') !== false),
    'var=' . $var . ' imported=' . implode(',', $imported));

// Cron: both lookups sit inside foreach (... as $conn).
$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('Cron: both scoped lookups are inside foreach as $conn',
    preg_match_all('/foreach \(\$store->active\(\) as \$conn\) \{(?:(?!foreach).)*?forConnection\(\$conn\)/s', $cron) === 2);

done();
