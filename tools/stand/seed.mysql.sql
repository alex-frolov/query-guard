-- Tier 2 stand: the plans are known in advance.
--   big.indexed_col   — indexed
--   big.plain_col     — no index at all (a fact about the schema, not about the data)
--   child.big_id      — a foreign key with no index

SET SESSION cte_max_recursion_depth = 200000;

CREATE TABLE big (
    id          INT PRIMARY KEY,
    indexed_col INT NOT NULL,
    plain_col   INT NOT NULL,
    name        VARCHAR(50) NOT NULL
);
CREATE INDEX idx_big_indexed ON big (indexed_col);

INSERT INTO big (id, indexed_col, plain_col, name)
WITH RECURSIVE seq(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 100000
)
SELECT n, n % 1000, n % 997, CONCAT('row ', n) FROM seq;

CREATE TABLE child (
    id     INT PRIMARY KEY,
    big_id INT NOT NULL,
    note   VARCHAR(50) NOT NULL
);

INSERT INTO child (id, big_id, note)
WITH RECURSIVE seq(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 50000
)
SELECT n, n % 100000, CONCAT('note ', n) FROM seq;

ANALYZE TABLE big, child;
