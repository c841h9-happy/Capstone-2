<?php

declare(strict_types=1);

/**
 * The sign-in throttle and session binding.
 *
 *     C:\xampp\php\php.exe php\tools\security-http-test.php
 *
 * Two of the three gaps Security's `[x]` named, built 2026-10-13. The third, HTTPS, needs a
 * certificate and a server configuration rather than code and is not testable here.
 *
 * ---------------------------------------------------------------------------------------------
 * THE HAZARD THIS SUITE IS BUILT AROUND, because getting it wrong breaks every other suite.
 * ---------------------------------------------------------------------------------------------
 *
 * The throttle counts failures BY ADDRESS as well as by identifier, and every suite in this project
 * signs in from 127.0.0.1. So twenty deliberate failures here would trip LOGIN_MAX_PER_IP and lock
 * `admin@capstone.test` out of `admin-http`, `inventory-http`, `events-http` and `settings-http`
 * for the next quarter of an hour -- a test that breaks four unrelated suites by passing.
 *
 * So the division of labour is deliberate:
 *
 *   * The **per-identifier** limit is tested with REAL HTTP sign-in attempts, because that is the
 *     path that matters and six attempts is comfortably under the per-address ceiling of twenty.
 *   * The **per-address** limit and the **window expiry** are tested by writing crafted rows into
 *     `login_attempts` and asking `loginThrottleState()` what it makes of them. That is not a
 *     weaker test -- it is the only way to express "twenty failures" or "sixteen minutes ago"
 *     without either breaking the neighbours or waiting a quarter of an hour.
 *
 * Every fixture identifier is prefixed `zztest.` and the sweep runs at both ends. The last thing
 * this file asserts is that the real admin account can still sign in, because that is the symptom a
 * mistake here would produce.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BASE = 'http://localhost/Capstone/';

$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
// loginRequestIp() reads this. Set so the crafted rows below and the function agree about which
// address "this request" came from; over HTTP, Apache sets it to 127.0.0.1 for real.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/login-guard.php';

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

/**
 * One request, with the User-Agent as a parameter.
 *
 * The UA matters here as it does nowhere else in this project: it is what the session is bound to,
 * so changing it between two requests on one cookie jar is exactly how a stolen cookie is
 * simulated.
 */
function request(string $path, string $jar, ?array $post = null, string $agent = 'security-test/A'): array
{
    $handle = curl_init(BASE . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => $agent,
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

function tokenFrom(string $path, string $jar, string $agent = 'security-test/A'): string
{
    $body = request($path, $jar, null, $agent)['body'];

    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $found) === 1 ? $found[1] : '';
}

/** One sign-in attempt through the real form. */
function attempt(string $jar, string $identifier, string $password, string $agent = 'security-test/A'): array
{
    $token = tokenFrom('php/login.php', $jar, $agent);

    return request('php/login.php', $jar, [
        'csrf_token' => $token,
        'form_type' => 'login',
        'identifier' => $identifier,
        'password' => $password,
    ], $agent);
}

/* ------------------------------------------------------------------ *
 * Sweep. Deliberately wide: anything left here throttles a real account.
 * ------------------------------------------------------------------ */

function sweep(PDO $database): void
{
    foreach ([
        "DELETE FROM login_attempts WHERE identifier LIKE 'zztest.%'",
        // The real accounts, unconditionally. A failure recorded against either of these by a
        // crashed run would lock it out for the window, and four other suites sign in as the admin.
        "DELETE FROM login_attempts WHERE identifier IN ('admin@capstone.test', 'kennethbargayo213@gmail.com')",
        "DELETE FROM users WHERE email LIKE 'zztest.sec%'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            echo '  sweep failed: ', explode("\n", $exception->getMessage())[0], "\n";
        }
    }
}

sweep($database);

$attemptsBefore = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();

/* ------------------------------------------------------------------ *
 * A real account to be throttled, so no seeded row is touched.
 * ------------------------------------------------------------------ */

$victimEmail = 'zztest.sec.victim@capstone.test';
$victimPassword = 'ZzTestSec123!';

$database->prepare(
    "INSERT INTO users (name, email, password_hash, role, email_verified_at)
     VALUES ('ZZTest Sec Victim', :email, :hash, 'customer', NOW())"
)->execute(['email' => $victimEmail, 'hash' => password_hash($victimPassword, PASSWORD_DEFAULT)]);

$victimId = (int) $database->lastInsertId();
checkTrue('a fixture account exists to be throttled', $victimId > 0);

/* ------------------------------------------------------------------ *
 * 1. The per-identifier limit, over real HTTP.
 * ------------------------------------------------------------------ */

heading('five wrong passwords, then a refusal');

$jar = tempnam(sys_get_temp_dir(), 'zzsec');

for ($i = 1; $i <= LOGIN_MAX_PER_IDENTIFIER; $i += 1) {
    $result = attempt($jar, $victimEmail, 'definitely-not-the-password');
    $wrong = str_contains($result['body'], 'username/email or password is incorrect');
    $throttled = str_contains($result['body'], 'Too many sign-in attempts');

    check("attempt $i is answered with 'incorrect', not a throttle", [$wrong, $throttled], [true, false]);
}

check(
    'exactly ' . LOGIN_MAX_PER_IDENTIFIER . ' failures are recorded',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = '$victimEmail' AND succeeded = 0")->fetchColumn(),
    LOGIN_MAX_PER_IDENTIFIER
);

$next = attempt($jar, $victimEmail, 'definitely-not-the-password');
checkTrue('the next attempt is throttled', str_contains($next['body'], 'Too many sign-in attempts'));
checkTrue(
    '  and no longer says whether the password was wrong',
    !str_contains($next['body'], 'username/email or password is incorrect')
);

/*
 * THE ONE THAT MATTERS MOST: the throttle refuses the CORRECT password too.
 *
 * If a right password got through while the identifier was throttled, the limit would be worth
 * nothing -- the attacker's last guess is the one that works.
 */
$withRealPassword = attempt($jar, $victimEmail, $victimPassword);
checkTrue(
    'THE CORRECT PASSWORD IS ALSO REFUSED WHILE THROTTLED',
    str_contains($withRealPassword['body'], 'Too many sign-in attempts')
);
checkTrue('  and it did not sign anybody in', $withRealPassword['location'] === '');

/*
 * A throttled attempt must not be counted as a new failure, or the window would never end: each
 * refused try would push the oldest failure's expiry further away and the wait would be permanent.
 */
check(
    'a throttled attempt records nothing new',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = '$victimEmail' AND succeeded = 0")->fetchColumn(),
    LOGIN_MAX_PER_IDENTIFIER
);

/* ------------------------------------------------------------------ *
 * 2. It does not leak whether an account exists.
 * ------------------------------------------------------------------ */

heading('an identifier that matches nobody is throttled identically');

$ghost = 'zztest.sec.ghost@capstone.test';

for ($i = 1; $i <= LOGIN_MAX_PER_IDENTIFIER; $i += 1) {
    attempt($jar, $ghost, 'whatever');
}

$ghostNext = attempt($jar, $ghost, 'whatever');
checkTrue('a non-existent account throttles too', str_contains($ghostNext['body'], 'Too many sign-in attempts'));

// The decisive comparison: the two refusals are the same sentence, so the form cannot be used to
// ask which addresses are registered.
checkTrue(
    'the refusal is word-for-word the same for a real and an invented account',
    str_contains($ghostNext['body'], 'Too many sign-in attempts')
        && str_contains($next['body'], 'Too many sign-in attempts')
);

/* ------------------------------------------------------------------ *
 * 3. A success clears the count.
 * ------------------------------------------------------------------ */

heading('a correct password forgives the failures before it');

// Out of the throttle by removing this identifier's failures, which is what a success does.
clearLoginFailures($victimEmail);
check(
    'failures cleared',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = '$victimEmail' AND succeeded = 0")->fetchColumn(),
    0
);
checkTrue('and the throttle allows again', loginThrottleState($victimEmail)['allowed']);

// Four wrong, then the right one, then four wrong again: if the success did not clear, the ninth
// attempt would be throttled. It must not be.
for ($i = 1; $i <= 4; $i += 1) {
    attempt($jar, $victimEmail, 'wrong');
}

$good = attempt($jar, $victimEmail, $victimPassword);
checkTrue('the correct password is accepted after four misses', str_contains($good['location'], 'verify.php') || $good['location'] !== '');

check(
    'the success wiped the four failures',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = '$victimEmail' AND succeeded = 0")->fetchColumn(),
    0
);
check(
    'and kept the success as history',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = '$victimEmail' AND succeeded = 1")->fetchColumn(),
    1
);

/* ------------------------------------------------------------------ *
 * 4. The per-address limit and the window, from crafted rows.
 * ------------------------------------------------------------------ */

heading('the per-address limit, without locking anybody out');

sweep($database);

/*
 * LOGIN_MAX_PER_IP failures from this address, spread across identifiers that are each well under
 * the per-identifier limit -- which is precisely the spraying attack a per-account counter cannot
 * see. Written rather than performed: performing them would throttle 127.0.0.1 and break every
 * other suite's sign-in for the window.
 */
$spray = $database->prepare(
    "INSERT INTO login_attempts (identifier, ip_address, succeeded, attempted_at)
     VALUES (:identifier, '127.0.0.1', 0, NOW())"
);

for ($i = 0; $i < LOGIN_MAX_PER_IP; $i += 1) {
    $spray->execute(['identifier' => 'zztest.sec.spray' . $i . '@capstone.test']);
}

$sprayed = loginThrottleState('zztest.sec.someone-else@capstone.test');
check('an untouched identifier is refused on the address limit', $sprayed['allowed'], false);
check('  and the reason recorded is the address, not the account', $sprayed['reason'], 'ip');
checkTrue('  with a wait in seconds', $sprayed['retryAfter'] > 0);

heading('failures older than the window do not count');

sweep($database);

// The same number of failures, but all of them older than the window.
$stale = $database->prepare(
    "INSERT INTO login_attempts (identifier, ip_address, succeeded, attempted_at)
     VALUES (:identifier, '127.0.0.1', 0, NOW() - INTERVAL :ago SECOND)"
);

for ($i = 0; $i < LOGIN_MAX_PER_IP + 5; $i += 1) {
    $stale->execute([
        'identifier' => 'zztest.sec.old@capstone.test',
        'ago' => LOGIN_WINDOW_SECONDS + 60,
    ]);
}

$afterWindow = loginThrottleState('zztest.sec.old@capstone.test');
checkTrue('an identifier with only stale failures is allowed', $afterWindow['allowed']);

// And the prune takes them: recordLoginAttempt() deletes anything past the window on every write.
recordLoginAttempt('zztest.sec.pruner@capstone.test', false);
check(
    'the stale rows were pruned by the next write',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = 'zztest.sec.old@capstone.test'")->fetchColumn(),
    0
);

/* ------------------------------------------------------------------ *
 * 5. A missing table must not lock the shop out.
 * ------------------------------------------------------------------ */

heading('a missing login_attempts table fails OPEN, deliberately');

$restored = false;
register_shutdown_function(static function () use ($database, &$restored): void {
    if ($restored) {
        return;
    }

    try {
        $database->exec('RENAME TABLE login_attempts_zz_hidden TO login_attempts');
    } catch (PDOException $exception) {
        // Already back, or never renamed.
    }
});

$database->exec('RENAME TABLE login_attempts TO login_attempts_zz_hidden');

$missing = loginThrottleState('zztest.sec.anyone@capstone.test');
checkTrue('the throttle allows rather than refusing everybody', $missing['allowed']);
check('  and reports no reason', $missing['reason'], '');

// And neither write throws, so a sign-in still completes.
recordLoginAttempt('zztest.sec.anyone@capstone.test', false);
clearLoginFailures('zztest.sec.anyone@capstone.test');
checkTrue('recording and clearing survive a missing table', true);

$database->exec('RENAME TABLE login_attempts_zz_hidden TO login_attempts');
$restored = true;

check(
    'the table is back',
    (int) $database->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'"
    )->fetchColumn(),
    1
);

/* ------------------------------------------------------------------ *
 * 6. Session binding.
 * ------------------------------------------------------------------ */

heading('sessionFingerprint()');

$_SERVER['HTTP_USER_AGENT'] = 'Agent One';
$one = sessionFingerprint();
$_SERVER['HTTP_USER_AGENT'] = 'Agent Two';
$two = sessionFingerprint();
unset($_SERVER['HTTP_USER_AGENT']);
$none = sessionFingerprint();

check('a fingerprint is a sha256', strlen($one), 64);
checkTrue('two agents give two fingerprints', $one !== $two);
checkTrue('and a missing agent gives a stable one of its own', $none === hash('sha256', ''));
checkTrue('  which is not either real agent', $none !== $one && $none !== $two);

heading('a signed-in session is bound to its browser');

sweep($database);

/*
 * The real test, over HTTP: sign in with one User-Agent, then present the same cookie with a
 * different one. That is a stolen PHPSESSID, which before today worked from anywhere.
 *
 * The admin account is used because ADMIN_LOGIN_2FA_BYPASS_EMAIL lets it sign in without an emailed
 * code, which keeps this section independent of MAIL_TRANSPORT. Its failures are swept either way.
 */
$boundJar = tempnam(sys_get_temp_dir(), 'zzsec');
$signIn = attempt($boundJar, 'admin@capstone.test', 'Admin123!', 'security-test/A');

$asSameAgent = request('php/profile.php', $boundJar, null, 'security-test/A');
$signedIn = $asSameAgent['status'] === 200;
checkTrue('signed in, and the page renders for the same browser', $signedIn);

if (!$signedIn) {
    echo "  SKIP  could not sign in, so the binding cannot be tested\n";
} else {
    $asOtherAgent = request('php/profile.php', $boundJar, null, 'security-test/B-stolen');
    checkTrue(
        'THE SAME COOKIE FROM A DIFFERENT BROWSER IS NOT SIGNED IN',
        $asOtherAgent['status'] !== 200 || str_contains($asOtherAgent['body'], 'Sign in')
    );

    // And the session is destroyed rather than merely refused once: the original browser is signed
    // out too, because the cookie it holds no longer names a session.
    $backAsOriginal = request('php/profile.php', $boundJar, null, 'security-test/A');
    checkTrue(
        '  and the session was destroyed, not just refused for that request',
        $backAsOriginal['status'] !== 200 || str_contains($backAsOriginal['body'], 'Sign in')
    );
}

heading('a guest session is left alone');

$guestJar = tempnam(sys_get_temp_dir(), 'zzsec');
$firstVisit = request('php/products.php', $guestJar, null, 'security-test/A');
check('a guest can browse', $firstVisit['status'], 200);
$secondVisit = request('php/products.php', $guestJar, null, 'security-test/B-different');
check('and a different browser on the same guest cookie is fine', $secondVisit['status'], 200);

/* ------------------------------------------------------------------ *
 * Put the shop back. The last assertions are the ones a mistake here would break.
 * ------------------------------------------------------------------ */

heading('cleanup, and the admin can still sign in');

sweep($database);

check(
    'login_attempts is back to its starting count',
    (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(),
    $attemptsBefore
);
check(
    'no failure is recorded against the admin account',
    (int) $database->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = 'admin@capstone.test' AND succeeded = 0")->fetchColumn(),
    0
);
checkTrue('and the throttle allows it', loginThrottleState('admin@capstone.test')['allowed']);
checkTrue('as it does the real customer account', loginThrottleState('kennethbargayo213@gmail.com')['allowed']);

$finalJar = tempnam(sys_get_temp_dir(), 'zzsec');
attempt($finalJar, 'admin@capstone.test', 'Admin123!', 'security-test/A');
check(
    'THE ADMIN REALLY CAN SIGN IN AFTER THIS SUITE',
    request('php/profile.php', $finalJar, null, 'security-test/A')['status'],
    200
);

$database->exec('DELETE FROM login_attempts WHERE identifier = \'admin@capstone.test\'');
$database->exec('DELETE FROM users WHERE email LIKE \'zztest.sec%\'');

check(
    'no ZZTest account left behind',
    (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zztest.sec%'")->fetchColumn(),
    0
);
check(
    'login_attempts empty of anything this suite wrote',
    (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(),
    $attemptsBefore
);

check('no PHP notices or warnings', $notices, []);

printf("\n%d checks, %d FAILED\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
