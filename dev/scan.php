<?php
/**
 * Run the scanner from the command line on any directory (a module, a theme, override/).
 *
 *   php dev/scan.php <dir> [--json]
 *
 * Dev tool, not shipped.
 */
define('_PS_VERSION_', 'cli');
require dirname(__DIR__) . '/classes/MnvPs9Scanner.php';
$dir = rtrim(str_replace('\\', '/', $argv[1]), '/');
$rules = require dirname(__DIR__) . '/data/rules.php';
$php8 = require dirname(__DIR__) . '/data/php8.php';
$scanner = new MnvPs9Scanner($rules, $php8);
$scanner->setModuleRoot($dir);
$t = microtime(true);
list($findings, $files, $skipped) = $scanner->scanDirectory($dir, $dir);
if (in_array('--json', $argv, true)) {
    echo json_encode(array_values($findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}
$counts = array('error' => 0, 'warning' => 0);
foreach ($findings as $f) {
    ++$counts[$f['severity']];
    printf("%-7s %-15s %-55s %s:%s%s\n", $f['severity'], $f['code'], $f['subject'], $f['file'], implode(',', $f['lines']), $f['extra'] ? ' [' . $f['extra'] . ']' : '');
}
printf("== %s: %d files (%d skipped), %d errors, %d warnings, %.2fs\n", basename($dir), $files, $skipped, $counts['error'], $counts['warning'], microtime(true) - $t);
