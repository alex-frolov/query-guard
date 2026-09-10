#!/bin/sh
# Captures reference plans from the stand into the fixtures.
#
# The expected `Plan` flags in the tests were written BY HAND from reading the EXPLAIN
# output, never recorded from our own parser: otherwise a misunderstanding of a plan
# would land in the code and in its test at the same time.
set -eu

cd "$(dirname "$0")/../.."
OUT=tests/Fixture/Explain
mkdir -p "$OUT/mysql" "$OUT/pgsql"

mysql_explain() {
    docker compose -f tools/stand/docker-compose.yml exec -T mysql \
        mysql -uroot -pstand stand -N --raw -e "EXPLAIN FORMAT=JSON $1" 2>/dev/null
}

pg_explain() {
    docker compose -f tools/stand/docker-compose.yml exec -T postgres \
        psql -U stand -d stand -tAc "EXPLAIN (FORMAT JSON) $1"
}

capture() {
    name="$1"
    sql="$2"
    echo "  $name"
    mysql_explain "$sql" > "$OUT/mysql/$name.json"
    pg_explain "$sql" > "$OUT/pgsql/$name.json"
}

echo "capturing plans:"
capture no-index        "SELECT id, name FROM big WHERE plain_col = 42"
capture indexed         "SELECT id, name FROM big WHERE indexed_col = 42"
capture filesort        "SELECT id, name FROM big WHERE indexed_col = 42 ORDER BY plain_col"
capture join-no-index   "SELECT b.id, c.note FROM big b JOIN child c ON c.big_id = b.id WHERE b.indexed_col = 42"
capture group-by        "SELECT plain_col, COUNT(*) AS n FROM big GROUP BY plain_col"
capture full-index-scan "SELECT indexed_col FROM big ORDER BY indexed_col"
echo "done: $OUT"
