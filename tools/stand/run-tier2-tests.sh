#!/bin/sh
# Runs the tier 2 tests against a live stand.
#
# There is no single local image carrying both pdo_mysql and pdo_pgsql, so the MySQL part
# runs in one image and the PostgreSQL part in another. Override them with QG_MYSQL_IMAGE
# and QG_PG_IMAGE.
#
# Caveat: the image has to satisfy the PHP requirement of the installed PHPUnit. If the
# image ships an older PHP, install a matching PHPUnit first:
#   composer update --with phpunit/phpunit:^11.0
set -eu
cd "$(dirname "$0")/../.."

NET=query-guard-stand_default
MYSQL_IMAGE="${QG_MYSQL_IMAGE:-kimai-dev:local}"
PG_IMAGE="${QG_PG_IMAGE:-app-app:latest}"

run() {
    image="$1"; shift
    docker run --rm --entrypoint php --network "$NET" -v "$PWD":/app -w /app "$@" "$image" \
        vendor/bin/phpunit --testsuite integration --filter Tier2StandTest
}

echo "=== MySQL ==="
run "$MYSQL_IMAGE" \
    -e QG_STAND_DRIVER=pdo_mysql -e QG_STAND_HOST=mysql \
    -e QG_STAND_USER=root -e QG_STAND_PASSWORD=stand -e QG_STAND_DB=stand

echo "=== PostgreSQL ==="
run "$PG_IMAGE" \
    -e QG_STAND_DRIVER=pdo_pgsql -e QG_STAND_HOST=postgres \
    -e QG_STAND_USER=stand -e QG_STAND_PASSWORD=stand -e QG_STAND_DB=stand
