# Store integration testing

Read `local-dev/README.md` and `examples/README.md` before starting. Use the isolated Docker store for all integration tests. Never point test imports at a production store.

## Prerequisites

1. Check Docker: `docker info`.
2. On macOS, if Docker isn't running, start an existing Docker Desktop or Colima environment.
3. If Docker is unavailable, stop and report it as a prerequisite. Don't claim the store was tested.

## Steps

1. Run `./local-dev/dev.sh test`. This installs locked dev dependencies in an isolated volume, runs PHPUnit and checks formatting. Fix failures and rerun.
2. Run `./local-dev/dev.sh up`. This prepares production dependencies using the store's PHP version and starts the store and database. Follow the install logs.
3. When the store is ready, run `./local-dev/dev.sh install` on a fresh store. On an existing store, use the module upgrade flow, not uninstall and reinstall.
4. Open the local back office using the credentials in `local-dev/.env`. Create both example sources as described in `examples/README.md`. Test the source, save mappings, run Test configuration, discover categories and brands, then import.
5. Re-import both sources and confirm the results.
6. For UI changes, also verify save and reopen, mapping dropdowns, and background-run progress.
7. For variant, image, or cron changes, exercise those features too. The two minimal examples don't cover them.
8. Record what passed, what failed, and what wasn't tested. Leave the test store running for review unless told otherwise. `stop` preserves volumes.

## Verification checklist

Pick the checks that fit the change.

- The four products are present by their DEMO references, with correct names, active status, stock, prices and discounts, categories and brand.
- Re-importing both sources leaves the count at four, with product IDs unchanged.
- Compare run logs and database or store values, not just the HTTP response.
- UI: save, reopen, mapping dropdowns, background-run progress.
- Variants, images, cron: exercise each feature that changed.

<!-- Paste the remaining original content of your runbook here, if any. -->