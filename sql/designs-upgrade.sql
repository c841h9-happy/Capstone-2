-- Adds the two tables a saved bouquet lives in: the 3D customizer's second quarter.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\designs-upgrade.sql
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS does nothing the second time.

USE capstone;

-- A bouquet somebody built and kept.
--
-- Its own table for the reason product_components is its own table: a design is not a
-- product and not a component. It is a *recipe* -- a list of parts and how many of each --
-- and it belongs to one account.
--
-- What is deliberately NOT in here is money. A saved design re-prices from
-- product_components every time it is read, because a design is a recipe rather than a
-- contract: if the shop's satin ribbon goes up by five pesos, a design naming that ribbon
-- costs five pesos more, and showing the customer last month's figure would be a quote the
-- shop is not offering. The moment a design becomes a basket line, Commerce has to copy the
-- prices into that line and freeze them -- an order is the contract, and it must not move
-- underneath a customer who has paid. That is the one thing this table asks of the module
-- that comes after it.
--
-- Also not in here: a stem count. It is SUM(saved_design_blooms.quantity), and storing it
-- alongside the rows it is the sum of is two numbers free to disagree about the same
-- bouquet. See the second table.
CREATE TABLE IF NOT EXISTS saved_designs (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED      NOT NULL,
    -- What the customer calls it: "Mum's birthday".
    --
    -- 80 rather than products.name's 120: this is a label in the customer's own list, not
    -- a heading on a public page, and a shorter cap is a smaller thing to have to
    -- truncate in a card. Unique per account below, not globally -- two customers may
    -- both have a design called "Wedding".
    name       VARCHAR(80)       NOT NULL,
    -- The three parts a bouquet has exactly one of, however many flowers are in it.
    --
    -- NULL-able, and ON DELETE SET NULL below, which is the whole reason they are columns
    -- rather than NOT NULL: an admin who deletes a withdrawn ribbon should not thereby
    -- delete a customer's saved design, and should not have their delete refused by a
    -- foreign key they cannot see the other end of. The design survives with a hole in it,
    -- and the page falls back to what is on offer today -- which is what
    -- readDesignSelection() already does for a hand-edited URL.
    wire_id    INT UNSIGNED          NULL,
    wrap_id    INT UNSIGNED          NULL,
    accent_id  INT UNSIGNED          NULL,
    -- How the stems are arranged. Renderer-only: it changes no price.
    --
    -- Here rather than in the query string alone because it is part of what somebody
    -- designed. 'dome' is quarter one's phyllotaxis fan, so an existing design re-read
    -- through this column looks exactly as it did before the column existed.
    arrangement ENUM('dome','round','loose') NOT NULL DEFAULT 'dome',
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Touched by a rename or an overwrite, so the list can be "most recently changed".
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One name per account. A customer who saves "Wedding" twice means the second one to
    -- replace the first, and this is what lets the save path offer that instead of
    -- quietly building a list of four things all called the same word.
    UNIQUE KEY uq_saved_designs_user_name (user_id, name),
    -- The customer's own list, newest first.
    KEY idx_saved_designs_user (user_id, updated_at),
    -- The account going away takes its designs with it. Unlike the component links
    -- below, this one really is ownership: a design with no owner is unreachable by
    -- anybody and belongs to nobody.
    CONSTRAINT fk_saved_designs_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_designs_wire
        FOREIGN KEY (wire_id) REFERENCES product_components (id) ON DELETE SET NULL,
    CONSTRAINT fk_saved_designs_wrap
        FOREIGN KEY (wrap_id) REFERENCES product_components (id) ON DELETE SET NULL,
    CONSTRAINT fk_saved_designs_accent
        FOREIGN KEY (accent_id) REFERENCES product_components (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- The flower heads in a saved design: one row per kind of bloom, with how many of it.
--
-- A separate table rather than a bloom_id column, and this is the one piece of the schema
-- built for a quarter that has not happened yet. Quarter two's builder offers one kind of
-- bloom times a count, so a design saved by it has exactly one row in here. Quarter four
-- mixes kinds -- four roses and two sunflowers in one bunch -- and that is a second row,
-- not a migration.
--
-- The alternative was bloom_id + bloom_count on the table above, and then this table
-- anyway three weeks later, with a migration that has to invent sort_order values for
-- every design already saved. Building the four quarters in one pass is exactly the
-- circumstance in which to spend one extra table now.
--
-- quantity is the number of stems of this bloom, and the design's flower count is the sum
-- of them. That sum is not stored anywhere: a bouquet is its flowers, and a cached count
-- beside them is a number that can be wrong about the rows it was derived from.
CREATE TABLE IF NOT EXISTS saved_design_blooms (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    design_id    INT UNSIGNED      NOT NULL,
    -- SET NULL for the same reason as the three above: deleting a part must not delete
    -- somebody's bouquet. The row keeps its quantity when this goes NULL, so a design
    -- that was six stems is still six stems -- two of which are now waiting for a flower
    -- head the shop no longer sells.
    component_id INT UNSIGNED          NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Which of the mixed blooms comes first in the picker and in the renderer's spiral.
    -- Ties fall back to id, as product_components.sort_order does.
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    -- Reading one design's blooms in order, which is every read this table has.
    KEY idx_saved_design_blooms_design (design_id, sort_order, id),
    -- Deleting the design deletes its flowers. This one is ownership, not a reference:
    -- a bloom row with no design is not a bouquet, it is a fragment.
    CONSTRAINT fk_saved_design_blooms_design
        FOREIGN KEY (design_id) REFERENCES saved_designs (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_design_blooms_component
        FOREIGN KEY (component_id) REFERENCES product_components (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
