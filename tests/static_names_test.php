<?php

// Review 2026-11-12: name resolution as PHP does it, over every shipped PHP file. Catches what
// fakes hide: a plugin class used without its `use` line (falls back to a GLPI class of the same
// short name, e.g. Config, or to a class that does not exist), `new` on an interface, and an alias
// that captures a qualified name (`use ...\Connection\Connection;` + `Connection\Settings`).

require __DIR__ . '/bootstrap.php';

const NS = 'GlpiPlugin\\Nms\\';

/** @return list<string> shipped PHP files, relative */
function shippedPhp(): array
{
    $out = [];
    foreach (['src', 'front', 'tools', 'install'] as $dir) {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OPM_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen(OPM_ROOT) + 1));
            }
        }
    }
    $out[] = 'setup.php';
    if (is_file(OPM_ROOT . '/hook.php')) {
        $out[] = 'hook.php';
    }
    return $out;
}

/**
 * Plugin declarations: FQN => kind (class, abstract, interface, trait, enum).
 *
 * @return array<string, string>
 */
function declarations(array $files): array
{
    $out = [];
    foreach ($files as $rel) {
        $tokens = token_get_all((string) file_get_contents(OPM_ROOT . '/' . $rel));
        $ns = '';
        $abstract = false;
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $ns .= $tokens[$j][1];
                    }
                }
            }
            if ($t[0] === T_ABSTRACT) {
                $abstract = true;
            }
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $i > 0 ? $tokens[$i - 1] : null;
                // `Foo::class` and `new class` are not declarations.
                $k = $i - 1;
                while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k--;
                }
                if ($k >= 0 && ($tokens[$k] === '::' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_DOUBLE_COLON, T_NEW], true)))) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $kind = match ($t[0]) { T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum', default => $abstract ? 'abstract' : 'class' };
                        $out[($ns !== '' ? $ns . '\\' : '') . $tokens[$j][1]] = $kind;
                        break;
                    }
                }
                $abstract = false;
            }
        }
    }
    return $out;
}

/** Declared on the class itself or on a plugin parent/trait it names (enough for this codebase). */
function methodExists(string $fqn, string $method, array $decl): bool
{
    static $sources = null;
    if ($sources === null) {
        $sources = [];
        foreach (shippedPhp() as $rel) {
            $src = (string) file_get_contents(OPM_ROOT . '/' . $rel);
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $m) && preg_match_all('/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $c)) {
                foreach ($c[1] as $short) {
                    $sources[$m[1] . '\\' . $short] = $src;
                }
            }
        }
    }
    $src = $sources[$fqn] ?? null;
    if ($src === null) {
        return true; // not a plugin file we can read: do not guess
    }
    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', $src)) {
        return true;
    }
    // Inherited from a non-plugin parent (CommonDBTM, ...): cannot check here.
    return (bool) preg_match('/class\s+\w+\s+extends\s+\\\\?(?!GlpiPlugin)/', $src);
}

$files = shippedPhp();
$decl  = declarations($files);
$shortNames = [];
foreach (array_keys($decl) as $fqn) {
    $shortNames[substr($fqn, strrpos($fqn, '\\') + 1)] = true;
}

$problems = [];
foreach ($files as $rel) {
    $src    = (string) file_get_contents(OPM_ROOT . '/' . $rel);
    $tokens = array_values(array_filter(token_get_all($src), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $ns   = '';
    $uses = [];
    $depth = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($t === '}') {
            $depth--;
        }
        if (is_array($t) && $t[0] === T_NAMESPACE && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])) {
            $ns = $tokens[$i + 1][1];
            continue;
        }
        // Top-level `use A\B [as C];` (not closure `use (...)`, not trait `use` inside a class).
        if (is_array($t) && $t[0] === T_USE && $depth === 0 && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])) {
            $j = $i + 1;
            if (in_array($tokens[$j][1], ['function', 'const'], true)) {
                continue;
            }
            $name  = ltrim($tokens[$j][1], '\\');
            $alias = substr($name, strrpos('\\' . $name, '\\'));
            if (isset($tokens[$j + 1]) && is_array($tokens[$j + 1]) && $tokens[$j + 1][0] === T_AS) {
                $alias = $tokens[$j + 2][1];
            }
            $uses[strtolower($alias)] = $name;
            continue;
        }
        $isNew    = is_array($t) && $t[0] === T_NEW;
        $nameTok  = $isNew ? ($tokens[$i + 1] ?? null) : $t;
        $nextTok  = $isNew ? null : ($tokens[$i + 1] ?? null);
        $isStatic = !$isNew && is_array($nextTok) && $nextTok[0] === T_DOUBLE_COLON;
        if (!$isNew && !$isStatic) {
            continue;
        }
        if (!is_array($nameTok) || !in_array($nameTok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $name = $nameTok[1];
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            continue;
        }
        if ($nameTok[0] === T_NAME_FULLY_QUALIFIED) {
            $fqn = ltrim($name, '\\');
        } else {
            $first = strtolower(explode('\\', $name)[0]);
            if (isset($uses[$first])) {
                $rest = substr($name, strlen(explode('\\', $name)[0]));
                $fqn  = $uses[$first] . $rest;
            } else {
                $fqn = ($ns !== '' ? $ns . '\\' : '') . $name;
            }
        }
        $line = $nameTok[2];
        if (str_starts_with($fqn, NS)) {
            if (!isset($decl[$fqn])) {
                $problems[] = "{$rel}:{$line} {$name} resolves to {$fqn}, which does not exist";
            } elseif ($isNew && $decl[$fqn] !== 'class') {
                $problems[] = "{$rel}:{$line} new {$name}: {$fqn} is an {$decl[$fqn]}";
            } elseif ($isStatic) {
                // Foo::bar( must name a method Foo declares (constants and ::class are skipped).
                $member = $tokens[$i + 2] ?? null;
                $paren  = $tokens[$i + 3] ?? null;
                if (is_array($member) && $member[0] === T_STRING && $paren === '(' && !methodExists($fqn, $member[1], $decl)) {
                    $problems[] = "{$rel}:{$line} {$name}::{$member[1]}() is not declared on {$fqn}";
                }
            }
        } elseif ($nameTok[0] === T_STRING && isset($shortNames[$name]) && !isset($uses[strtolower($name)])) {
            // A plugin short name used without its `use` line: PHP takes the global class of the
            // same name (GLPI's Config, ...), or fails at run time.
            $problems[] = "{$rel}:{$line} {$name} is a plugin class but is not imported; it resolves to {$fqn}";
        }
    }
}

check('every class name in shipped PHP resolves to what it means', $problems === [], "\n  " . implode("\n  ", $problems));

done();
