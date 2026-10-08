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
  logs) docker compose logs -f prestashop ;;
  stop) docker compose stop ;;
  *) echo "Usage: $0 {up|test|install|logs|stop}"; exit 2 ;;
esac
