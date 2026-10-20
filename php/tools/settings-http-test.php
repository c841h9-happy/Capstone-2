<?php

declare(strict_types=1);

/**
 * The settings store, the mail-sender accessors, and the admin screen that writes them.
 *
 *     C:\xampp\php\php.exe php\tools\settings-http-test.php
 *
 * THE ASSERTION THIS SUITE EXISTS FOR is that the app password never reaches the page. Everything
 * else here is ordinary key/value plumbing; that one is a credential, and the failure mode is
 * silent -- a password in a `value` attribute looks identical to an empty field on screen and is
 * sitting in the page source, the browser's autofill and any screenshot of the screen. So the
 * screen is fetched as a signed-in admin with a known password stored, and the body is searched for
 * it.
 *
 * SAFETY. This suite writes to the live mail settings, which is how the shop sends verification
 * codes. Two things make that safe to do:
 *
 *   * every fixture value is obviously fake and prefixed, and
 *   * the sweep DELETES the rows rather than restoring a remembered value -- deleting is what makes
 *     the accessors fall back to php/config/mail.php, so a run that dies half way leaves the shop
 *     on the file's own settings rather than on a test address. There is no state to restore
 *     wrongly.
 *
 * Nothing here prints the real password, and the fixture password is printed nowhere either.
 */

// Same guard as every other file in this folder. php\tools is under the webroot, and this one
// writes the outgoing mail configuration.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BASE = 'http://localhost/Capstone/';

$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/settings.php';

$database = getDatabaseConnection();

$notices = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$notices): bool {
    $notices[] = sprintf('%s in %s:%d', $message, basename($file), $line);
    return true;
});
error_reporting(E_ALL);

$failures = 0;
$checks = 0;

function heading(string $text): void
{
    echo "\n--- $text ---\n";
}

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
        echo "  ok    $label\n";
        return;
    }

    $failures++;
    printf(
        "  FAIL  %s\n          expected: %s\n          actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function checkTrue(string $label, mixed $actual): void
{
    check($label, $actual === true, true);
}

/* ------------------------------------------------------------------ *
 * HTTP harness.
 * ------------------------------------------------------------------ */

function request(string $path, string $jar, ?array $post = null): array
{
    $handle = curl_init(BASE . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => 'settings-http-test',
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    $location = '';

    if (preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $found) === 1) {
        $location = trim($found[1]);
    }

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => $location];
}

function tokenFrom(string $path, string $jar): string
{
    $body = request($path, $jar)['body'];

    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $found) === 1 ? $found[1] : '';
}

/**
 * Sign in as an existing account.
 *
 * php/login.php reads `form_type` and `identifier`, not `action` and `email` -- guessing those
 * costs a whole run, because a wrong-named POST re-renders the form with a 200 and looks exactly
 * like a bad password. Verified by a 200 from a page that only renders for a signed-in account,
 * never by the absence of the words "Sign in" from a body that may be an empty 302.
 */
function signIn(string $jar, string $email, string $password): bool
{
    $token = tokenFrom('php/login.php', $jar);

    if ($token === '') {
        return false;
    }

    $result = request('php/login.php', $jar, [
        'csrf_token' => $token,
        'form_type' => 'login',
        'identifier' => $email,
        'password' => $password,
    ]);

    if (str_contains($result['location'], 'verify.php')) {
        // The code lands in the mail log only on the 'log' transport. With 'smtp' there is nothing
        // to read, so this suite says so rather than failing obscurely.
        return false;
    }

    return request('php/profile.php', $jar)['status'] === 200;
}

/* ------------------------------------------------------------------ *
 * Sweep: delete, never restore. Deleting IS the restore.
 * ------------------------------------------------------------------ */

function sweep(PDO $database): void
{
    foreach ([
        "DELETE FROM settings WHERE name LIKE 'mail.%'",
        "DELETE FROM settings WHERE name LIKE 'zztest.%'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            echo '  sweep failed: ', explode("\n", $exception->getMessage())[0], "\n";
        }
    }

    forgetSettings();
}

sweep($database);

$settingsBefore = (int) $database->query('SELECT COUNT(*) FROM settings')->fetchColumn();

/* ------------------------------------------------------------------ *
 * 1. The store.
 * ------------------------------------------------------------------ */

heading('settingValue() prefers the store and falls back to PHP');

check('an unset setting returns the default', settingValue('zztest.nothing', 'fallback'), 'fallback');
check('and an unset setting with no default is empty', settingValue('zztest.nothing'), '');
check('settingIsStored() says no', settingIsStored('zztest.nothing'), false);

checkTrue('a setting saves', saveSetting('zztest.thing', 'stored value'));
check('and reads back without a cache flush by the caller', settingValue('zztest.thing', 'fallback'), 'stored value');
check('settingIsStored() now says yes', settingIsStored('zztest.thing'), true);

checkTrue('saving again overwrites rather than duplicating', saveSetting('zztest.thing', 'second value'));
check('and the new value is what reads back', settingValue('zztest.thing', 'fallback'), 'second value');
check(
    'exactly one row exists for it',
    (int) $database->query("SELECT COUNT(*) FROM settings WHERE name = 'zztest.thing'")->fetchColumn(),
    1
);

/*
 * Empty means absent, not "stored as nothing". That is what lets a cleared field fall back to the
 * file rather than making the shop send mail from nobody.
 */
checkTrue('saving an empty value removes the row', saveSetting('zztest.thing', ''));
check('so the default comes back', settingValue('zztest.thing', 'fallback'), 'fallback');
check(
    'and no row is left behind',
    (int) $database->query("SELECT COUNT(*) FROM settings WHERE name = 'zztest.thing'")->fetchColumn(),
    0
);

// A value that is only whitespace is the same as empty -- a field somebody cleared with the spacebar.
saveSetting('zztest.blank', '   ');
check('whitespace counts as absent', settingValue('zztest.blank', 'fallback'), 'fallback');

/* ------------------------------------------------------------------ *
 * 2. The mail accessors.
 * ------------------------------------------------------------------ */

heading('the mail sender prefers the store and falls back to mail.php');

sweep($database);

check('with nothing stored, the address is the file const', mailSenderAddress(), GMAIL_ADDRESS);
check('and the from-name is the file const', mailFromName(), MAIL_FROM_NAME);
checkTrue('and the password is whatever the file holds', mailSenderPassword() === GMAIL_APP_PASSWORD);

saveSetting(SETTING_MAIL_SENDER_ADDRESS, 'zztest.sender@example.test');
saveSetting(SETTING_MAIL_FROM_NAME, 'ZZTest Shop Name');

check('a stored address wins', mailSenderAddress(), 'zztest.sender@example.test');
check('a stored from-name wins', mailFromName(), 'ZZTest Shop Name');
checkTrue('and the password is untouched by either', mailSenderPassword() === GMAIL_APP_PASSWORD);

/*
 * The fixture password. Distinctive enough that finding it in a page body is unambiguous, and never
 * printed by this file.
 */
$fixturePassword = 'zztest-app-password-' . bin2hex(random_bytes(6));
saveSetting(SETTING_MAIL_SENDER_PASSWORD, $fixturePassword);

check('a stored password wins', mailSenderPassword(), $fixturePassword);
check('and the file const is no longer what the mailer would use', mailSenderPassword() === GMAIL_APP_PASSWORD, false);

/* ------------------------------------------------------------------ *
 * 3. A missing table costs the feature, not the site.
 * ------------------------------------------------------------------ */

heading('a missing settings table does not take the site down');

/*
 * Renamed out from under a live read, the same technique admin-http-test.php uses for `orders` and
 * for the same reason: DB_NAME is a hard const and getDatabaseConnection() memoises, so there is no
 * seam to point this at a throwaway database.
 *
 * Reversed inline AND in a shutdown function, because a shutdown function runs even on a fatal and
 * a shop whose settings table is called something else would send no mail at all.
 */
$restored = false;
register_shutdown_function(static function () use ($database, &$restored): void {
    if ($restored) {
        return;
    }

    try {
        $database->exec('RENAME TABLE settings_zz_hidden TO settings');
    } catch (PDOException $exception) {
        // Already back, or never renamed.
    }
});

$database->exec('RENAME TABLE settings TO settings_zz_hidden');
forgetSettings();

check('allSettings() returns nothing rather than throwing', allSettings(), []);
check('the address falls back to the file', mailSenderAddress(), GMAIL_ADDRESS);
check('the from-name falls back to the file', mailFromName(), MAIL_FROM_NAME);
checkTrue('saveSetting() reports failure rather than throwing', saveSetting('zztest.thing', 'x') === false);

$database->exec('RENAME TABLE settings_zz_hidden TO settings');
$restored = true;
forgetSettings();

check('the table is back', (int) $database->query('SELECT COUNT(*) FROM settings')->fetchColumn() > 0, true);
check('and the stored address is readable again', mailSenderAddress(), 'zztest.sender@example.test');

/* ------------------------------------------------------------------ *
 * 4. The admin screen. The password must not reach the page.
 * ------------------------------------------------------------------ */

heading('php/admin/settings.php');

$jarAdmin = tempnam(sys_get_temp_dir(), 'zzset');
$jarStranger = tempnam(sys_get_temp_dir(), 'zzset');

$signedIn = signIn($jarAdmin, 'admin@capstone.test', 'Admin123!');
checkTrue('signed in as the shop', $signedIn);

if (!$signedIn) {
    echo "  SKIP  the HTTP section needs the admin account signed in; the rest is skipped\n";
} else {
    $page = request('php/admin/settings.php', $jarAdmin);
    check('the screen answers 200', $page['status'], 200);

    /*
     * THE ONE THAT MATTERS. A stored password is in the database and the screen has just rendered
     * the form that changes it; if it put the value in the input, this finds it.
     */
    checkTrue(
        'THE APP PASSWORD IS NOT ANYWHERE IN THE PAGE BODY',
        !str_contains($page['body'], $fixturePassword)
    );

    checkTrue('it does say a password is saved', str_contains($page['body'], 'A password is saved'));
    checkTrue('the stored address IS shown, because it is not a secret', str_contains($page['body'], 'zztest.sender@example.test'));
    checkTrue('the password field is a password field', str_contains($page['body'], 'type="password"'));
    checkTrue('and carries a CSRF token', str_contains($page['body'], 'name="csrf_token"'));

    // The live transport is reported, so the shop is never guessing which one is on.
    checkTrue(
        'the page says which transport is live',
        str_contains($page['body'], MAIL_TRANSPORT === 'smtp' ? 'Sending for real' : 'Writing to a file')
    );

    $profilePage = request('php/profile.php', $jarAdmin);
    check('the combined admin settings page answers 200', $profilePage['status'], 200);
    checkTrue('the profile page includes its mail card', str_contains($profilePage['body'], 'id="mail-settings"'));
    checkTrue(
        'the mail card follows the account cards',
        strpos($profilePage['body'], 'id="password"') < strpos($profilePage['body'], 'id="mail-settings"')
    );
    checkTrue('the profile mail card never exposes the app password', !str_contains($profilePage['body'], $fixturePassword));

    heading('the guard and the token');

    $asStranger = request('php/admin/settings.php', $jarStranger);
    checkTrue(
        'a signed-out stranger is turned away',
        $asStranger['status'] !== 200 || str_contains($asStranger['body'], 'Sign in')
    );

    $forged = request('php/admin/settings.php', $jarAdmin, [
        'sender_address' => 'forged@example.test',
        'from_name' => 'Forged',
    ]);
    checkTrue('a POST with no token is refused', $forged['status'] !== 200 || str_contains($forged['body'], 'expired'));
    forgetSettings();
    check('and the address is unchanged', mailSenderAddress(), 'zztest.sender@example.test');

    heading('saving through the form');

    $token = tokenFrom('php/profile.php', $jarAdmin);
    $saved = request('php/admin/settings.php?return=profile', $jarAdmin, [
        'csrf_token' => $token,
        'sender_address' => 'zztest.second@example.test',
        'from_name' => 'ZZTest Second',
        'sender_password' => '',
    ]);
    checkTrue('a good save redirects', $saved['status'] === 302 || $saved['status'] === 303);
    checkTrue('a profile save returns to the mail card', str_contains($saved['location'], 'php/profile.php#mail-settings'));
    forgetSettings();
    check('the address changed', mailSenderAddress(), 'zztest.second@example.test');
    check('the from-name changed', mailFromName(), 'ZZTest Second');

    /*
     * An empty password field keeps what is stored. That is the behaviour the whole
     * never-render-it-back design depends on: if a blank submission wiped the password, every edit
     * of the address would silently break sending.
     */
    check('AN EMPTY PASSWORD FIELD KEPT THE STORED PASSWORD', mailSenderPassword(), $fixturePassword);

    heading('what the form refuses');

    foreach ([
        'a blank address' => ['sender_address' => '', 'from_name' => 'ZZTest Second'],
        'an address that is not one' => ['sender_address' => 'not-an-email', 'from_name' => 'ZZTest Second'],
        'a blank sender name' => ['sender_address' => 'zztest.third@example.test', 'from_name' => ''],
        'a newline in the address' => ['sender_address' => "a@b.test\r\nBcc: x@y.test", 'from_name' => 'ZZTest Second'],
    ] as $label => $fields) {
        $token = tokenFrom('php/admin/settings.php', $jarAdmin);
        $refused = request('php/admin/settings.php', $jarAdmin, ['csrf_token' => $token] + $fields);
        check("refused: $label", $refused['status'], 200);
        forgetSettings();
        check("  and nothing changed: $label", mailSenderAddress(), 'zztest.second@example.test');
    }

    heading('forgetting the stored password');

    $token = tokenFrom('php/admin/settings.php', $jarAdmin);
    request('php/admin/settings.php', $jarAdmin, [
        'csrf_token' => $token,
        'action' => 'forget-password',
    ]);
    forgetSettings();
    checkTrue('the password falls back to the file', mailSenderPassword() === GMAIL_APP_PASSWORD);
    check('and the address is left alone', mailSenderAddress(), 'zztest.second@example.test');
}

/* ------------------------------------------------------------------ *
 * Put the shop back.
 * ------------------------------------------------------------------ */

heading('cleanup');

sweep($database);

check(
    'settings is back to its starting count',
    (int) $database->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
    $settingsBefore
);
check('the sender is the file const again', mailSenderAddress(), GMAIL_ADDRESS);
check('the from-name is the file const again', mailFromName(), MAIL_FROM_NAME);
checkTrue('and so is the password', mailSenderPassword() === GMAIL_APP_PASSWORD);
check(
    'no settings_zz_hidden table was left behind',
    (int) $database->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings_zz_hidden'"
    )->fetchColumn(),
    0
);

check('no PHP notices or warnings', $notices, []);

printf("\n%d checks, %d FAILED\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
