-- The basket. Run against the `capstone` database:
--
--     C:\xampp\mysql\bin\mysql.exe -u root capstone < sql\cart-upgrade.sql
--
-- Safe to re-run, like every other migration in this folder: CREATE TABLE IF NOT EXISTS.
--
-- Three tables, and the reason for three rather than one is the same reason saved_designs
-- needed two. A basket line is either a catalogue product or a bouquet somebody designed, and
-- a designed bouquet is a list of parts with quantities. That does not flatten into a column
-- without a comma-separated list in it, and a list in a column cannot be joined against,
-- counted, or repaired when a part is withdrawn.


-- One basket. Owned by an account, or by a browser that has not signed in yet.
--
-- Exactly one of user_id and token is set, which is what the CHECK enforces. A guest basket
-- carries a random token matching a cookie; signing in moves its lines into the account's
-- basket and deletes it. That merge is in mergeGuestCart() in php/config/cart.php, called from
-- signInUser().
--
-- Why a table at all, rather than keeping a guest basket in the session: a session is thrown
-- away on a schedule this project does not control, and session_regenerate_id() runs at
-- sign-in -- which is the exact moment the basket has to survive. A row keyed by a cookie
-- token survives both, and gives the merge something to move rather than something to copy out
-- of an array that may already be gone.
CREATE TABLE IF NOT EXISTS carts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- The owner, once there is one. NULL while the basket belongs to a browser.
    user_id    INT UNSIGNED     NULL,
    -- The guest's cookie token, hashed. Hashed rather than stored plain for the reason
    -- devices.php gives: sha256 because it has to be searchable, which is safe here because
    -- the token is 32 bytes from the system random source and has no small set of likely
    -- values. NULL once the basket has an account.
    token_hash CHAR(64)         NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One basket per account. The merge relies on this: it finds the account's basket or
    -- creates it, and cannot accidentally leave two behind.
    UNIQUE KEY uq_carts_user (user_id),
    UNIQUE KEY uq_carts_token (token_hash),
    -- Deleting an account takes its basket. A basket is not a record anybody needs to keep.
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    -- Owned by exactly one of the two. A row with neither is unreachable; a row with both is
    -- two baskets wearing one id, and the merge would have no way to say which it moved.
    CONSTRAINT chk_carts_owner CHECK (
        (user_id IS NOT NULL AND token_hash IS NULL)
        OR (user_id IS NULL AND token_hash IS NOT NULL)
    )
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- One line in a basket: either a catalogue product or a designed bouquet.
--
-- **Prices are frozen here.** This is the point php/config/customizer.php's designSnapshot()
-- docblock names: "an order is a contract, so the prices in it have to stop tracking
-- product_components, and the copy has to happen exactly once, at a known point". Adding to the
-- basket is that point. Everything before it -- the builder, a saved design -- re-prices live,
-- which is why saved_designs has no money column and this table does.
--
-- product_id is ON DELETE SET NULL rather than CASCADE for the same reason: a line is a record
-- of what was agreed, and it keeps its own name and price, so it survives the product being
-- deleted and can still be shown and totalled. What it cannot do is be re-added, which
-- cartLineIsOrderable() is for.
CREATE TABLE IF NOT EXISTS cart_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id      INT UNSIGNED NOT NULL,
    -- Which of the two shapes this line is. A designed bouquet has no product_id and has part
    -- rows; a catalogue product has a product_id and none.
    kind         ENUM('product','design') NOT NULL,
    -- The catalogue product, for a 'product' line. NULL for a design.
    product_id   INT UNSIGNED     NULL,
    -- What it was called when it was added. Frozen, like the price: a basket that renames
    -- itself when the shop renames a product is a basket that cannot be reconciled later.
    name         VARCHAR(120) NOT NULL,
    -- Money, frozen. DECIMAL and never FLOAT, like every other price column here.
    -- For a design this is the whole bouquet's price, so quantity is bouquets, not stems.
    unit_price   DECIMAL(10,2) UNSIGNED NOT NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Renderer-only, and only meaningful on a design line. Kept so the basket can say "round"
    -- and so a re-open of the design draws what was actually added.
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
    -- Only a product line may name a product. A design line carrying a stale product_id would
    -- be totalled twice over by a careless join, and this is what stops it.
    --
    -- It deliberately does not also demand that a product line HAS a product_id. The foreign
    -- key above nulls that column when a product is deleted, and MariaDB 10.4 does not run
    -- CHECK constraints for changes made by a referential action -- so that half would be
    -- quietly broken by this table's own key, leaving rows the table says cannot exist.
    -- sql/kind-check-upgrade.sql replaces the earlier version of this line on an install that
    -- already exists, and carries the full reasoning.
    CONSTRAINT chk_cart_items_kind CHECK (kind = 'product' OR product_id IS NULL)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- The parts of a designed basket line, frozen at the moment it was added.
--
-- This is designSnapshot()'s 'lines' array given a table to live in. Each row is one part --
-- a bloom, the wire, the wrap, the accent -- with the name and the price it had then.
--
-- component_id travels with the name for the reason the snapshot docblock gives: "a frozen
-- line saying 'Blush Rose Head, 38.00' with no id cannot be traced back to the part it was,
-- once the shop has renamed it. A frozen line is a receipt, and a receipt that cannot be
-- reconciled is decoration." ON DELETE SET NULL, so deleting the part loses the trace and
-- keeps the receipt.
--
-- colour_hex is frozen too, so the basket can draw its swatches without reading
-- product_components back -- which is exactly the re-read designSnapshot() exists to prevent.
CREATE TABLE IF NOT EXISTS cart_item_parts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id      INT UNSIGNED NOT NULL,
    component_id INT UNSIGNED     NULL,
    -- Which kind of part this was. Not read back from the component, because the component may
    -- be gone -- and a receipt that cannot say whether a missing part was the wrap or a flower
    -- is not much of a receipt.
    kind         ENUM('bloom','wire','wrap','accent') NOT NULL,
    name         VARCHAR(120) NOT NULL,
    colour_hex   CHAR(7)      NOT NULL DEFAULT '#cccccc',
    -- Price of one of this part, frozen. quantity is stems for a bloom or a wire, and 1 for
    -- the wrap and the accent -- a bouquet has one wrapper however many flowers are in it.
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
