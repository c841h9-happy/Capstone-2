-- Sample components, so the customizer's parts list is not empty before the 3D
-- builder is written.
--
--     mysql -u root capstone < sql\components-seed.sql
--
-- Needs sql\components-upgrade.sql to have run first.
--
-- Safe to run twice. The LEFT JOIN at the bottom drops any row whose name is already
-- in the table, so a second run inserts nothing rather than doubling the list. Name
-- alone is the right key here, unlike products-seed.sql: two components with the same
-- name would be the same part entered twice whatever kind they claim to be.
--
-- Prices are Philippine pesos and are per part, which is why they are small next to
-- sql\products-seed.sql -- a finished bouquet is a dozen of these plus the making.
--
-- sort_order goes up in tens inside each kind, so a part can be slipped between two
-- others later without renumbering the rest.
--
-- The colour_hex values are what the 3D builder paints with, so they are the material
-- colour rather than a brand colour: chenille is matte and slightly dusty, and a pure
-- #ff0000 rose reads as plastic.

USE capstone;

INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order)
SELECT s.name, s.kind, s.description, s.price, s.colour, s.colour_hex, s.sort_order
FROM (
    -- The CAST pins the description column's width, for the reason given in
    -- sql\products-seed.sql: in a UNION the result type comes from the branches, and
    -- without it a longer description further down is clipped to the length of the
    -- first one.
    SELECT 'Blush Rose Head' AS name,
           'bloom' AS kind,
           CAST('A furled chenille rose about 6cm across. The one most custom bouquets are built around.' AS CHAR(500)) AS description,
           38.00 AS price,
           'Blush pink' AS colour,
           '#e8b4b8' AS colour_hex,
           10 AS sort_order
    UNION ALL SELECT 'Red Rose Head', 'bloom',
        'The same rose in deep red. Wound tighter, so it holds its shape in a full bouquet.',
        38.00, 'Red', '#b3242b', 20
    UNION ALL SELECT 'Ivory Rose Head', 'bloom',
        'A pale rose that reads as cream indoors. Pairs with almost anything, which makes it the safe pick.',
        38.00, 'Ivory', '#f0e6d8', 30
    UNION ALL SELECT 'Tulip Head', 'bloom',
        'A closed tulip cup, six petals folded rather than wound. Shorter than the roses, so it sits lower in an arrangement.',
        32.00, 'Yellow', '#f2c14e', 40
    UNION ALL SELECT 'Sunflower Head', 'bloom',
        'A wide sunflower with a dark felt centre and two rings of petals. The biggest bloom on the list and priced for the work in it.',
        55.00, 'Golden yellow', '#e5a323', 50
    UNION ALL SELECT 'Daisy Head', 'bloom',
        'A small five-petal daisy. The usual filler between larger blooms, and the cheapest way to make a bouquet look full.',
        18.00, 'White', '#f7f4ec', 60
    UNION ALL SELECT 'Lavender Sprig', 'bloom',
        'Clustered buds along the top third of a sprig. Counts as one bloom even though it looks like a dozen.',
        26.00, 'Purple', '#9b8ec4', 70

    UNION ALL SELECT 'Green Chenille Stem', 'wire',
        'The standard stem: 30cm of green chenille over florist wire. Bends once and stays where it is put.',
        10.00, 'Leaf green', '#4a7c3f', 10
    UNION ALL SELECT 'Brown Chenille Stem', 'wire',
        'A drier brown stem for autumn arrangements and for anything wrapped in kraft paper.',
        10.00, 'Brown', '#6b4f3a', 20
    UNION ALL SELECT 'White Chenille Stem', 'wire',
        'A white stem, for pale bouquets where a green one would show through the wrap.',
        12.00, 'White', '#f2f0eb', 30

    UNION ALL SELECT 'Kraft Paper Sleeve', 'wrap',
        'Plain brown kraft folded into a cone and creased at the base. The default wrap and the one in most of the shop photos.',
        45.00, 'Kraft brown', '#b98f5f', 10
    UNION ALL SELECT 'Ivory Linen Wrap', 'wrap',
        'A soft linen-look fabric wrap, tied rather than taped, so it can be taken off and kept.',
        85.00, 'Ivory', '#efe6d6', 20
    UNION ALL SELECT 'Sage Tissue Wrap', 'wrap',
        'Two sheets of sage tissue with the edges left loose. Lighter than the linen and about half the price.',
        40.00, 'Sage green', '#9caf9c', 30

    UNION ALL SELECT 'Jute Twine Tie', 'accent',
        'Three turns of jute twine and a knot. What holds the kraft sleeve closed on almost every bouquet.',
        15.00, 'Natural', '#a58a5c', 10
    UNION ALL SELECT 'Satin Ribbon Bow', 'accent',
        'A wide satin bow tied at the front. The one thing on this list that is purely decoration.',
        35.00, 'Sage green', '#8aa88a', 20
    UNION ALL SELECT 'Pearl Bead Pins', 'accent',
        'Five faux-pearl pins pushed in among the blooms. Catches the light where the chenille does not.',
        28.00, 'Pearl white', '#f4f1e8', 30
    UNION ALL SELECT 'Kraft Gift Tag', 'accent',
        'A small kraft tag on twine, left blank for a handwritten message.',
        12.00, 'Kraft brown', '#d9cdb8', 40
) AS s
LEFT JOIN product_components c
       ON c.name = s.name
WHERE c.id IS NULL;
