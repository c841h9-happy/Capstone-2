-- Adds 'bud' to product_components.shape: a rose drawn part-opened, the sixth flower head the
-- 3D builder knows how to draw.
--
--     mysql -u root capstone < sql\component-shape-bud-upgrade.sql
--
-- Re-runnable, like every migration here. MODIFY COLUMN is idempotent by nature -- setting a
-- column to the definition it already has is a no-op that exits 0 -- so there is no
-- IF NOT EXISTS to write and a second immediate run changes nothing.
--
-- WHY 'bud' IS APPENDED AND NOT INSERTED IN ORDER. MySQL stores an ENUM value as the ordinal of
-- its position in the list, not as the text. Appending is therefore free: every existing row
-- keeps the index it already had. Slipping 'bud' in next to 'rose' because the two read as
-- related would renumber 'tulip', 'sunflower', 'daisy' and 'sprig' by one each, and every row
-- holding one of them would quietly come back as the shape before it. The list is a storage
-- format, not a running order; php/config/components.php:COMPONENT_SHAPE_LABELS is where the
-- reading order lives, and the admin form renders from that.
--
-- NOTHING IS BACKFILLED, which is the difference between this migration and
-- sql/components-shape-upgrade.sql. That one had to give 17 existing parts a shape, because the
-- column was new and every row held the default. This one only widens the vocabulary: no
-- existing part becomes a bud, because which parts are buds is the shop's decision and not this
-- file's. An admin picks it per part on php/admin/component-edit.php.
--
-- Mirrored into sql/schema.sql so a fresh install matches a migrated one, which is the rule
-- every migration in this folder follows.

ALTER TABLE product_components
    MODIFY COLUMN shape
        ENUM('none','rose','tulip','sunflower','daisy','sprig','bud')
        NOT NULL DEFAULT 'none';

-- Proof, for the person running this by hand: the new vocabulary, and the count of rows holding
-- each shape so it is obvious at a glance that nothing moved.
SELECT COLUMN_TYPE AS shape_enum_now
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_components'
  AND COLUMN_NAME = 'shape';

SELECT shape, COUNT(*) AS parts
FROM product_components
GROUP BY shape
ORDER BY shape;
