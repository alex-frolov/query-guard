-- The same stand for PostgreSQL: an identical schema, so that tier 2 rules can be
-- compared between platforms on equivalent data.

CREATE TABLE big (
    id          INT PRIMARY KEY,
    indexed_col INT NOT NULL,
    plain_col   INT NOT NULL,
    name        VARCHAR(50) NOT NULL
);
CREATE INDEX idx_big_indexed ON big (indexed_col);

INSERT INTO big (id, indexed_col, plain_col, name)
SELECT g, g % 1000, g % 997, 'row ' || g FROM generate_series(1, 100000) g;

CREATE TABLE child (
    id     INT PRIMARY KEY,
    big_id INT NOT NULL,
    note   VARCHAR(50) NOT NULL
);

INSERT INTO child (id, big_id, note)
SELECT g, g % 100000, 'note ' || g FROM generate_series(1, 50000) g;

ANALYZE big;
ANALYZE child;
