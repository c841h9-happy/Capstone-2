-- Upgrade an existing `capstone` database for two-step verification.
--
-- Run once from the XAMPP shell:
--     mysql -u root capstone < sql\2fa-upgrade.sql
-- or paste it into phpMyAdmin's SQL tab with `capstone` selected.
--
-- sql/schema.sql already contains all of this, so a database created from
-- scratch does not need this file. It exists for the one that already has rows
-- in `users`.

USE capstone;

-- IF NOT EXISTS is a MariaDB extension, and it is what makes this whole file safe
-- to run twice: nothing here complains if the upgrade already happened. On MySQL,
-- which does not have it, drop those three words -- and expect a "Duplicate column
-- name" error on a second run, which is harmless.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER password_hash;


CREATE TABLE IF NOT EXISTS email_codes (
    user_id    INT UNSIGNED             NOT NULL,
    purpose    ENUM('signup', 'login')  NOT NULL,
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
