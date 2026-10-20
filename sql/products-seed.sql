-- Sample products, so the catalogue has something in it before the Products
-- module adds admin CRUD.
--
--     mysql -u root capstone < sql\products-seed.sql
--
-- Needs sql\products-upgrade.sql to have run first.
--
-- Safe to run twice. The LEFT JOIN at the bottom drops any row whose name and
-- kind are already in the table, so a second run inserts nothing rather than
-- doubling the catalogue. Name alone would be the wrong key -- "Single Fuzzy Rose"
-- the flower and a rose bouquet are different products that may share a word.
--
-- Prices are Philippine pesos. Nothing outside this file assumes a currency; the
-- symbol lives in CURRENCY_SYMBOL in php/config/products.php.

USE capstone;

INSERT INTO products (name, kind, description, price, colour, stem_count)
SELECT s.name, s.kind, s.description, s.price, s.colour, s.stem_count
FROM (
    -- The CAST is not decoration. In a UNION the result column's type comes from
    -- the branches, and pinning the width here means a longer description further
    -- down cannot be silently clipped to the length of the first one.
    SELECT 'Sunflower Trio' AS name,
           'bouquet' AS kind,
           CAST('Three oversized sunflowers twisted from golden chenille wire, with wrapped kraft paper and a jute tie. Bright enough to carry a whole table on its own.' AS CHAR(500)) AS description,
           380.00 AS price,
           'Yellow' AS colour,
           3 AS stem_count
    UNION ALL SELECT 'Pastel Rose Dozen', 'bouquet',
        'Twelve roses in blush, cream and dusty pink, each petal rolled by hand from soft fuzzy wire. Our most-asked-for arrangement, and the one that takes longest to make.',
        1150.00, 'Pink', 12
    UNION ALL SELECT 'Tulip Spring Bunch', 'bouquet',
        'Seven tulips in mixed spring colours, still closed the way real tulips are on the first morning. Cheerful without being loud.',
        620.00, 'Mixed', 7
    UNION ALL SELECT 'Daisy Chain Posy', 'bouquet',
        'Nine small white daisies with yellow centres, gathered short and round so it stands up on a desk without a vase.',
        540.00, 'White', 9
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet',
        'Six deep red roses with wired leaves and a satin ribbon. The classic, and it never goes back out of season because none of it was ever in season.',
        690.00, 'Red', 6
    UNION ALL SELECT 'Lavender Field Bundle', 'bouquet',
        'Ten slender lavender stalks bound with twine. Loose, tall and a little wild -- meant to be stood in a jar and left alone.',
        750.00, 'Purple', 10
    UNION ALL SELECT 'Single Fuzzy Rose', 'flower',
        'One hand-rolled rose on a wired stem, with two leaves. Buy a few and arrange them yourself, or add one to a bouquet you are building.',
        65.00, 'Red', NULL
    UNION ALL SELECT 'Single Tulip', 'flower',
        'One tulip with a gently cupped head and a bendable stem, so it can lean whichever way the arrangement needs.',
        55.00, 'Yellow', NULL
    UNION ALL SELECT 'Single Sunflower', 'flower',
        'One large sunflower with a dark centre and two rows of petals. The biggest single flower we make, and the sturdiest.',
        95.00, 'Yellow', NULL
    UNION ALL SELECT 'Single Daisy', 'flower',
        'One small white daisy. The cheapest thing in the shop and the most popular filler for a custom bouquet.',
        45.00, 'White', NULL
    UNION ALL SELECT 'Single Calla Lily', 'flower',
        'One calla lily, a single curled petal around a yellow centre. Deceptively difficult to shape, which is why it costs what it does.',
        110.00, 'White', NULL
    UNION ALL SELECT 'Single Lavender Sprig', 'flower',
        'One lavender sprig with clustered buds along the top third of the stem. Sold singly because most people want an armful.',
        50.00, 'Purple', NULL
) AS s
LEFT JOIN products p
       ON p.name = s.name
      AND p.kind = s.kind
WHERE p.id IS NULL;
