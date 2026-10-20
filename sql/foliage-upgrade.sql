-- Adds 'foliage' as a new component kind, and a foliage_id column to saved_designs.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\foliage-upgrade.sql
--
-- Needs sql\components-upgrade.sql, sql\cart-upgrade.sql, sql\orders-upgrade.sql
-- and sql\designs-upgrade.sql to have run first.
--
-- The ENUM extension is appended last so MySQL does not have to re-point any existing
-- row (the engine stores the ordinal, not the string). Any component whose kind column
-- was written before this migration keeps its value; only newly-created or edited rows
-- may use 'foliage'.
--
-- The saved_designs column is guarded by IF NOT EXISTS so the script is safe to run
-- twice: a second run skips the ADD COLUMN and stops before the FK re-check.

USE capstone;

-- Extend the kind ENUM. Order matters: append only. Inserting 'foliage' before
-- 'accent' would silently re-point every row with ordinal >= 4, which is wrong and
-- silent.
ALTER TABLE product_components
    MODIFY kind ENUM('bloom','wire','wrap','accent','foliage') NOT NULL DEFAULT 'bloom';

-- Foliage is part of the frozen basket and order receipts too. Append it so every
-- existing ENUM value keeps its ordinal and existing snapshots remain unchanged.
ALTER TABLE cart_item_parts
    MODIFY kind ENUM('bloom','wire','wrap','accent','foliage') NOT NULL;

ALTER TABLE order_item_parts
    MODIFY kind ENUM('bloom','wire','wrap','accent','foliage') NOT NULL;

-- A foliage column on saved_designs, matching the wire_id / wrap_id / accent_id
-- pattern exactly. NULL-able so a saved design without foliage is legal, and ON
-- DELETE SET NULL so deleting a withdrawn foliage component does not delete the
-- customer's design with it.
ALTER TABLE saved_designs
    ADD COLUMN IF NOT EXISTS foliage_id INT UNSIGNED NULL AFTER accent_id;

-- MariaDB 10.4 does not support ADD CONSTRAINT IF NOT EXISTS. Check the catalog and
-- prepare either the ALTER or a harmless SELECT, so this migration is safe to rerun.
SET @foliage_fk_exists = (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'saved_designs'
       AND CONSTRAINT_NAME = 'fk_saved_designs_foliage'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @foliage_fk_sql = IF(
    @foliage_fk_exists = 0,
    'ALTER TABLE saved_designs ADD CONSTRAINT fk_saved_designs_foliage FOREIGN KEY (foliage_id) REFERENCES product_components (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE foliage_fk_statement FROM @foliage_fk_sql;
EXECUTE foliage_fk_statement;
DEALLOCATE PREPARE foliage_fk_statement;

-- Seed: two ready-to-use foliage options so the picker is not empty after the
-- migration. Remove or replace these with real parts once the shop has its own images.
-- sort_order 10 and 20 leave room for more before and after.
INSERT INTO product_components
    (name, kind, description, price, colour, colour_hex, shape, image_path, sort_order, is_active)
SELECT seed.name, 'foliage', seed.description, seed.price, seed.colour, seed.colour_hex,
       'none', '', seed.sort_order, 1
FROM (
    SELECT CAST('Baby Eucalyptus' AS CHAR(120)) AS name,
           CAST('Soft silver-green sprigs of eucalyptus, one bunch per bouquet.' AS CHAR(500)) AS description,
           25.00 AS price,
           'sage green' AS colour,
           '#8faf82' AS colour_hex,
           10 AS sort_order
    UNION ALL SELECT 'Dark Fern Fronds',
           'Deep green fern fronds that fan out behind the flowers.',
           20.00,
           'dark green',
           '#3d6b3b',
           20
) AS seed
WHERE NOT EXISTS (
    SELECT 1
      FROM product_components c
     WHERE c.name = seed.name
       AND c.kind = 'foliage'
);

