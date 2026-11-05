<?php
/**
 * Build the release zip: modules/mnvps9check-<version>.zip with a top-level
 * mnvps9check/ folder, as PrestaShop's module upload expects. dev/ never ships.
 * Translations are rebuilt first so the zip always carries the current texts.
 *
 *   php dev/build_release.php
 *
 * Dev tool, not shipped.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$moduleDir = dirname(__DIR__);
$name = basename($moduleDir);
$main = (string) file_get_contents("$moduleDir/$name.php");
if (!preg_match("/this->version = '([^']+)'/", $main, $m)) {
    exit("version not found\n");
}
$version = $m[1];

passthru('php ' . escapeshellarg(__DIR__ . '/i18n/build.php'), $rc);
if ($rc !== 0) {
    exit("translations incomplete\n");
}

// config.xml is what the module list reads before loading the module; keep it in sync.
$xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n<module>\n"
    . "    <name>$name</name>\n"
    . "    <displayName><![CDATA[PrestaShop 9 Readiness Check]]></displayName>\n"
    . "    <version><![CDATA[$version]]></version>\n"
    . "    <description><![CDATA[Find out what in your store would break on PrestaShop 9: modules, theme, overrides and server. Read-only, changes nothing.]]></description>\n"
    . "    <author><![CDATA[Moonivo]]></author>\n"
    . "    <tab><![CDATA[administration]]></tab>\n"
    . "    <is_configurable>1</is_configurable>\n"
    . "    <need_instance>0</need_instance>\n"
    . "    <limited_countries></limited_countries>\n"
    . "</module>\n";
file_put_contents("$moduleDir/config.xml", $xml);

$exclude = array('dev', '.git', '.idea', '.vscode', 'node_modules', 'vendor', 'tests', '.php-cs-fixer.dist.php', '.php-cs-fixer.cache', 'CLAUDE.md', 'composer.lock');
$zipPath = dirname($moduleDir) . "/$name-$version.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    exit("cannot write $zipPath\n");
}
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleDir) + 1));
    $top = explode('/', $rel)[0];
    if (in_array($top, $exclude, true) || substr($rel, -4) === '.zip') {
        continue;
    }
    if ($file->isDir()) {
        $zip->addEmptyDir("$name/$rel");
    } else {
        $zip->addFile($file->getPathname(), "$name/$rel");
        ++$count;
    }
}
$zip->close();
printf("%s: %d files, %d KB\n", basename($zipPath), $count, filesize($zipPath) / 1024);
