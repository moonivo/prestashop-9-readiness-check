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

/*
 * PHP functions that no longer exist on PHP 8.1 (the minimum for PrestaShop 9), with the
 * PHP version that removed them. Calls to them are fatal errors.
 */
return array(
    'functions' => array(
        'create_function' => '8.0',
        'each' => '8.0',
        'money_format' => '8.0',
        'get_magic_quotes_gpc' => '8.0',
        'get_magic_quotes_runtime' => '8.0',
        'fgetss' => '8.0',
        'gzgetss' => '8.0',
        'restore_include_path' => '8.0',
        'hebrevc' => '8.0',
        'convert_cyr_string' => '8.0',
        'ezmlm_hash' => '8.0',
        'is_real' => '8.0',
        'image2wbmp' => '8.0',
        'jpeg2wbmp' => '8.0',
        'png2wbmp' => '8.0',
        'read_exif_data' => '8.0',
        'ldap_sort' => '8.0',
        'mbereg' => '8.0',
        'mberegi' => '8.0',
        'mbereg_replace' => '8.0',
        'mbsplit' => '8.0',
        'set_magic_quotes_runtime' => '7.0',
        'magic_quotes_runtime' => '7.0',
        'call_user_method' => '7.0',
        'call_user_method_array' => '7.0',
        'ereg' => '7.0',
        'eregi' => '7.0',
        'ereg_replace' => '7.0',
        'eregi_replace' => '7.0',
        'split' => '7.0',
        'spliti' => '7.0',
        'sql_regcase' => '7.0',
        'mysql_connect' => '7.0',
        'mysql_pconnect' => '7.0',
        'mysql_query' => '7.0',
        'mysql_fetch_array' => '7.0',
        'mysql_fetch_assoc' => '7.0',
        'mysql_fetch_row' => '7.0',
        'mysql_num_rows' => '7.0',
        'mysql_real_escape_string' => '7.0',
        'mysql_escape_string' => '7.0',
        'mysql_select_db' => '7.0',
        'mysql_close' => '7.0',
        'mysql_error' => '7.0',
        'mysql_insert_id' => '7.0',
        'mcrypt_encrypt' => '7.2',
        'mcrypt_decrypt' => '7.2',
        'mcrypt_create_iv' => '7.2',
        'mcrypt_get_iv_size' => '7.2',
        'mcrypt_module_open' => '7.2',
        'mcrypt_generic' => '7.2',
        'mdecrypt_generic' => '7.2',
    ),
);
