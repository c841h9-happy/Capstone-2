<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/twofactor.php';
require_once __DIR__ . '/config/devices.php';
// Loaded for its side effect on signInUser(): with cart.php in memory, the guest basket this
// browser filled before signing in is merged onto the account. Without it the sign-in still
// works and the basket is simply left behind, which is the wrong half of the bargain.
require_once __DIR__ . '/config/cart.php';
require_once __DIR__ . '/config/csrf.php';
// The sign-in throttle. ensureSession() already pulls this in for the session-binding check, but
// this page calls loginThrottleState(), recordLoginAttempt() and clearLoginFailures() directly, and
// a page should require what it uses rather than rely on another file having loaded it first.
require_once __DIR__ . '/config/login-guard.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/password-field.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

$loginErrors = [];
$signupErrors = [];
$identifier = '';
$name = '';
$email = '';

// Which panel is showing. A POST always reopens the form that was submitted;
// otherwise ?form=signup lets other pages link straight to the sign-up panel.
$activeForm = ($_GET['form'] ?? '') === 'signup' ? 'signup' : 'login';

// "Start over" on the verification page comes back here, so drop the half-finished
// challenge rather than leaving it to be resumed by the next visitor to this browser.
if (isset($_GET['restart'])) {
    unset($_SESSION['pending_2fa']);
    redirectTo(url('php/login.php'));
}

/**
 * Park the account on the session and send them to the code page.
 *
 * Nothing about being signed in is set here -- that only happens in verify.php,
 * once the code has been typed in. Until then the session holds a claim, not a
 * privilege.
 */
function startVerification(int $userId, string $purpose, string $email, string $name): never
{
    $result = issueEmailCode($userId, $purpose, $email);

    $_SESSION['pending_2fa'] = [
        'user_id' => $userId,
        'purpose' => $purpose,
        'email' => $email,
        'name' => $name,
        // Lets verify.php announce the send once, without saying it again on every
        // wrong code.
        'just_sent' => $result['sent'],
    ];

    // A failed send still goes to verify.php: the code exists, and that page has
    // the Resend button. Stranding them here with no way forward would be worse.
    if (!$result['sent'] && $result['error'] !== null) {
        $_SESSION['pending_2fa']['send_error'] = $result['error'];
    }

    redirectTo(url('php/verify.php'));
}

/**
 * Take over a sign-up that was started but never confirmed.
 *
 * An abandoned sign-up leaves a row holding that email address, so a second
 * attempt hits the UNIQUE index and today's "already exists" message -- a dead
 * end, because the first attempt never worked either and there is no password
 * reset here.
 *
 * An unverified row is a claim on an address, not an account: nobody has yet
 * shown they can read that mailbox, so nothing has been earned. The claim is
 * therefore handed to the latest attempt, name and password included, and the
 * code still has to be typed in before any of it can be used. Someone who does
 * not own the mailbox gains nothing by overwriting a pending row they cannot
 * finish.
 *
 * Returns false when there is nothing to resume, so the caller can go on to
 * report the clash as before. Never returns on success -- it redirects.
 */
function resumeUnverifiedSignup(string $email, string $name, string $passwordHash): bool
{
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'SELECT id FROM users WHERE email = :email AND email_verified_at IS NULL LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $userId = $statement->fetchColumn();

    if ($userId === false) {
        return false;
    }

    // Can throw 23000 again if the new name belongs to somebody else. That is
    // left to the caller's handler, which already words name clashes correctly.
    $connection->prepare(
        'UPDATE users SET name = :name, password_hash = :password_hash WHERE id = :id'
    )->execute([
        'name' => $name,
        'password_hash' => $passwordHash,
        'id' => $userId,
    ]);

    startVerification((int) $userId, 'signup', $email, $name);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Signing somebody in is a change too: without this, another site can quietly sign a visitor
    // into an account it controls, and everything they do next is done in somebody else's name.
    requireCsrfToken();

    $formType = ($_POST['form_type'] ?? '') === 'signup' ? 'signup' : 'login';
    $activeForm = $formType;

    if ($formType === 'login') {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($identifier === '' || strlen($identifier) > 255) {
            $loginErrors[] = 'Enter your username or email address.';
        }

        if ($password === '') {
            $loginErrors[] = 'Enter your password.';
        }

        if ($loginErrors === []) {
            /*
             * THE THROTTLE, BEFORE THE PASSWORD IS LOOKED AT.
             *
             * Placed here rather than after the query on purpose. Checked afterwards it would still
             * count attempts, but it would also have done the work -- a database read and a bcrypt
             * verify -- for every one of them, so the form would stay a usable oracle for timing
             * and a usable load generator. Refusing first means a throttled attempt costs two
             * indexed counts and nothing else.
             *
             * The refusal says the same thing however the limit was reached and never says whether
             * the account exists. See loginThrottleMessage() in config/login-guard.php.
             */
            $throttle = loginThrottleState($identifier);

            if (!$throttle['allowed']) {
                $loginErrors[] = loginThrottleMessage((int) $throttle['retryAfter']);
            }
        }

        if ($loginErrors === []) {
            try {
                $statement = getDatabaseConnection()->prepare(
                    'SELECT id, name, email, password_hash, role, email_verified_at FROM users
                     WHERE email = :email OR name = :name
                     LIMIT 1'
                );
                // Two separate placeholders on purpose: with EMULATE_PREPARES off,
                // PDO cannot bind the same named placeholder twice (HY093).
                $statement->execute(['email' => $identifier, 'name' => $identifier]);
                $user = $statement->fetch();

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    // Recorded against the typed identifier whether or not it matched anybody, so a
                    // real account and an invented one are throttled identically and the form
                    // cannot be used to ask which is which.
                    recordLoginAttempt($identifier, false);
                    $loginErrors[] = 'The username/email or password is incorrect.';
                } else {
                    /*
                     * The password was right. Recorded as a success and the identifier's failures
                     * forgotten, before the branches below decide which of the three ways this
                     * sign-in continues -- all three of them are a correct password, so all three
                     * should clear the count. Doing it inside one of the branches is how the
                     * trusted-device path and the code path would come to disagree.
                     */
                    recordLoginAttempt($identifier, true);
                    clearLoginFailures($identifier);

                    if ($user['email_verified_at'] === null) {
                        // Signed up but never confirmed the address. Finish that first,
                        // otherwise the account is unreachable forever.
                        startVerification((int) $user['id'], 'signup', $user['email'], $user['name']);
                    } elseif (
                        ($user['role'] === 'admin'
                            && defined('ADMIN_LOGIN_2FA_BYPASS_EMAIL')
                            && ADMIN_LOGIN_2FA_BYPASS_EMAIL !== ''
                            && strcasecmp((string) $user['email'], ADMIN_LOGIN_2FA_BYPASS_EMAIL) === 0)
                        || deviceIsTrusted((int) $user['id'])
                    ) {
                        // The local override names one admin account; everyone else must
                        // already have a trusted device or complete the email code.
                        signInUser($user);
                        redirectTo(homeUrlForRole($user['role']));
                    } else {
                        // The password was right, which is step one of two.
                        startVerification((int) $user['id'], 'login', $user['email'], $user['name']);
                    }
                }
            } catch (PDOException $exception) {
                $loginErrors[] = 'Unable to sign in right now. Check your database connection.';
            }
        }
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            $signupErrors[] = 'Enter a name between 1 and 100 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $signupErrors[] = 'Enter a valid email address.';
        }

        $signupErrors = array_merge($signupErrors, passwordStrengthErrors($password));

        if ($password !== $confirmPassword) {
            $signupErrors[] = 'Passwords do not match.';
        }

        if ($signupErrors === []) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $connection = getDatabaseConnection();
                $statement = $connection->prepare(
                    'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
                );
                $statement->execute([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                ]);

                // email_verified_at is left NULL by the insert, so the account does
                // not work until the code below is typed in.
                startVerification((int) $connection->lastInsertId(), 'signup', $email, $name);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    $signupErrors[] = 'Unable to create your account right now. Check your database connection.';
                } elseif (str_contains($exception->getMessage(), 'uq_users_name')) {
                    // Both name and email are UNIQUE, so say which one clashed.
                    $signupErrors[] = 'That name is already taken. Try a different one.';
                } else {
                    // The email is taken. If only by an unfinished sign-up, carry on
                    // into it instead of stopping here.
                    try {
                        if (!resumeUnverifiedSignup($email, $name, $passwordHash)) {
                            $signupErrors[] = 'An account with that email already exists.';
                        }
                    } catch (PDOException $resumeException) {
                        $signupErrors[] = $resumeException->getCode() === '23000'
                            ? 'That name is already taken. Try a different one.'
                            : 'Unable to create your account right now. Check your database connection.';
                    }
                }
            }
        }
    }
}

// Every message on this page pops up as a toast, so collect them in one list.
// Only one of the two error arrays can be filled -- a request submits one form.
$toasts = [];

foreach (array_merge($loginErrors, $signupErrors) as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

renderHeader('Sign in or create an account');
renderToasts($toasts);
?>

    <main class="auth-page">
        <section class="auth-card" data-auth-card data-active-form="<?php echo escape($activeForm); ?>">
            <!-- Sign in -->
            <div class="auth-panel" data-auth-panel="login" <?php echo $activeForm === 'login' ? '' : 'hidden'; ?>>
                <p class="auth-eyebrow">Welcome back</p>
                <h1>Sign in to your account</h1>
                <p class="auth-intro">Continue creating something made for you.</p>

                <form class="auth-form" method="post" action="<?php echo escape(url('php/login.php')); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_type" value="login">

                    <label for="login-identifier">Username or email address</label>
                    <input id="login-identifier" name="identifier" type="text" value="<?php echo escape($identifier); ?>" autocomplete="username" maxlength="255" required>

                    <label for="login-password">Password</label>
                    <?php renderPasswordField([
                        'id' => 'login-password',
                        'name' => 'password',
                        'autocomplete' => 'current-password',
                    ]); ?>

                    <button type="submit" class="auth-submit">Sign in</button>
                </form>

                <p class="auth-switch">New here? <a href="?form=signup" data-auth-tab="signup">Create an account</a></p>
            </div>

            <!-- Create account -->
            <div class="auth-panel" data-auth-panel="signup" <?php echo $activeForm === 'signup' ? '' : 'hidden'; ?>>
                <p class="auth-eyebrow">Start creating</p>
                <h1>Create your account</h1>
                <p class="auth-intro">Save your designs and make every detail yours.</p>

                <form class="auth-form" method="post" action="<?php echo escape(url('php/login.php')); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_type" value="signup">

                    <label for="signup-name">Name</label>
                    <input id="signup-name" name="name" type="text" value="<?php echo escape($name); ?>" autocomplete="name" maxlength="100" required>

                    <label for="signup-email">Email address</label>
                    <input id="signup-email" name="email" type="email" value="<?php echo escape($email); ?>" autocomplete="email" maxlength="255" required>

                    <label for="signup-password">Password</label>
                    <?php renderPasswordField([
                        'id' => 'signup-password',
                        'name' => 'password',
                        'autocomplete' => 'new-password',
                        'describedBy' => 'password-rules',
                        // 72 is bcrypt's hard limit -- see passwordStrengthErrors().
                        'attributes' => 'minlength="8" maxlength="72"',
                    ]); ?>

                    <!--
                        Always on the page, not revealed on focus: without JavaScript
                        this list is the only thing telling you the rules before you
                        submit. script.js ticks items off as you type, and the
                        attribute names the input it is watching.
                    -->
                    <ul class="password-rules" id="password-rules" data-password-rules="signup-password">
                        <li data-rule="length">At least 8 characters</li>
                        <li data-rule="lower">A small letter</li>
                        <li data-rule="upper">A capital letter</li>
                        <li data-rule="number">A number</li>
                        <li data-rule="symbol">A symbol, such as ! ? @ or #</li>
                    </ul>

                    <label for="signup-confirm-password">Confirm password</label>
                    <?php renderPasswordField([
                        'id' => 'signup-confirm-password',
                        'name' => 'confirm_password',
                        'autocomplete' => 'new-password',
                        'attributes' => 'minlength="8" maxlength="72"',
                    ]); ?>

                    <button type="submit" class="auth-submit">Create account</button>
                </form>

                <p class="auth-switch">Already have an account? <a href="?form=login" data-auth-tab="login">Sign in</a></p>
            </div>
        </section>
    </main>
<?php renderFooter(); ?>
