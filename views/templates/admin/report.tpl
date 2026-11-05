{*
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
 *}
<link rel="stylesheet" href="{$mnv_css|escape:'html':'UTF-8'}">
<div class="mnv9" id="mnv9"
     data-ajax="{$mnv_ajax_url|escape:'html':'UTF-8'}"
     data-offer="{$mnv_offer_url|escape:'html':'UTF-8'}"
     data-care="{$mnv_care_url|escape:'html':'UTF-8'}"
     data-pdf="{$mnv_pdf_url|escape:'html':'UTF-8'}"
     data-ps="{$mnv_ps|escape:'html':'UTF-8'}"
     data-shop="{$mnv_shop|escape:'html':'UTF-8'}">
  <div class="mnv9-head">
    <div>
      <p class="mnv9-eyebrow">Moonivo</p>
      <h2 class="mnv9-title">{l s='PrestaShop 9 Readiness Check' mod='mnvps9check'}</h2>
      <p class="mnv9-lead">
        {l s='Reads your modules, theme and overrides and checks the server, then tells you what would break on PrestaShop 9 and how much work the upgrade is. It changes nothing in your store.' mod='mnvps9check'}
      </p>
    </div>
    <div class="mnv9-head-actions">
      <button type="button" class="mnv9-btn mnv9-btn--primary" id="mnv9-run"></button>
      <p class="mnv9-version">{l s='Your store' mod='mnvps9check'}: PrestaShop {$mnv_ps|escape:'html':'UTF-8'}</p>
    </div>
  </div>

  <div class="mnv9-progress" id="mnv9-progress" hidden>
    <div class="mnv9-progress-bar"><span id="mnv9-progress-fill"></span></div>
    <p id="mnv9-progress-text"></p>
  </div>

  <div id="mnv9-report"></div>
  <p class="mnv9-privacy" id="mnv9-privacy"></p>
</div>
<script>
  window.MNV9_TEXTS = {$mnv_texts nofilter};
  window.MNV9_LAST = {$mnv_last nofilter};
</script>
<script src="{$mnv_js|escape:'html':'UTF-8'}"></script>
