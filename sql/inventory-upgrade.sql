-- Adds the Inventory module's three pieces: a stock count on every part, the recipe that
-- says what a catalogue product is made of, and the ledger of every stock movement.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\inventory-upgrade.sql
--
-- Safe to run twice. ADD COLUMN IF NOT EXISTS and CREATE TABLE IF NOT EXISTS both do
-- nothing the second time, and there is no backfill here at all -- the opening stock-take
-- and the seeded recipes are separate, in sql\inventory-seed.sql, exactly as the component
-- rows are separate from sql\components-upgrade.sql.
--
-- Needs sql\components-upgrade.sql and sql\products-upgrade.sql to have run first.

USE capstone;

-- ---------------------------------------------------------------------------------------
-- 1. Stock, on the parts.
-- ---------------------------------------------------------------------------------------
--
-- On product_components and not on products, which is the module's first real decision.
--
-- The shop makes to order. It does not hold twelve finished Pastel Rose Dozens on a shelf;
-- it holds rose heads, chenille stems, kraft sleeves and twine, and assembles a bouquet
-- when somebody buys one. So the thing there is a count of is a part, and a part is a row
-- in this table -- "Blush Rose Head" *is* the material. Whether a given bouquet can be
-- made is then a question that gets *answered* from these counts and the recipe below,
-- rather than a number somebody has to remember to keep up to date in two places.
--
-- That is also the long-promised answer to the comment on products.is_active in
-- sql\products-upgrade.sql: "whether something is in stock is the Inventory module's
-- question, and answering it here with a boolean would be a lie the moment real stock
-- exists." is_active stays exactly what it was -- published or hidden -- and availability
-- is computed. The two never disagree because only one of them is stored.
--
-- No side table with a 1:1 row per component: that would be a join on every screen that
-- wants a count, to hold two integers that have no life of their own.
ALTER TABLE product_components
    -- INT, deliberately NOT INT UNSIGNED.
    --
    -- Stock has to be able to go below zero. The shop was asked what should happen when a
    -- customer orders a bouquet there are not enough parts for, and the answer was to warn
    -- the shop and never block the customer -- so an order can legitimately consume four
    -- rose heads the books only had two of. UNSIGNED would answer that with SQLSTATE 22003
    -- and an "out of range" error, which would turn "our count was wrong" into "the site is
    -- broken". A negative number here is not a bug: it is the books saying out loud that
    -- they disagree with the drawer, which is the single most useful thing a stock system
    -- ever says.
    --
    -- This column is written by exactly one function, recordStockMove() in
    -- php/config/inventory.php, and by nothing else -- the same single-writer rule
    -- image_path already has with setComponentImage(). Every change to it also writes a
    -- stock_moves row, in the same transaction, which is what makes the two agree.
    ADD COLUMN IF NOT EXISTS stock_on_hand INT NOT NULL DEFAULT 0 AFTER is_active,
    -- "Tell me when this gets low."
    --
    -- UNSIGNED here, unlike the count above, because a threshold is a choice rather than a
    -- measurement and a negative one means nothing. 0 is the default and means "never warn
    -- about this part", which is the right thing for an accent nobody minds running out of.
    -- SMALLINT because a threshold in the tens of thousands is not a threshold.
    ADD COLUMN IF NOT EXISTS low_stock_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 0
        AFTER stock_on_hand;

-- The stock screen's default view is "what is low", which is this index.
--
-- It cannot be a plain index on stock_on_hand: the comparison is against another column
-- (stock_on_hand <= low_stock_threshold), which no index can satisfy on its own. What this
-- does give is a cheap ordered read of the counts for the list itself, with is_active
-- first because a withdrawn part is not what anybody is restocking.
ALTER TABLE product_components
    ADD KEY IF NOT EXISTS idx_components_stock (is_active, stock_on_hand);

-- ---------------------------------------------------------------------------------------
-- 2. The recipe.
-- ---------------------------------------------------------------------------------------
--
-- What a catalogue product is made of. The gap this module exists to close.
--
-- Three tables already answer this question for everything *except* a product:
-- saved_design_blooms for a saved design, cart_item_parts for a basket line,
-- order_item_parts for an order line. A custom bouquet has therefore always known what it
-- is built from, and a catalogue bouquet -- the thing the shop actually sells most of --
-- has known only a stem_count and a sentence of description.
--
-- This table is deliberately NOT shaped like those three, and the difference is the whole
-- reason it is worth writing down.
--
-- Those three are RECEIPTS. order_item_parts is what the shop reads to know what to make
-- and what the customer reads to know what they bought, months later, and it has to stay
-- readable when the part it names has been deleted from the catalogue. That is why its
-- component_id is nullable with ON DELETE SET NULL, and why it carries frozen copies of
-- the name, the colour and the price paid.
--
-- This one is a RECIPE. It is a live instruction, read fresh every time somebody asks
-- "can we make this" or "what do we need to buy", and it is never historical. A recipe
-- still naming a part the shop does not stock is not a record of anything -- it is an
-- instruction nobody can follow, quietly producing a bouquet that is short a component.
-- So: no snapshot columns, both foreign keys NOT NULL, and both cascading. If the product
-- goes, its recipe goes with it; if a part is destroyed, the recipes that called for it
-- stop claiming they can be followed.
--
-- (php/admin/component-edit.php warns before that second cascade happens, naming the
-- products that would lose a part. The cascade is correct and the silence would not be.)
CREATE TABLE IF NOT EXISTS product_parts (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    product_id   INT UNSIGNED      NOT NULL,
    component_id INT UNSIGNED      NOT NULL,
    -- How many of this part go into one of this product. Twelve rose heads, twelve stems,
    -- one sleeve, one tie.
    --
    -- UNSIGNED and no zero is meaningful: a quantity of 0 is not "none of this part", it is
    -- a row that should not exist, and saveProductParts() deletes rather than storing one.
    -- That is what keeps "is this part in the recipe" answerable by the row's existence
    -- alone, with no caller having to remember to filter.
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- The order the build instruction reads in: blooms, then stems, then wrap, then the
    -- tie, the way somebody actually assembles a bouquet. Defaults to the component's own
    -- picker position, so a recipe entered without thinking about order still reads
    -- sensibly. Ties fall back to id.
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One row per part per product, enforced rather than trusted.
    --
    -- This is what makes the recipe editor safe. That screen posts the whole recipe at once
    -- -- every component with a number in its box -- so it has to be able to run twice with
    -- the same values and leave the same rows behind. Without this key a double-submitted
    -- form would turn "12 rose heads" into two rows of 12, and buildableCount() would
    -- silently halve.
    UNIQUE KEY uniq_product_parts (product_id, component_id),
    -- "What is this product made of", the common read.
    KEY idx_product_parts_product (product_id, sort_order, id),
    -- "What uses this part", the read behind the deletion warning and the restock list.
    KEY idx_product_parts_component (component_id),
    CONSTRAINT fk_product_parts_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_product_parts_component
        FOREIGN KEY (component_id) REFERENCES product_components (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- 3. The ledger.
-- ---------------------------------------------------------------------------------------
--
-- Every movement of every part, ever. The "transactions" half of the module's brief.
--
-- product_components.stock_on_hand is a stored running balance AND this is the full trail.
-- Both, not either, and the reasoning is worth keeping:
--
--   * Balance only. Fast, and unanswerable. "Why is this 14?" has no answer, and a count
--     nobody can explain is a count nobody trusts enough to act on.
--   * Ledger only, with SUM(delta) computed on demand. Honest, and a table scan on every
--     screen that wants to print a number, forever, growing with the shop's whole history.
--   * Both. recordStockMove() writes the row and moves the balance in one transaction, so
--     they cannot drift apart, and stockAuditDiscrepancies() re-derives the sums and says
--     so if they ever do. That check is a feature on php/admin/stock-moves.php and an
--     assertion in php/tools/inventory-http-test.php.
--
-- Note that the snapshot pattern IS right here, for precisely the reason it was wrong for
-- product_parts above: this is history. A movement recorded in March is still a true fact
-- about March after the part is deleted in June, so component_id goes nullable with
-- ON DELETE SET NULL and the name is frozen beside it -- exactly what order_item_parts
-- does, and for the same reason.
CREATE TABLE IF NOT EXISTS stock_moves (
    -- BIGINT, unlike every other id in this schema. This is the one table that gets a row
    -- per event rather than a row per thing: it grows with every restock, every stock-take
    -- and every order the shop makes. INT UNSIGNED would last a
    -- very long time and BIGINT costs four bytes a row to never have to think about it.
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    component_id   INT UNSIGNED    NULL,
    -- Frozen at the moment of the movement, so a ledger read months later still says which
    -- part moved even if that part has since been destroyed. Same width as
    -- product_components.name.
    component_name VARCHAR(120)    NOT NULL,
    -- Why it moved.
    --
    -- An ENUM rather than free text because these are the only six things that can happen
    -- to stock here, and a seventh should be an ALTER somebody has to think about rather
    -- than a typo that quietly becomes a category.
    --
    --   opening  -- the first count of a part. Written by the seed and by the first
    --              stock-take; the row that stops a balance appearing from nowhere.
    --   received -- a delivery arrived. The only routinely positive one.
    --   used     -- consumed making an order. Written by consumeOrderStock() when an order is
    --              marked as being made, which takes the parts out of the drawer.
    --   returned -- put back because an order was un-started or cancelled. The twin of 'used',
    --              and the reason that one is reversible at all.
    --   adjusted -- a stock-take corrected the books. Signed either way.
    --   wasted   -- a part was spoiled or lost. Always negative, and kept apart from
    --              'adjusted' on purpose: "we miscounted" and "we ruined four" are the same
    --              arithmetic and completely different facts, and a shop that cannot tell
    --              them apart cannot find out it has a problem.
    --
    -- 'used' and 'returned' were in this ENUM from the first day, before anything wrote them,
    -- so that wiring them up needed no migration at all -- the same call the Admin module made
    -- keeping adminInventorySummary() standing for this one. It paid off on 2026-09-29.
    reason         ENUM('opening','received','used','returned','adjusted','wasted') NOT NULL,
    -- Signed, and the whole point of the table. +40 received, -12 used, -4 wasted.
    --
    -- A magnitude column plus a direction flag was the alternative, and it makes the one
    -- query this table exists for -- SUM() to re-derive a balance -- into a CASE. Storing
    -- the sign is storing the arithmetic.
    delta          INT             NOT NULL,
    -- What stock_on_hand became immediately after this row was written.
    --
    -- Redundant with a running SUM, and stored anyway for two reasons. The ledger page
    -- prints a balance column without a window function or a correlated subquery per row.
    -- And it is *history*: a movement that took the count from 9 to -3 still says so in a
    -- year, whatever the count is by then.
    balance_after  INT             NOT NULL,
    -- Which order caused it, for 'used' and 'returned'. NULL for every other reason: a
    -- delivery arriving and a drawer being counted are not about an order.
    --
    -- ON DELETE SET NULL rather than CASCADE: if an order row is ever destroyed the parts
    -- still left the drawer, and deleting the evidence would put the books out by however
    -- much that order consumed.
    order_id       INT UNSIGNED    NULL,
    -- "Restock from Lazada", "dropped a box". Free text, and the difference between a
    -- ledger somebody can read and a column of numbers.
    note           VARCHAR(255)    NOT NULL DEFAULT '',
    -- Who did it. NULL for a machine-written row and for an admin account deleted since.
    user_id        INT UNSIGNED    NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One part's history, newest first, and the index the audit SUM() groups on.
    KEY idx_stock_moves_component (component_id, id),
    -- The ledger screen's default: everything, newest first.
    KEY idx_stock_moves_created (created_at, id),
    -- "What did this order consume", the read behind the order page's stock panel and behind
    -- stock-moves.php?order=N.
    KEY idx_stock_moves_order (order_id),
    KEY idx_stock_moves_reason (reason),
    CONSTRAINT fk_stock_moves_component
        FOREIGN KEY (component_id) REFERENCES product_components (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_stock_moves_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_stock_moves_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    -- A movement of nothing is not a movement.
    --
    -- Unlike the kind checks in sql\kind-check-upgrade.sql this one cannot be broken by a
    -- referential action: none of the three foreign keys above touches delta, so nulling
    -- component_id, order_id or user_id leaves it exactly as it was. See that file for what
    -- a CHECK that a cascade *can* violate looks like, and why it had to be rewritten.
    CONSTRAINT chk_stock_moves_delta CHECK (delta <> 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
