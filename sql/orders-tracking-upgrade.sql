-- Order tracking: the history of where an order has got to. Run against the `capstone`
-- database:
--
--     C:\xampp\mysql\bin\mysql.exe -u root capstone < sql\orders-tracking-upgrade.sql
--
-- Safe to re-run, like every other migration in this folder: CREATE TABLE IF NOT EXISTS.
--
-- Needs sql\orders-upgrade.sql to have been run first, for the `orders` table this hangs off.


-- One row per move an order makes after it is placed.
--
-- **Why a table and not two columns on `orders`.** The question a customer actually asks is
-- "when did you start on it", and the question the shop has to answer weeks later is "what
-- did we tell them, and when". Two columns hold one hand-off; a history is one-to-many, and
-- an order can move more than once -- forward, and back again when somebody ticks the wrong
-- row in the queue. `orders.updated_at` already holds the last time anything changed, which
-- is exactly as much as one timestamp can say, and it cannot say which change it was.
--
-- **Why placement is not in here.** The first dot on the customer's timeline is derived from
-- `orders.placed_at`, not stored as a row. That means no backfill for orders that already
-- exist, no change to placeOrder()'s transaction -- the most delicate write in the project --
-- and no way for the first dot to disagree with the column it came from. Only moves *after*
-- placement are rows.
--
-- **Why payment is not in here either.** php/order.php draws the order's state and its payment
-- state as two panels side by side, and says why in its own comment: they are two clocks that
-- do not run together, since a transfer can land before anything is wrapped and a cash order
-- is delivered before it is paid for. Threading payment through this table would merge the two
-- clocks that page deliberately separates. A payment still sends the customer word -- see
-- php/config/orders-mail.php -- it just does not draw a dot on this ladder.
CREATE TABLE IF NOT EXISTS order_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    -- The state the order arrived *at*, not the transition. The same four words as
    -- orders.status, so the timeline and the pills beside it read from one vocabulary and
    -- orderStatusLabel() serves both. A transition ('started','finished') would be a second
    -- vocabulary to keep in step with the first.
    status        ENUM('placed','packed','delivered','cancelled') NOT NULL,
    -- Who moved it. A customer can only ever cancel their own; everything else is the shop.
    -- Worth a column because "cancelled" is the one state both sides can reach, and the shop
    -- needs to know whether they called it off or the customer did.
    actor         ENUM('customer','shop') NOT NULL,
    -- Which account, when there is one. NULL once that account is deleted: the history has to
    -- outlive the person, which is the whole point of keeping it, so this is ON DELETE SET
    -- NULL rather than CASCADE. `actor` above still says which side it was.
    actor_user_id INT UNSIGNED     NULL,
    happened_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Every read is "this order's events, in order". The id is in the key because that is what
    -- they are sorted by: two moves can land in the same second -- unpack and repack is two
    -- clicks -- and TIMESTAMP has one-second resolution, so happened_at cannot break that tie.
    -- AUTO_INCREMENT can.
    KEY idx_order_events_order (order_id, id),
    -- An event without its order is unreadable, so it goes when the order goes. That is the
    -- opposite of fk_orders_user's RESTRICT, and deliberately: an order is a record the shop
    -- must keep, and a note attached to a deleted record is not.
    CONSTRAINT fk_order_events_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
