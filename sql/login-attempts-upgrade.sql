-- Login attempt records, so a password cannot be guessed at machine speed.
--
--     mysql -u root capstone < sql\login-attempts-upgrade.sql
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS, no backfill, no seed rows. A second immediate run
-- changes nothing and exits 0. Mirrored into sql/schema.sql so a fresh install matches a migrated
-- one.
--
-- WHY A TABLE OF ATTEMPTS AND NOT A COUNTER ON `users`.
--
-- A counter column answers "how many times has this account been got wrong" and nothing else. Three
-- things this project needs are not answerable from it:
--
--   * **An account that does not exist still has to be throttled.** Otherwise the response time and
--     the lockout behaviour differ between a real account and an invented one, and the form becomes
--     a way to ask "is bob@example.com registered here" a thousand times a minute. A row keyed on
--     the typed identifier works whether or not it matches anybody.
--   * **Spraying one password across many accounts** never trips a per-account counter. Counting by
--     address catches it.
--   * **A window, not a total.** Five wrong tries last March should not lock anybody out today, and
--     a counter has no memory of when.
--
-- WHAT IS DELIBERATELY NOT DONE: there is no `users.locked_until`, and a wrong password never
-- disables an account. Locking the account would hand anybody who knows an email address the power
-- to lock its owner out -- the throttle would become the attack. What is limited here is the rate of
-- GUESSING, by identifier and by address, and it expires on its own.
--
-- PRIVACY. `ip_address` is a VARCHAR(45) -- long enough for IPv6 -- and `identifier` holds whatever
-- was typed into the sign-in box, which is usually an email address. Both are personal data and
-- neither is needed once the window has passed, so pruneLoginAttempts() deletes rows older than the
-- window on every write. Nothing in php/tools/ prints either column.

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- What was typed in the username/email box, lowercased so "Bob@x.com" and "bob@x.com" are one
    -- account's worth of attempts rather than two. NOT a foreign key to users: the whole point is
    -- that it has to work for an identifier that matches nobody.
    identifier   VARCHAR(255)    NOT NULL,
    -- REMOTE_ADDR as the server saw it. 45 characters is the maximum length of a text IPv6 address
    -- including an IPv4-mapped suffix.
    ip_address   VARCHAR(45)     NOT NULL,
    -- Successes are recorded too, not just failures. Two reasons: a successful sign-in is what
    -- clears the identifier's failures, and keeping both makes the table readable as a history
    -- rather than as a grudge.
    succeeded    TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- The two reads the throttle makes, each covered by its own index: failures for this identifier
    -- inside the window, and failures from this address inside the window. succeeded is in both so
    -- the count never has to touch a row it will discard.
    KEY idx_login_attempts_identifier (identifier, succeeded, attempted_at),
    KEY idx_login_attempts_ip (ip_address, succeeded, attempted_at),
    -- For the prune, which deletes by age alone.
    KEY idx_login_attempts_age (attempted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Proof for whoever runs this by hand. Counts only: the identifier column holds email addresses and
-- a migration that printed them to a terminal and a scrollback buffer would be the wrong trade.
SELECT COUNT(*) AS rows_now,
       SUM(succeeded = 0) AS failures,
       SUM(succeeded = 1) AS successes
FROM login_attempts;
