-- Adds the product_components table: the parts the 3D customizer assembles.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\components-upgrade.sql
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS does nothing the second time.
-- Sample rows are separate, in sql\components-seed.sql.

USE capstone;

-- What a bouquet is made of, as opposed to a bouquet.
--
-- Its own table rather than more rows in `products`, and that is the whole point of
-- it. A product is a thing a customer buys; a component is a thing the customizer
-- puts together, and the two disagree about almost every column. A component needs
-- a colour a renderer can actually paint with and a deliberate position in a picker;
-- it has no stem_count, because it *is* one part. Meanwhile every public catalogue
-- query would have to grow "AND kind NOT IN (...)" the day components moved into
-- products -- and the first one that forgot would put a length of wire on the shop
-- front at PHP-25.00.
--
-- The customizer prices a design by adding up the components in it, which is why
-- price is here and is the same DECIMAL(10,2) UNSIGNED as products.price: a total
-- built from a mix of exact and floating money is a total that does not add up.
CREATE TABLE IF NOT EXISTS product_components (
    id          INT UNSIGNED           NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)           NOT NULL,
    -- The four things a fuzzy wire bouquet is built from.
    --
    -- 'bloom' rather than 'flower' on purpose: products.kind already uses the word
    -- "flower" for a whole single flower somebody buys, and two tables side by side
    -- using one word for two different things is how a later query gets written
    -- against the wrong one. 'wire' is the stem, 'wrap' is the paper or sleeve
    -- around the outside, 'accent' is a ribbon, bead or tag.
    kind        ENUM('bloom','wire','wrap','accent') NOT NULL DEFAULT 'bloom',
    description TEXT                   NOT NULL,
    price       DECIMAL(10,2) UNSIGNED NOT NULL,
    -- What a person would call the colour: "blush pink", "kraft brown".
    colour      VARCHAR(40)            NOT NULL DEFAULT '',
    -- The same colour as something a renderer can use: '#rrggbb', lower case.
    --
    -- This is the one column that exists purely for the 3D builder, and it is why a
    -- component cannot just be a product. "Blush pink" is not a thing WebGL can
    -- paint with. CHAR(7) rather than VARCHAR because every value is exactly seven
    -- characters, and storing the '#' with it means the value drops straight into
    -- CSS or a material without a caller having to remember to add one.
    --
    -- NOT NULL with no default: a component the builder cannot draw is not a
    -- component, so the admin form makes this a colour picker, which always has a
    -- value.
    colour_hex  CHAR(7)                NOT NULL,
    -- Project-relative, e.g. 'uploads/components/<32 hex>.jpg'. Same shape as
    -- products.image_path and written by the same storeUploadedImage(), which takes
    -- the folder as an argument for exactly this reason.
    image_path  VARCHAR(255)           NOT NULL DEFAULT '',
    -- Where it sits in the picker, low first.
    --
    -- Not a sort the customer chooses. A picker wants blooms in a deliberate
    -- sequence rather than in the order the admin happened to add them, and
    -- "newest first" is meaningless to somebody choosing a colour. Ties fall back to
    -- id, so an admin who leaves every row at 0 still gets a stable order.
    sort_order  SMALLINT UNSIGNED      NOT NULL DEFAULT 0,
    -- Offered in the customizer, or not. As with products, this is NOT stock.
    is_active   TINYINT(1)             NOT NULL DEFAULT 1,
    created_at  TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The customizer asks for one kind of active component at a time, in order.
    KEY idx_components_kind_active (kind, is_active, sort_order)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
