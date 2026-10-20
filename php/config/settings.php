<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Settings the shop can change without editing PHP.
 *
 * A thin reader and writer over the `settings` table (sql/settings-upgrade.sql). Today it holds the
 * outgoing mail sender; the point of a key/value store rather than named columns is that the next
 * setting the shop wants to own will not be about mail.
 *
 * THE CONTRACT, which everything else here depends on: **every setting has a default in PHP and the
 * store is consulted first.** An empty table, or no table at all, means the project behaves exactly
 * as it did before this file existed. That is what makes the migration safe to apply to a running
 * shop, and it is why settingValue() swallows a PDOException rather than letting one reach a page:
 * these are read on the sign-in path, and a missing migration must cost the feature rather than the
 * ability to log in.
 *
 * MAIL_TRANSPORT IS DELIBERATELY NOT IN HERE. Switching between 'log' and real sending changes
 * whether the shop's verification codes reach anybody, and it is the one mail setting where getting
 * it wrong locks every customer out of their own account. It stays a `const` in
 * php/config/mail.php, where changing it is a deliberate act by somebody looking at the file's
 * instructions.
 */

/** The names this file knows about. Not a constraint on the table -- a list of what is read. */
const SETTING_MAIL_SENDER_ADDRESS = 'mail.sender_address';
const SETTING_MAIL_SENDER_PASSWORD = 'mail.sender_password';
const SETTING_MAIL_FROM_NAME = 'mail.from_name';

/**
 * Every stored setting, as name => value, read once per request.
 *
 * One query rather than one per setting: the mailer asks for three of these to send a single
 * message, and the sign-in path sends one on every attempt.
 *
 * A missing table returns [] and says so in the error log once per request. Anything else -- a
 * syntax error in here, a connection failure -- is a PDOException too, which is the cost of
 * catching broadly on a read this important; the log line is what makes it findable.
 */
function allSettings(bool $forget = false): array
{
    static $settings = null;

    if ($forget) {
        $settings = null;
    }

    if ($settings !== null) {
        return $settings;
    }

    $settings = [];

    try {
        $rows = getDatabaseConnection()->query('SELECT name, value FROM settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[(string) $row['name']] = (string) $row['value'];
        }
    } catch (PDOException $exception) {
        error_log('settings.php: could not read settings (has sql/settings-upgrade.sql been run?) ' . $exception->getMessage());
        $settings = [];
    }

    return $settings;
}

/**
 * Drop the per-request cache.
 *
 * For the admin screen, which saves and then re-renders in the same request, and for the test
 * suite. Without it a page that had already read a setting would show the old value back to the
 * person who just changed it -- which looks exactly like a save that did not work.
 */
function forgetSettings(): void
{
    allSettings(true);
}

/**
 * One setting, or $default when it is not stored or is stored empty.
 *
 * Empty counts as absent on purpose. A blank sender address is not a choice somebody made, it is a
 * field they cleared, and falling back to the file's value is kinder than sending mail from nobody.
 */
function settingValue(string $name, string $default = ''): string
{
    $stored = allSettings()[$name] ?? '';

    return trim($stored) === '' ? $default : $stored;
}

/** Is there a stored value for this setting, whatever it is? */
function settingIsStored(string $name): bool
{
    return trim(allSettings()[$name] ?? '') !== '';
}

/**
 * Store one setting. Pass '' to remove it and fall back to the PHP default.
 *
 * INSERT ... ON DUPLICATE KEY UPDATE rather than a SELECT and a branch: the primary key on `name`
 * makes it one statement, and two admins saving at the same instant cannot produce two rows or lose
 * one. `updated_at` moves on its own through ON UPDATE CURRENT_TIMESTAMP.
 *
 * Clears the per-request cache, so the caller reads back what it just wrote.
 */
function saveSetting(string $name, string $value, ?int $userId = null): bool
{
    $value = trim($value);

    try {
        if ($value === '') {
            $statement = getDatabaseConnection()->prepare('DELETE FROM settings WHERE name = :name');
            $statement->execute(['name' => $name]);
            forgetSettings();

            return true;
        }

        $statement = getDatabaseConnection()->prepare(
            'INSERT INTO settings (name, value, updated_by)
             VALUES (:name, :value, :user)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
        );
        $statement->bindValue('name', $name);
        $statement->bindValue('value', $value);

        // PARAM_NULL and not (int) 0: there is no user 0, and 0 would be a foreign key violation
        // the first time a setting was saved by something without an account.
        if ($userId === null || $userId <= 0) {
            $statement->bindValue('user', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue('user', $userId, PDO::PARAM_INT);
        }

        $statement->execute();
        forgetSettings();

        return true;
    } catch (PDOException $exception) {
        error_log('settings.php: could not save ' . $name . ': ' . $exception->getMessage());

        return false;
    }
}

/** When a setting was last changed, as a timestamp string, or '' if it is not stored. */
function settingUpdatedAt(string $name): string
{
    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT updated_at FROM settings WHERE name = :name LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $value = $statement->fetchColumn();

        return $value === false ? '' : (string) $value;
    } catch (PDOException $exception) {
        error_log('settings.php: could not read updated_at for ' . $name . ': ' . $exception->getMessage());

        return '';
    }
}

/* ------------------------------------------------------------------ *
 * The mail sender, which is what this file exists for today.
 * ------------------------------------------------------------------ *
 *
 * Each of these reads the store and falls back to the `const` in php/config/mail.php, so the file
 * stays the documented default and the admin screen is an override. php/config/mailer.php calls
 * these three instead of reading the constants directly -- that swap is the whole integration.
 */

/** The address outgoing mail is sent from and authenticates as. */
function mailSenderAddress(): string
{
    return settingValue(SETTING_MAIL_SENDER_ADDRESS, defined('GMAIL_ADDRESS') ? GMAIL_ADDRESS : '');
}

/**
 * The app password for that address.
 *
 * NEVER RENDER THIS. php/admin/settings.php shows whether one is saved and when, and offers an
 * empty box to replace it; it does not put the value in an input, because a password in a `value`
 * attribute is a password in the page source, in the browser's autofill, and in any screenshot of
 * the screen. Nothing in php/tools/ prints it either.
 */
function mailSenderPassword(): string
{
    return settingValue(SETTING_MAIL_SENDER_PASSWORD, defined('GMAIL_APP_PASSWORD') ? GMAIL_APP_PASSWORD : '');
}

/** The name a recipient sees in their inbox. */
function mailFromName(): string
{
    return settingValue(SETTING_MAIL_FROM_NAME, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Website');
}
