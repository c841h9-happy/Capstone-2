-- Adds the products table.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\products-upgrade.sql
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS does nothing the second time.
-- Sample rows are separate, in sql\products-seed.sql.

USE capstone;

-- The catalogue. One table for both things the shop sells.
--
-- The shop makes fuzzy wire handicraft flowers -- wire and chenille, not real
-- stems -- and sells them two ways: as a ready-made bouquet, or as a single
-- flower. Those differ by two fields, not by shape, so they share a table and are
-- told apart by `kind`. Two tables would mean two of every query, two admin
-- screens, and a UNION every time something wants to list the shop's products.
--
-- Nothing here is perishable, which is why there is no season, best-before or
-- freshness column: a wire flower keeps. What does run out is the material it is
-- made from, and that belongs to the Inventory module.
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED             NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)             NOT NULL,
    kind        ENUM('bouquet','flower') NOT NULL DEFAULT 'bouquet',
    description TEXT                     NOT NULL,
    -- DECIMAL, never FLOAT or DOUBLE. Binary floating point cannot hold 0.1
    -- exactly, so a float total drifts by fractions of a centavo and then a
    -- receipt does not add up to the sum of its lines. DECIMAL(10,2) stores the
    -- digits themselves and arithmetic on it is exact. 10 digits reaches
    -- 99,999,999.99, which is more than enough for handicraft prices.
    price       DECIMAL(10,2) UNSIGNED   NOT NULL,
    colour      VARCHAR(40)              NOT NULL DEFAULT '',
    -- How many flowers are in it. NULL for kind = 'flower', where the answer is
    -- always one and storing it would just be a column that is 1 on every row.
    stem_count  SMALLINT UNSIGNED        NULL DEFAULT NULL,
    -- Project-relative, e.g. 'uploads/products/rose-pink.jpg'. Empty until the
    -- Products module adds image upload; the catalogue draws a placeholder tile
    -- for an empty value rather than a broken-image icon.
    image_path  VARCHAR(255)             NOT NULL DEFAULT '',
    -- Published or hidden, and nothing more. This is NOT a stock count: whether
    -- something is in stock is the Inventory module's question, and answering it
    -- here with a boolean would be a lie the moment real stock exists.
    is_active   TINYINT(1)               NOT NULL DEFAULT 1,
    created_at  TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Every catalogue query filters on both of these, in this order.
    KEY idx_products_kind_active (kind, is_active)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
