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
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * TCPDF with a one-line footer: who made the report and the page number.
 */
class MnvPs9PdfDocument extends TCPDF
{
    /** @var string */
    public $footerText = '';

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(120, 116, 140);
        $this->Cell(0, 6, $this->footerText . '  ·  ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}
