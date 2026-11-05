# PrestaShop 8.x → 9.x: what breaks (research, 2026-11-05)

Source of truth for the checks. Code facts come from `dev/api` (diff of the 1.7.8.11, 8.2.8,
9.0.0 and 9.2.0 sources); everything else from the official pages below.

- [REQ] https://devdocs.prestashop-project.org/9/basics/installation/system-requirements/
- [C90] https://devdocs.prestashop-project.org/9/modules/core-updates/9.0/
- [C91] https://devdocs.prestashop-project.org/9/modules/core-updates/9.1/
- [C80] https://devdocs.prestashop-project.org/9/modules/core-updates/8.0/
- [OVR] https://devdocs.prestashop-project.org/9/modules/concepts/overrides/
- [UBO] https://devdocs.prestashop-project.org/9/basics/keeping-up-to-date/update/update-from-the-back-office/
- [AU] https://github.com/PrestaShop/autoupgrade/blob/v7.6.6/classes/UpgradeSelfCheck.php
- [AUM] https://github.com/PrestaShop/autoupgrade/blob/v7.6.6/classes/UpgradeTools/Module/ModuleCompatibilityChecker.php

## Requirements [REQ]
- PHP: 9.0 supports 8.1–8.4 (8.4 recommended); 9.1 supports 8.1–8.5. Below 8.1 is unsupported.
- Extensions (same as 8.2): curl, dom, fileinfo, gd, iconv, intl, json, mbstring, openssl, pdo, pdo_mysql, simplexml, zip.
- MySQL 5.7+ or MariaDB 10.2+. memory_limit ≥ 512M, allow_url_fopen On.
- Apache 2.4+ or Nginx. `modules/.htaccess` (Apache) now denies direct access to
  `.php .log .txt .lock .json .yml .yaml .tpl .twig config.xml` inside modules → modules with
  their own `ajax.php`-style endpoints break; use ModuleFrontController [C90].

## Code (removed APIs) — data/rules.php, generated
- Removed legacy classes, methods, constants, hooks from the source diff, with the version
  they disappeared in (8.0 or 9.0). 1.7 shops jump over the 8.0 removals too
  (`Tools::jsonEncode`, `Media::getJqueryPath`, `Attribute` → `ProductAttribute`, `Cookie::isLogged`) [C80].
- Core methods that gained a return type in 9.x: an override or module subclass redefining
  one without it is a fatal error when the class loads.
- Not in the diff, added by hand: `actionAdmin{Activate,Deactivate,Delete,Sort}{Before,After}`
  and `actionAdminProductsController{Activate,Deactivate,Delete,Duplicate,Sort}{Before,After}`
  (dynamic hooks) [C90].
- AdminController `run/initHeader/initContent/initFooter/display` still exist but are no longer
  called for migrated pages → overrides of them silently stop working [C90].
- Swift Mailer and Guzzle left the core: `Swift_*` / `GuzzleHttp\*` without the module bundling
  its own copy breaks [C90].
- Symfony 4.4 → 6.4: `$this->get()` removed from Symfony controllers; ~40 `prestashop.*` services
  removed (e.g. `prestashop.module.manager`, `prestashop.adapter.image_manager`);
  `sensio/framework-extra-bundle` removed (`@AdminSecurity` annotations → attributes) [C90].
- `Tools::convertPrice`, `Tools::displayPriceSmarty`, `Tools::usingSecureMode`,
  `Tools::getCurrentUrlProtocolPrefix` still exist; `{displayPrice}` & co. are back-office only
  in every version (not a 9.0 change).

## Themes [C90] [C91]
- Smarty stays 4.x (3 → 4 happened in 8.0: matters for 1.7 themes).
- jQuery: only `js/jquery/jquery-3.7.1` and `jquery-migrate-3.4.0` remain; hard-coded paths to
  1.11.0 / 3.4.1 / 3.5.1 / migrate-1.2.1 / 3.1.0 404.
- Removed template data: `$productPriceWithoutReduction`, `meta_keywords`; `$manufacturer.image`
  is now an array; category/supplier/manufacturer/store pages use presenters.
- Hummingbird is default only for new 9.1 installs; upgraded shops keep their theme.

## Store settings [C90]
- Advanced Stock Management and multi-address delivery removed.
- SMTP with SSL encryption removed (TLS or none only).

## What Update Assistant 7 already checks [AU] [AUM]
Server (writable dirs, extensions, memory_limit, max_execution_time, mod_rewrite, PHP vs target),
maintenance mode, PS_CACHE, modified core/default-theme files by checksum, and per module only
"does Addons list a compatible release" (homemade/unlisted = "uncertain"). It never reads module,
theme or override code — that is what this module adds.
