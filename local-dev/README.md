# Local PrestaShop test store

A disposable PrestaShop **9.1.5 / PHP 8.3** and MySQL 8 store for this standalone
module. Docker bind-mounts the repository as `modules/productimport/`. The
shop is exposed only on localhost; the database has no published port.

## Requirements and first run

Use Docker Desktop, Docker Engine with Compose v2, or an existing Colima setup
(`colima start` on macOS). Images and Composer packages need network access.
No host PHP or Composer installation is required. From the repository root:

```sh
./local-dev/dev.sh test
./local-dev/dev.sh up
./local-dev/dev.sh logs
```

The script copies `.env.example` to ignored `.env` on first use. It contains
local development defaults only; change them before first boot if desired.
Environment changes do not reset accounts in an already-initialized database.
`up` builds store tools, installs production Composer dependencies in the
`module_vendor` volume, then starts the stack. First boot auto-installs the
store and may take several minutes. Wait until installation completes in
logs and the storefront responds before installing the module:

```sh
./local-dev/dev.sh install
```

- Storefront: http://localhost:8080/
- Back office: http://localhost:8080/admin-dev/
- Login: `ADMIN_MAIL` / `ADMIN_PASSWD` from `.env`.
- Module screens: Catalog → Product Import / Product Import runs.

`HOST_PORT` in `.env` changes the port if 8080 is in use. After a schema
change do not reinstall: bump the module version, add
`upgrade/upgrade-X.Y.Z.php`, and run `docker compose exec --user www-data prestashop php bin/console prestashop:module upgrade productimport`.

## Unit tests and formatting

`dev.sh test` runs the locked Composer dev dependencies, PHPUnit and
PHP-CS-Fixer in **PHP 8.4**, because the locked PHPUnit 13 cannot run under
PHP 8.3. A separate `test_vendor` volume keeps dev dependencies away from the
store's production `module_vendor`. Test failures propagate as a nonzero exit.

To apply formatting intentionally:

```sh
cd local-dev
docker compose run --rm --entrypoint sh php-tests -ec 'composer install --no-interaction && vendor/bin/php-cs-fixer fix'
```

PHP 8.4 unit tests alone do not establish PHP 8.3 store compatibility. The
store tools install production dependencies on the actual PHP 8.3 image.
For changed PHP files, also run `php -l` in the running store container.

## Store integration tests

Follow [the JSON and XML examples](../examples/README.md) to configure two
sources from read-only files already mounted into the store at the default
import folder, `/var/www/html/var/productimport/feeds/`. Use `products.json`
and `products.xml` as the Source. The feed loads automatically on save, the
identifier is suggested, and the Categories and Brands tabs fill by
themselves. Import each source, verify the four DEMO products, then import again and
verify that product IDs/counts stay stable. Check stock, active status, brand,
category and discounted price. Record each result.

These are manual integration checks; `dev.sh test` runs unit tests only.
No example image URLs are included, so these minimal feeds need no external
fetches. Images, combinations, cron and background-process spawning require
additional checks when relevant to a change.

## Editing and troubleshooting

Changes in `src/`, `controllers/`, `views/` and the module entrypoint appear
through the bind mount. If opcache masks a change:

```sh
cd local-dev
docker compose restart prestashop
docker compose logs --tail=100 prestashop
docker compose exec --user www-data prestashop php bin/console prestashop:module list
```

After changing composer.lock, rerun `dev.sh up` to refresh the production
vendor volume. If installation fails, inspect logs and container health; do
not erase volumes to hide failures. A Composer platform mismatch is a failure
to fix, not a reason to disable platform checks.

## Build the module zip

```sh
./local-dev/dev.sh package            # packages HEAD (PACKAGE_REF=v1.2.3 for another ref)
```

Writes `dist/productimport-<version>.zip`. The host runs `git archive` (the
tools image has no git, and `.gitattributes` export-ignore drops dev-only
paths); the PHP 8.3 composer container then runs `bin/package.php --archive=...`
to install production vendor/ and zip. Uncommitted changes are not included.

## Stop or erase

```sh
./local-dev/dev.sh stop
```

This preserves the database and store. Only when explicitly authorized to
erase the local store, run `cd local-dev && docker compose down -v`. That
deletes the database, store files and both dependency volumes.

## Packaging

The PrestaShop technical name remains **productimport**. Deploy module code
and production `vendor/` under `modules/productimport/`. Keep `local-dev/`,
`examples/`, `tests/` and dev dependencies out of a release archive. Never
package the local `.env`. The repository name is not the module technical name.
