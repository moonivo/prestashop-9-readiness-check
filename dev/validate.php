<?php
/**
 * Release check of the built zip (run dev/build_release.php first). Reuses the Image Map
 * validator's pieces where they are generic (impro/dev/validator: Smarty escaping rule,
 * DOM taint scanner, phpcs + PHPCompatibility from impro's vendor) and adds what this
 * module promises: it must load on PHP 5.6 through 8.x.
 *
 *   php dev/validate.php
 *
 * Exit code 0 = all sections passed. Dev tool, not shipped.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$src = dirname(__DIR__);
$name = basename($src);
$impro = dirname($src) . '/impro';
preg_match("/this->version = '([^']+)'/", (string) file_get_contents("$src/$name.php"), $vm);
$zip = dirname($src) . "/$name-{$vm[1]}.zip";
if (!is_file($zip)) {
    exit("no $zip — run dev/build_release.php\n");
}
$work = sys_get_temp_dir() . '/mnv9_validate_' . getmypid();
$z = new ZipArchive();
$z->open($zip);
$z->extractTo($work);
$z->close();
$m = "$work/$name";
$phpBins = glob('C:/wamp64/bin/php/php*/php.exe');
$results = array();

function section($title, $ok, array $details)
{
    global $results;
    $results[] = $ok;
    echo ($ok ? "PASS" : "FAIL") . "  $title\n";
    foreach ($details as $d) {
        echo "      $d\n";
    }
}

$rii = function ($dir) {
    return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
};

// 1. Package
$d = array();
$ok = true;
foreach (array('dev', 'vendor', 'node_modules', 'tests', 'composer.lock', '.git') as $bad) {
    if (file_exists("$m/$bad")) {
        $ok = false;
        $d[] = "forbidden in zip: $bad";
    }
}
$xml = @simplexml_load_file("$m/config.xml");
if (!$xml || (string) $xml->version !== $vm[1]) {
    $ok = false;
    $d[] = 'config.xml version differs from the module';
}
$main = (string) file_get_contents("$m/$name.php");
if (!preg_match("/'max' => _PS_VERSION_/", $main)) {
    $ok = false;
    $d[] = 'ps_versions_compliancy max should be _PS_VERSION_';
}
foreach ($rii($m) as $f) {
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($m) + 1);
    if ($f->isDir() && !is_file($f->getPathname() . '/index.php')) {
        $ok = false;
        $d[] = "no index.php guard: $rel";
    }
    if ($f->isFile() && $f->getExtension() === 'php') {
        $code = (string) file_get_contents($f->getPathname());
        if (strpos($code, "<?php\n/**") !== 0 || strpos($code, '@license') === false) {
            $ok = false;
            $d[] = "license header missing or not right after <?php: $rel";
        }
        if (basename($rel) !== 'index.php' && strpos($code, "defined('_PS_VERSION_')") === false) {
            $ok = false;
            $d[] = "no _PS_VERSION_ guard: $rel";
        }
        if (strpos($code, 'Context::getContext()') !== false && in_array($rel, array("$name.php"), true)) {
            $ok = false;
            $d[] = "Context::getContext() in $rel (use \$this->context)";
        }
    }
}
section('1. Package / structure', $ok, $d ?: array('clean: guards, headers, versions, compliancy'));

// 2. Syntax on every PHP the module claims
$d = array();
$ok = true;
foreach ($phpBins as $bin) {
    $ver = basename(dirname($bin));
    $bad = 0;
    foreach ($rii($m) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            exec('"' . $bin . '" -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                ++$bad;
                $d[] = "$ver: " . trim(implode(' ', $out));
            }
            $out = array();
        }
    }
    if ($bad) {
        $ok = false;
    } else {
        $d[] = "$ver: all files parse";
    }
}
section('2. PHP syntax (every local PHP version)', $ok, $d);

// 3. PHPCompatibility (needs impro's dev vendor)
$phpcs = "$impro/vendor/bin/phpcs.bat";
if (is_file($phpcs)) {
    exec('"' . $phpcs . '" -q --runtime-set installed_paths ' . escapeshellarg("$impro/vendor/phpcompatibility/php-compatibility") . ' --standard=PHPCompatibility --runtime-set testVersion 5.6- --extensions=php --report=summary ' . escapeshellarg($m) . ' 2>&1', $out, $rc);
    $text = trim(implode("\n", $out));
    section('3. PHPCompatibility (PHP 5.6 and later)', $rc === 0, array($text !== '' ? $text : '0 errors, 0 warnings'));
    $out = array();
} else {
    section('3. PHPCompatibility', false, array('impro/vendor/bin/phpcs not found'));
}

// 4. Security: DOM taint in admin JS + Smarty escaping (rules shared with impro)
$d = array();
$ok = true;
foreach (glob("$m/views/js/*.js") as $js) {
    exec('node ' . escapeshellarg("$impro/dev/validator/scan_dom_taint.js") . ' ' . escapeshellarg($js) . ' 2>&1', $out, $rc);
    $scan = json_decode(implode("\n", $out), true);
    $out = array();
    if (!is_array($scan)) {
        $ok = false;
        $d[] = 'taint scan failed on ' . basename($js);
    } elseif (!empty($scan['count'])) {
        $ok = false;
        foreach ($scan['findings'] as $f) {
            $d[] = basename($js) . ':' . $f['line'] . ' [' . implode(',', $f['sinks']) . '] ' . $f['code'];
        }
    } else {
        $d[] = basename($js) . ': no AJAX-tainted DOM sinks';
    }
}
$rule = require "$impro/dev/validator/rules/42-smarty-escape.php";
list($sok, $sd) = $rule['run'](array('moduleDir' => $m));
section('4. Security (JS taint, Smarty escaping)', $ok && $sok, array_merge($d, $sd));

// 5. Tests on the oldest and newest PHP
$d = array();
$ok = true;
foreach (array('C:/wamp64/bin/php/php5.6.40/php.exe', 'php') as $bin) {
    exec('"' . $bin . '" ' . escapeshellarg("$src/dev/tests/run.php") . ' 2>&1', $out, $rc);
    $d[] = end($out);
    $ok = $ok && $rc === 0;
    $out = array();
}
section('5. Rule tests (fixtures)', $ok, $d);

// Clean up the extracted copy.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($work);

$passed = count(array_filter($results));
echo "\n$passed/" . count($results) . " sections passed\n";
exit($passed === count($results) ? 0 : 1);
