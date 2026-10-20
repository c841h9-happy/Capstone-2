<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/twofactor.php';
require_once __DIR__ . '/config/devices.php';
// Same reason as php/login.php: this is the other path that reaches signInUser(), so the guest
// basket has to be mergeable from here too. Signing in by emailed code and signing in on a
// trusted device must not differ in whether the basket survives.
require_once __DIR__ . '/config/cart.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

/**
 * Step two of signing in: type in the code that was emailed.
 *
 * The account being verified is held in the session, never in the form. A hidden
 * field would let anyone change whose account they are answering for and brute
 * force codes against a user id they picked.
 */
$pending = $_SESSION['pending_2fa'] ?? null;

// Nothing to verify -- either they came here directly or the session expired.
if (!is_array($pending) || !isset($pending['user_id'], $pending['purpose'], $pending['email'])) {
    redirectTo(url('php/login.php'));
}

$userId = (int) $pending['user_id'];
$purpose = $pending['purpose'] === 'signup' ? 'signup' : 'login';
$email = (string) $pending['email'];

$errors = [];
$notices = [];

// Set when the previous page already sent a code, so the page can say so once.
if (!empty($_SESSION['pending_2fa']['just_sent'])) {
    $notices[] = 'We sent a 6-digit code to ' . maskEmail($email) . '.';
    unset($_SESSION['pending_2fa']['just_sent']);
}

// Or say why it did not arrive. login.php sends people here even on a failed
// send, because the Resend button is here -- but only if they are told why.
if (isset($_SESSION['pending_2fa']['send_error'])) {
    $errors[] = (string) $_SESSION['pending_2fa']['send_error'];
    unset($_SESSION['pending_2fa']['send_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = ($_POST['action'] ?? '') === 'resend' ? 'resend' : 'verify';

    if ($action === 'resend') {
        $result = issueEmailCode($userId, $purpose, $email);

        if ($result['sent']) {
            $notices[] = 'A new code is on its way to ' . maskEmail($email) . '.';
        } else {
            $errors[] = $result['error'];
        }
    } else {
        // Digits only, so a pasted "123 456" or a stray space still works.
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));

        if ($code === '' || strlen($code) !== 6) {
            $errors[] = 'Enter the 6-digit code from your email.';
        } else {
            $result = verifyEmailCode($userId, $purpose, $code);

            if (!$result['ok']) {
                $errors[] = $result['error'];
            } else {
                try {
                    $connection = getDatabaseConnection();

                    // Signing up and confirming the address are the same act, so
                    // finishing this step is what marks the address as proven.
                    if ($purpose === 'signup') {
                        $connection->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id')
                            ->execute(['id' => $userId]);
                    }

                    // The role is read here, from the database, rather than being
                    // carried over from login.php in $_SESSION['pending_2fa'] --
                    // that state is written before the code is proven, so anything
                    // in it is a claim. Reading it now also means a promotion made
                    // between the two steps is already in effect.
                    $statement = $connection->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
                    $statement->execute(['id' => $userId]);
                    $user = $statement->fetch();

                    if ($user === false) {
                        // The account went away mid-verification.
                        unset($_SESSION['pending_2fa']);
                        redirectTo(url('php/login.php'));
                    }

                    // Ticked box, and only now that the code is proven -- trusting
                    // the browser any earlier would hand out a skip-the-code token
                    // to someone who never finished verifying.
                    if (isset($_POST['remember_device'])) {
                        trustThisDevice($userId);
                    }

                    // Regenerates the session id as it grants it, so an id captured
                    // beforehand is worthless.
                    signInUser($user);
                    unset($_SESSION['pending_2fa']);

                    redirectTo(homeUrlForRole($user['role']));
                } catch (PDOException $exception) {
                    error_log('verify.php: ' . $exception->getMessage());
                    $errors[] = 'Unable to finish signing in right now. Check your database connection.';
                }
            }
        }
    }
}

// How long the Resend button has to stay quiet. The server enforces this either
// way; passing it to the page just lets the button show a countdown.
$waitSeconds = secondsUntilResend($userId, $purpose);

$toasts = [];

foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

foreach ($notices as $notice) {
    $toasts[] = ['type' => 'success', 'message' => $notice];
}

renderHeader($purpose === 'signup' ? 'Confirm your email' : 'Enter your sign-in code');
renderToasts($toasts);
?>

    <main class="auth-page">
        <section class="auth-card">
            <p class="auth-eyebrow"><?php echo $purpose === 'signup' ? 'One last step' : 'Two-step verification'; ?></p>
            <h1><?php echo $purpose === 'signup' ? 'Confirm your email' : 'Enter your code'; ?></h1>
            <p class="auth-intro">
                We emailed a 6-digit code to <strong><?php echo escape(maskEmail($email)); ?></strong>.
                It expires in <?php echo (int) round(CODE_TTL_SECONDS / 60); ?> minutes.
            </p>

            <form class="auth-form" method="post" action="<?php echo escape(url('php/verify.php')); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="verify">

                <label for="code">6-digit code</label>
                <!--
                    autocomplete="one-time-code" is what lets a phone offer the code
                    straight from the notification. inputmode brings up the number
                    pad without making this a number input, which would strip the
                    leading zeros a code like 004182 needs.
                -->
                <input
                    id="code"
                    class="code-field"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    spellcheck="false"
                    aria-describedby="code-hint"
                    data-code-field
                    required
                    autofocus>

                <p class="auth-hint" id="code-hint">Six digits, no spaces.</p>

                <label class="auth-check">
                    <input type="checkbox" name="remember_device" value="1">
                    <span>Remember this device for <?php echo TRUSTED_DEVICE_DAYS; ?> days</span>
                </label>

                <button type="submit" class="auth-submit">Verify</button>
            </form>

            <!--
                Its own form, because it is a separate action -- putting a second
                submit button inside the form above would send whatever half-typed
                code was in the field along with it.
            -->
            <form class="auth-resend" method="post" action="<?php echo escape(url('php/verify.php')); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="resend">
                <span>Didn't get it?</span>
                <button type="submit" class="auth-link-btn" data-resend-button data-wait-seconds="<?php echo $waitSeconds; ?>">Resend code</button>
            </form>

            <p class="auth-switch">
                Wrong account? <a href="<?php echo escape(url('php/login.php?restart=1')); ?>">Start over</a>
            </p>
        </section>
    </main>
<?php renderFooter(); ?>
