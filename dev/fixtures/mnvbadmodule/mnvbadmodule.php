<?php
/**
 * Test fixture: a module written for PrestaShop 1.7 that breaks on PrestaShop 9 in every
 * way the check knows. dev/tests/run.php asserts each finding; also installable in the
 * Docker test stores to see the report. Not shipped.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Mnvbadmodule extends Module
{
    public function __construct()
    {
        $this->name = 'mnvbadmodule';
        $this->tab = 'front_office_features';
        $this->version = '0.9.0';
        $this->author = 'Fixture Agency';
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '8.2.0');
        parent::__construct();
        $this->displayName = $this->l('Old banner (test fixture)');
    }

    public function install()
    {
        return parent::install() && $this->registerHook(array('displayHome', 'actionAjaxDieBefore'));
    }

    public function hookDisplayHome($params)
    {
        $price = Tools::displayPrice(10);                    // removed_method 9.0
        $json = Tools::jsonEncode(array('a' => 1));          // removed_method 8.0
        $uploader = new FileUploader();                      // removed_class
        $first = $json{0};                                   // php8_syntax (brace offset)
        $fn = create_function('$a', 'return $a;');           // php8_function
        $client = new \GuzzleHttp\Client();                  // library (not bundled)
        if (method_exists('Tools', 'encrypt')) {
            $hash = Tools::encrypt('x');                     // guarded -> warning
        }

        return $price . $first . $fn(1);
    }

    public function hookActionAjaxDieBefore($params)          // removed_hook
    {
    }
}
