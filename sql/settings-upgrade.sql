-- A small key/value store for settings the shop can change without editing PHP.
--
--     mysql -u root capstone < sql\settings-upgrade.sql
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS, no backfill, no seed rows. A second immediate run
-- changes nothing and exits 0. Mirrored into sql/schema.sql so a fresh install matches a migrated
-- one.
--
-- WHY A KEY/VALUE TABLE AND NOT COLUMNS. There is exactly one thing in here today -- the mail
-- sender -- and a `mail_settings` table with three named columns would be a better shape for it.
-- This is deliberately the looser one, because the reason it exists is that `php/config/mail.php`
-- is a file full of `const`s that somebody has to edit by hand to change anything, and the next
-- setting the shop wants to own will not be about mail. One table, one row per setting, and
-- php/config/settings.php is the only thing that reads it.
--
-- NOTHING IN HERE IS REQUIRED. Every setting has a default in PHP and the store is consulted first;
-- an empty table, or a missing one, means the project behaves exactly as it did before this file
-- existed. That is what makes the migration safe to apply to a running shop and what makes
-- settingValue() able to swallow a missing table without blanking the site.
--
-- -------------------------------------------------------------------------------------------
-- A WARNING THAT BELONGS WITH THE SCHEMA, not only in the admin screen.
--
-- One of the values this table is designed to hold is `mail.sender_password` -- a Gmail app
-- password. Until 2026-10-11 that credential lived only in php/config/mail.php, which .gitignore
-- excludes, so it could not leave the machine in a commit. Putting it here makes the shop able to
-- change the sending account without editing code, which is what it is for, and it also means:
--
--   * a database dump now contains a live credential, where before it did not;
--   * anything that can read this table can read it.
--
-- It is stored as given, not hashed, because SMTP AUTH has to present the actual password -- a hash
-- would make the feature not work rather than make it safer. php/admin/settings.php never renders
-- it back to the page, and no tool in php/tools/ prints it. Treat a dump of this database the way
-- php/config/mail.php's own header says to treat that file.
-- -------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
    -- Dotted names, so a glance at the table says which feature owns which row:
    -- 'mail.sender_address', 'mail.sender_password', 'mail.from_name'.
    name       VARCHAR(64)  NOT NULL,
    -- TEXT rather than VARCHAR: the next setting might be a sentence, and a width guessed now is a
    -- migration later. Never NULL -- an absent setting is an absent ROW, which is what lets
    -- settingValue() fall back to the PHP default without having to tell "unset" from "set to
    -- nothing".
    value      TEXT         NOT NULL,
    -- Who last changed it and when, because a setting that silently differs from the file somebody
    -- is reading is the whole hazard this table introduces.
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,

    PRIMARY KEY (name),
    -- ON DELETE SET NULL, the receipt pattern: the record of a change outlives the account that
    -- made it, and deleting a staff account must not be refused because they once saved a setting.
    CONSTRAINT fk_settings_user
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Proof for whoever runs this by hand. Deliberately does NOT select `value`: one of the rows this
-- table is for is a password, and a migration that prints it to a terminal and a scrollback buffer
-- would undo the care taken everywhere else.
SELECT name, updated_at, updated_by, CHAR_LENGTH(value) AS value_length
FROM settings
ORDER BY name;
