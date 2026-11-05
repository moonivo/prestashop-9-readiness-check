<?php
/**
 * List every translatable string of the module (PHP $this->l() and template {l s=''}),
 * as JSON: source text => file key. Dev tool, not shipped.
 *
 *   php dev/i18n/extract.php > dev/i18n/strings.json
 */
$root = dirname(dirname(__DIR__));
$out = array();
$php = file_get_contents("$root/mnvps9check.php");
preg_match_all('/\$this->l\(\'((?:[^\'\\\\]|\\\\.)*)\'\)/', $php, $m);
foreach ($m[1] as $s) {
    $out[stripslashes($s)]['mnvps9check'] = true;
}
foreach (glob("$root/views/templates/admin/*.tpl") as $tpl) {
    preg_match_all('/\{l s=\'((?:[^\'\\\\]|\\\\.)*)\' mod=\'mnvps9check\'/', file_get_contents($tpl), $m);
    foreach ($m[1] as $s) {
        $out[stripslashes($s)][basename($tpl, '.tpl')] = true;
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
