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

require_once dirname(__FILE__) . '/classes/MnvPs9Scanner.php';
require_once dirname(__FILE__) . '/classes/MnvPs9Environment.php';
require_once dirname(__FILE__) . '/classes/MnvPs9Inventory.php';

/**
 * PrestaShop 9 Readiness Check: reads the store's modules, theme and overrides and the
 * server setup, and reports what would break on PrestaShop 9. Read-only: it changes
 * nothing in the store, runs only from its own configuration page, and adds no hooks.
 *
 * Written for PHP 5.6+ and PrestaShop 1.7.0+, the stores that have not upgraded yet.
 */
class Mnvps9check extends Module
{
    /** Where the "get it done" offer leads (moonivo.com, fixed-price upgrade); Lithuanian has its own page. */
    const OFFER_URL = 'https://moonivo.com/prestashop-9-upgrade';
    const OFFER_URL_LT = 'https://moonivo.com/lt/prestashop-9-atnaujinimas';

    /** For stores already fine on PrestaShop 9: the store health check and maintenance (prices page). */
    const CARE_URL = 'https://moonivo.com/pricing';
    const CARE_URL_LT = 'https://moonivo.com/lt/kainos';

    /** Admin controller of the menu item. */
    const TAB = 'AdminMnvPs9Check';

    public function __construct()
    {
        $this->name = 'mnvps9check';
        $this->tab = 'administration';
        $this->version = '1.1.1';
        $this->author = 'Moonivo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('PrestaShop 9 Readiness Check');
        $this->description = $this->l('Find out what in your store would break on PrestaShop 9: modules, theme, overrides and server. Read-only, changes nothing.');
    }

    public function install()
    {
        return parent::install() && $this->installTab();
    }

    public function uninstall()
    {
        @unlink($this->reportFile());

        return $this->uninstallTab() && parent::uninstall();
    }

    /**
     * Advanced Parameters > PrestaShop 9 check, next to Information (where PrestaShop
     * already shows the server details): where a store owner looks for it.
     */
    protected function installTab()
    {
        if ($this->tabId(self::TAB)) {
            return true;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = self::TAB;
        $tab->module = $this->name;
        $tab->id_parent = (int) $this->tabId('AdminAdvancedParameters');
        $names = array('lt' => 'PrestaShop 9 patikra', 'fr' => 'Diagnostic PrestaShop 9');
        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = isset($names[$lang['iso_code']]) ? $names[$lang['iso_code']] : 'PrestaShop 9 check';
        }

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $id = (int) $this->tabId(self::TAB);
        if (!$id) {
            return true;
        }
        $tab = new Tab($id);

        return (bool) $tab->delete();
    }

    /** Direct lookup: Tab::getIdFromClassName() is deprecated in recent versions. */
    protected function tabId($className)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_tab` FROM `' . _DB_PREFIX_ . "tab` WHERE `class_name` = '" . pSQL($className) . "'"
        );
    }

    /** Module list > Configure shows the same page as the menu item. */
    public function getContent()
    {
        return $this->renderReport($this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name);
    }

    /**
     * The report page; its AJAX calls go back to the page that rendered it.
     *
     * @param string $pageUrl admin URL of the current page, with its token
     *
     * @return string
     */
    public function renderReport($pageUrl)
    {
        if (Tools::getValue('mnv_ajax')) {
            $this->ajax();
        }
        if (Tools::getValue('mnv_pdf')) {
            $this->pdf();
        }

        $this->context->smarty->assign(array(
            'mnv_ajax_url' => $pageUrl . '&mnv_ajax=1',
            'mnv_texts' => json_encode($this->texts()),
            'mnv_last' => $this->lastReport(),
            'mnv_pdf_url' => $pageUrl . '&mnv_pdf=1',
            'mnv_offer_url' => $this->offerUrl(),
            'mnv_care_url' => $this->careUrl(),
            'mnv_ps' => _PS_VERSION_,
            'mnv_shop' => Configuration::get('PS_SHOP_NAME'),
            'mnv_css' => $this->_path . 'views/css/admin.css?v=' . $this->version,
            'mnv_js' => $this->_path . 'views/js/admin.js?v=' . $this->version,
        ));

        return $this->display(__FILE__, 'views/templates/admin/report.tpl');
    }

    /**
     * @return string the offer page in the employee's language
     */
    protected function offerUrl()
    {
        return $this->context->language->iso_code === 'lt' ? self::OFFER_URL_LT : self::OFFER_URL;
    }

    /**
     * @return string the prices page (health check, maintenance) in the employee's language
     */
    protected function careUrl()
    {
        return $this->context->language->iso_code === 'lt' ? self::CARE_URL_LT : self::CARE_URL;
    }

    /**
     * Sends the last saved report as a PDF (TCPDF, shipped with PrestaShop).
     */
    protected function pdf()
    {
        $data = json_decode((string) @file_get_contents($this->reportFile()), true);
        if (!is_array($data) || empty($data['env'])) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $this->l('Run the check first, then download the report.');
            exit;
        }
        require_once dirname(__FILE__) . '/classes/MnvPs9PdfDocument.php';
        require_once dirname(__FILE__) . '/classes/MnvPs9Pdf.php';

        $pdf = new MnvPs9Pdf($data, $this->texts(), $this->offerUrl(), $this->careUrl());
        $content = $pdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="ps9-readiness-' . date('Y-m-d') . '.pdf"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    /**
     * AJAX steps of a scan: env (server and settings), jobs (what to scan),
     * scan (one module, the theme or the overrides), save (keep the finished report).
     */
    protected function ajax()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $step = (string) Tools::getValue('step');
        $rules = $this->rules();
        $out = array('ok' => true);

        if ($step === 'env') {
            $env = new MnvPs9Environment(Db::getInstance());
            $out['env'] = $env->run();
        } elseif ($step === 'jobs') {
            $inventory = new MnvPs9Inventory(Db::getInstance(), $rules);
            $out['modules'] = $inventory->modules();
            $out['themes'] = $inventory->themes();
            $out['overrides'] = $inventory->overrides();
        } elseif ($step === 'scan') {
            $out['result'] = $this->scanJob((string) Tools::getValue('type'), (string) Tools::getValue('name'), $rules);
        } elseif ($step === 'save') {
            $report = (string) Tools::getValue('report');
            if (strlen($report) < 5000000 && json_decode($report) !== null) {
                @file_put_contents($this->reportFile(), $report);
            }
        } else {
            $out = array('ok' => false);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out);
        exit;
    }

    /**
     * @param string $type module|theme|overrides
     * @param string $name
     * @param array $rules
     *
     * @return array
     */
    protected function scanJob($type, $name, array $rules)
    {
        $scanner = new MnvPs9Scanner($rules, $this->php8());
        $inventory = new MnvPs9Inventory(Db::getInstance(), $rules);
        $findings = array();
        $files = 0;

        if ($type === 'module') {
            // Names come from the module table; still, only a plain directory name is accepted.
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name) || !is_dir(_PS_MODULE_DIR_ . $name)) {
                return array('missing' => true);
            }
            $dir = _PS_MODULE_DIR_ . $name;
            $scanner->setModuleRoot($dir);
            list($findings, $files) = $scanner->scanDirectory($dir, $dir, array('node_modules', 'tests', 'test'));
            $module = null;
            foreach ($inventory->modules() as $row) {
                if ($row['name'] === $name) {
                    $module = $row;
                    break;
                }
            }
            $findings = array_merge(array_values($findings), $inventory->extraFindings($dir, 'module', $module));
        } elseif ($type === 'theme') {
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name) || !is_dir(_PS_ALL_THEMES_DIR_ . $name)) {
                return array('missing' => true);
            }
            $dir = _PS_ALL_THEMES_DIR_ . $name;
            list($findings, $files) = $scanner->scanDirectory($dir, $dir, array('node_modules', '_dev'));
            $findings = array_merge(array_values($findings), $inventory->extraFindings($dir, 'theme'));
        } elseif ($type === 'overrides') {
            $dir = rtrim(_PS_OVERRIDE_DIR_, '/\\');
            list($findings, $files) = $scanner->scanDirectory($dir, $dir);
        }

        return array('findings' => array_values($findings), 'files' => $files);
    }

    protected function rules()
    {
        return require dirname(__FILE__) . '/data/rules.php';
    }

    protected function php8()
    {
        return require dirname(__FILE__) . '/data/php8.php';
    }

    protected function reportFile()
    {
        return _PS_CACHE_DIR_ . 'mnvps9check-report.json';
    }

    protected function lastReport()
    {
        $file = $this->reportFile();
        if (!is_file($file)) {
            return 'null';
        }
        $data = json_decode((string) @file_get_contents($file));

        // Re-encoded: json_encode escapes "/", so nothing in it can close the <script> tag.
        return $data !== null ? json_encode($data) : 'null';
    }

    /**
     * Every text the report page shows, translated here so the legacy translation tool
     * finds them; the page script fills {placeholders}.
     */
    protected function texts()
    {
        return array(
            'run' => $this->l('Check my store'),
            'rerun' => $this->l('Check again'),
            'running' => $this->l('Checking…'),
            'step_env' => $this->l('Server and settings'),
            'step_jobs' => $this->l('Listing modules, theme and overrides'),
            'step_scan' => $this->l('Reading {name}'),
            'done' => $this->l('Finished in {seconds} s: {files} files read.'),
            'failed' => $this->l('The check stopped: {error}. Reload the page and try again.'),
            'last_run' => $this->l('Last check: {date}'),
            'download' => $this->l('Download the report (PDF)'),
            'pdf_title' => $this->l('PrestaShop 9 Readiness Check'),
            'print' => $this->l('Print'),

            'verdict_ready' => $this->l('Your store looks ready for PrestaShop 9'),
            'verdict_ready_text' => $this->l('Nothing we check would break. Back up the store and run the upgrade on a copy first.'),
            'verdict_work' => $this->l('Your store needs some work before PrestaShop 9'),
            'verdict_work_text' => $this->l('Some modules, overrides or settings would break pages or features after the upgrade. Each one is listed below with what to do.'),
            'count_errors' => $this->l('would break'),
            'count_warnings' => $this->l('worth checking'),
            'verdict_on9' => $this->l('Your store already runs PrestaShop {version}'),
            'verdict_on9_ok_text' => $this->l('No module, theme or override uses code that PrestaShop 9 removed.'),
            'verdict_on9_work_text' => $this->l('Some modules, the theme or overrides still use code that PrestaShop 9 removed, so those features may already fail. Each one is listed below.'),
            'offer9_title' => $this->l('Something not working since the upgrade?'),
            'offer9_text' => $this->l('Moonivo updates or replaces the modules, themes and overrides that PrestaShop 9 broke.'),
            'offer9_button' => $this->l('Ask for a fixed price'),
            'offer9_inline' => $this->l('We can fix these for a fixed price'),
            'level' => $this->l('Upgrade effort'),
            'level_simple' => $this->l('Simple'),
            'level_medium' => $this->l('Medium'),
            'level_complex' => $this->l('Complex'),
            'level_simple_text' => $this->l('A routine upgrade: update the server, update the flagged modules, upgrade.'),
            'level_medium_text' => $this->l('Several modules or overrides need updates or replacements before upgrading.'),
            'level_complex_text' => $this->l('Plan it as a project: custom code, overrides and the theme need rework, best done on a copy of the store.'),
            'level_note' => $this->l('A rough guide based on what this check can read; custom code can hide more.'),

            'sec_server' => $this->l('Server and settings'),
            'sec_modules' => $this->l('Modules'),
            'sec_theme' => $this->l('Theme'),
            'sec_overrides' => $this->l('Overrides'),
            'current' => $this->l('now: {value}'),
            'required' => $this->l('needed: {value}'),
            'disabled' => $this->l('disabled'),
            'line' => $this->l('line'),
            'no_overrides' => $this->l('The store has no overrides.'),
            'override_note' => $this->l('Overrides replace core code. Each one has to be checked against PrestaShop 9, even when nothing below is flagged.'),
            'library_note' => $this->l('in a bundled library'),

            'st_native_upgrade' => $this->l('Updated by the upgrade'),
            'st_native_9' => $this->l('Comes with PrestaShop'),
            'native_group' => $this->l('{n} modules that come with PrestaShop'),
            'st_prestashop' => $this->l('PrestaShop module: update to its latest version'),
            'st_error' => $this->l('Needs an update or a replacement'),
            'st_warning' => $this->l('Worth checking'),
            'st_ok' => $this->l('No problems found'),
            'st_missing' => $this->l('Files missing'),

            'env_php' => $this->l('PHP version'),
            'env_extensions' => $this->l('PHP extensions'),
            'env_extensions_missing' => $this->l('missing: {value}'),
            'env_memory' => $this->l('PHP memory limit'),
            'env_time' => $this->l('Max execution time'),
            'env_fopen' => $this->l('allow_url_fopen'),
            'env_database' => $this->l('Database server'),
            'env_myisam' => $this->l('MyISAM tables'),
            'env_charset' => $this->l('Tables not in utf8mb4'),
            'env_asm' => $this->l('Advanced Stock Management'),
            'env_multishipping' => $this->l('Delivery to several addresses'),
            'env_smtp' => $this->l('SMTP encryption'),
            'env_php_help' => $this->l('PrestaShop 9 needs PHP 8.1 or later. Your host can usually switch it in the hosting panel; test the store on PHP 8 first, older modules often fail there.'),
            'env_asm_help' => $this->l('PrestaShop 9 removed Advanced Stock Management. Stock movements and warehouses stop working; plan how you manage stock first.'),
            'env_multishipping_help' => $this->l('PrestaShop 9 removed delivery to several addresses in one order.'),
            'env_smtp_help' => $this->l('PrestaShop 9 sends mail with TLS or no encryption only. Switch to TLS (usually port 587) before upgrading.'),
            'env_myisam_help' => $this->l('Convert them to InnoDB before upgrading; MyISAM tables are not transactional and slow the upgrade down.'),
            'env_charset_help' => $this->l('Older tables use utf8 instead of utf8mb4. Not blocking, but emoji and some characters can be lost.'),
            'env_database_help' => $this->l('PrestaShop 9 needs MySQL 5.7+ or MariaDB 10.2+.'),
            'env_memory_help' => $this->l('PrestaShop 9 recommends at least 512M.'),

            'f_removed_method' => $this->l('Calls {subject}, removed in PrestaShop {extra}. Whatever uses it stops with a fatal error.'),
            'f_removed_class' => $this->l('Uses the class {subject}, removed in PrestaShop {extra}.'),
            'f_removed_const' => $this->l('Uses the constant {subject}, removed in PrestaShop {extra}.'),
            'f_removed_hook' => $this->l('Uses the hook {subject}, which PrestaShop {extra} no longer triggers: that feature stops working.'),
            'f_return_type' => $this->l('Redefines {subject} without that return type, which PrestaShop 9 added: fatal error as soon as the file loads.'),
            'f_admin_silent' => $this->l('Changes {subject}, a back-office page PrestaShop 9 rebuilt: the change silently stops working.'),
            'f_removed_service' => $this->l('Asks for the service {subject}, which PrestaShop 9 no longer has.'),
            'f_library' => $this->l('Uses {subject}, which PrestaShop 9 no longer includes, without shipping its own copy.'),
            'f_php8_function' => $this->l('Calls {subject}, removed in PHP {extra}; PrestaShop 9 needs PHP 8.1+.'),
            'f_php8_syntax' => $this->l('Uses {subject}, a syntax PHP 8 rejects: the file does not load.'),
            'f_php8_autoload' => $this->l('Declares __autoload(), removed in PHP 8.'),
            'f_compliancy' => $this->l('The module declares support up to PrestaShop {subject}: PrestaShop 9 will not install it until the author releases an update.'),
            'f_endpoint' => $this->l('{subject} is opened directly by the browser; PrestaShop 9 blocks direct access to PHP files in modules (Apache).'),
            'f_jquery' => $this->l('Loads {subject} from the core, a file PrestaShop 9 no longer has.'),
            'f_tplvar' => $this->l('The template uses {subject}, which PrestaShop 9 templates no longer receive.'),
            'f_guarded' => $this->l('The code checks the PrestaShop version or the library is bundled, so this may already be handled.'),

            'theme_native_upgrade' => $this->l('Default PrestaShop theme: updated by the upgrade.'),
            'theme_native_9' => $this->l('Default PrestaShop theme.'),
            'theme_child' => $this->l('Child theme of {parent}.'),
            'theme_custom' => $this->l('Custom theme: test every page on a copy of the store after the upgrade.'),
            'theme_compat' => $this->l('Declares PrestaShop {from} – {to}.'),

            'offer_title' => $this->l('Want it done for you?'),
            'offer_text' => $this->l('Moonivo upgrades PrestaShop stores to version 9 for a fixed price: we update or replace the flagged modules, fix the overrides and the theme, and switch over on a tested copy, so the live store keeps selling.'),
            'offer_button' => $this->l('Get a fixed price'),
            'offer_from' => $this->l('from €900'),
            'offer_inline' => $this->l('Want us to do it? Fixed price from €900'),
            'care_warn_title' => $this->l('Not sure about the items worth checking?'),
            'care_warn_text' => $this->l('Our store health check looks at them together with security, errors, speed and SEO basics, and tells you in writing what to fix first and what it would cost.'),
            'care_warn_button' => $this->l('See the store health check'),
            'care_warn_from' => $this->l('€190 · written report in 3 working days'),
            'care_warn_inline' => $this->l('Want us to look at them? Store health check €190'),
            'care_ok_title' => $this->l('Your store is in good shape for PrestaShop 9'),
            'care_ok_text' => $this->l('To keep it that way, Moonivo checks and maintains PrestaShop stores and builds the modules they need.'),
            'care_ok_button' => $this->l('See plans and prices'),
            'privacy' => $this->l('This check runs in your store only. Nothing is sent anywhere.'),
        );
    }
}
