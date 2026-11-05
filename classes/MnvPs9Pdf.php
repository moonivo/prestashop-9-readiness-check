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
 * The saved report as a PDF, made with the TCPDF library PrestaShop already ships for invoices
 * (1.7 to 9), so it needs nothing extra on the server. The layout follows the report page:
 * verdict and effort first, then the offer, server, modules, theme and overrides.
 * The summary rules mirror summarize() in views/js/admin.js; keep the two in step.
 */
class MnvPs9Pdf
{
    const NIGHT = '#211c45';
    const SOFT = '#5b5775';
    const LINE = '#e7e4f3';
    const RED = '#d64545';
    const AMBER = '#b07a0f';
    const GREEN = '#2f9e5b';
    const BLUE = '#3b6fd4';

    /** @var array */
    protected $report;

    /** @var array */
    protected $texts;

    /** @var string */
    protected $offerUrl;

    /** @var string */
    protected $careUrl;

    /** @var bool */
    protected $on9;

    /**
     * @param array $report the saved report (decoded JSON)
     * @param array $texts the module's texts() in the employee's language
     * @param string $offerUrl moonivo.com page of the upgrade offer
     * @param string $careUrl moonivo.com prices page (health check, maintenance)
     */
    public function __construct(array $report, array $texts, $offerUrl, $careUrl)
    {
        $this->careUrl = $careUrl;
        $this->report = $report;
        $this->texts = $texts;
        $this->offerUrl = $offerUrl;
        $this->on9 = (int) $this->get($report, 'ps', '0') >= 9;
    }

    /**
     * @return string the PDF file contents
     */
    public function render()
    {
        $pdf = new MnvPs9PdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->footerText = 'Moonivo · moonivo.com · ' . $this->t('pdf_title');
        $pdf->SetCreator('Moonivo PrestaShop 9 Readiness Check');
        $pdf->SetAuthor('Moonivo');
        $pdf->SetTitle($this->t('pdf_title'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML($this->html(), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /**
     * @return string
     */
    protected function html()
    {
        $s = $this->summarize();
        $ready = $s['errors'] === 0;
        $html = $this->head() . $this->verdict($s, $ready);
        // A store already fine on PrestaShop 9 gets the health check or maintenance instead of the upgrade.
        $html .= $this->on9 && $ready ? $this->care($s['warnings'] > 0) : $this->offer();

        return $html . $this->server() . $this->modules() . $this->theme() . $this->overrides()
            . '<p style="color:' . self::SOFT . ';font-size:8px;">' . $this->e($this->t('privacy')) . '</p>';
    }

    protected function head()
    {
        $finished = (int) ($this->get($this->report, 'finishedAt', 0) / 1000);
        $meta = array_filter(array(
            (string) $this->get($this->report, 'shop', ''),
            'PrestaShop ' . $this->get($this->report, 'ps', ''),
            $finished ? date('Y-m-d H:i', $finished) : '',
        ));

        return '<p style="color:#5a4cab;font-size:8px;font-weight:bold;letter-spacing:1px;">MOONIVO</p>'
            . '<h1 style="color:' . self::NIGHT . ';font-size:18px;">' . $this->e($this->t('pdf_title')) . '</h1>'
            . '<p style="color:' . self::SOFT . ';">' . $this->e(implode(' · ', $meta)) . '</p>';
    }

    protected function verdict(array $s, $ready)
    {
        $ps = (string) $this->get($this->report, 'ps', '');
        if ($this->on9) {
            $title = $this->t('verdict_on9', array('version' => $ps));
            $text = $this->t($ready ? 'verdict_on9_ok_text' : 'verdict_on9_work_text');
        } else {
            $title = $this->t($ready ? 'verdict_ready' : 'verdict_work');
            $text = $this->t($ready ? 'verdict_ready_text' : 'verdict_work_text');
        }
        $bg = $ready ? '#eefaf2' : '#fdf0f0';
        $counts = '<span style="color:' . self::RED . ';"><b>' . $s['errors'] . '</b> ' . $this->e($this->t('count_errors')) . '</span>'
            . '&nbsp;&nbsp;&nbsp;&nbsp;<span style="color:' . self::AMBER . ';"><b>' . $s['warnings'] . '</b> ' . $this->e($this->t('count_warnings')) . '</span>';
        $main = '<td width="' . ($this->on9 ? '100' : '62') . '%" style="background-color:' . $bg . ';">'
            . '<h2 style="color:' . self::NIGHT . ';font-size:13px;">' . $this->e($title) . '</h2>'
            . '<p style="color:' . self::SOFT . ';">' . $this->e($text) . '</p><p>' . $counts . '</p></td>';
        $level = '';
        if (!$this->on9) {
            $colors = array('simple' => self::GREEN, 'medium' => self::AMBER, 'complex' => self::RED);
            $level = '<td width="38%" style="background-color:#f7f6fc;">'
                . '<p style="color:' . self::SOFT . ';font-size:8px;font-weight:bold;">' . $this->e($this->upper($this->t('level'))) . '</p>'
                . '<p style="color:' . $colors[$s['level']] . ';font-size:15px;font-weight:bold;">' . $this->e($this->t('level_' . $s['level'])) . '</p>'
                . '<p>' . $this->e($this->t('level_' . $s['level'] . '_text')) . '</p>'
                . '<p style="color:' . self::SOFT . ';font-size:7.5px;">' . $this->e($this->t('level_note')) . '</p></td>';
        }

        return '<table cellpadding="8" cellspacing="0"><tr>' . $main . $level . '</tr></table><br>';
    }

    protected function care($warn)
    {
        $k = $warn ? 'care_warn_' : 'care_ok_';

        return $this->offerBox(
            $this->t($k . 'title'),
            $this->t($k . 'text'),
            $this->t($k . 'button'),
            $this->careUrl . '?utm_source=ps9check&utm_medium=pdf&utm_campaign=report-care#packages',
            $warn ? ' · ' . $this->t('care_warn_from') : '',
            $this->careUrl
        );
    }

    protected function offer()
    {
        return $this->offerBox(
            $this->t($this->on9 ? 'offer9_title' : 'offer_title'),
            $this->t($this->on9 ? 'offer9_text' : 'offer_text'),
            $this->t($this->on9 ? 'offer9_button' : 'offer_button'),
            $this->offerUrl . '?utm_source=ps9check&utm_medium=pdf&utm_campaign=' . ($this->on9 ? 'report-ps9' : 'report'),
            $this->on9 ? '' : ' · ' . $this->t('offer_from'),
            $this->offerUrl
        );
    }

    protected function offerBox($title, $text, $button, $url, $from, $shownUrl)
    {
        return '<table cellpadding="9" cellspacing="0"><tr><td style="background-color:' . self::NIGHT . ';color:#ffffff;">'
            . '<p style="font-size:12px;font-weight:bold;color:#ffffff;">' . $this->e($title) . '</p>'
            . '<p style="color:#e4e0f7;">' . $this->e($text) . '</p>'
            . '<p><a href="' . $this->e($url) . '" style="color:#ffffff;font-weight:bold;">' . $this->e($button) . ' →</a>'
            . '<span style="color:#c9c3ec;">' . $this->e($from) . ' · ' . $this->e(preg_replace('#^https?://#', '', $shownUrl)) . '</span></p>'
            . '</td></tr></table><br>';
    }

    protected function server()
    {
        $rows = '';
        foreach ((array) $this->get($this->get($this->report, 'env', array()), 'checks', array()) as $c) {
            $id = (string) $c[0];
            $status = (string) $c[1];
            $current = (string) $c[2];
            $needed = isset($c[3]) ? (string) $c[3] : '';
            $detail = '';
            if ($id === 'extensions') {
                $detail = $status === 'ok' ? '' : $this->t('env_extensions_missing', array('value' => $current));
            } elseif ($current !== '') {
                $detail = $this->t('current', array('value' => $current))
                    . ($status !== 'ok' && $needed !== '' ? ' · ' . $this->t('required', array('value' => $needed)) : '');
            }
            $help = $status !== 'ok' && isset($this->texts['env_' . $id . '_help']) ? $this->t('env_' . $id . '_help') : '';
            $rows .= '<tr><td width="4%">' . $this->dot($status) . '</td><td width="96%"><b>' . $this->e($this->t('env_' . $id)) . '</b>'
                . ($detail !== '' ? ' <span style="color:' . self::SOFT . ';">' . $this->e($detail) . '</span>' : '')
                . ($help !== '' ? '<br><span style="color:' . self::SOFT . ';font-size:8px;">' . $this->e($help) . '</span>' : '')
                . '</td></tr>';
        }

        return $this->section($this->t('sec_server')) . '<table cellpadding="2">' . $rows . '</table>';
    }

    protected function modules()
    {
        $order = array('error' => 0, 'warning' => 1, 'missing' => 2, 'prestashop' => 3, 'ok' => 4, 'native' => 5);
        $modules = (array) $this->get($this->report, 'modules', array());
        $self = $this;
        usort($modules, function ($a, $b) use ($order, $self) {
            $d = $order[$self->moduleStatus($a)] - $order[$self->moduleStatus($b)];

            return $d !== 0 ? $d : strcasecmp((string) $a['displayName'], (string) $b['displayName']);
        });

        $html = $this->section($this->t('sec_modules'), count($modules));
        $natives = array();
        foreach ($modules as $m) {
            $status = $this->moduleStatus($m);
            if ($status === 'native') {
                $natives[] = (string) $m['displayName'];
                continue;
            }
            $meta = $m['name'] . ' ' . $m['version'] . (!empty($m['author']) ? ' · ' . $m['author'] : '')
                . (empty($m['active']) ? ' · ' . $this->t('disabled') : '');
            $html .= '<table cellpadding="3"><tr><td width="4%">' . $this->dot($status) . '</td>'
                . '<td width="58%"><b>' . $this->e($m['displayName']) . '</b> <span style="color:' . self::SOFT . ';">' . $this->e($meta) . '</span></td>'
                . '<td width="38%" align="right" style="color:' . $this->color($status) . ';">' . $this->e($this->statusLabel($status)) . '</td></tr></table>'
                . $this->findings($this->findingsOf($m));
        }
        if ($natives) {
            $html .= '<table cellpadding="3"><tr><td width="4%">' . $this->dot('native') . '</td><td width="96%"><b>'
                . $this->e($this->t('native_group', array('n' => count($natives)))) . '</b> <span style="color:' . self::SOFT . ';">'
                . $this->e($this->statusLabel('native')) . '</span><br><span style="color:' . self::SOFT . ';font-size:7.5px;">'
                . $this->e(implode(', ', $natives)) . '</span></td></tr></table>';
        }

        return $html;
    }

    protected function theme()
    {
        $html = $this->section($this->t('sec_theme'));
        foreach ((array) $this->get($this->report, 'themes', array()) as $th) {
            $lines = array();
            if (!empty($th['native'])) {
                $lines[] = $this->t($this->on9 ? 'theme_native_9' : 'theme_native_upgrade');
            } else {
                $lines[] = !empty($th['parent']) ? $this->t('theme_child', array('parent' => $th['parent'])) : $this->t('theme_custom');
            }
            if (!empty($th['from']) || !empty($th['to'])) {
                $lines[] = $this->t('theme_compat', array(
                    'from' => !empty($th['from']) ? $th['from'] : '?',
                    'to' => !empty($th['to']) ? $th['to'] : '?',
                ));
            }
            $findings = $this->findingsOf($th);
            $status = !empty($th['native']) ? 'native' : $this->statusOf($findings);
            $html .= '<table cellpadding="3"><tr><td width="4%">' . $this->dot($status) . '</td><td width="96%"><b>' . $this->e($th['name']) . '</b>'
                . (!empty($th['version']) ? ' <span style="color:' . self::SOFT . ';">' . $this->e($th['version']) . '</span>' : '')
                . '<br><span style="color:' . self::SOFT . ';">' . $this->e(implode(' ', $lines)) . '</span></td></tr></table>'
                . $this->findings($findings);
        }

        return $html;
    }

    protected function overrides()
    {
        $ov = (array) $this->get($this->report, 'overrides', array());
        $files = (array) $this->get($ov, 'files', array());
        $html = $this->section($this->t('sec_overrides'), $files ? count($files) : null);
        if (!$files) {
            return $html . '<p style="color:' . self::SOFT . ';">' . $this->e($this->t('no_overrides')) . '</p>';
        }
        $list = array();
        foreach ($files as $f) {
            $list[] = 'override/' . $f;
        }

        return $html . '<p style="color:' . self::SOFT . ';">' . $this->e($this->t('override_note')) . '</p>'
            . '<p style="font-family:courier;font-size:8px;color:' . self::SOFT . ';">' . implode('<br>', array_map(array($this, 'e'), $list)) . '</p>'
            . $this->findings($this->findingsOf($ov));
    }

    /**
     * @param array $findings
     *
     * @return string
     */
    protected function findings(array $findings)
    {
        if (!$findings) {
            return '';
        }
        usort($findings, function ($a, $b) {
            return ($a['severity'] === 'error' ? 0 : 1) - ($b['severity'] === 'error' ? 0 : 1);
        });
        $rows = '';
        foreach ($findings as $f) {
            $text = $this->t('f_' . $f['code'], array('subject' => $f['subject'], 'extra' => isset($f['extra']) ? $f['extra'] : ''));
            $lines = isset($f['lines']) ? (array) $f['lines'] : array();
            $where = $f['file'] . ($lines ? ' · ' . $this->t('line') . ' ' . implode(', ', $lines)
                . (isset($f['count']) && $f['count'] > count($lines) ? '…' : '') : '');
            $note = '';
            if (!empty($f['library'])) {
                $note = ' · ' . $this->t('library_note');
            } elseif ($f['severity'] === 'warning' && !in_array($f['code'], array('compliancy', 'tplvar', 'removed_service', 'admin_silent'), true)) {
                $note = ' · ' . $this->t('f_guarded');
            }
            $bg = $f['severity'] === 'error' ? '#fdf0f0' : '#fdf6e6';
            $rows .= '<tr><td width="5%"></td><td width="95%" style="background-color:' . $bg . ';">' . $this->e($text)
                . '<br><span style="font-family:courier;font-size:7.5px;color:' . self::SOFT . ';">' . $this->e($where) . '</span>'
                . ($note !== '' ? '<span style="font-size:7.5px;color:' . self::SOFT . ';">' . $this->e($note) . '</span>' : '')
                . '</td></tr>';
        }

        return '<table cellpadding="4" cellspacing="2">' . $rows . '</table>';
    }

    // ---- Summary (mirrors views/js/admin.js) ------------------------------

    /**
     * @return array errors, warnings, level
     */
    public function summarize()
    {
        $errors = 0;
        $warnings = 0;
        $points = 0;
        $ps = (string) $this->get($this->report, 'ps', '');
        if (strpos($ps, '1.7') === 0) {
            $points += 2;
        } elseif (strpos($ps, '8.0') === 0 || strpos($ps, '8.1') === 0) {
            $points += 1;
        }
        foreach ((array) $this->get($this->get($this->report, 'env', array()), 'checks', array()) as $c) {
            if ($c[1] === 'error') {
                ++$errors;
                $points += $c[0] === 'asm' ? 2 : 1;
            } elseif ($c[1] === 'warning') {
                ++$warnings;
            }
        }
        foreach ((array) $this->get($this->report, 'modules', array()) as $m) {
            $c = $this->counts($this->findingsOf($m));
            $errors += $c['error'];
            $warnings += $c['warning'];
            $points += $c['error'] ? 1 : 0;
        }
        $ov = (array) $this->get($this->report, 'overrides', array());
        $files = (array) $this->get($ov, 'files', array());
        if ($files) {
            $points += 1 + (int) floor(count($files) / 5);
            $c = $this->counts($this->findingsOf($ov));
            $errors += $c['error'];
            $warnings += $c['warning'];
            $points += $c['error'] ? 1 : 0;
        }
        foreach ((array) $this->get($this->report, 'themes', array()) as $th) {
            if (!empty($th['native'])) {
                continue;
            }
            $points += !empty($th['parent']) ? 1 : 2;
            $c = $this->counts($this->findingsOf($th));
            $errors += $c['error'];
            $warnings += $c['warning'];
            $points += $c['error'] ? 1 : 0;
        }

        return array(
            'errors' => $errors,
            'warnings' => $warnings,
            'level' => $points <= 2 ? 'simple' : ($points <= 6 ? 'medium' : 'complex'),
        );
    }

    /**
     * @param array $m
     *
     * @return string
     */
    public function moduleStatus(array $m)
    {
        $kind = isset($m['kind']) ? $m['kind'] : '';
        if (in_array($kind, array('native', 'prestashop', 'missing'), true)) {
            return $kind;
        }

        return $this->statusOf($this->findingsOf($m));
    }

    protected function statusOf(array $findings)
    {
        $c = $this->counts($findings);

        return $c['error'] ? 'error' : ($c['warning'] ? 'warning' : 'ok');
    }

    protected function counts(array $findings)
    {
        $c = array('error' => 0, 'warning' => 0);
        foreach ($findings as $f) {
            if (isset($f['severity'], $c[$f['severity']])) {
                ++$c[$f['severity']];
            }
        }

        return $c;
    }

    protected function findingsOf($item)
    {
        $result = $this->get((array) $item, 'result', array());

        return (array) $this->get((array) $result, 'findings', array());
    }

    // ---- Helpers ----------------------------------------------------------

    protected function statusLabel($status)
    {
        if ($status === 'native') {
            return $this->t($this->on9 ? 'st_native_9' : 'st_native_upgrade');
        }

        return $this->t('st_' . $status);
    }

    protected function color($status)
    {
        $map = array('error' => self::RED, 'warning' => self::AMBER, 'ok' => self::GREEN, 'native' => self::BLUE, 'prestashop' => self::BLUE);

        return isset($map[$status]) ? $map[$status] : '#999999';
    }

    protected function dot($status)
    {
        return '<span style="color:' . $this->color($status) . ';font-size:11px;">●</span>';
    }

    protected function section($title, $count = null)
    {
        return '<br><h3 style="color:' . self::NIGHT . ';font-size:12px;border-bottom:1px solid ' . self::LINE . ';">' . $this->e($title)
            . ($count !== null ? ' <span style="color:' . self::SOFT . ';font-weight:normal;">' . (int) $count . '</span>' : '') . '</h3>';
    }

    /**
     * A text with its {placeholders} filled.
     */
    protected function t($key, array $vars = array())
    {
        $text = isset($this->texts[$key]) ? (string) $this->texts[$key] : $key;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }

        return $text;
    }

    protected function upper($text)
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    public function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    protected function get($array, $key, $default)
    {
        return is_array($array) && array_key_exists($key, $array) && $array[$key] !== null ? $array[$key] : $default;
    }
}

