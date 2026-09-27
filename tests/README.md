# Same-request pricing regression

This CLI integration test requires WordPress/WooCommerce and a separately copied database named `goldmate_audit_isolated_20260917`. It refuses to run against any other database. It creates/deletes only its fixture products, configures rate/rounding settings in the isolated schema, and does not delete rate history. Do not expose the bootstrap through HTTP.

Current machine runner:

    python C:/Users/moham/AppData/Local/Temp/hermes-supervisor-readonly-test/goldmate/run_regressions.py

The runner compares preserved original, starting-patch, and current pricing classes in separate PHP processes. It lints all plugin PHP files and checks that the intentional variation-table change remains byte-identical.

Single scenario (Bash; use the native PHP executable, not the `php` shell wrapper):

    GOLDMATE_TEST_BOOTSTRAP='C:/Users/moham/AppData/Local/Temp/hermes-supervisor-readonly-test/goldmate/isolated/bootstrap.php' 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' tests/same-request-pricing.php changed-weight

Scenarios: `empty-parent`, `changed-weight`, `overwritten-price`, `repeated-save`, `changed-rate`, `discount-sale` (an active GoldMate discount is written as a WooCommerce sale price).

Bootstrap requirements for another machine: boot WordPress against the isolated schema with only WooCommerce activated; disable outbound HTTP and async cron; load the GoldMate helpers, install/schema class, rate-items, formulas, discounts, components, calculator, product-fields, variation-table and chosen pricing class; call Pricing::init and Variation_Table::init. No shop credentials are stored in this repository.

Results: fixed code passes 5/5; starting patch passes empty-parent and repeated-save but fails changed-weight, overwritten-price and changed-rate. Original fails all five in this combined create-and-reprice setup; its early empty-parent failure masks later conditions, so those original failures do not independently establish five root causes.

Scope: real WooCommerce CRUD, calculator, stored prices, parent ranges, and deferred shutdown sync; not browser/theme behavior, cart taxation, or arbitrary third-party hooks. The breakdown is not mocked. The full JSON evidence is in the isolated workspace's regression-results.json. The existing bin/reprice-same-request.php is not used because its cleanup is unsafe.
