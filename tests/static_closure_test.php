<?php

// VM run of rc3 (2026-11-13): DbOutboxStore::summary() used $this inside a `static fn`, a fatal
// ("Using $this when not in object context") on every connection page. PHP reports it only at
// run time, and the fakes never called summary() on a real object. This test reads every shipped
// file and fails on $this inside a static closure or a static arrow function.
// It also pins the names nms shows in GLPI next to the live opmanager plugin.

require __DIR__ . '/bootstrap.php';

$files = [];
foreach (['src', 'front', 'tools', 'install'] as $dir) {
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OPM_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
$files[] = OPM_ROOT . '/setup.php';

/** @return list<string> "file:line" of each $this inside a static closure */
function staticThis(string $src, string $rel): array
{
    $t = array_values(array_filter(token_get_all($src), static fn ($x) => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $n = count($t);
    $out = [];
    for ($i = 0; $i + 1 < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STATIC || !is_array($t[$i + 1]) || !in_array($t[$i + 1][0], [T_FN, T_FUNCTION], true)) {
            continue;
        }
        $line = $t[$i][2];
        $arrow = $t[$i + 1][0] === T_FN;
        // Skip to the body: `=>` for fn, the first `{` for function.
        $j = $i + 2;
        $depth = 0;
        while ($j < $n) {
            if ($arrow && is_array($t[$j]) && $t[$j][0] === T_DOUBLE_ARROW && $depth === 0) {
                break;
            }
            if (!$arrow && $t[$j] === '{') {
                break;
            }
            if ($t[$j] === '(' || $t[$j] === '[') {
                $depth++;
            } elseif ($t[$j] === ')' || $t[$j] === ']') {
                $depth--;
            }
            $j++;
        }
        // Walk the body until it closes.
        $depth = 0;
        for ($k = $j + 1; $k < $n; $k++) {
            $x = $t[$k];
            if ($x === '(' || $x === '[' || $x === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($x === ')' || $x === ']' || $x === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                if (!$arrow && $depth === 0 && $x === '}') {
                    break;
                }
            } elseif ($arrow && $depth === 0 && ($x === ';' || $x === ',')) {
                break;
            }
            if (is_array($x) && $x[0] === T_VARIABLE && $x[1] === '$this') {
                $out[] = "{$rel}:{$x[2]} (static closure from line {$line})";
            }
        }
    }
    return $out;
}

$problems = [];
foreach ($files as $path) {
    $rel = str_replace('\\', '/', substr($path, strlen(OPM_ROOT) + 1));
    $problems = array_merge($problems, staticThis((string) file_get_contents($path), $rel));
}
check('no $this inside a static closure', $problems === [], "\n  " . implode("\n  ", $problems));

// Self-test: the scanner sees both forms, and leaves non-static closures alone.
check('scanner catches static fn', staticThis('<?php $f = static fn () => $this->x;', 'x') !== []);
check('scanner catches static function', staticThis('<?php $f = static function () { return $this->x; };', 'x') !== []);
check('scanner ignores fn', staticThis('<?php $f = fn () => $this->x;', 'x') === []);
check('scanner stops at the end of a static fn', staticThis('<?php $f = static fn () => 1; $g = $this->x;', 'x') === []);

// Names shown in GLPI next to the live opmanager plugin must not be "OpManager".
$profile = (string) file_get_contents(OPM_ROOT . '/src/Profile.php');
check('Profiles tab is not named OpManager', !preg_match("/function getTypeName.*?return 'OpManager';/s", $profile));
$cards = (string) file_get_contents(OPM_ROOT . '/src/Coverage/Cards.php');
check('dashboard card group is not OpManager', !str_contains($cards, "GROUP = 'OpManager'"));

done();
