#!/bin/sh
set -eu
cd "$(dirname "$0")"
if [ ! -f .env ]; then cp .env.example .env; fi
case "${1:-help}" in
  up)
    docker compose config --quiet
    docker compose run --rm --build composer install --no-dev --optimize-autoloader --no-interaction
    docker compose up -d
    ;;
  test) docker compose run --rm --build php-tests ;;
  install) docker compose exec --user www-data prestashop php bin/console prestashop:module install productimport ;;
  package)
    # git archive runs on the host (the tools image has no git); the container
    # installs production vendor/ with PHP 8.3 and zips into ../dist.
    root="$(cd .. && pwd)"
    mkdir -p "$root/dist"
    if [ -n "$(git -C "$root" status --porcelain)" ]; then
      echo "WARNING: working tree is dirty; uncommitted changes are NOT packaged." >&2
    fi
    git -C "$root" archive --format=tar --prefix=productimport/ -o "$root/dist/.export.tar" "${PACKAGE_REF:-HEAD}"
    docker compose run --rm --build --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
      --entrypoint php composer bin/package.php --archive=dist/.export.tar --out=dist
    rm -f "$root/dist/.export.tar"
    ;;
  logs) docker compose logs -f prestashop ;;
  stop) docker compose stop ;;
  *) echo "Usage: $0 {up|test|install|package|logs|stop}"; exit 2 ;;
esac
