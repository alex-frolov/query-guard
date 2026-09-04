#!/bin/sh
# Runs the tier 2 tests against a live stand.
#
# The official php images ship neither pdo_mysql nor pdo_pgsql, so each half of the run
# needs an image that carries the driver it explains. By default this script builds two
# throwaway ones from the current php:8.4-cli; override either with QG_MYSQL_IMAGE /
# QG_PG_IMAGE to reuse an image your project already has.
#
# Caveat: the image has to satisfy the PHP requirement of the installed PHPUnit. If the
# image ships an older PHP, install a matching PHPUnit first:
#   composer update --with phpunit/phpunit:^11.0
set -eu
cd "$(dirname "$0")/../.."

NET=query-guard-stand_default
PHP_IMAGE="${QG_PHP_IMAGE:-php:8.4-cli}"
MYSQL_IMAGE="${QG_MYSQL_IMAGE:-}"
PG_IMAGE="${QG_PG_IMAGE:-}"

# Built here rather than documented as a prerequisite: a stand script whose defaults only
# exist on the author's machine is a script that works for exactly one person.
build() {
    tag="$1"; ext="$2"
    echo "=== building $tag ($ext) ==="
    docker build -q -t "$tag" - <<EOF
FROM $PHP_IMAGE
RUN docker-php-ext-install $ext
EOF
}

if [ -z "$MYSQL_IMAGE" ]; then
    MYSQL_IMAGE=query-guard-stand-mysql:local
    build "$MYSQL_IMAGE" pdo_mysql
fi

if [ -z "$PG_IMAGE" ]; then
    PG_IMAGE=query-guard-stand-pgsql:local
    # libpq-dev is not in the base image, and pdo_pgsql will not configure without it
    echo "=== building $PG_IMAGE (pdo_pgsql) ==="
    docker build -q -t "$PG_IMAGE" - <<EOF
FROM $PHP_IMAGE
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
EOF
fi

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
