# PrestaShop Product Import

This repository is the standalone `productimport` module for PrestaShop 9.x.
Its files live at the root. Scrapers are maintained independently at
https://github.com/ladismrkolj/webshop-scraper. JSON/XML feeds are the interface.

## Testing workflow for Codex

Read `local-dev/README.md` and `examples/README.md` first. Use the isolated Docker
store for integration tests; never point test imports at a production store.
Do not install PHP, Composer or database software globally.

1. Check Docker availability (`docker info`). On macOS start an existing Docker
   Desktop/Colima environment if available. If absent, report the prerequisite
   rather than claiming the store was tested.
2. Run `./local-dev/dev.sh test`. This installs locked dev dependencies in an
   isolated volume, runs PHPUnit and checks formatting. Fix failures and rerun.
3. Run `./local-dev/dev.sh up`. This prepares production dependencies using the
   store's PHP version and starts the store/database. Follow the install logs.
4. When the store is ready, run `./local-dev/dev.sh install` on a fresh store.
   On an existing store use the module upgrade flow, not uninstall/reinstall.
5. Open the local back office; use the credentials in local-dev/.env. Create
   both example sources as described in examples/README.md. Test source, save
   mappings, Test configuration, discover categories/brands and import.
6. Verify four products by their DEMO references: names, active status, stock,
   prices/discounts, categories and brand. Re-import both sources; assert the
   count remains four and product IDs remain unchanged. Compare run logs and
   database/store values, not just the successful HTTP response.
7. For UI changes, also verify save/reopen, mapping dropdowns and background-run
   progress. For variants/images/cron changes, additionally exercise those
   features; the two minimal examples do not cover them.
8. Record what passed, what failed and any untested behavior. Leave the test
   store available for review unless instructed otherwise. `stop` preserves
   volumes. Never run `docker compose down -v` without explicit authorization
   to erase the local test store.

## Code and security

Keep dependencies in vendor/ or Docker volumes. The production store uses PHP
8.3; PHPUnit 13 needs PHP 8.4, so tests use a separate image and vendor volume.
Use local vendor tools if suitable PHP is already available. Check changed PHP
syntax, PHPUnit and PHP-CS-Fixer. New parsing/mapping behavior needs focused
unit tests. Follow PrestaShop escaping and database conventions, validate input
and preserve cron token protection. Do not commit secrets, real store exports
or local .env. Do not confuse successful unit tests with store integration
testing. Example feeds are synthetic and can be edited for local tests.
