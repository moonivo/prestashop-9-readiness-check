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
 * Advanced Parameters > PrestaShop 9 check: the report page inside the menu, so the
 * menu keeps the right section highlighted.
 */
class AdminMnvPs9CheckController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->displayName;
    }

    public function initContent()
    {
        parent::initContent();
        $this->content .= $this->module->renderReport($this->context->link->getAdminLink('AdminMnvPs9Check', true));
        $this->context->smarty->assign('content', $this->content);
    }
}
