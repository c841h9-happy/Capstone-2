-- Adds product_components.shape: what the 3D builder actually draws for a flower head.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\components-shape-upgrade.sql
--
-- Safe to run twice. ADD COLUMN IF NOT EXISTS does nothing the second time, and every
-- backfill below is guarded on shape = 'none' so a re-run cannot overwrite a shape an
-- admin has since changed by hand.

USE capstone;

-- The one column the renderer cannot do without, and the one thing the seeded rows do
-- not already say.
--
-- Seventeen parts arrived with a name, a price and a colour_hex. That is enough to paint
-- a bloom but not to draw one: "Sunflower Head" and "Daisy Head" differ by 37 pesos and
-- two hex digits, so a builder reading only those columns has no way to make one wide
-- and flat and the other small and five-petalled. It would draw seventeen identical
-- blobs in seventeen colours, which reads as a broken renderer rather than a shop.
--
-- This is the same argument colour_hex was added for -- see the comment on that column
-- in sql\components-upgrade.sql. "Blush pink" is not a thing WebGL can paint with, and
-- "Sunflower Head" is not a thing it can draw. Both are facts about the part, both live
-- in the row, and neither belongs in a name-matching table inside a JavaScript file
-- where renaming a part in the admin would silently change its shape to a rose.
--
-- An ENUM rather than a VARCHAR because the renderer has a finite set of geometries and
-- a value outside it cannot be drawn at all. Extending the list later is an ALTER and a
-- new branch in the builder, which is the right amount of friction for adding a shape.
--
-- 'none' is the default, and it is what every wire, wrap and accent keeps. Each of those
-- three kinds has exactly one drawn form -- a stem is a cylinder, a wrap is a cone, an
-- accent is a band -- so naming a shape for them would be a column nobody reads. The
-- honest default for "this part has no chosen shape" is a value that says so; defaulting
-- a length of chenille wire to 'rose' would be wrong in the table forever and merely
-- happen not to be read.
--
-- AFTER colour_hex puts it beside the other column that exists for the builder, so the
-- two render-only fields sit together in DESCRIBE rather than one being at the end.
ALTER TABLE product_components
    ADD COLUMN IF NOT EXISTS shape
        ENUM('none','rose','tulip','sunflower','daisy','sprig')
        NOT NULL DEFAULT 'none'
        AFTER colour_hex;

-- Backfill the seeded blooms.
--
-- By name, because the name is what the seed guaranteed to be unique -- see the LEFT
-- JOIN in sql\components-seed.sql, which uses the name alone as the key for the same
-- reason. Not by id: these seventeen rows were inserted by a SELECT and an admin may
-- have added and deleted parts since, so ids are not predictable across installs.
--
-- AND shape = 'none' on every one. Without it a second run would drag a bloom an admin
-- had deliberately re-shaped back to whatever this file thinks it should be, which is
-- the sort of thing that gets noticed a fortnight later and blamed on the browser.
UPDATE product_components SET shape = 'rose'
 WHERE name IN ('Blush Rose Head', 'Red Rose Head', 'Ivory Rose Head')
   AND kind = 'bloom' AND shape = 'none';

UPDATE product_components SET shape = 'tulip'
 WHERE name = 'Tulip Head' AND kind = 'bloom' AND shape = 'none';

UPDATE product_components SET shape = 'sunflower'
 WHERE name = 'Sunflower Head' AND kind = 'bloom' AND shape = 'none';

UPDATE product_components SET shape = 'daisy'
 WHERE name = 'Daisy Head' AND kind = 'bloom' AND shape = 'none';

-- A sprig is a column of small buds rather than one head. It counts as one bloom in the
-- picker and in the price, which is what the seed's own description says out loud, so it
-- gets its own geometry rather than being drawn as a lumpy rose.
UPDATE product_components SET shape = 'sprig'
 WHERE name = 'Lavender Sprig' AND kind = 'bloom' AND shape = 'none';

-- Anything that is not a flower head is forced back to 'none'.
--
-- Nothing should have a shape here yet, so on a first run this changes nothing. It is
-- here for the second run: validateComponentInput() forces 'none' for the other three
-- kinds, but a row edited straight in the MySQL shell can hold whatever it likes, and a
-- wrap claiming to be a sunflower would be drawn by neither branch of the renderer.
UPDATE product_components SET shape = 'none'
 WHERE kind <> 'bloom' AND shape <> 'none';
