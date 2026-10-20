-- Orders: the basket, frozen. Run against the `capstone` database:
--
--     C:\xampp\mysql\bin\mysql.exe -u root capstone < sql\orders-upgrade.sql
--
-- Safe to re-run, like every other migration in this folder: CREATE TABLE IF NOT EXISTS.
--
-- Three tables mirroring the basket's three, and that is the point rather than an accident.
-- sql/cart-upgrade.sql froze a name and a price onto every basket line so that a shop-side edit
-- could not re-price something already chosen. An order is the same promise made harder: the
-- basket may still be emptied and refilled, and an order may not. So placing one copies the
-- frozen lines across rather than pointing at them, and the basket is emptied in the same
-- transaction. See placeOrder() in php/config/orders.php.
--
-- What is deliberately NOT here:
--
--   * No delivery fee column. php/cart.php has said "Delivery is arranged after you order, so it
--     is not in this figure yet" since the basket was built, and that is still true -- the shop
--     quotes carriage once it knows where a bouquet is going. A column that nothing writes is a
--     value that is never read and still wrong in the table, which is the argument
--     sql/components-shape-upgrade.sql already makes about defaulting a length of wire to 'rose'.
--     When the Orders module quotes carriage it can add the column and the breakdown together.
--
--   * No stock or reservation columns. Inventory is its own module and nothing here pretends to
--     hold anything back. Two customers can order the last bouquet, and the shop sorts that out
--     the way a handicraft shop already does -- by making another one.


-- One order.
--
-- reference is what a customer quotes in a message, so it is generated rather than derived from
-- the id: a sequential number tells whoever reads it how many orders the shop has taken and
-- invites a guess at the one next door. SLJ-20260917-K4M9 -- a date somebody can say out loud and
-- four characters from an alphabet with no 0/O or 1/I in it. UNIQUE, and orderReference() retries
-- on a clash rather than trusting 1.6 million combinations per day to never collide.
--
-- Two status columns rather than one, because an order has two clocks that do not run together: a
-- GCash transfer can land before anything is wrapped, and a cash-on-delivery bouquet is packed,
-- delivered and only then paid for. One column would have to invent 'packed-but-unpaid'.
--
-- **This module writes 'placed' and 'cancelled' only**, plus the two payment states below.
-- 'packed' and 'delivered' are the Orders module's to drive, and they are in the ENUM now because
-- the vocabulary of a lifecycle is one decision -- adding a value later is an ALTER on a table
-- with rows in it, and a status spelt differently in two places is how an order goes missing.
CREATE TABLE IF NOT EXISTS orders (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference         CHAR(17)     NOT NULL,
    -- The account. An order always has one -- that was the decision recorded when the basket was
    -- built: a visitor may fill a basket, and is asked for an account at checkout.
    --
    -- RESTRICT rather than CASCADE, unlike carts. A basket is not a record anybody needs to keep
    -- and an order is: it is what was agreed, for money. Nothing in this project deletes an
    -- account today, so this costs nothing now and refuses to lose the shop's books later --
    -- whoever adds account deletion has to decide what happens to the orders first, which is
    -- exactly the decision that should not be made silently by a foreign key.
    user_id           INT UNSIGNED NOT NULL,
    status            ENUM('placed','packed','delivered','cancelled') NOT NULL DEFAULT 'placed',
    -- 'unpaid'    -- cash on delivery, or a transfer not sent yet. Nothing to check.
    -- 'submitted' -- the customer has typed a GCash or bank reference and it has not been checked.
    -- 'paid'      -- the shop has looked at its own account and agrees the money arrived.
    --
    -- The shop confirms it by hand and that is not a shortcut: this project has no payment
    -- gateway and no webhook to be told by, so the only honest source of truth is somebody
    -- looking at the receiving account. A status that said 'paid' because a customer typed a
    -- reference would be a lie the shop found out about at delivery.
    payment_status    ENUM('unpaid','submitted','paid') NOT NULL DEFAULT 'unpaid',
    payment_method    ENUM('cod','gcash','bank') NOT NULL,
    -- The transfer reference the customer read off their own GCash or bank app. Free text and
    -- deliberately not validated into a shape: every provider numbers its own way, and a
    -- reference refused for not matching a regex is a customer who cannot pay.
    payment_reference VARCHAR(60)  NOT NULL DEFAULT '',
    fulfilment        ENUM('delivery','pickup') NOT NULL,
    -- Who to hand it to and how to reach them. Frozen on the order rather than read from users,
    -- for the reason every other frozen column here exists: the account's name and email can
    -- change afterwards -- php/profile.php changes both -- and a receipt that rewrites itself is
    -- not a receipt. The email is also where the Orders module will send word, so it needs to be
    -- the address that was current when the order was placed.
    contact_name      VARCHAR(100) NOT NULL,
    contact_phone     VARCHAR(30)  NOT NULL,
    contact_email     VARCHAR(255) NOT NULL,
    -- Where it goes, for a delivery. All five are empty on a pickup, which the CHECK below
    -- enforces from the other side: a pickup with a half-typed address in it is a row that cannot
    -- be read either way.
    address_line      VARCHAR(160) NOT NULL DEFAULT '',
    barangay          VARCHAR(80)  NOT NULL DEFAULT '',
    city              VARCHAR(80)  NOT NULL DEFAULT '',
    province          VARCHAR(80)  NOT NULL DEFAULT '',
    -- Optional even on a delivery. Plenty of people do not know their own postcode, and a
    -- required field they cannot fill is a checkout they abandon.
    postcode          VARCHAR(10)  NOT NULL DEFAULT '',
    -- "Leave it with the guard", "for a birthday on the 20th". The shop's to read, nobody's to
    -- parse.
    notes             VARCHAR(300) NOT NULL DEFAULT '',
    -- What it came to, frozen. Recomputed from the frozen line prices at the moment of placing
    -- and never taken from the form: a total in a hidden field is the customer's to edit.
    total             DECIMAL(10,2) UNSIGNED NOT NULL,
    placed_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_reference (reference),
    -- The customer's own list, newest first, which is the only way php/orders.php reads them.
    KEY idx_orders_user (user_id, placed_at, id),
    -- The admin's list, which reads by state and then by age.
    KEY idx_orders_status (status, placed_at),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    -- A delivery must say where; a pickup must not. Without this a pickup could carry a stale
    -- address from a form the customer changed their mind on halfway through, and the shop would
    -- have two contradictory instructions on one row.
    CONSTRAINT chk_orders_address CHECK (
        (fulfilment = 'delivery'
            AND address_line <> '' AND barangay <> '' AND city <> '' AND province <> '')
        OR (fulfilment = 'pickup'
            AND address_line = '' AND barangay = '' AND city = '' AND province = ''
            AND postcode = '')
    ),
    -- A reference belongs to a transfer. Cash on delivery has nothing to reference, and a
    -- reference sitting on a COD order is somebody's GCash number in a field nobody will read.
    CONSTRAINT chk_orders_payment_reference CHECK (
        (payment_method = 'cod' AND payment_reference = '')
        OR payment_method <> 'cod'
    )
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- One line of an order: a catalogue product or a designed bouquet, copied from the basket line it
-- came from with its name and price already frozen.
--
-- The columns are cart_items' columns, minus the two that stop meaning anything once the order
-- exists (cart_id, and its updated_at -- a line of an order does not get updated) and plus
-- line_total.
--
-- line_total is stored here although cart_items computes it, and that is a considered difference.
-- cartLineTotal() multiplies in PHP with integer centavos because a basket is re-read and
-- re-totalled on every page load, so there is nothing to disagree with. An order is read years
-- later by whatever code exists then, and the number that was agreed should not depend on that
-- code multiplying the same way. It is written once, from the same helper, and checked against
-- the order's total by the test suite.
--
-- product_id is ON DELETE SET NULL for exactly the reason cart_items gives: the line keeps its
-- own name and price, so it survives the product being deleted and can still be shown and
-- totalled. What it loses is the link back, which is a trace and not the receipt.
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    kind        ENUM('product','design') NOT NULL,
    product_id  INT UNSIGNED     NULL,
    name        VARCHAR(120) NOT NULL,
    unit_price  DECIMAL(10,2) UNSIGNED NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    line_total  DECIMAL(10,2) UNSIGNED NOT NULL,
    arrangement ENUM('dome','round','loose') NOT NULL DEFAULT 'dome',
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id, id),
    KEY fk_order_items_product (product_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    -- Only a product line may name a product. Not also "a product line must have one": the key
    -- above nulls product_id when a product is deleted, and MariaDB 10.4 does not run CHECK
    -- constraints for changes made by a referential action, so that half would be broken by this
    -- table's own foreign key. sql/kind-check-upgrade.sql carries the full reasoning.
    CONSTRAINT chk_order_items_kind CHECK (kind = 'product' OR product_id IS NULL)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- What a designed bouquet on an order is made of. cart_item_parts, copied.
--
-- The shop reads this to know what to make: four rows saying Blush Rose Head x6, Green Chenille
-- Stem x6, Kraft Paper Sleeve, Jute Twine Tie is the build instruction as well as the receipt.
-- That is why colour_hex is carried across too -- somebody at the table needs to know which pink.
CREATE TABLE IF NOT EXISTS order_item_parts (
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
    KEY idx_order_item_parts_item (item_id, sort_order, id),
    KEY fk_order_item_parts_component (component_id),
    CONSTRAINT fk_order_item_parts_item
        FOREIGN KEY (item_id) REFERENCES order_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_parts_component
        FOREIGN KEY (component_id) REFERENCES product_components (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
