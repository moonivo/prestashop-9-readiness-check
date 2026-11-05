<?php
/**
 * Compare extracted APIs: what PrestaShop 1.7.8 / 8.2 offered and PrestaShop 9 no longer
 * has. Writes removed.json (raw, for review) and prints a summary.
 *
 *   php dev/api/diff.php
 *
 * Dev tool, not shipped.
 */
$dir = __DIR__;
$old = ['1.7.8.11', '8.2.8'];
$new = ['9.0.0', '9.2.0'];
$load = function ($v) use ($dir) {
    return json_decode(file_get_contents("$dir/api-$v.json"), true);
};
$o = array_map($load, array_combine($old, $old));
$n = array_map($load, array_combine($new, $new));
$n9 = $n['9.0.0'];

$out = ['classes' => [], 'methods' => [], 'consts' => [], 'functions' => [], 'hooks' => [], 'smarty' => []];

// Only what a module can realistically use: legacy classes, and namespaced classes of the core.
foreach ($o as $ver => $api) {
    foreach ($api['classes'] as $name => $c) {
        if (!$c['legacy'] && strpos($name, 'PrestaShop\\') !== 0 && strpos($name, 'PrestaShopBundle\\') !== 0) {
            continue;
        }
        if ($c['legacy'] && substr($name, -4) === 'Core') {
            continue;
        }
        if (!isset($n9['classes'][$name])) {
            $key = $name;
            if (!isset($out['classes'][$key])) {
                $out['classes'][$key] = ['since' => $ver, 'legacy' => $c['legacy'], 'kind' => $c['kind'], 'deprecatedBefore' => $c['deprecated']];
            }
            continue;
        }
        // Legacy FooCore and its alias Foo are the same class: keep the alias only.
        if ($c['legacy'] && substr($name, -4) === 'Core' && isset($api['classes'][substr($name, 0, -4)])) {
            continue;
        }
        $parent = $c['parent'];
        if ($parent !== null && $c['legacy'] && substr($parent, -4) === 'Core') {
            $parent = substr($parent, 0, -4);
        }
        foreach ($c['methods'] as $lc => $m) {
            if ($m[1] === 'private' || strpos($lc, '__') === 0) {
                continue;
            }
            // Report a method once, on the class that declared it, not on every subclass.
            if ($parent !== null && isset($api['classes'][$parent]['methods'][$lc])) {
                continue;
            }
            if (!isset($n9['classes'][$name]['methods'][$lc])) {
                $out['methods'][$name . '::' . $m[0]] = ['static' => $m[2], 'visibility' => $m[1], 'legacy' => $c['legacy'], 'deprecatedBefore' => $m[3]];
            }
        }
        foreach ($c['consts'] as $const) {
            if (!in_array($const, $n9['classes'][$name]['consts'], true)) {
                $out['consts'][$name . '::' . $const] = ['legacy' => $c['legacy']];
            }
        }
    }
    foreach ($api['functions'] as $f) {
        if (!in_array($f, $n9['functions'], true)) {
            $out['functions'][$f] = true;
        }
    }
    foreach ($api['hooks'] as $h) {
        if (!in_array($h, $n9['hooks'], true) && !in_array($h, $n['9.2.0']['hooks'], true)) {
            $out['hooks'][$h] = $ver;
        }
    }
    foreach ($api['smarty'] as $type => $names) {
        foreach ($names as $s) {
            if (!in_array($s, $n9['smarty'][$type], true)) {
                $out['smarty'][$type . ':' . $s] = $ver;
            }
        }
    }
}

// Things removed in 9.0 but back in 9.2 are not a problem for an upgrade to the latest.
foreach (array_keys($out['classes']) as $name) {
    if (isset($n['9.2.0']['classes'][$name])) {
        unset($out['classes'][$name]);
    }
}

ksort($out['classes']);
ksort($out['methods']);
file_put_contents("$dir/removed.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$legacyClasses = array_filter($out['classes'], function ($c) { return $c['legacy']; });
$legacyMethods = array_filter($out['methods'], function ($m) { return $m['legacy']; });
printf("removed classes: %d (legacy %d)\nremoved methods: %d (legacy %d)\nremoved consts: %d\nremoved functions: %d\nremoved hooks: %d\nremoved smarty: %d\n",
    count($out['classes']), count($legacyClasses), count($out['methods']), count($legacyMethods), count($out['consts']),
    count($out['functions']), count($out['hooks']), count($out['smarty']));
echo "\nlegacy classes: " . implode(', ', array_keys($legacyClasses)) . "\n";
echo "\nlegacy methods (first 120):\n  " . implode("\n  ", array_keys($legacyMethods)) . "\n";
echo "\nfunctions: " . implode(', ', array_keys($out['functions'])) . "\n";
echo "\nsmarty: " . implode(', ', array_keys($out['smarty'])) . "\n";
echo "\nhooks: " . implode(', ', array_keys($out['hooks'])) . "\n";
