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
 * Server, database and store settings compared with what PrestaShop 9 needs
 * (devdocs: basics/installation/system-requirements, modules/core-updates/9.0).
 *
 * Each check returns [id, status, current value, required value]; status is
 * ok | warning | error. The texts are added by the module (translations).
 */
class MnvPs9Environment
{
    const PHP_MIN = '8.1.0';
    const PHP_MAX_TESTED = '8.5';
    const MEMORY_MIN = 536870912; // 512M

    /** @var Db */
    protected $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function run()
    {
        $checks = array();
        $checks[] = $this->phpVersion();
        foreach ($this->extensions() as $check) {
            $checks[] = $check;
        }
        $checks[] = $this->memoryLimit();
        $checks[] = $this->maxExecutionTime();
        $checks[] = $this->urlFopen();
        $checks[] = $this->database();
        $checks[] = $this->engines();
        $checks[] = $this->charset();
        $checks[] = $this->advancedStock();
        $checks[] = $this->multiShipping();
        $checks[] = $this->smtpSsl();

        return array(
            'ps' => _PS_VERSION_,
            'php' => PHP_VERSION,
            'checks' => array_values(array_filter($checks)),
            'dbSize' => $this->databaseSize(),
            'shops' => (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'shop`'),
            'products' => (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`'),
            'languages' => (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'lang` WHERE `active` = 1'),
        );
    }

    protected function phpVersion()
    {
        $status = version_compare(PHP_VERSION, self::PHP_MIN, '<') ? 'error' : 'ok';

        return array('php', $status, PHP_VERSION, '8.1 – ' . self::PHP_MAX_TESTED);
    }

    protected function extensions()
    {
        $out = array();
        $missing = array();
        foreach (array('curl', 'dom', 'fileinfo', 'gd', 'iconv', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'simplexml', 'zip') as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        $out[] = array('extensions', $missing ? 'error' : 'ok', $missing ? implode(', ', $missing) : '', '');

        return $out;
    }

    protected function memoryLimit()
    {
        $raw = (string) ini_get('memory_limit');
        $bytes = $this->toBytes($raw);
        $status = ($bytes === -1 || $bytes >= self::MEMORY_MIN) ? 'ok' : 'warning';

        return array('memory', $status, $raw, '512M');
    }

    protected function maxExecutionTime()
    {
        $value = (int) ini_get('max_execution_time');
        $status = ($value === 0 || $value >= 30) ? 'ok' : 'warning';

        return array('time', $status, $value === 0 ? '∞' : $value . ' s', '30 s');
    }

    protected function urlFopen()
    {
        $on = (bool) ini_get('allow_url_fopen');

        return array('fopen', $on ? 'ok' : 'warning', $on ? 'On' : 'Off', 'On');
    }

    protected function database()
    {
        $version = (string) $this->db->getValue('SELECT VERSION()');
        $isMaria = stripos($version, 'mariadb') !== false;
        preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $m);
        $number = isset($m[1]) ? $m[1] : '0';
        $min = $isMaria ? '10.2' : '5.7';
        $status = version_compare($number, $min, '<') ? 'error' : 'ok';

        return array('database', $status, ($isMaria ? 'MariaDB ' : 'MySQL ') . $number, $isMaria ? 'MariaDB 10.2+' : 'MySQL 5.7+');
    }

    protected function engines()
    {
        $rows = $this->db->executeS(
            'SELECT `TABLE_NAME` FROM information_schema.TABLES WHERE `TABLE_SCHEMA` = DATABASE()'
            . " AND `TABLE_NAME` LIKE '" . pSQL(str_replace('_', '\_', _DB_PREFIX_)) . "%' AND `ENGINE` = 'MyISAM'"
        );
        $count = is_array($rows) ? count($rows) : 0;

        return array('myisam', $count ? 'warning' : 'ok', (string) $count, '0');
    }

    protected function charset()
    {
        $rows = $this->db->executeS(
            'SELECT `TABLE_NAME` FROM information_schema.TABLES WHERE `TABLE_SCHEMA` = DATABASE()'
            . " AND `TABLE_NAME` LIKE '" . pSQL(str_replace('_', '\_', _DB_PREFIX_)) . "%'"
            . " AND `TABLE_COLLATION` NOT LIKE 'utf8mb4%'"
        );
        $count = is_array($rows) ? count($rows) : 0;

        return array('charset', $count ? 'warning' : 'ok', (string) $count, '0');
    }

    protected function advancedStock()
    {
        if (!Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
            return null;
        }

        return array('asm', 'error', 'On', 'Off');
    }

    protected function multiShipping()
    {
        if (!Configuration::get('PS_ALLOW_MULTISHIPPING')) {
            return null;
        }

        return array('multishipping', 'warning', 'On', 'Off');
    }

    protected function smtpSsl()
    {
        if ((int) Configuration::get('PS_MAIL_METHOD') !== 2 || strtolower((string) Configuration::get('PS_MAIL_SMTP_ENCRYPTION')) !== 'ssl') {
            return null;
        }

        return array('smtp', 'warning', 'SSL', 'TLS');
    }

    protected function databaseSize()
    {
        $size = $this->db->getValue(
            'SELECT SUM(`DATA_LENGTH` + `INDEX_LENGTH`) FROM information_schema.TABLES WHERE `TABLE_SCHEMA` = DATABASE()'
        );

        return (int) $size;
    }

    protected function toBytes($value)
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ($unit === 'g') {
            $number *= 1073741824;
        } elseif ($unit === 'm') {
            $number *= 1048576;
        } elseif ($unit === 'k') {
            $number *= 1024;
        }

        return (int) $number;
    }
}
