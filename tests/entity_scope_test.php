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
check('webhook scopes its lookup', preg_match_all($pin, (string) file_get_contents(OPM_ROOT . '/front/webhook.php')) === 1);

done();
