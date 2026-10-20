-- Events and promos: a dated occasion with one featured product and a percentage discount.
--
--     mysql -u root capstone < sql\events-upgrade.sql
--
-- Re-runnable, like every migration here: CREATE TABLE IF NOT EXISTS on both tables, no backfill,
-- and no seed rows. A second immediate run changes nothing and exits 0.
--
-- Mirrored into sql/schema.sql so a fresh install matches a migrated one.
--
-- WHY THIS IS TWO TABLES AND NOT A COLUMN ON products. A promo is not a property of a product; it
-- is a property of an occasion that happens to name some products. Putting `discount_percent` on
-- products would mean the shop editing twelve rows to start a sale and twelve more to end it, with
-- nothing recording that the twelve belonged together or when it ran.
--
-- WHERE THE DISCOUNT IS APPLIED, because it is not in this file and the reason matters. Commerce
-- freezes a basket line's price when it is added (php/config/cart.php binds products.price straight
-- into cart_items.unit_price) and placeOrder() copies that frozen figure into order_items without
-- re-reading anything. So the discount is computed in the products data layer -- fetchProduct() and
-- fetchProducts() in php/config/products.php, the only customer-facing readers of products.price --
-- and every consumer downstream of those two inherits it, the basket freeze included. Nothing in
-- cart.php, checkout.php, orders.php or admin.php changes, and there is no discount column on
-- orders: orders.total stays exactly SUM(order_items.line_total), which is what the reports assume.

-- ---------------------------------------------------------------------------------------
-- 1. The event.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    -- What the shop calls it: "Valentine's", "Mother's Day", "Fiesta week".
    name                VARCHAR(80)      NOT NULL,
    -- One line under the name on the home page banner. Optional -- an event with nothing to add
    -- should not be forced to invent a slogan.
    tagline             VARCHAR(160)     NOT NULL DEFAULT '',
    -- DATE and not DATETIME, and inclusive at both ends. A shop says "the 10th to the 14th" and
    -- means both of those days entirely; an occasion that ended at midnight on the 14th would
    -- switch itself off on the morning of the busiest day.
    starts_on           DATE             NOT NULL,
    ends_on             DATE             NOT NULL,
    -- The one product the banner showcases.
    --
    -- Nullable with ON DELETE SET NULL, which is the receipt pattern and is right here for the
    -- receipt pattern's reason: the event is a record of something the shop ran, and withdrawing
    -- the bouquet it featured should not delete the event or refuse the withdrawal. The banner
    -- simply stops showing a product. Compare event_products below, which is the opposite case.
    featured_product_id INT UNSIGNED     NULL,
    -- Whole percent off, applied to every product in event_products.
    --
    -- 0 is meaningful and is not the same as "no promo configured": it is an event that features a
    -- flower and discounts nothing, which is a real thing a shop does. The ceiling is 90 rather
    -- than 100 because a 100% promo is a free product, and nobody reaches for a percentage field
    -- intending to give stock away.
    discount_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Publication, not scheduling. The dates decide whether an event is ON; this decides whether
    -- it exists as far as the shop is concerned, so one can be drafted in advance or pulled
    -- mid-run without editing its dates and losing the record of when it was meant to run.
    is_active           TINYINT(1)       NOT NULL DEFAULT 1,
    created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- The lookup activeEvent() makes on every customer-facing page load: switched on, and today
    -- between the two dates. is_active first because it is the cheaper discriminator.
    KEY idx_events_run (is_active, starts_on, ends_on),
    CONSTRAINT fk_events_featured_product
        FOREIGN KEY (featured_product_id) REFERENCES products (id) ON DELETE SET NULL,
    -- A backwards range is not a weird edge case to tolerate, it is a typo in a date field, and an
    -- event that can never be on is worse than a refusal because nobody finds out for a week.
    CONSTRAINT chk_events_range CHECK (ends_on >= starts_on),
    CONSTRAINT chk_events_percent CHECK (discount_percent <= 90)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- 2. Which products the promo covers.
-- ---------------------------------------------------------------------------------------
--
-- This table is a LIVE INSTRUCTION, not a receipt, and it is shaped like product_parts rather than
-- like order_items for exactly the reason product_parts gives.
--
-- A receipt has to stay readable after the thing it names is gone: order_item_parts carries a
-- nullable component_id with ON DELETE SET NULL beside frozen copies of the name and the price,
-- because a customer reading an order from March must still see what they bought. The frozen price
-- IS the record.
--
-- This one is read fresh every time somebody loads the catalogue, and it answers one question:
-- is this product, right now, in the current promo. A row naming a product that no longer exists
-- cannot answer that question -- it can only make a join return a discount for nothing. So: both
-- keys NOT NULL, both foreign keys ON DELETE CASCADE, and no snapshot columns at all.
--
-- Nothing is lost by cascading. What a customer actually paid during the promo is frozen in
-- cart_items.unit_price and order_items.unit_price at the moment they added the line, so the
-- history lives in the receipts where it belongs, and deleting a membership row cannot change a
-- price anybody was ever charged.
--
-- UNIQUE (event_id, product_id) is what makes "set the whole promo list in one POST" idempotent:
-- saveEventProducts() deletes what was unticked and inserts what was ticked, and a double submit
-- cannot produce two rows claiming the same product is in the same promo twice.
CREATE TABLE IF NOT EXISTS event_products (
    event_id   INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (event_id, product_id),
    -- The direction the catalogue reads it in: given a product, is it in this event. The primary
    -- key already covers (event_id, product_id), so this is the other way round.
    KEY idx_event_products_product (product_id),
    CONSTRAINT fk_event_products_event
        FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_products_product
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Proof, for whoever runs this by hand: both tables present, and what is in them.
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('events', 'event_products')
ORDER BY TABLE_NAME;

SELECT (SELECT COUNT(*) FROM events)         AS events,
       (SELECT COUNT(*) FROM event_products) AS promo_rows;
