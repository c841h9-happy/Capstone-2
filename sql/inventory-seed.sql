-- What the seeded products are made of, and how much of each part the shop starts with.
--
--     mysql -u root capstone < sql\inventory-seed.sql
--
-- Needs sql\inventory-upgrade.sql, sql\products-seed.sql and sql\components-seed.sql to
-- have run first -- everything below joins to those rows by name and silently skips any
-- that are missing, so a half-seeded database gets a half-filled recipe book rather than
-- an error.
--
-- Safe to run twice, in two different ways, because the two halves need different guards:
--
--   * The recipes use the LEFT JOIN ... WHERE IS NULL pattern the other two seeds use, so
--     a second run inserts only rows that are not there yet.
--   * The stock-take only touches parts Inventory has never touched -- no movement ever
--     recorded, count and threshold still at their defaults. A second run therefore cannot
--     undo a delivery, a stock-take or a waste write-off that happened in between. This is
--     the one seed that could destroy real data if it were naive about it.
--
-- Nothing here is required for the module to work. A shop that has its own parts and its
-- own products fills both in from the admin screens; this is so the screens have something
-- to show on a fresh install.

USE capstone;

-- ---------------------------------------------------------------------------------------
-- 1. Recipes: what each catalogue product is built from.
-- ---------------------------------------------------------------------------------------
--
-- Every one of these follows the catalogue copy in sql\products-seed.sql rather than
-- inventing a build. "Six deep red roses with wired leaves and a satin ribbon" gets six red
-- rose heads, six stems and a satin bow, and the bloom count matches products.stem_count on
-- all six bouquets -- which is exactly what php/admin/product-parts.php checks for and warns
-- about, so the seeded data must not be the first thing to trip it.
--
-- Single Calla Lily is deliberately left with no recipe at all. There is no calla lily in
-- sql\components-seed.sql, and inventing one here would put a part in the customizer's
-- picker that the shop never said it makes. An empty recipe is a real state the module has
-- to handle -- buildableCount() answers null for "unknown", never 0 -- and this is the row
-- that proves it does.
INSERT INTO product_parts (product_id, component_id, quantity, sort_order)
SELECT p.id,
       c.id,
       r.quantity,
       -- Assembly order: blooms, then stems, then the wrap, then the tie.
       --
       -- A part's own sort_order only orders it within its kind -- every kind starts again
       -- at 10 -- so a hundred-wide band per kind turns four independent picker orders into
       -- one sequence that reads the way somebody actually builds a bouquet.
       CASE c.kind WHEN 'bloom' THEN 0
                   WHEN 'wire'  THEN 100
                   WHEN 'wrap'  THEN 200
                   ELSE 300 END + c.sort_order
FROM (
    -- The CAST pins both name columns' widths, for the reason sql\components-seed.sql
    -- gives: in a UNION the result type comes from the branches, so without it every name
    -- below would be clipped to the length of the first one and join to nothing.
    SELECT CAST('Sunflower Trio' AS CHAR(120)) AS product,
           'bouquet' AS kind,
           CAST('Sunflower Head' AS CHAR(120)) AS component,
           3 AS quantity
    UNION ALL SELECT 'Sunflower Trio', 'bouquet', 'Green Chenille Stem', 3
    UNION ALL SELECT 'Sunflower Trio', 'bouquet', 'Kraft Paper Sleeve',  1
    UNION ALL SELECT 'Sunflower Trio', 'bouquet', 'Jute Twine Tie',      1

    -- "Twelve roses in blush, cream and dusty pink" -- so eight blush and four ivory, not
    -- twelve of one. Twelve blooms either way, which is what stem_count says.
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet', 'Blush Rose Head',     8
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet', 'Ivory Rose Head',     4
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet', 'Green Chenille Stem', 12
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet', 'Kraft Paper Sleeve',  1
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet', 'Jute Twine Tie',      1

    UNION ALL SELECT 'Tulip Spring Bunch', 'bouquet', 'Tulip Head',          7
    UNION ALL SELECT 'Tulip Spring Bunch', 'bouquet', 'Green Chenille Stem', 7
    UNION ALL SELECT 'Tulip Spring Bunch', 'bouquet', 'Sage Tissue Wrap',    1
    UNION ALL SELECT 'Tulip Spring Bunch', 'bouquet', 'Satin Ribbon Bow',    1

    UNION ALL SELECT 'Daisy Chain Posy', 'bouquet', 'Daisy Head',          9
    UNION ALL SELECT 'Daisy Chain Posy', 'bouquet', 'Green Chenille Stem', 9
    UNION ALL SELECT 'Daisy Chain Posy', 'bouquet', 'Sage Tissue Wrap',    1
    UNION ALL SELECT 'Daisy Chain Posy', 'bouquet', 'Jute Twine Tie',      1

    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 'Red Rose Head',       6
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 'Green Chenille Stem', 6
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 'Ivory Linen Wrap',    1
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 'Satin Ribbon Bow',    1

    -- No wrap on this one. "Bound with twine ... meant to be stood in a jar" -- a bouquet
    -- with three kinds instead of four, so the recipe screen is not only ever shown the
    -- same shape.
    UNION ALL SELECT 'Lavender Field Bundle', 'bouquet', 'Lavender Sprig',      10
    UNION ALL SELECT 'Lavender Field Bundle', 'bouquet', 'Green Chenille Stem', 10
    UNION ALL SELECT 'Lavender Field Bundle', 'bouquet', 'Jute Twine Tie',      1

    -- The singles: one head, one stem, no wrap and no tie. Their colour column in
    -- sql\products-seed.sql picks which head -- 'Single Fuzzy Rose' is Red there, so it is
    -- the red head here and not the blush one.
    UNION ALL SELECT 'Single Fuzzy Rose', 'flower', 'Red Rose Head',       1
    UNION ALL SELECT 'Single Fuzzy Rose', 'flower', 'Green Chenille Stem', 1
    UNION ALL SELECT 'Single Tulip', 'flower', 'Tulip Head',          1
    UNION ALL SELECT 'Single Tulip', 'flower', 'Green Chenille Stem', 1
    UNION ALL SELECT 'Single Sunflower', 'flower', 'Sunflower Head',      1
    UNION ALL SELECT 'Single Sunflower', 'flower', 'Green Chenille Stem', 1
    UNION ALL SELECT 'Single Daisy', 'flower', 'Daisy Head',          1
    UNION ALL SELECT 'Single Daisy', 'flower', 'Green Chenille Stem', 1
    UNION ALL SELECT 'Single Lavender Sprig', 'flower', 'Lavender Sprig',      1
    UNION ALL SELECT 'Single Lavender Sprig', 'flower', 'Green Chenille Stem', 1
) AS r
-- Name and kind together, the same key sql\products-seed.sql uses: "Single Fuzzy Rose" the
-- flower and a rose bouquet are different products that may share a word.
JOIN products p
  ON p.name = r.product
 AND p.kind = r.kind
-- Name alone for a component, again matching its own seed.
JOIN product_components c
  ON c.name = r.component
LEFT JOIN product_parts existing
       ON existing.product_id = p.id
      AND existing.component_id = c.id
WHERE existing.id IS NULL;

-- ---------------------------------------------------------------------------------------
-- 2. The opening stock-take.
-- ---------------------------------------------------------------------------------------
--
-- Two things have to happen together: product_components.stock_on_hand gets a count, and
-- stock_moves gets an 'opening' row saying where that count came from. If they disagree the
-- module is broken on its first day, and stockAuditDiscrepancies() will say so on the
-- ledger screen.
--
-- So the numbers are written down exactly once, in the temporary table below, and both
-- statements read from it. Two hand-kept lists that happen to match today is precisely the
-- arrangement that stops matching the first time somebody edits one of them.
DROP TEMPORARY TABLE IF EXISTS seed_opening_stock;

CREATE TEMPORARY TABLE seed_opening_stock (
    component_id   INT UNSIGNED      NOT NULL,
    component_name VARCHAR(120)      NOT NULL,
    opening        INT               NOT NULL,
    low_at         SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (component_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The list, and the guard that makes re-running it safe.
--
-- A part is opened only if Inventory has never touched it: no stock_moves row naming it,
-- and both new columns still at the defaults the migration gave them. That is a much
-- tighter test than "is the count zero". A part that was received and then entirely used up
-- is at zero and must not be re-opened at 96; a part whose threshold an admin set by hand
-- must not have that quietly reverted. Both are excluded by the three conditions below.
--
-- The quantities are a small shop's drawer rather than a warehouse, and they are chosen so
-- the screens have something to say on a fresh install: Lavender Sprig is below its
-- threshold, which puts Lavender Field Bundle at zero buildable, and Pearl Bead Pins is
-- flat out. Everything else can be made.
INSERT INTO seed_opening_stock (component_id, component_name, opening, low_at)
SELECT c.id, c.name, s.opening, s.low_at
FROM (
    SELECT CAST('Blush Rose Head' AS CHAR(120)) AS name, 96 AS opening, 24 AS low_at
    UNION ALL SELECT 'Red Rose Head',   42, 24
    UNION ALL SELECT 'Ivory Rose Head', 30, 24
    UNION ALL SELECT 'Tulip Head',      28, 14
    UNION ALL SELECT 'Sunflower Head',  18, 12
    UNION ALL SELECT 'Daisy Head',      60, 24
    -- Under its threshold on purpose: the low-stock filter and the shortfall warning both
    -- need something to point at, and a demo where nothing is ever low teaches nobody what
    -- the screen is for.
    UNION ALL SELECT 'Lavender Sprig',   8, 20

    -- Stems go into almost everything, so the shop keeps far more of them.
    UNION ALL SELECT 'Green Chenille Stem', 240, 60
    UNION ALL SELECT 'Brown Chenille Stem',  60, 30
    UNION ALL SELECT 'White Chenille Stem',  45, 30

    UNION ALL SELECT 'Kraft Paper Sleeve', 35, 10
    UNION ALL SELECT 'Ivory Linen Wrap',   12,  5
    UNION ALL SELECT 'Sage Tissue Wrap',   24, 10

    UNION ALL SELECT 'Jute Twine Tie',  50, 20
    UNION ALL SELECT 'Satin Ribbon Bow', 16, 10
    -- Zero, and no ledger row for it: see the WHERE on the INSERT below. Chosen because no
    -- recipe calls for it, so being out of stock shows up on the stock screen without
    -- making a second bouquet unbuildable.
    UNION ALL SELECT 'Pearl Bead Pins',   0,  6
    UNION ALL SELECT 'Kraft Gift Tag',   40, 15
) AS s
JOIN product_components c
  ON c.name = s.name
 AND c.stock_on_hand = 0
 AND c.low_stock_threshold = 0
LEFT JOIN stock_moves m
       ON m.component_id = c.id
WHERE m.id IS NULL;

-- The balance.
UPDATE product_components c
  JOIN seed_opening_stock s ON s.component_id = c.id
   SET c.stock_on_hand       = s.opening,
       c.low_stock_threshold = s.low_at;

-- And the row that explains it.
--
-- balance_after equals delta because this is the first movement of the part, which is the
-- whole meaning of 'opening': a count that arrives without one is a number nobody can
-- account for. user_id stays NULL -- no admin typed this in, a seed file did.
--
-- WHERE opening <> 0 is not an optimisation. chk_stock_moves_delta refuses a movement of
-- nothing, and rightly: a part the shop starts with none of has not moved. It does mean the
-- audit has to read a missing SUM as zero rather than as a mismatch, which is why
-- stockAuditDiscrepancies() wraps it in COALESCE.
INSERT INTO stock_moves (component_id, component_name, reason, delta, balance_after, note)
SELECT s.component_id,
       s.component_name,
       'opening',
       s.opening,
       s.opening,
       'Opening stock-take, from the seed file'
FROM seed_opening_stock s
WHERE s.opening <> 0;

DROP TEMPORARY TABLE IF EXISTS seed_opening_stock;
