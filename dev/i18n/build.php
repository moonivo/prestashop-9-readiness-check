<?php
/**
 * Build translations/<iso>.php (PrestaShop's module translation format, read by
 * $this->l() and {l mod=''} on 1.7.0 through 9.x) from dev/i18n/<iso>.json.
 * Fails if any string of the module has no translation.
 *
 *   php dev/i18n/build.php
 *
 * Dev tool, not shipped.
 */
$root = dirname(dirname(__DIR__));
exec('php ' . escapeshellarg(__DIR__ . '/extract.php'), $lines, $code);
$strings = json_decode(implode("\n", $lines), true);
if (!is_array($strings)) {
    exit("extract failed\n");
}
$header = file_get_contents($root . '/dev/header.txt');
$failed = false;
@mkdir($root . '/translations', 0755, true);

foreach (glob(__DIR__ . '/*.json') as $file) {
    $iso = basename($file, '.json');
    if ($iso === 'strings') {
        continue;
    }
    $dict = json_decode(file_get_contents($file), true);
    $php = "<?php\n" . $header . "\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nglobal \$_MODULE;\n\$_MODULE = array();\n";
    foreach ($strings as $source => $fileKey) {
        if (!isset($dict[$source]) || $dict[$source] === '') {
            echo "$iso: missing \"$source\"\n";
            $failed = true;
            continue;
        }
        // Same key PrestaShop computes in Module::findTranslation() / smartyTranslate(),
        // once per file that uses the string.
        foreach (array_keys($fileKey) as $f) {
            $key = '<{mnvps9check}prestashop>' . strtolower($f) . '_' . md5(str_replace("'", "\\'", $source));
            $php .= '$_MODULE[' . var_export($key, true) . '] = ' . var_export($dict[$source], true) . ";\n";
        }
    }
    file_put_contents($root . "/translations/$iso.php", $php);
    echo "$iso: " . count($strings) . " strings\n";
}
copy($root . '/index.php', $root . '/translations/index.php');
exit($failed ? 1 : 0);
