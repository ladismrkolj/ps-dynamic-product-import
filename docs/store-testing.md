# Store integration testing

Read `local-dev/README.md` and `examples/README.md` before starting. Use the isolated Docker store for all integration tests. Never point test imports at a production store.

## Prerequisites

1. Check Docker: `docker info`.
2. On macOS, if Docker isn't running, start an existing Docker Desktop or Colima environment.
3. If Docker is unavailable, stop and report it as a prerequisite. Don't claim the store was tested.

## Steps

1. Run `./local-dev/dev.sh test`. This installs locked dev dependencies in an isolated volume, runs PHPUnit and checks formatting. Fix failures and rerun.
2. Run `./local-dev/dev.sh up`. This prepares production dependencies using the store's PHP version and starts the store and database. Follow the install logs.
3. When the store is ready, run `./local-dev/dev.sh install` on a fresh store. There are no upgrade scripts: after a schema change on an existing store, uninstall and reinstall the module. This deletes sources, mappings and product links, so first delete the imported DEMO products (otherwise the next import duplicates them). Optionally export the sources as YAML (`.productimport.yaml`) before uninstalling and import them afterwards.
4. Open the local back office using the credentials in `local-dev/.env`. Create both example sources as described in `examples/README.md`. The examples are mounted read-only at the default import folder, so use `products.json` / `products.xml` as the Source. Saving loads the feed automatically; confirm the identifier suggestion (coverage, uniqueness) and that the Categories and Brands tabs fill by themselves. Save mappings, then import.
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
- YAML config: export a source (`.productimport.yaml`), import it as a new source, then import it again choosing overwrite and confirm the existing source is updated, not duplicated.
- Mapping tabs: in Categories and Brands, add an entry, delete an entry, and confirm the seen status reflects whether the value appears in the loaded feed.
- Import folder: a location outside the import folder is rejected; a relative path resolves inside it.
- Variants, images, cron: exercise each feature that changed.

<!-- Paste the remaining original content of your runbook here, if any. -->