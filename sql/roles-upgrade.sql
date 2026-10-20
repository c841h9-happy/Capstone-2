-- Add customer/admin roles to an existing `capstone` database.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\roles-upgrade.sql
-- or paste it into phpMyAdmin's SQL tab with `capstone` selected.
--
-- sql/schema.sql already contains this column, so a database created from scratch
-- does not need this file. It exists for the one that already has rows in `users`.
--
-- Nobody is promoted by this file. Every existing account stays a customer; make
-- the first admin afterwards with
--     php php/tools/make-admin.php you@example.com

USE capstone;

-- ENUM rather than VARCHAR so a typo like 'Admin' or 'adminn' is refused by the
-- database instead of quietly becoming a role that matches nothing. Two roles do
-- not warrant a separate roles table -- if a third ever needs its own set of
-- permissions rather than just a name, that is the point to revisit this.
--
-- DEFAULT 'customer' is load-bearing: the sign-up INSERT in php/login.php does not
-- mention this column, so every account created through the form is a customer
-- without that code having to say so. New privileges should never be the default
-- that arrives by omission.
--
-- IF NOT EXISTS is a MariaDB extension, and it is what makes this file safe to run
-- twice. On MySQL, drop those three words and expect a harmless "Duplicate column
-- name" error on a second run.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role ENUM('customer', 'admin') NOT NULL DEFAULT 'customer' AFTER password_hash;
