<?php

require __DIR__ . '/bootstrap.php';

/** Grep the tree (excluding tests/, docs/, CHANGELOG.md, src/Migration/) for a literal pattern. */
function identityGrepCount(string $pattern): int
{
    $root  = __DIR__ . '/..';
    $count = 0;
    $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $rel  = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $top  = explode('/', $rel, 2)[0];
        if ($top === 'tests' || $top === 'docs') {
            continue;
        }
        if ($top === 'src' && str_starts_with($rel, 'src/Migration/')) {
            continue;
        }
        if ($rel === 'CHANGELOG.md') {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($path);
        if ($content !== false) {
            $count += substr_count($content, $pattern);
        }
    }
    return $count;
}

/** Read a source file, or '' on failure. */
function src(string $rel): string
{
    $path = __DIR__ . '/../' . $rel;
    $content = @file_get_contents($path);
    return $content === false ? '' : $content;
}

check('no opmanager namespace', identityGrepCount('GlpiPlugin\\Opmanager') === 0);
check('no opmanager tables',   identityGrepCount('glpi_plugin_opmanager_') === 0);
check('no opmanager rights',   identityGrepCount('plugin_opmanager_') === 0);

$config  = src('src/Config.php');
check('context', str_contains($config, "const CONTEXT = 'plugin:nms'"));

$assetSections = src('src/AssetSections.php');
$menu          = src('src/Menu.php');
$suiteConfig   = src('src/SuiteConfig.php');
check('orders',
    str_contains($assetSections, 'public const ORDER = 65;')
    && str_contains($menu, 'public const ORDER = 65;')
    && str_contains($suiteConfig, 'public const ORDER = 75;'));

// Search option IDs: every numeric id in Device::rawSearchOptions() and Alarm::rawSearchOptions()
// must be within 8380..8389. Parse ['id' => N] / ['id' => N,] from the source.
$searchIds = [];
foreach (['src/Device.php', 'src/Alarm.php'] as $rel) {
    $source = src($rel);
    preg_match_all("/'id'\s*=>\s*(\d+)/", $source, $matches, \PREG_SET_ORDER);
    foreach ($matches as $m) {
        $searchIds[] = (int) $m[1];
    }
}
if ($searchIds !== []) {
    $inRange = true;
    foreach ($searchIds as $id) {
        if ($id < 8380) {
            $inRange = false;
            break;
        }
    }
    check('search options in range', $inRange, 'ids: ' . implode(',', $searchIds));
} else {
    check('search options in range', false, 'no ids found');
}

done();
