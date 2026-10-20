-- Lets a signed-in account change its own email address, from php/profile.php.
--
--     mysql -u root capstone < sql\email-change-upgrade.sql
--
-- Safe to run twice: MODIFY COLUMN to the value it already has is a no-op, and the
-- ADD COLUMN is guarded by IF NOT EXISTS.

USE capstone;

-- A third reason to email somebody a code.
--
-- 'signup' proves a new account's address and 'login' proves the person with the
-- password also has the mailbox. 'email_change' proves the *new* address before it
-- replaces the old one -- otherwise a typo would move the account to a mailbox
-- nobody can read, and since signing in needs a code from that mailbox, the
-- account would be gone for good.
--
-- The code is sent to the address being claimed, not the one on file. Sending it
-- to the old address would prove the wrong thing.
--
-- MODIFY rather than ALTER ... SET DEFAULT: the whole ENUM definition has to be
-- restated, so the two existing values are repeated here on purpose. Dropping
-- either one would silently invalidate every outstanding sign-in code.
--
-- The primary key stays (user_id, purpose), so an email-change code and a sign-in
-- code can be outstanding at the same time without one replacing the other, and
-- each gets its own resend throttle.
ALTER TABLE email_codes
    MODIFY COLUMN purpose ENUM('signup', 'login', 'email_change') NOT NULL;

-- The address being changed *to*, held from the moment it is requested until the
-- code proves it.
--
-- On the row rather than in the session, so closing the tab or signing out does
-- not lose a change that is half done -- the code in the mailbox would still be
-- valid with nothing left to apply it to.
--
-- Deliberately NOT UNIQUE. Two accounts may both claim the same address, because a
-- claim is not ownership; whichever one confirms first takes it on the existing
-- uq_users_email, and the second gets told the address was taken in the meantime.
-- A UNIQUE index here would instead let anyone block an address from being used by
-- claiming it and never confirming.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS pending_email VARCHAR(255) NULL DEFAULT NULL AFTER email;
