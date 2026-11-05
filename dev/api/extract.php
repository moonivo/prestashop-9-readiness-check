<?php
/**
 * Extract the public API of a PrestaShop source tree: classes (with inherited methods
 * and constants), global functions, hooks and Smarty plugins, as JSON. diff.php then
 * compares versions to find what PrestaShop 9 removed.
 *
 *   php dev/api/extract.php <prestashop-source-dir> <out.json>
 *
 * Dev tool, not shipped.
 */
if (PHP_SAPI !== 'cli' || $argc < 3) {
    exit("usage: php extract.php <ps-src> <out.json>\n");
}

$root = rtrim($argv[1], '/\\');
$classes = [];
$functions = [];

$dirs = ['classes', 'controllers', 'src', 'config'];
foreach ($dirs as $dir) {
    if (!is_dir("$root/$dir")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        parseFile($file->getPathname(), $dir, $classes, $functions);
    }
}

// Legacy classes are declared as FooCore and used as Foo (class_index / autoload alias).
foreach ($classes as $name => $c) {
    if ($c['legacy'] && substr($name, -4) === 'Core' && !isset($classes[substr($name, 0, -4)])) {
        $classes[substr($name, 0, -4)] = $c + ['aliasOf' => $name];
    }
}

// Flatten inheritance so a method moved to a parent class does not count as removed.
$resolved = [];
foreach (array_keys($classes) as $name) {
    $resolved[$name] = resolve($name, $classes, []);
}

// Hooks from the install data.
$hooks = [];
$hookXml = "$root/install-dev/data/xml/hook.xml";
if (is_file($hookXml)) {
    preg_match_all('/<name>([^<]+)<\/name>/', file_get_contents($hookXml), $m);
    $hooks = array_values(array_unique($m[1]));
}
foreach (['hook_alias.xml'] as $f) {
    if (is_file("$root/install-dev/data/xml/$f")) {
        preg_match_all('/<alias>([^<]+)<\/alias>/', file_get_contents("$root/install-dev/data/xml/$f"), $m);
        $hooks = array_values(array_unique(array_merge($hooks, $m[1])));
    }
}

// Smarty plugins registered by the core.
$smarty = ['function' => [], 'modifier' => [], 'block' => []];
foreach (glob("$root/config/smarty*.php") as $f) {
    preg_match_all("/smartyRegisterFunction\(\s*\\\$smarty\s*,\s*'(function|modifier|block)'\s*,\s*'([^']+)'/", file_get_contents($f), $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $smarty[$hit[1]][] = $hit[2];
    }
    preg_match_all("/registerPlugin\(\s*'(function|modifier|block)'\s*,\s*'([^']+)'/", file_get_contents($f), $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $smarty[$hit[1]][] = $hit[2];
    }
}
foreach ($smarty as $k => $v) {
    $smarty[$k] = array_values(array_unique($v));
}

$composer = json_decode((string) @file_get_contents("$root/composer.json"), true);

file_put_contents($argv[2], json_encode([
    'source' => basename($root),
    'php' => isset($composer['require']['php']) ? $composer['require']['php'] : null,
    'classes' => $resolved,
    'functions' => array_values(array_unique($functions)),
    'hooks' => $hooks,
    'smarty' => $smarty,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo basename($root) . ': ' . count($resolved) . ' classes, ' . count($functions) . ' functions, ' . count($hooks) . " hooks\n";

function parseFile($path, $dir, &$classes, &$functions)
{
    $tokens = @token_get_all(file_get_contents($path));
    $ns = '';
    $class = null;
    $depth = 0;
    $classDepth = -1;
    $n = count($tokens);
    for ($i = 0; $i < $n; ++$i) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            if ($t === '{') {
                ++$depth;
            } elseif ($t === '}') {
                --$depth;
                if ($class !== null && $depth === $classDepth) {
                    $class = null;
                    $classDepth = -1;
                }
            }
            continue;
        }
        if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            ++$depth;
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $ns = '';
            for ($j = $i + 1; $j < $n; ++$j) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NS_SEPARATOR, defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1], true)) {
                    $ns .= $tokens[$j][1];
                }
            }
            continue;
        }
        if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && $class === null) {
            // Skip "::class" and anonymous classes.
            $prev = prevToken($tokens, $i);
            if ($prev === T_DOUBLE_COLON || $prev === T_NEW) {
                continue;
            }
            $name = nextString($tokens, $i);
            if ($name === null) {
                continue;
            }
            $fq = ($ns !== '' ? $ns . '\\' : '') . $name;
            $parent = null;
            for ($j = $i + 1; $j < $n && $tokens[$j] !== '{'; ++$j) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                    $parent = readName($tokens, $j + 1, $ns);
                    break;
                }
            }
            $classes[$fq] = [
                'legacy' => $ns === '' && in_array($dir, ['classes', 'controllers'], true),
                'kind' => $t[0] === T_CLASS ? 'class' : ($t[0] === T_INTERFACE ? 'interface' : 'trait'),
                'parent' => $parent,
                'deprecated' => isDeprecated($tokens, $i),
                'methods' => [],
                'consts' => [],
                'file' => str_replace('\\', '/', $path),
            ];
            $class = $fq;
            $classDepth = $depth;
            continue;
        }
        if ($t[0] === T_FUNCTION) {
            $name = nextString($tokens, $i);
            if ($name === null) {
                continue;
            }
            if ($class !== null && $depth === $classDepth + 1) {
                $vis = 'public';
                $static = false;
                for ($j = $i - 1; $j > 0; --$j) {
                    if (!is_array($tokens[$j])) {
                        break;
                    }
                    if ($tokens[$j][0] === T_PRIVATE) {
                        $vis = 'private';
                    } elseif ($tokens[$j][0] === T_PROTECTED) {
                        $vis = 'protected';
                    } elseif ($tokens[$j][0] === T_STATIC) {
                        $static = true;
                    } elseif (!in_array($tokens[$j][0], [T_WHITESPACE, T_PUBLIC, T_ABSTRACT, T_FINAL, T_DOC_COMMENT, T_COMMENT], true)) {
                        break;
                    }
                }
                if ($vis !== 'private') {
                    $classes[$class]['methods'][strtolower($name)] = [$name, $vis, $static, isDeprecated($tokens, $i), returnType($tokens, $i)];
                }
            } elseif ($class === null && $depth === 0) {
                $functions[] = $name;
            }
            continue;
        }
        if ($t[0] === T_CONST && $class !== null && $depth === $classDepth + 1) {
            for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; ++$j) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && isset($tokens[$j + 1]) && (trim(is_array($tokens[$j + 1]) ? $tokens[$j + 1][1] : $tokens[$j + 1]) === '=' || (is_array($tokens[$j + 1]) && $tokens[$j + 1][0] === T_WHITESPACE && $tokens[$j + 2] === '='))) {
                    $classes[$class]['consts'][] = $tokens[$j][1];
                }
            }
        }
    }
}

/** Declared return type of the method starting at token $i, or null. */
function returnType($tokens, $i)
{
    $n = count($tokens);
    $level = 0;
    for ($j = $i; $j < $n; ++$j) {
        if ($tokens[$j] === '(') {
            ++$level;
        } elseif ($tokens[$j] === ')') {
            --$level;
            if ($level === 0) {
                break;
            }
        }
    }
    $type = '';
    for (++$j; $j < $n; ++$j) {
        $t = $tokens[$j];
        if ($t === '{' || $t === ';') {
            break;
        }
        if ($t === ':' || (is_array($t) && $t[0] === T_WHITESPACE)) {
            continue;
        }
        $type .= is_array($t) ? $t[1] : $t;
    }

    return $type !== '' ? $type : null;
}

function prevToken($tokens, $i)
{
    for ($j = $i - 1; $j >= 0; --$j) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ABSTRACT, T_FINAL, defined('T_READONLY') ? T_READONLY : -1], true)) {
            continue;
        }

        return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
    }

    return null;
}

function nextString($tokens, $i)
{
    for ($j = $i + 1; $j < count($tokens); ++$j) {
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            return $tokens[$j][1];
        }
        if ($tokens[$j] === '(' || $tokens[$j] === '{') {
            return null;
        }
    }

    return null;
}

function readName($tokens, $j, $ns)
{
    $name = '';
    for (; $j < count($tokens); ++$j) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_WHITESPACE && $name === '') {
            continue;
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1, defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1], true)) {
            $name .= $t[1];
            continue;
        }
        break;
    }

    return ltrim($name, '\\');
}

function isDeprecated($tokens, $i)
{
    for ($j = $i - 1; $j > 0 && $j > $i - 12; --$j) {
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_DOC_COMMENT) {
            return stripos($tokens[$j][1], '@deprecated') !== false;
        }
        if (!is_array($tokens[$j]) && $tokens[$j] !== '') {
            return false;
        }
    }

    return false;
}

function resolve($name, $classes, $seen)
{
    $c = $classes[$name];
    $out = ['kind' => $c['kind'], 'legacy' => $c['legacy'], 'deprecated' => $c['deprecated'], 'methods' => $c['methods'], 'consts' => $c['consts']];
    $parent = $c['parent'];
    if ($parent !== null && !isset($seen[$parent])) {
        $seen[$name] = true;
        $key = isset($classes[$parent]) ? $parent : (isset($classes[$parent . 'Core']) ? $parent . 'Core' : null);
        if ($key !== null) {
            $p = resolve($key, $classes, $seen);
            $out['methods'] += $p['methods'];
            $out['consts'] = array_values(array_unique(array_merge($out['consts'], $p['consts'])));
        }
    }
    $out['parent'] = $parent;

    return $out;
}
