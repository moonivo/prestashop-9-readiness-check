# PrestaShop 9 Readiness Check

A free back-office module that tells you what would break if your store moved to PrestaShop 9, before you start the upgrade.

![The report in the back office](https://moonivo.com/images/ps9-check-report.webp)

It reads the code of every installed module, the active theme and the `override/` folder, checks the server, and lists each problem with the file, the line, what happens on PrestaShop 9 and what to do. At the top: a verdict and a rough estimate of the upgrade effort. The report can be downloaded as a PDF for your developer.

## What it checks

- **Removed and changed code.** More than 1,000 classes, methods, constants, hooks, services and Smarty plugins that PrestaShop 8 and 9 removed or changed, for example `Tools::displayPrice()`, `Controller::ajaxDie()`, `Tools::jsonEncode()`, hooks that are no longer triggered, and core methods that gained return types (an override that redeclares one without it is a fatal error).
- **PHP 8.** Syntax and functions that PHP 8.1+ no longer accepts.
- **Overrides and templates.** Overrides of methods that changed or no longer run; templates that use removed template variables or the old bundled jQuery files.
- **Direct PHP endpoints.** Module files like `ajax.php` that are called directly, which PrestaShop 9's `modules/.htaccess` blocks on Apache.
- **Server.** PHP version and extensions, memory limit and the other requirements of PrestaShop 9.

On a store that is already on PrestaShop 9, it lists what still uses removed code, which helps when something broke after the upgrade.

## Good to know

- **Read-only.** It changes no files or settings. Nothing is sent anywhere: the report is saved in your store.
- **Runs on PrestaShop 1.7, 8 and 9**, PHP 5.6 to 8.4. Tested on 1.7.6, 8.2, 9.1 and 9.2.
- **Languages:** English, French, Spanish, Italian, German, Dutch, Polish, Lithuanian.
- **Guarded code is a warning, not an error.** Code behind a PrestaShop version check, `class_exists()`, `method_exists()` or `instanceof` usually already runs on both versions, so it is listed to check, not counted as a failure.
- **A guide, not a guarantee.** It reads the code without running it, so code built at run time can hide more. Always test the upgrade on a copy of the store first.

## Install

1. Download `mnvps9check-x.y.z.zip` from [Releases](../../releases) or from [moonivo.com](https://moonivo.com/prestashop-9-upgrade). Do not use GitHub's "Download ZIP": PrestaShop expects the folder inside the zip to be named `mnvps9check`.
2. In the back office: **Modules > Module Manager > Upload a module**.
3. Open **Advanced Parameters > PrestaShop 9 check**.

## How the rules are made

The list of removed code is not written by hand. `dev/api/extract.php` reads the public API of the PrestaShop 1.7.8, 8.2, 9.0 and 9.2 sources (classes with inherited methods and constants, functions, hooks, Smarty plugins), `dev/api/diff.php` compares them and `dev/api/build_rules.php` writes `data/rules.php`. Rules that cannot come from a diff (dynamic hooks, services, behaviour changes) are added by hand, each with its source in [`dev/RESEARCH.md`](dev/RESEARCH.md).

The extracted API files are not in this repository (about 50 MB); run `extract.php` on the PrestaShop sources to rebuild them.

## Development

```bash
php dev/scan.php <dir> [--json]   # run the scanner on any module, theme or override folder
php dev/tests/run.php             # scanner tests against dev/fixtures
php dev/build_release.php         # build the release zip (dev/ is never shipped)
```

Translations live in `dev/i18n/*.json` and are compiled into `translations/`.

## Found a wrong result?

If it flags something wrongly, or misses something that broke on your upgrade, please [open an issue](../../issues) with your PrestaShop and PHP versions and the module or file involved.

## Upgrade help

The module is free and works fully on its own. If you would rather have the upgrade done for you, [Moonivo](https://moonivo.com/prestashop-9-upgrade) upgrades stores to PrestaShop 9 for a fixed price, on a tested copy.

## License

[Academic Free License 3.0](LICENSE.txt), © 2026 Moonivo.
