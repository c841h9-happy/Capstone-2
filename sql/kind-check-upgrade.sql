-- Replace the kind/product_id CHECK on both item tables. Run against the `capstone` database:
--
--     C:\xampp\mysql\bin\mysql.exe -u root capstone < sql\kind-check-upgrade.sql
--
-- Safe to re-run, like every other migration in this folder: each constraint is dropped by name
-- with IF EXISTS before it is added, so a second run ends where the first one did.
--
--
-- What was wrong with the old constraint
-- --------------------------------------
-- Both item tables carried this, and sql/schema.sql created it for a fresh install too:
--
--     CHECK ((kind = 'product' AND product_id IS NOT NULL) OR (kind = 'design'))
--
-- It enforced the one half that the schema itself breaks, and left unenforced the half its own
-- comment said mattered.
--
-- The half it should never have enforced. product_id is ON DELETE SET NULL on both tables, on
-- purpose: a line freezes its own name and unit_price when it is added, so it survives the
-- product being deleted and can still be shown and totalled. What it loses is the link back,
-- which is a trace and not the receipt. But a 'product' line whose product_id has just been
-- nulled fails "kind = 'product' AND product_id IS NOT NULL" -- so the table's own constraint
-- forbids the exact row the table's own foreign key produces. MariaDB 10.4 does not evaluate
-- CHECK constraints for changes made by a referential action, so the delete goes through and
-- the rows land anyway. Nothing complains at the time. It surfaces later, on a restore from a
-- dump or a move to a server that does evaluate them, as a failure caused by a delete from
-- months earlier.
--
-- The half it never enforced. "OR (kind = 'design')" is unconditional, so a design line could
-- carry any product_id at all. That is precisely the row the old comment warned about -- "a
-- design line carrying a stale product_id would be totalled twice over by a careless join" --
-- and the constraint as written let it straight in.
--
--
-- The replacement
-- ---------------
--     CHECK (kind = 'product' OR product_id IS NULL)
--
-- Read it as: only a product line may name a product. It is strictly stronger where it matters,
-- because a design line can no longer carry a product_id at all, which is what the comment
-- wanted all along. And it cannot be broken by a referential action: nulling the column always
-- satisfies it, whichever kind the line is.
--
-- What it deliberately stops promising is "a product line must name a product". That is not a
-- retreat. The schema cannot promise it -- ON DELETE SET NULL takes it away -- and a constraint
-- a foreign key is allowed to violate is worse than no constraint, because it makes the table
-- dishonest rather than merely permissive. Nothing on the reading side ever depended on it: the
-- frozen name and unit_price are what a line is displayed and totalled from, which is why they
-- are frozen, and both HTTP suites cover a product being deleted out from under a live line.
--
--
-- Before running this, both of these should be zero. If either is not, those rows are the bug
-- this constraint exists to catch, and they want looking at rather than deleting:
--
--     SELECT COUNT(*) FROM cart_items  WHERE kind <> 'product' AND product_id IS NOT NULL;
--     SELECT COUNT(*) FROM order_items WHERE kind <> 'product' AND product_id IS NOT NULL;
--
-- Both were 0 here on 2026-09-23, when this was first run.
--
-- The drop and the add are separate statements rather than one comma-separated ALTER, because
-- naming the same constraint twice inside a single ALTER is not reliably accepted.


ALTER TABLE cart_items
    DROP CONSTRAINT IF EXISTS chk_cart_items_kind;

ALTER TABLE cart_items
    ADD CONSTRAINT chk_cart_items_kind CHECK (kind = 'product' OR product_id IS NULL);


ALTER TABLE order_items
    DROP CONSTRAINT IF EXISTS chk_order_items_kind;

ALTER TABLE order_items
    ADD CONSTRAINT chk_order_items_kind CHECK (kind = 'product' OR product_id IS NULL);
