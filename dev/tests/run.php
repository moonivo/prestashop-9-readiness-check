<?php
/**
 * Dependency-free tests: every rule must fire on the fixtures, and nothing may fire on
 * clean code. Run on the oldest and the newest PHP the module supports:
 *
 *   C:\wamp64\bin\php\php5.6.40\php.exe dev/tests/run.php
 *   php dev/tests/run.php
 *
 * Optional: --ps9=<modules dir of a PrestaShop 9 store> also asserts zero errors in every
 * non-PrestaShop module installed there (they already run on PrestaShop 9).
 *
 * Dev tool, not shipped.
 */
define('_PS_VERSION_', 'cli');
define('_PS_MODULE_DIR_', '');
$root = dirname(dirname(__DIR__));
require $root . '/classes/MnvPs9Scanner.php';
require $root . '/classes/MnvPs9Inventory.php';
$rules = require $root . '/data/rules.php';
$php8 = require $root . '/data/php8.php';
$fx = __DIR__ . '/../fixtures';

$pass = 0;
$fail = 0;
function check($label, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
    } else {
        ++$fail;
        echo "FAIL $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}
function has(array $findings, $code, $subject, $severity = 'error', $file = null)
{
    foreach ($findings as $f) {
        if ($f['code'] === $code && $f['subject'] === $subject && $f['severity'] === $severity && ($file === null || $f['file'] === $file)) {
            return true;
        }
    }

    return false;
}
function scanCode($code, $rules, $php8)
{
    $s = new MnvPs9Scanner($rules, $php8);

    return array_values($s->scanCode($code, '/x/test.php'));
}

// ---- The bad module: every check fires --------------------------------------
$scanner = new MnvPs9Scanner($rules, $php8);
$scanner->setModuleRoot("$fx/mnvbadmodule");
list($found) = $scanner->scanDirectory("$fx/mnvbadmodule", "$fx/mnvbadmodule");
$found = array_values($found);
$main = 'mnvbadmodule.php';
check('Tools::displayPrice', has($found, 'removed_method', 'Tools::displayPrice', 'error', $main));
check('Tools::jsonEncode (8.0)', has($found, 'removed_method', 'Tools::jsonEncode', 'error', $main));
check('new FileUploader', has($found, 'removed_class', 'FileUploader', 'error', $main));
check('$s{0}', has($found, 'php8_syntax', '$var{…}', 'error', $main));
check('create_function', has($found, 'php8_function', 'create_function()', 'error', $main));
check('Guzzle not bundled', has($found, 'library', 'Guzzle', 'error', $main));
check('guarded Tools::encrypt is a warning', has($found, 'removed_method', 'Tools::encrypt', 'warning', $main));
check('hook method of a removed hook', has($found, 'removed_hook', 'actionAjaxDieBefore', 'error', $main));
check('$this->ajaxDie in a front controller', has($found, 'removed_method', 'Controller::ajaxDie', 'error', 'controllers/front/ajax.php'));
$tagged = false;
foreach ($found as $f) {
    if ($f['subject'] === 'Tools::jsonEncode') {
        $tagged = $f['extra'] === '8.0';
    }
}
check('jsonEncode tagged 8.0', $tagged);

$inv = new MnvPs9Inventory(null, $rules);
$extra = $inv->extraFindings("$fx/mnvbadmodule", 'module', array('name' => 'mnvbadmodule', 'max' => '8.2.0'));
check('compliancy max 8.2', has($extra, 'compliancy', '8.2.0', 'warning'));
check('direct endpoint cron.php', has($extra, 'endpoint', 'cron.php', 'error'));
check('front controller is not an endpoint', !has($extra, 'endpoint', 'ajax.php', 'error'));
check('jquery 1.11 file', has($extra, 'jquery', 'jquery-1.11.0', 'error'));

// ---- Override: redefined method without the PS9 return type -----------------
$scanner = new MnvPs9Scanner($rules, $php8);
list($found) = $scanner->scanDirectory("$fx/override", "$fx/override");
check('override Context::isMobile without : bool', has(array_values($found), 'return_type', 'Context::isMobile(): bool', 'error'));

// ---- Theme ------------------------------------------------------------------
$extra = $inv->extraFindings("$fx/mnvbadtheme", 'theme');
check('theme $productPriceWithoutReduction', has($extra, 'tplvar', '$productPriceWithoutReduction', 'warning'));

// ---- Clean code: nothing may fire ---------------------------------------------
$clean = array(
    'PHP 8 attribute' => '<?php #[\Attribute(\Attribute::TARGET_CLASS)] class A {}',
    'use of removed class only imported' => '<?php namespace X; use PrestaShop\PrestaShop\Adapter\ObjectPresenter; class A {}',
    'own method named like a removed one' => '<?php class A { public static function displayPrice() {} } A::displayPrice();',
    'method call on an object' => '<?php $x->displayPrice(1); $y->each();',
    'return type kept' => '<?php class Context extends ContextCore { public function isMobile(): bool { return true; } }',
    'current API' => '<?php Tools::getValue("a"); Context::getContext()->getTranslator(); new ProductAttribute(1); Tools::convertPrice(1);',
    'string offset with brackets' => '<?php $s = "ab"; echo $s[0]; echo "{$s}";',
    'each as a method name' => '<?php class A { public function each() {} }',
    'own namespaced class named like a legacy one' => '<?php namespace Vendor\Mod; class Referrer {} new Referrer();',
);
foreach ($clean as $label => $code) {
    $f = scanCode($code, $rules, $php8);
    check('clean: ' . $label, count($f) === 0, json_encode($f));
}

// Dirty one-liners.
$dirty = array(
    array('<?php Media::getJqueryPath();', 'removed_method', 'Media::getJqueryPath'),
    array('<?php use Foo\Bar; class A extends AdminController { function x() { $this->l("a"); } }', 'removed_method', 'AdminController::l'),
    array('<?php class X extends ObjectModel { function f() { return self::getValidationRules("A"); } } ObjectModel::getValidationRules("A");', 'removed_method', 'ObjectModel::getValidationRules'),
    array('<?php $a = each($b);', 'php8_function', 'each()'),
    array('<?php $x = (real) $y;', 'php8_syntax', '(real)'),
    array('<?php $this->get("prestashop.adapter.image_manager");', 'removed_service', 'prestashop.adapter.image_manager'),
    array('<?php namespace M; use GuzzleHttp\Client; $c = new Client();', 'library', 'Guzzle'),
    array('<?php $a = new \Attribute(1); Attribute::getAttributes(1);', 'removed_class', 'Attribute'),
);
foreach ($dirty as $d) {
    $f = scanCode($d[0], $rules, $php8);
    $hit = false;
    foreach ($f as $x) {
        if ($x['code'] === $d[1] && $x['subject'] === $d[2]) {
            $hit = true;
        }
    }
    check('dirty: ' . $d[2], $hit, json_encode($f));
}

// ---- Optional: real PrestaShop 9 modules must show no errors ----------------
foreach ($argv as $arg) {
    if (strpos($arg, '--ps9=') !== 0) {
        continue;
    }
    $dir = rtrim(substr($arg, 6), '/\\');
    foreach (scandir($dir) as $m) {
        $mainFile = "$dir/$m/$m.php";
        if ($m[0] === '.' || $m === 'mnvps9check' || !is_file($mainFile) || preg_match('/author\s*=\s*[\'"]PrestaShop/i', (string) file_get_contents($mainFile))) {
            continue;
        }
        $s = new MnvPs9Scanner($rules, $php8);
        $s->setModuleRoot("$dir/$m");
        list($f) = $s->scanDirectory("$dir/$m", "$dir/$m", array('node_modules', 'tests', 'test'));
        $errors = array_filter($f, function ($x) {
            return $x['severity'] === 'error';
        });
        check("PS9 module $m has no errors", count($errors) === 0, json_encode(array_values($errors)));
    }
}

printf("%d passed, %d failed (PHP %s)\n", $pass, $fail, PHP_VERSION);
exit($fail ? 1 : 0);
