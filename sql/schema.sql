-- Capstone database schema.
--
-- Run once from the XAMPP shell:
--     mysql -u root < sql\schema.sql
-- or paste it into phpMyAdmin's SQL tab.
--
-- The database name, user and password must match php/config/database.php.

CREATE DATABASE IF NOT EXISTS capstone
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE capstone;

-- Accounts created by php/login.php (sign-up panel).
--
-- Both `name` and `email` are UNIQUE because login.php accepts either one as
-- the identifier -- duplicates would make "WHERE email = :id OR name = :id
-- LIMIT 1" pick an arbitrary row, so the wrong person could be signed in or a
-- valid password could be rejected.
--
-- The utf8mb4_unicode_ci collation is case-insensitive, so signing in with
-- "John@Example.com" still matches the stored "john@example.com".
--
-- email_verified_at stays NULL until the account holder types in the code that
-- was emailed to them. Sign-in treats a NULL as "prove you own this address
-- first" -- see php/login.php.
--
-- role decides what the signed-in session may reach; php/config/auth.php reads it
-- back out with currentUserRole() and isAdmin(). ENUM rather than VARCHAR so a
-- typo like 'Admin' is refused by the database instead of quietly becoming a role
-- that matches nothing, and DEFAULT 'customer' so the sign-up INSERT in
-- php/login.php never has to mention it -- privileges should not be the default
-- that arrives by omission. Nothing here creates an admin; that is
-- php/tools/make-admin.php.
-- 
-- pending_email holds an address change from the moment php/profile.php requests
-- one until the emailed code proves it. On the row rather than in the session, so
-- an unfinished change is not stranded by closing the tab. Deliberately not
-- UNIQUE -- see sql/email-change-upgrade.sql for why claiming is not owning.
CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED             NOT NULL AUTO_INCREMENT,
    name              VARCHAR(100)             NOT NULL,
    email             VARCHAR(255)             NOT NULL,
    pending_email     VARCHAR(255)             NULL DEFAULT NULL,
    password_hash     VARCHAR(255)             NOT NULL,
    role              ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    email_verified_at TIMESTAMP                NULL DEFAULT NULL,
    created_at        TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_name (name)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- The 6-digit codes emailed for two-step verification, from php/config/twofactor.php.
--
-- One row per account per purpose, so issuing a code replaces the one before it.
-- That keeps the table from growing without bound and removes any question about
-- which code is the current one. It also means the three purposes do not interfere:
-- an email-change code and a sign-in code can be outstanding at once, each with its
-- own resend throttle.
--
-- 'signup' proves a new account's address, 'login' proves the person with the
-- password also has the mailbox, and 'email_change' proves a new address before it
-- replaces the old one -- see sql/email-change-upgrade.sql.
--
-- code_hash is a password_hash() of the digits rather than the digits themselves:
-- a database dump then hands over nothing usable, and bcrypt being slow is a
-- feature when there are only a million possible codes. Lookups are always by
-- user_id, never by the code, so a deterministic hash is not needed here.
CREATE TABLE IF NOT EXISTS email_codes (
    user_id    INT UNSIGNED             NOT NULL,
    purpose    ENUM('signup', 'login', 'email_change') NOT NULL,
    code_hash  VARCHAR(255)             NOT NULL,
    attempts   TINYINT UNSIGNED         NOT NULL DEFAULT 0,
    sent_at    TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- The default is never used -- every insert sets this. It has to be spelled
    -- out because MariaDB gives a TIMESTAMP NOT NULL column with no default an
    -- implicit '0000-00-00 00:00:00', which the NO_ZERO_DATE sql_mode then
    -- refuses, and CREATE TABLE fails with error 1067. CURRENT_TIMESTAMP is the
    -- right choice of the available ones: a row that somehow arrived without an
    -- expiry counts as already expired rather than valid forever.
    expires_at TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, purpose),
    CONSTRAINT fk_email_codes_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- Browsers that ticked "Remember this device for 30 days" on the code page, from
-- php/config/devices.php.
--
-- token_hash is a plain sha256 of the cookie value, not a password_hash(). This
-- row has to be found *by* the token the browser presents, which needs a hash
-- that lands on the same value every time. The token is 32 bytes from
-- random_bytes(), so there is nothing to slow-hash against -- and bcrypt would
-- turn one indexed lookup into a scan of every row plus a verify each.
--
-- label holds the browser's user agent, so a future "your devices" screen has
-- something to show. Nothing reads it yet.
CREATE TABLE IF NOT EXISTS trusted_devices (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    label      VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Spelled out for the same reason as email_codes.expires_at above, and it
    -- fails the same safe way: no expiry means expired now, not trusted forever.
    expires_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id),
    CONSTRAINT fk_trusted_devices_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- The catalogue, read by php/products.php and php/product.php.
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
--
-- Sample rows are in sql/products-seed.sql, not here -- a schema file should build
-- an empty database, not a populated one.
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED             NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)             NOT NULL,
    kind        ENUM('bouquet','flower') NOT NULL DEFAULT 'bouquet',
    description TEXT                     NOT NULL,
    -- DECIMAL, never FLOAT or DOUBLE. Binary floating point cannot hold 0.1
    -- exactly, so a float total drifts by fractions of a centavo and then a
    -- receipt does not add up to the sum of its lines. DECIMAL(10,2) stores the
    -- digits themselves and arithmetic on it is exact.
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

-- What a bouquet is made of, as opposed to a bouquet. Read by the 3D customizer;
-- managed from php/admin/components.php.
--
-- Its own table rather than more rows in `products`, and that is the whole point of
-- it. A product is a thing a customer buys; a component is a thing the customizer
-- puts together, and the two disagree about almost every column. A component needs a
-- colour a renderer can actually paint with and a deliberate position in a picker; it
-- has no stem_count, because it *is* one part. Meanwhile every public catalogue query
-- would have to grow "AND kind NOT IN (...)" the day components moved into products --
-- and the first one that forgot would put a length of wire on the shop front.
--
-- Sample rows are in sql/components-seed.sql. See sql/components-upgrade.sql for the
-- per-column reasoning; this is the same table.
CREATE TABLE IF NOT EXISTS product_components (
    id          INT UNSIGNED           NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)           NOT NULL,
    -- 'bloom' rather than 'flower': products.kind already uses "flower" for a whole
    -- single flower somebody buys, and one word meaning two things in two tables is
    -- how a later query gets written against the wrong one.
    kind        ENUM('bloom','wire','wrap','accent') NOT NULL DEFAULT 'bloom',
    description TEXT                   NOT NULL,
    -- The customizer prices a design by adding its components up, so this is the same
    -- exact DECIMAL as products.price rather than a float.
    price       DECIMAL(10,2) UNSIGNED NOT NULL,
    colour      VARCHAR(40)            NOT NULL DEFAULT '',
    -- The same colour as something a renderer can use: '#rrggbb', lower case. The one
    -- column that exists purely for the 3D builder -- "blush pink" is not a thing
    -- WebGL can paint with. No default, because a component the builder cannot draw is
    -- not a component.
    colour_hex  CHAR(7)                NOT NULL,
    -- Which flower head the 3D builder draws. Blooms only: a stem, a wrap and a ribbon
    -- each have one drawn form and do not need naming, so 'none' is what they keep, and
    -- php/config/components-admin.php forces it for every kind but 'bloom'. Added by
    -- sql/components-shape-upgrade.sql on an existing database; here for a fresh one.
    -- 'bud' was added later, by sql/component-shape-bud-upgrade.sql, and is last for that
    -- reason: MySQL stores an ENUM as the ordinal of its value, so appending is free while
    -- inserting one in the middle would silently re-point every row after it.
    shape       ENUM('none','rose','tulip','sunflower','daisy','sprig','bud')
                                       NOT NULL DEFAULT 'none',
    image_path  VARCHAR(255)           NOT NULL DEFAULT '',
    -- Where it sits in the picker, low first, ties broken by id. Not a sort the
    -- customer chooses: "newest first" means nothing to somebody picking a colour.
    sort_order  SMALLINT UNSIGNED      NOT NULL DEFAULT 0,
    is_active   TINYINT(1)             NOT NULL DEFAULT 1,
    -- How many of this part the shop physically has. The Inventory module's count, and the
    -- answer to what is_active above is NOT: published and in stock are different questions.
    --
    -- On the parts rather than on products because the shop makes to order -- it holds rose
    -- heads and chenille stems, not finished bouquets -- so whether a bouquet can be made is
    -- computed from these counts and the product_parts recipe below.
    --
    -- INT rather than INT UNSIGNED, deliberately: a shortfall warns the shop and never blocks
    -- the customer, so an order may legitimately take out more than the books had. UNSIGNED
    -- would answer that with SQLSTATE 22003 instead of with the truth. Written by exactly one
    -- function, recordStockMove() in php/config/inventory.php, which writes a stock_moves row
    -- in the same transaction. Added by sql/inventory-upgrade.sql on an existing database.
    stock_on_hand       INT               NOT NULL DEFAULT 0,
    -- "Tell me when this gets low." UNSIGNED because a threshold is a choice rather than a
    -- measurement; 0 means never warn about this part.
    low_stock_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The customizer asks for one kind of active component at a time, in order.
    KEY idx_components_kind_active (kind, is_active, sort_order),
    -- The stock screen's list. It cannot cover the low-stock filter itself -- that compares
    -- two columns -- but it gives the counts back in order, withdrawn parts last.
    KEY idx_components_stock (is_active, stock_on_hand)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- A bouquet somebody built in the customizer and kept.
--
-- A design is neither a product nor a component: it is a recipe -- a list of parts and how
-- many of each -- owned by one account.
--
-- No money in here on purpose. A saved design re-prices from product_components every time
-- it is read, because a recipe is not a quote: if the satin ribbon goes up five pesos, a
-- design naming it costs five pesos more. The freeze belongs one step later -- when a design
-- becomes a basket line, Commerce copies the prices into that line, because an order *is* a
-- contract and must not move underneath somebody who has paid.
--
-- No stem count either. It is SUM(saved_design_blooms.quantity), and a copy of that sum
-- beside the rows it came from is a number free to be wrong about them.
CREATE TABLE IF NOT EXISTS saved_designs (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED      NOT NULL,
    -- What the customer calls it: "Mum's birthday". Unique per account, not globally.
    name        VARCHAR(80)       NOT NULL,
    -- The three parts a bouquet has exactly one of, however many flowers are in it.
    -- NULL-able, and ON DELETE SET NULL, so an admin deleting a withdrawn ribbon does not
    -- delete somebody's saved design: it survives with a hole, and the page falls back to
    -- what is on offer today.
    wire_id     INT UNSIGNED          NULL,
    wrap_id     INT UNSIGNED          NULL,
    accent_id   INT UNSIGNED          NULL,
    -- How the stems are arranged. Renderer-only: it changes no price. 'dome' is the
    -- phyllotaxis fan the builder shipped with, so an older design looks unchanged.
    arrangement ENUM('dome','round','loose') NOT NULL DEFAULT 'dome',
    created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saved_designs_user_name (user_id, name),
    KEY idx_saved_designs_user (user_id, updated_at),
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
-- A table rather than a bloom_id column, because a bouquet may mix kinds -- four roses and
-- two sunflowers is two rows, not a migration. quantity is stems of this bloom, and the
-- design's flower count is the sum of them.
CREATE TABLE IF NOT EXISTS saved_design_blooms (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    design_id    INT UNSIGNED      NOT NULL,
    component_id INT UNSIGNED          NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_saved_design_blooms_design (design_id, sort_order, id),
    CONSTRAINT fk_saved_design_blooms_design
        FOREIGN KEY (design_id) REFERENCES saved_designs (id) ON DELETE CASCADE,
    CONSTRAINT fk_saved_design_blooms_component
        FOREIGN KEY (component_id) REFERENCES product_components (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- The basket. See sql/cart-upgrade.sql for the full reasoning behind these three tables; the
-- short version is that a line is either a catalogue product or a designed bouquet, a designed
-- bouquet is a list of parts, and prices freeze here because a basket line is the one place the
-- customizer's designSnapshot() says they must stop tracking product_components.
--
-- Note the CHECK constraints are enforced by MariaDB 10.4 but that this server's sql_mode has
-- no STRICT_TRANS_TABLES, so an out-of-range DECIMAL is silently clamped rather than refused.
-- The real guard on money is in PHP, as it is for products.
CREATE TABLE IF NOT EXISTS carts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Exactly one of these two is set: an account owns it, or a browser does.
    user_id    INT UNSIGNED     NULL,
    token_hash CHAR(64)         NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carts_user (user_id),
    UNIQUE KEY uq_carts_token (token_hash),
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_carts_owner CHECK (
        (user_id IS NOT NULL AND token_hash IS NULL)
        OR (user_id IS NULL AND token_hash IS NOT NULL)
    )
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id      INT UNSIGNED NOT NULL,
    kind         ENUM('product','design') NOT NULL,
    product_id   INT UNSIGNED     NULL,
    -- Name and price frozen at the moment it was added.
    name         VARCHAR(120) NOT NULL,
    unit_price   DECIMAL(10,2) UNSIGNED NOT NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    arrangement  ENUM('dome','round','loose') NOT NULL DEFAULT 'dome',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cart_items_cart (cart_id, id),
    KEY fk_cart_items_product (product_id),
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    -- Only a product line may name a product. Not the reverse: the key above nulls product_id
    -- when a product is deleted, and a CHECK cannot survive that. See sql/kind-check-upgrade.sql.
    CONSTRAINT chk_cart_items_kind CHECK (kind = 'product' OR product_id IS NULL)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_item_parts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id      INT UNSIGNED NOT NULL,
    component_id INT UNSIGNED     NULL,
    kind         ENUM('bloom','wire','wrap','accent') NOT NULL,
    name         VARCHAR(120) NOT NULL,
    colour_hex   CHAR(7)      NOT NULL DEFAULT '#cccccc',
    each_price   DECIMAL(10,2) UNSIGNED NOT NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_cart_item_parts_item (item_id, sort_order, id),
    KEY fk_cart_item_parts_component (component_id),
    CONSTRAINT fk_cart_item_parts_item
        FOREIGN KEY (item_id) REFERENCES cart_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_item_parts_component
        FOREIGN KEY (component_id) REFERENCES product_components (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- What a catalogue product is made of. See sql/inventory-upgrade.sql for the full reasoning;
-- this is the same table, here so a fresh install matches a migrated one.
--
-- The short version: its three siblings above -- saved_design_blooms, cart_item_parts and
-- order_item_parts -- are receipts, so they snapshot the name and price and survive their
-- part being deleted. This is a recipe, a live instruction read fresh every time somebody
-- asks "can we make this", so it is the opposite: no snapshots, both keys NOT NULL, both
-- cascading. A recipe still naming a part the shop no longer stocks is not a record of
-- anything -- it is an instruction nobody can follow.
CREATE TABLE IF NOT EXISTS product_parts (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    product_id   INT UNSIGNED      NOT NULL,
    component_id INT UNSIGNED      NOT NULL,
    -- How many of this part go into one of this product. A quantity of 0 is not "none of
    -- this part", it is a row that should not exist, and saveProductParts() deletes instead.
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- The order the build instruction reads in, defaulting to the component's own picker
    -- position. Ties fall back to id.
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One row per part per product, which is what lets the recipe editor post the whole
    -- recipe at once and survive a double submit.
    UNIQUE KEY uniq_product_parts (product_id, component_id),
    KEY idx_product_parts_product (product_id, sort_order, id),
    -- "What uses this part": the read behind the deletion warning on a component.
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

-- The Inventory module's third table, stock_moves, is NOT here, and that is not an
-- oversight.
--
-- It carries a foreign key to orders (id) -- the ledger records which order consumed which
-- parts -- and this file does not create the orders tables. Commerce's three live in
-- sql/orders-upgrade.sql and were never mirrored back here, so a CREATE TABLE for the
-- ledger at this point in the file would fail outright with errno 150. Defining it without
-- that key instead would be worse: a fresh install would then differ from a migrated one in
-- a way nothing notices until an order is marked as being made and writes the first
-- order_id.
--
-- So the ledger's one definition is sql/inventory-upgrade.sql, and a fresh install is this
-- file followed by the migrations in sql/ -- orders before inventory. Same for order_events
-- (sql/orders-tracking-upgrade.sql), for the same reason.

-- ---------------------------------------------------------------------------------------
-- Events and promos.
-- ---------------------------------------------------------------------------------------
--
-- Added by sql/events-upgrade.sql on an existing database; here for a fresh one. Unlike
-- stock_moves above, both of these reference only products, which this file does create --
-- so they can live here and a fresh install matches a migrated one exactly.
--
-- The discount is NOT stored against a product and NOT stored against an order. It is
-- computed in php/config/products.php, in fetchProduct() and fetchProducts(), which are the
-- only customer-facing readers of products.price -- so the catalogue, the product page and
-- the basket freeze all inherit it, and orders.total stays exactly
-- SUM(order_items.line_total) the way the reports assume.
CREATE TABLE IF NOT EXISTS events (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name                VARCHAR(80)      NOT NULL,
    -- One line under the name on the banner. Optional.
    tagline             VARCHAR(160)     NOT NULL DEFAULT '',
    -- DATE, and inclusive at both ends: a shop saying "the 10th to the 14th" means both of
    -- those days entirely.
    starts_on           DATE             NOT NULL,
    ends_on             DATE             NOT NULL,
    -- The product the banner showcases. Nullable ON DELETE SET NULL -- the receipt pattern,
    -- and right here for its reason: withdrawing a bouquet must not delete the record of an
    -- event that featured it, nor be refused. Contrast event_products below.
    featured_product_id INT UNSIGNED     NULL,
    -- Whole percent off every product in event_products. 0 is meaningful: an event that
    -- features a flower and discounts nothing. Capped at 90, because 100 is a free product
    -- and nobody types into a percentage field meaning to give stock away.
    discount_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Publication, not scheduling. The dates decide whether an event is ON; this decides
    -- whether it exists at all, so one can be drafted ahead or pulled mid-run without
    -- editing its dates and losing the record of when it was meant to run.
    is_active           TINYINT(1)       NOT NULL DEFAULT 1,
    created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The lookup activeEvent() makes on every customer-facing page load.
    KEY idx_events_run (is_active, starts_on, ends_on),
    CONSTRAINT fk_events_featured_product
        FOREIGN KEY (featured_product_id) REFERENCES products (id)
        ON DELETE SET NULL,
    -- A backwards range is a typo in a date field, not an edge case to tolerate, and an
    -- event that can never be on is worse than a refusal -- nobody finds out for a week.
    CONSTRAINT chk_events_range CHECK (ends_on >= starts_on),
    CONSTRAINT chk_events_percent CHECK (discount_percent <= 90)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Which products the promo covers.
--
-- A LIVE INSTRUCTION, shaped like product_parts and not like order_items. It answers one
-- question -- is this product in the current promo, right now -- and a row naming a deleted
-- product cannot answer it, only make a join hand back a discount for nothing. So both keys
-- NOT NULL, both FKs CASCADE, and no snapshot columns.
--
-- Nothing is lost by cascading: what a customer actually paid is frozen in
-- cart_items.unit_price and order_items.unit_price when the line was added, so the history
-- lives in the receipts and deleting a membership row cannot change a price anybody paid.
CREATE TABLE IF NOT EXISTS event_products (
    event_id   INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    -- One row per product per event, which is what lets the editor post the whole promo
    -- list at once and survive a double submit.
    PRIMARY KEY (event_id, product_id),
    -- The direction the catalogue reads it: given a product, is it in this event.
    KEY idx_event_products_product (product_id),
    CONSTRAINT fk_event_products_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_event_products_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Settings the shop can change without editing PHP.
-- ---------------------------------------------------------------------------------------
--
-- Added by sql/settings-upgrade.sql on an existing database; here for a fresh one. That file
-- claimed to be mirrored here from the day it was written and was not -- found 2026-10-13 by
-- listing what schema.sql creates against what the migrations create, which is a check worth
-- repeating after any new table.
--
-- One row per setting, read only by php/config/settings.php. Every setting has a default in PHP
-- and the store is consulted first, so an empty table -- or no table -- means the project behaves
-- exactly as it did before this existed.
--
-- WARNING, the same one sql/settings-upgrade.sql carries: one of the values this is designed to
-- hold is `mail.sender_password`, a live Gmail app password. Before this table that credential
-- lived only in php/config/mail.php, which .gitignore excludes. A dump of this database now
-- contains it. It is stored as given, not hashed, because SMTP AUTH has to present the real value.
CREATE TABLE IF NOT EXISTS settings (
    -- Dotted names, so a glance says which feature owns which row.
    name       VARCHAR(64)  NOT NULL,
    -- Never NULL: an absent setting is an absent ROW, which is what lets settingValue() tell
    -- "unset" from "set to nothing" without a third state.
    value      TEXT         NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    PRIMARY KEY (name),
    -- The receipt pattern: the record of a change outlives the account that made it, and deleting
    -- a staff account must not be refused because they once saved a setting.
    CONSTRAINT fk_settings_user
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Login attempts, so a password cannot be guessed at machine speed.
-- ---------------------------------------------------------------------------------------
--
-- Added by sql/login-attempts-upgrade.sql on an existing database; here for a fresh one.
--
-- Deliberately NOT a foreign key to users and deliberately not a counter column on it. An
-- identifier that matches nobody has to be throttled too, or the form answers "is this address
-- registered" differently for a real account and an invented one and becomes an enumeration
-- oracle. And a per-account counter never sees one password sprayed across many accounts, which is
-- what the address index is for.
--
-- Nothing is ever locked: there is no `locked_until` anywhere. A lockout would hand anybody who
-- knows an email address the power to lock its owner out. What is limited is the RATE, by a sliding
-- window that expires on its own.
--
-- Both columns are personal data and neither is wanted past the window, so recordLoginAttempt()
-- prunes on every write.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Whatever was typed in the username/email box, lowercased.
    identifier   VARCHAR(255)    NOT NULL,
    -- REMOTE_ADDR as the server saw it; 45 characters covers IPv6.
    ip_address   VARCHAR(45)     NOT NULL,
    -- Successes are recorded too: a success is what clears the identifier's failures, and keeping
    -- both makes the table a history rather than a grudge.
    succeeded    TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The two reads the throttle makes, each covered. succeeded is in both so a count never
    -- touches a row it will discard.
    KEY idx_login_attempts_identifier (identifier, succeeded, attempted_at),
    KEY idx_login_attempts_ip (ip_address, succeeded, attempted_at),
    -- For the prune, which deletes by age alone.
    KEY idx_login_attempts_age (attempted_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

