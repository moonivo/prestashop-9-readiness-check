<?php
/**
 * Build the shipped rule data (data/rules.php) from the API extracts: what PrestaShop 9
 * removed (classes, methods, constants, hooks) and the return types core methods gained,
 * which break overrides and module subclasses that redefine those methods without them.
 *
 *   php dev/api/extract.php <src> api-<version>.json   (for 1.7.8.11, 8.2.8, 9.0.0, 9.2.0)
 *   php dev/api/diff.php
 *   php dev/api/build_rules.php
 *
 * The output is plain PHP 5.6 array syntax so the module loads it on any store.
 * Dev tool, not shipped.
 */
$dir = __DIR__;
$removed = json_decode(file_get_contents("$dir/removed.json"), true);
$target = json_decode(file_get_contents("$dir/api-9.2.0.json"), true);
$first = json_decode(file_get_contents("$dir/api-9.0.0.json"), true);

$rules = [
    'generated' => date('Y-m-d'),
    'compared' => '1.7.8.11 + 8.2.8 -> 9.0.0 / 9.2.0',
    // Class names are matched case-insensitively, as PHP does.
    'classes' => [],
    'methods' => [],
    'consts' => [],
    'hooks' => [],
    'returnTypes' => [],
    'parents' => [],
    'classNames' => [],
];

// Removed in 8.0 (gone from 8.2.8 already: matters for 1.7 stores) or in 9.0.
$v82 = json_decode(file_get_contents("$dir/api-8.2.8.json"), true);
$lc82 = array();
foreach ($v82['classes'] as $name => $c) {
    $lc82[strtolower($name)] = $c;
}
foreach ($removed['classes'] as $name => $c) {
    $rules['classes'][strtolower($name)] = array($name, isset($lc82[strtolower($name)]) ? '9.0' : '8.0');
}
foreach ($removed['methods'] as $key => $m) {
    list($class, $method) = explode('::', $key);
    $in82 = isset($lc82[strtolower($class)]['methods'][strtolower($method)]);
    $rules['methods'][strtolower($class)][strtolower($method)] = array($method, $in82 ? '9.0' : '8.0');
    $rules['classNames'][strtolower($class)] = $class;
}
foreach ($removed['consts'] as $key => $c) {
    list($class, $const) = explode('::', $key);
    $in82 = isset($lc82[strtolower($class)]) && in_array($const, $lc82[strtolower($class)]['consts'], true);
    $rules['consts'][strtolower($class)][$const] = $in82 ? '9.0' : '8.0';
    $rules['classNames'][strtolower($class)] = $class;
}
$hooks82 = array_map('strtolower', $v82['hooks']);
foreach ($removed['hooks'] as $hook => $since) {
    $rules['hooks'][strtolower($hook)] = array($hook, in_array(strtolower($hook), $hooks82, true) ? '9.0' : '8.0');
}
// Dynamic hooks (built from controller names, not in hook.xml) removed in 9.0 [C90].
foreach (array('Activate', 'Deactivate', 'Delete', 'Sort', 'Duplicate') as $action) {
    foreach (array('Before', 'After') as $when) {
        if ($action !== 'Duplicate') {
            $h = 'actionAdmin' . $action . $when;
            $rules['hooks'][strtolower($h)] = array($h, '9.0');
        }
        $h = 'actionAdminProductsController' . $action . $when;
        $rules['hooks'][strtolower($h)] = array($h, '9.0');
    }
}

// Symfony service IDs that no longer exist (8.2.8 -> 9.0.0 and 9.2.0 config files).
$rules['services'] = array();
foreach (file("$dir/removed-services.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $id) {
    $rules['services'][$id] = true;
}

// Libraries the core no longer ships [C90]: fine only if the module bundles its own copy.
$rules['libraries'] = array(
    'swift_' => array('Swift Mailer', 'swiftmailer'),
    'guzzlehttp\\' => array('Guzzle', 'guzzlehttp'),
    'sensio\\bundle\\frameworkextrabundle\\' => array('SensioFrameworkExtraBundle', 'sensio'),
);

// Hard-coded jQuery files deleted in 9.0 (only jquery-3.7.1 and jquery-migrate-3.4.0 remain).
$rules['jqueryFiles'] = array('jquery-1.11.0', 'jquery-3.4.1', 'jquery-3.5.1', 'jquery-migrate-1.2.1', 'jquery-migrate-3.1.0');

// Theme template data removed or changed in 9.0 [C90].
$rules['tplVars'] = array(
    'productPriceWithoutReduction' => 'removed',
    'meta_keywords' => 'removed',
);

// Admin controller methods still declared but no longer called for migrated pages [C90].
$rules['silentAdmin'] = array('run' => true, 'initheader' => true, 'initcontent' => true, 'initfooter' => true, 'display' => true);

// Return types of legacy core methods in 9.x (a method gaining one in 9.0 or later is what
// breaks an override; keep both versions' types so the message can name the right one).
foreach ([$first, $target] as $api) {
    foreach ($api['classes'] as $name => $c) {
        if (!$c['legacy'] || substr($name, -4) === 'Core') {
            continue;
        }
        foreach ($c['methods'] as $lc => $m) {
            if ($m[4] !== null && $m[1] !== 'private') {
                $rules['returnTypes'][strtolower($name)][$lc] = $m[4];
            }
        }
        if ($c['parent'] !== null) {
            $p = $c['parent'];
            if (substr($p, -4) === 'Core') {
                $p = substr($p, 0, -4);
            }
            $rules['parents'][strtolower($name)] = strtolower($p);
        }
        if (isset($rules['returnTypes'][strtolower($name)])) {
            $rules['classNames'][strtolower($name)] = $name;
        }
    }
}

// A return type already present in 8.2 broke nothing new; drop those.
$old = json_decode(file_get_contents("$dir/api-8.2.8.json"), true);
foreach ($rules['returnTypes'] as $class => $methods) {
    foreach ($methods as $lc => $type) {
        $name = null;
        foreach ($old['classes'] as $n => $c) {
            if (strtolower($n) === $class) {
                $name = $n;
                break;
            }
        }
        if ($name !== null && isset($old['classes'][$name]['methods'][$lc]) && $old['classes'][$name]['methods'][$lc][4] !== null) {
            unset($rules['returnTypes'][$class][$lc]);
        }
    }
    if (empty($rules['returnTypes'][$class])) {
        unset($rules['returnTypes'][$class]);
    }
}

$php = "<?php\n" . file_get_contents(dirname(dirname(__DIR__)) . '/dev/header.txt')
    . "\n/*\n * Generated by dev/api/build_rules.php on " . $rules['generated'] . ' from the PrestaShop sources ('
    . $rules['compared'] . "). Do not edit by hand.\n */\n"
    . "if (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nreturn " . export($rules) . ";\n";
@mkdir(dirname(dirname(__DIR__)) . '/data', 0755, true);
file_put_contents(dirname(dirname(__DIR__)) . '/data/rules.php', $php);
printf("services %d, classes %d, methods %d, consts %d, hooks %d, return types %d classes / %d methods, %d KB\n",
    count($rules['services']), count($rules['classes']), array_sum(array_map('count', $rules['methods'])), array_sum(array_map('count', $rules['consts'])),
    count($rules['hooks']), count($rules['returnTypes']), array_sum(array_map('count', $rules['returnTypes'])), strlen($php) / 1024);

/** var_export with short, stable, PHP 5.6 friendly formatting. */
function export($value, $indent = '')
{
    if (!is_array($value)) {
        return var_export($value, true);
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    $out = "array(\n";
    foreach ($value as $k => $v) {
        $out .= $indent . '    ' . ($isList ? '' : var_export($k, true) . ' => ') . export($v, $indent . '    ') . ",\n";
    }

    return $out . $indent . ')';
}
