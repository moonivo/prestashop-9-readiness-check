<?php
/**
 * 2026 Moonivo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@moonivo.com so we can send you a copy immediately.
 *
 * @author Moonivo <support@moonivo.com>
 * @copyright 2026 Moonivo
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *
 * @see https://moonivo.com
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a store runs besides the core: installed modules, the active themes and the
 * overrides, plus the per-item checks that are not about PHP code (module metadata,
 * direct PHP endpoints, hard-coded jQuery files, removed template data).
 *
 * Module metadata is read from the main file's source, never by loading the module.
 */
class MnvPs9Inventory
{
    /** Modules shipped with PrestaShop 9: the upgrade replaces them with their PS9 versions. */
    protected static $native = array(
        'blockreassurance', 'blockwishlist', 'contactform', 'dashactivity', 'dashgoals', 'dashproducts', 'dashtrends',
        'graphnvd3', 'gridhtml', 'gsitemap', 'pagesnotfound', 'productcomments', 'ps_apiresources', 'ps_banner',
        'ps_bestsellers', 'ps_brandlist', 'ps_cashondelivery', 'ps_categoryproducts', 'ps_categorytree', 'ps_checkpayment',
        'ps_contactinfo', 'ps_crossselling', 'ps_currencyselector', 'ps_customeraccountlinks', 'ps_customersignin',
        'ps_customtext', 'ps_dataprivacy', 'ps_distributionapiclient', 'ps_emailalerts', 'ps_emailsubscription',
        'ps_facetedsearch', 'ps_faviconnotificationbo', 'ps_featuredproducts', 'ps_googleanalytics', 'ps_imageslider',
        'ps_languageselector', 'ps_linklist', 'ps_mainmenu', 'ps_newproducts', 'ps_onepagecheckout', 'ps_searchbar',
        'ps_sharebuttons', 'ps_shoppingcart', 'ps_socialfollow', 'ps_specials', 'ps_supplierlist', 'ps_themecusto',
        'ps_viewedproduct', 'ps_wirepayment', 'psgdpr', 'statsbestcategories', 'statsbestcustomers',
        'statsbestmanufacturers', 'statsbestproducts', 'statsbestsuppliers', 'statsbestvouchers', 'statscarrier',
        'statscatalog', 'statscheckup', 'statsdata', 'statsforecast', 'statsnewsletter', 'statspersonalinfos',
        'statsproduct', 'statsregistrations', 'statssales', 'statssearch', 'statsstock',
    );

    /** Directories inside a module that hold code PrestaShop loads itself (not direct endpoints). */
    protected static $codeDirs = array('controllers', 'classes', 'src', 'vendor', 'upgrade', 'translations', 'override', 'views', 'lib', 'libs', 'tests', 'test', 'sql', 'install', 'config', 'docs', 'dev', 'mails');

    /** @var Db */
    protected $db;

    /** @var array */
    protected $rules;

    /**
     * @param Db|null $db null in the command-line tests (only the file checks run)
     */
    public function __construct($db, array $rules)
    {
        $this->db = $db;
        $this->rules = $rules;
    }

    /**
     * Installed modules, with what the upgrade will do with each.
     *
     * @return array
     */
    public function modules()
    {
        $rows = $this->db->executeS('SELECT `id_module`, `name`, `active`, `version` FROM `' . _DB_PREFIX_ . 'module` ORDER BY `name`');
        $out = array();
        foreach ((array) $rows as $row) {
            $name = $row['name'];
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name) || $name === 'mnvps9check') {
                continue;
            }
            $main = _PS_MODULE_DIR_ . $name . '/' . $name . '.php';
            $meta = is_file($main) ? $this->readMeta((string) @file_get_contents($main)) : array();
            $author = isset($meta['author']) ? $meta['author'] : '';
            $kind = 'thirdparty';
            if (in_array($name, self::$native, true)) {
                $kind = 'native';
            } elseif (strcasecmp($author, 'PrestaShop') === 0) {
                $kind = 'prestashop';
            } elseif (!is_file($main)) {
                $kind = 'missing';
            }
            $out[] = array(
                'name' => $name,
                'displayName' => isset($meta['displayName']) ? $meta['displayName'] : $name,
                'version' => $row['version'],
                'author' => $author,
                'active' => (bool) $row['active'],
                'kind' => $kind,
                'max' => isset($meta['max']) ? $meta['max'] : null,
            );
        }

        return $out;
    }

    /**
     * Themes in use by the shops, with their parent and declared compatibility.
     *
     * @return array
     */
    public function themes()
    {
        $names = array();
        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            $rows = $this->db->executeS('SELECT DISTINCT `theme_name` FROM `' . _DB_PREFIX_ . 'shop`');
            foreach ((array) $rows as $row) {
                $names[] = $row['theme_name'];
            }
        }
        $out = array();
        foreach (array_unique($names) as $name) {
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name) || !is_dir(_PS_ALL_THEMES_DIR_ . $name)) {
                continue;
            }
            $yml = (string) @file_get_contents(_PS_ALL_THEMES_DIR_ . $name . '/config/theme.yml');
            $out[] = array(
                'name' => $name,
                'parent' => $this->ymlValue($yml, 'parent'),
                'from' => $this->ymlValue($yml, 'from'),
                'to' => $this->ymlValue($yml, 'to'),
                'version' => $this->ymlValue($yml, 'version'),
                'native' => in_array($name, array('classic', 'hummingbird'), true),
            );
        }

        return $out;
    }

    /**
     * PHP files in override/ (the empty index.php guards excluded).
     *
     * @return array relative paths
     */
    public function overrides()
    {
        $out = array();
        foreach (array('classes', 'controllers') as $sub) {
            $dir = _PS_OVERRIDE_DIR_ . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (strtolower($file->getExtension()) !== 'php' || $file->getFilename() === 'index.php') {
                    continue;
                }
                $out[] = $sub . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Findings that are not about PHP code: metadata, endpoints, jQuery files, templates.
     *
     * @param string $dir module or theme directory
     * @param string $type module|theme
     * @param array|null $module module row from modules()
     *
     * @return array findings in the scanner's format
     */
    public function extraFindings($dir, $type, $module = null)
    {
        $findings = array();
        if ($type === 'module' && $module !== null) {
            if ($module['max'] !== null && $module['max'] !== '_PS_VERSION_' && version_compare($module['max'], '9.0.0', '<')) {
                $findings[] = $this->finding('warning', 'compliancy', $module['max'], $module['name'] . '.php');
            }
            foreach ($this->directEndpoints($dir) as $file) {
                $findings[] = $this->finding('error', 'endpoint', basename($file), $file);
            }
        }
        foreach ($this->textFiles($dir) as $file) {
            $code = @file_get_contents($dir . '/' . $file);
            if ($code === false || $code === '') {
                continue;
            }
            foreach ($this->rules['jqueryFiles'] as $jq) {
                if (strpos($code, 'jquery/' . $jq) !== false || strpos($code, '/' . $jq . '.min.js') !== false || strpos($code, '/' . $jq . '.js') !== false) {
                    $findings[] = $this->finding('error', 'jquery', $jq, $file);
                }
            }
            if (substr($file, -4) === '.tpl') {
                foreach ($this->rules['tplVars'] as $var => $how) {
                    if (preg_match('/\$' . preg_quote($var, '/') . '\b/', $code)) {
                        $findings[] = $this->finding('warning', 'tplvar', '$' . $var, $file);
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * PHP files a module serves directly (they bootstrap PrestaShop themselves). PrestaShop
     * 9's modules/.htaccess blocks direct .php requests on Apache.
     *
     * @param string $dir
     *
     * @return array relative paths
     */
    public function directEndpoints($dir)
    {
        $out = array();
        $name = basename($dir);
        $files = @scandir($dir);
        foreach ((array) $files as $file) {
            if (strtolower(substr($file, -4)) !== '.php' || $file === $name . '.php' || $file === 'index.php') {
                continue;
            }
            $code = (string) @file_get_contents($dir . '/' . $file, false, null, 0, 20000);
            if (preg_match('/(config\.inc\.php|init\.php)[\'"]/', $code)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    protected function textFiles($dir)
    {
        $out = array();
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (($ext === 'tpl' || $ext === 'js' || $ext === 'php') && $file->getSize() < 1000000) {
                $out[] = substr($path, strlen(str_replace('\\', '/', $dir)) + 1);
                if (++$count > 5000) {
                    break;
                }
            }
        }

        return $out;
    }

    protected function readMeta($code)
    {
        $meta = array();
        if (preg_match('/\$this->author\s*=\s*[\'"]([^\'"]+)/', $code, $m)) {
            $meta['author'] = $m[1];
        }
        if (preg_match('/\$this->displayName\s*=\s*(?:\$this->(?:l|trans)\(\s*)?[\'"]([^\'"]+)/', $code, $m)) {
            $meta['displayName'] = $m[1];
        }
        if (preg_match('/ps_versions_compliancy\s*=\s*(?:array\s*\(|\[)([^\]\)]*)/i', $code, $m)
            && preg_match('/[\'"]max[\'"]\s*=>\s*([\'"]([^\'"]+)[\'"]|_PS_VERSION_)/', $m[1], $mm)) {
            $meta['max'] = isset($mm[2]) && $mm[2] !== '' ? $mm[2] : '_PS_VERSION_';
        }

        return $meta;
    }

    protected function ymlValue($yml, $key)
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . ':\s*[\'"]?([^\'"\r\n#]+)/m', $yml, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected function finding($severity, $code, $subject, $file)
    {
        return array(
            'severity' => $severity,
            'code' => $code,
            'subject' => $subject,
            'file' => $file,
            'lines' => array(),
            'count' => 1,
            'extra' => null,
            'library' => false,
        );
    }
}
