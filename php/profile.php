<?php
// Must run before any HTML: this page redirects, sets cookies and starts sessions,
// all of which need the headers unsent.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/twofactor.php';
require_once __DIR__ . '/config/devices.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/password-field.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * Changing your own name, email address and password.
 *
 * Both of the dangerous actions here -- moving the account to another address, and
 * changing the password -- ask for the current password first. That is the standard
 * protection against a signed-in browser left unattended, and it happens to make
 * those two forms resistant to cross-site request forgery on their own: somebody
 * forging the request from another site does not know the password. The name form
 * never had that, and now does not need it: every form on this page carries a token
 * and the handler below refuses a POST without one. The password prompt stays, because
 * it answers the other threat -- the unattended browser, which a token does nothing
 * about.
 *
 * A form that succeeded answers with a redirect and leaves its message in the session
 * -- see php/config/flash.php for why. A form that failed re-renders with the values
 * still in the boxes, the way php/login.php does, because that is a form you still
 * need to correct.
 */
$userId = (int) $_SESSION['user_id'];
$isAdminProfile = isAdmin();

$account = null;
$loadFailed = false;

try {
    $statement = getDatabaseConnection()->prepare(
        'SELECT name, email, pending_email, password_hash, role
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $account = $statement->fetch() ?: null;
} catch (PDOException $exception) {
    error_log('profile.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Signed in against an account that is no longer there.
if ($account === null && !$loadFailed) {
    $_SESSION = [];
    session_destroy();
    redirectTo(url('php/login.php'));
}

$currentName = (string) ($account['name'] ?? '');
$currentEmail = (string) ($account['email'] ?? '');
$currentHash = (string) ($account['password_hash'] ?? '');
$pendingEmail = trim((string) ($account['pending_email'] ?? ''));
$emailVerified = !empty($account['email_verified_at']);
$mailAddress = mailSenderAddress();
$mailFromName = mailFromName();
$mailPasswordIsStored = $isAdminProfile && settingIsStored(SETTING_MAIL_SENDER_PASSWORD);
$mailPasswordChanged = $isAdminProfile ? settingUpdatedAt(SETTING_MAIL_SENDER_PASSWORD) : '';
$mailSettingsAction = url('php/admin/settings.php?return=profile');

// One list per section, so a failed password change does not put its complaint
// under the name field.
$nameErrors = [];
$emailErrors = [];
$confirmErrors = [];
$passwordErrors = [];

// What goes back in the boxes when a section is re-rendered after a failure.
$nameValue = $currentName;
$emailValue = '';

/**
 * Answer a successful POST with a redirect, so a refresh does not resend it.
 *
 * $fragment scrolls the page to the section that was acted on -- after asking for an
 * email change the thing to do next is enter the code, and that panel is below the
 * fold on a phone.
 */
function profileRedirect(string $fragment = ''): never
{
    redirectTo(url('php/profile.php') . ($fragment === '' ? '' : '#' . $fragment));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadFailed) {
    // Before the first field is read, and it matters more here than anywhere: the email form on
    // this page decides where a password reset would go, so a forged one is a stolen account.
    requireCsrfToken();

    $formType = (string) ($_POST['form_type'] ?? '');

    /* ---------------------------------------------------------------- name */
    if ($formType === 'name') {
        // strlen, not mb_strlen, to match the sign-up check in php/login.php exactly.
        // The column holds 100 characters and this allows 100 bytes, so it is the
        // stricter of the two -- but two forms writing one column must agree, or a
        // name accepted in one place is refused in the other for no visible reason.
        $nameValue = trim((string) ($_POST['name'] ?? ''));

        if ($nameValue === '' || strlen($nameValue) > 100) {
            $nameErrors[] = 'Enter a name between 1 and 100 characters.';
        } elseif ($nameValue === $currentName) {
            $nameErrors[] = 'That is already your name.';
        } else {
            try {
                getDatabaseConnection()
                    ->prepare('UPDATE users SET name = :name WHERE id = :id')
                    ->execute(['name' => $nameValue, 'id' => $userId]);

                // The navbar greeting reads the session, not the database, so this
                // has to be updated here -- otherwise the new name only appears
                // after signing out and back in, which reads as the save failing.
                $_SESSION['user_name'] = $nameValue;

                flashToast('success', 'Your name is now ' . $nameValue . '.');
                profileRedirect();
            } catch (PDOException $exception) {
                // Worded the way sign-up words it, because it is the same clash on
                // the same uq_users_name index.
                $nameErrors[] = $exception->getCode() === '23000'
                    ? 'That name is already taken. Try a different one.'
                    : 'Unable to save your name right now. Check your database connection.';
            }
        }
    }

    /* --------------------------------------------------- ask to move email */
    if ($formType === 'email') {
        $emailValue = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['current_password'] ?? '');

        if (!filter_var($emailValue, FILTER_VALIDATE_EMAIL) || strlen($emailValue) > 255) {
            $emailErrors[] = 'Enter a valid email address.';
        }

        if ($password === '') {
            $emailErrors[] = 'Enter your current password.';
        } elseif (!password_verify($password, $currentHash)) {
            $emailErrors[] = 'That password is not right.';
        }

        if ($emailErrors === [] && $emailValue === $currentEmail) {
            $emailErrors[] = 'That is already your email address.';
        }

        if ($emailErrors === []) {
            try {
                // Checked here as well as by uq_users_email, so the answer is a
                // sentence rather than a caught constraint violation five steps
                // later, after a code has already been emailed to the address.
                $statement = getDatabaseConnection()->prepare(
                    'SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1'
                );
                $statement->execute(['email' => $emailValue, 'id' => $userId]);

                if ($statement->fetchColumn() !== false) {
                    $emailErrors[] = 'An account with that email already exists.';
                }
            } catch (PDOException $exception) {
                error_log('profile.php: ' . $exception->getMessage());
                $emailErrors[] = 'Unable to start the change right now. Check your database connection.';
            }
        }

        if ($emailErrors === []) {
            /*
             * The throttle is checked before pending_email is written, not after.
             *
             * issueEmailCode() refuses an early send by itself, but by then the row
             * would already say the account is moving to the new address while the
             * only outstanding code was issued for the previous one. email_codes does
             * not record which address a code went to -- it is keyed on
             * (user_id, purpose) -- so entering that older code would move the
             * account to an address nobody has proved they can read.
             *
             * Refusing the whole request keeps the code and pending_email in step.
             */
            $wait = secondsUntilResend($userId, 'email_change');

            if ($wait > 0) {
                $emailErrors[] = 'A code was just sent. Wait ' . $wait . ' second'
                    . ($wait === 1 ? '' : 's') . ' before starting again.';
            }
        }

        if ($emailErrors === []) {
            try {
                getDatabaseConnection()
                    ->prepare('UPDATE users SET pending_email = :email WHERE id = :id')
                    ->execute(['email' => $emailValue, 'id' => $userId]);

                $result = issueEmailCode($userId, 'email_change', $emailValue);

                if ($result['sent']) {
                    // The whole address, not maskEmail() -- they typed it a moment
                    // ago, and printing it back is what catches the typo that would
                    // otherwise send the code somewhere unreachable.
                    flashToast('success', 'We sent a 6-digit code to ' . $emailValue . '. Enter it below to finish.');
                } else {
                    // pending_email stays set on a failed send, the same way
                    // php/login.php still sends people to the code page: the code
                    // row exists and the Resend button is on the panel below.
                    flashToast('error', (string) $result['error']);
                }

                profileRedirect('email');
            } catch (PDOException $exception) {
                error_log('profile.php: ' . $exception->getMessage());
                $emailErrors[] = 'Unable to start the change right now. Check your database connection.';
            }
        }
    }

    /* ------------------------------------------------ confirm the new email */
    if ($formType === 'confirm_email') {
        if ($pendingEmail === '') {
            $confirmErrors[] = 'There is no email change waiting to be confirmed.';
        } else {
            // Digits only, so a pasted "123 456" still works -- same as verify.php.
            $code = (string) preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));

            if (strlen($code) !== 6) {
                $confirmErrors[] = 'Enter the 6-digit code from your email.';
            } else {
                $result = verifyEmailCode($userId, 'email_change', $code);

                if (!$result['ok']) {
                    $confirmErrors[] = (string) $result['error'];
                } else {
                    try {
                        /*
                         * email_verified_at is deliberately left alone. The address
                         * being written was just proved by the code that got here, so
                         * there is nothing unproven to record -- clearing it would
                         * lock the account out at the next sign-in, because
                         * php/login.php treats a NULL as "confirm your address
                         * first" and sends a signup code instead.
                         */
                        getDatabaseConnection()->prepare(
                            'UPDATE users SET email = :email, pending_email = NULL WHERE id = :id'
                        )->execute(['email' => $pendingEmail, 'id' => $userId]);

                        // Sign-in accepts the email address as an identifier, so the
                        // session copy has to follow the change.
                        $_SESSION['user_email'] = $pendingEmail;

                        flashToast('success', 'Your email address is now ' . $pendingEmail . '. Use it to sign in from now on.');
                        profileRedirect();
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            /*
                             * Somebody else took the address between the request and
                             * the confirmation. pending_email is not UNIQUE on
                             * purpose -- see sql/email-change-upgrade.sql -- so two
                             * accounts can both be claiming one address, and
                             * uq_users_email decides it here, in favour of whoever
                             * confirmed first.
                             *
                             * The claim is dropped rather than left to fail again on
                             * every retry.
                             */
                            try {
                                getDatabaseConnection()
                                    ->prepare('UPDATE users SET pending_email = NULL WHERE id = :id')
                                    ->execute(['id' => $userId]);
                                deleteEmailCode($userId, 'email_change');
                            } catch (PDOException $cleanupException) {
                                error_log('profile.php: ' . $cleanupException->getMessage());
                            }

                            $pendingEmail = '';
                            $confirmErrors[] = 'Another account claimed that address before you confirmed it. Your email address has not changed.';
                        } else {
                            error_log('profile.php: ' . $exception->getMessage());
                            $confirmErrors[] = 'Unable to save the new address right now. Check your database connection.';
                        }
                    }
                }
            }
        }
    }

    /* ------------------------------------------------------ send the code again */
    if ($formType === 'resend_email') {
        if ($pendingEmail === '') {
            $confirmErrors[] = 'There is no email change waiting to be confirmed.';
        } else {
            $result = issueEmailCode($userId, 'email_change', $pendingEmail);

            // Redirects either way: the button's countdown is rendered from
            // secondsUntilResend(), so the page has to be re-read to show it.
            flashToast(
                $result['sent'] ? 'success' : 'error',
                $result['sent']
                    ? 'A new code is on its way to ' . $pendingEmail . '.'
                    : (string) $result['error']
            );

            profileRedirect('email');
        }
    }

    /* ---------------------------------------------------- drop the email change */
    if ($formType === 'cancel_email') {
        // No password needed: this only ever puts the account back the way it was,
        // and making it hard to abandon a half-finished change is not security.
        try {
            getDatabaseConnection()
                ->prepare('UPDATE users SET pending_email = NULL WHERE id = :id')
                ->execute(['id' => $userId]);

            // The code goes too. Leaving it would keep the resend throttle running
            // against a change that no longer exists, so starting again would be
            // refused for up to a minute for no reason the customer can see.
            deleteEmailCode($userId, 'email_change');

            flashToast('success', 'Email change cancelled. Your address is still ' . $currentEmail . '.');
            profileRedirect();
        } catch (PDOException $exception) {
            error_log('profile.php: ' . $exception->getMessage());
            $confirmErrors[] = 'Unable to cancel the change right now. Check your database connection.';
        }
    }

    /* ------------------------------------------------------------- password */
    if ($formType === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($current === '') {
            $passwordErrors[] = 'Enter your current password.';
        } elseif (!password_verify($current, $currentHash)) {
            $passwordErrors[] = 'That password is not right.';
        }

        // The same function sign-up uses, so the rules cannot drift apart.
        $passwordErrors = array_merge($passwordErrors, passwordStrengthErrors($new));

        if ($new !== $confirm) {
            $passwordErrors[] = 'Passwords do not match.';
        }

        if ($passwordErrors === [] && $new === $current) {
            $passwordErrors[] = 'That is already your password. Choose a different one.';
        }

        if ($passwordErrors === []) {
            try {
                getDatabaseConnection()
                    ->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                    ->execute([
                        'hash' => password_hash($new, PASSWORD_DEFAULT),
                        'id' => $userId,
                    ]);

                /*
                 * Every remembered browser is forgotten, including this one.
                 *
                 * Someone changing their password because another person knows it has
                 * to actually shut that person out, and a trusted-device row is a
                 * standing permission to skip the emailed code. There is no way to
                 * tell the intruder's row from the owner's, so they all go and
                 * everybody proves themselves once more.
                 */
                forgetAllDevices($userId);

                // A fresh session id at the moment the credentials change, so a
                // session id captured before the change is worth nothing after it.
                signInUser([
                    'id' => $userId,
                    'name' => $currentName,
                    'email' => $currentEmail,
                    'role' => $account['role'] ?? ROLE_CUSTOMER,
                ]);

                flashToast('success', 'Your password has been changed. Every device will be asked for a code next time it signs in.');
                profileRedirect();
            } catch (PDOException $exception) {
                error_log('profile.php: ' . $exception->getMessage());
                $passwordErrors[] = 'Unable to change your password right now. Check your database connection.';
            }
        }
    }
}

// Messages left by the redirect that got here, then this request's own.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load your account right now. Check your database connection.',
    ];
}

foreach (array_merge($nameErrors, $emailErrors, $confirmErrors, $passwordErrors) as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

// Only used by the confirm panel, and only worth a query when that panel exists.
$resendWait = 0;

if ($pendingEmail !== '' && !$loadFailed) {
    try {
        $resendWait = secondsUntilResend($userId, 'email_change');
    } catch (PDOException $exception) {
        error_log('profile.php: ' . $exception->getMessage());
    }
}

renderHeader($isAdminProfile ? 'Settings' : 'Your details');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow"><?php echo $isAdminProfile ? 'Admin settings' : 'Your account'; ?></p>
            <h1><?php echo $isAdminProfile ? 'Admin settings' : 'Your details'; ?></h1>
            <p class="page-intro">
                <?php echo $isAdminProfile ? 'Manage the administrator account, sign-in details and outgoing mail.' : 'Change your name, your email address or your password.'; ?>
                <a href="<?php echo escape(url($isAdminProfile ? 'php/admin/dashboard.php' : 'php/dashboard.php')); ?>"><?php echo $isAdminProfile ? 'Back to the Dashboard' : 'Back to your account'; ?></a>.
            </p>
        </header>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load your details</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php else: ?>
            <?php if ($isAdminProfile): ?>
                <section class="admin-profile-overview" aria-labelledby="admin-profile-overview-title">
                    <div class="admin-profile-overview-heading">
                        <div>
                            <span class="page-eyebrow">Account overview</span>
                            <h2 id="admin-profile-overview-title">Administrator access</h2>
                        </div>
                        <span class="admin-badge admin-badge--admin">Administrator</span>
                    </div>
                    <div class="admin-profile-overview-grid">
                        <div>
                            <span class="admin-stat-label">Sign-in email</span>
                            <strong><?php echo escape($currentEmail); ?></strong>
                        </div>
                        <div>
                            <span class="admin-stat-label">Email status</span>
                            <strong><?php echo $emailVerified ? 'Verified' : 'Needs verification'; ?></strong>
                        </div>
                        <div>
                            <span class="admin-stat-label">Access level</span>
                            <strong>Full admin access</strong>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <div class="profile-panels<?php echo $isAdminProfile ? ' profile-panels--admin' : ''; ?>">
                <section class="panel" id="name">
                    <h2>Name</h2>
                    <p class="panel-intro">This is the name shown when you are signed in, and one of the two things you can sign in with.</p>

                    <form class="panel-form" method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="name">

                        <label for="profile-name">Name</label>
                        <input id="profile-name" name="name" type="text" value="<?php echo escape($nameValue); ?>"
                               autocomplete="name" maxlength="100" required>

                        <button type="submit" class="btn btn-primary">Save name</button>
                    </form>
                </section>

                <section class="panel" id="email">
                    <h2>Email address</h2>

                    <?php if ($pendingEmail === ''): ?>
                        <p class="panel-intro">
                            Codes and receipts go to <strong><?php echo escape($currentEmail); ?></strong>.
                            A new address has to be confirmed before it takes over.
                        </p>

                        <form class="panel-form" method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="form_type" value="email">

                            <label for="profile-email">New email address</label>
                            <input id="profile-email" name="email" type="email" value="<?php echo escape($emailValue); ?>"
                                   autocomplete="email" maxlength="255" required>

                            <!-- The current password, because this is how an account
                                 gets taken over: change the address, then ask for a
                                 code at the new one. An unattended signed-in browser
                                 should not be enough. -->
                            <label for="profile-email-password">Your current password</label>
                            <?php renderPasswordField([
                                'id' => 'profile-email-password',
                                'name' => 'current_password',
                                'autocomplete' => 'current-password',
                            ]); ?>

                            <button type="submit" class="btn btn-primary">Send a code</button>
                        </form>
                    <?php else: ?>
                        <p class="panel-intro">
                            Your address is still <strong><?php echo escape($currentEmail); ?></strong>.
                            We emailed a 6-digit code to <strong><?php echo escape($pendingEmail); ?></strong>;
                            it expires in <?php echo (int) round(CODE_TTL_SECONDS / 60); ?> minutes.
                        </p>

                        <form class="panel-form" method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="form_type" value="confirm_email">

                            <label for="profile-code">6-digit code</label>
                            <input
                                id="profile-code"
                                class="code-field"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                spellcheck="false"
                                data-code-field
                                required>

                            <button type="submit" class="btn btn-primary">Confirm new address</button>
                        </form>

                        <!--
                            Two separate forms, because they are two separate actions.
                            A second submit button inside the form above would send
                            whatever half-typed code was in the field along with it.
                        -->
                        <div class="panel-actions">
                            <form method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="form_type" value="resend_email">
                                <button type="submit" class="btn btn-quiet" data-resend-button
                                        data-wait-seconds="<?php echo $resendWait; ?>">Resend code</button>
                            </form>

                            <form method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="form_type" value="cancel_email">
                                <button type="submit" class="btn btn-quiet">Cancel the change</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel" id="password">
                    <h2>Password</h2>
                    <p class="panel-intro">
                        Changing this signs every remembered device out of the shortcut,
                        so each one is asked for a code the next time it signs in.
                    </p>

                    <form class="panel-form" method="post" action="<?php echo escape(url('php/profile.php')); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="password">

                        <label for="profile-current-password">Current password</label>
                        <?php renderPasswordField([
                            'id' => 'profile-current-password',
                            'name' => 'current_password',
                            'autocomplete' => 'current-password',
                        ]); ?>

                        <label for="profile-new-password">New password</label>
                        <?php renderPasswordField([
                            'id' => 'profile-new-password',
                            'name' => 'new_password',
                            'autocomplete' => 'new-password',
                            'describedBy' => 'profile-password-rules',
                            // 72 is bcrypt's hard limit -- see passwordStrengthErrors().
                            'attributes' => 'minlength="8" maxlength="72"',
                        ]); ?>

                        <!-- Same rules as sign-up, same live ticking. The attribute
                             names the input this list belongs to, so a page can carry
                             more than one checklist. -->
                        <ul class="password-rules" id="profile-password-rules" data-password-rules="profile-new-password">
                            <li data-rule="length">At least 8 characters</li>
                            <li data-rule="lower">A small letter</li>
                            <li data-rule="upper">A capital letter</li>
                            <li data-rule="number">A number</li>
                            <li data-rule="symbol">A symbol, such as ! ? @ or #</li>
                        </ul>

                        <label for="profile-confirm-password">Confirm new password</label>
                        <?php renderPasswordField([
                            'id' => 'profile-confirm-password',
                            'name' => 'confirm_password',
                            'autocomplete' => 'new-password',
                            'attributes' => 'minlength="8" maxlength="72"',
                        ]); ?>

                        <button type="submit" class="btn btn-primary">Change password</button>
                    </form>
                </section>

                <?php if ($isAdminProfile): ?>
                    <section class="panel" id="mail-settings">
                        <h2>Mail settings</h2>
                        <p class="panel-intro">Sender details for sign-in codes and order emails.</p>

                        <p class="field-hint">
                            <?php if (MAIL_TRANSPORT === 'smtp'): ?>
                                <span class="pill pill-active">Sending via <?php echo escape(SMTP_HOST); ?></span>
                            <?php else: ?>
                                <span class="pill pill-hidden">Writing to a file</span>
                                Messages stay in the system temp folder; no email is sent.
                            <?php endif; ?>
                            Transport mode is controlled in <code>php/config/mail.php</code>.
                        </p>

                        <form class="panel-form" method="post" action="<?php echo escape($mailSettingsAction); ?>">
                            <?php echo csrfField(); ?>
                            <label for="profile-sender-address">Sending address</label>
                            <input id="profile-sender-address" name="sender_address" type="email" required
                                   maxlength="255" autocomplete="off" value="<?php echo escape($mailAddress); ?>">
                            <label for="profile-from-name">Sender name</label>
                            <input id="profile-from-name" name="from_name" type="text" required
                                   maxlength="80" value="<?php echo escape($mailFromName); ?>">
                            <label for="profile-sender-password">App password</label>
                            <input id="profile-sender-password" name="sender_password" type="password"
                                   autocomplete="new-password" maxlength="255"
                                   placeholder="<?php echo $mailPasswordIsStored ? 'Leave empty to keep the saved one' : 'Not set here yet'; ?>">
                            <p class="field-hint">
                                <?php if ($mailPasswordIsStored): ?>
                                    A password is saved<?php if ($mailPasswordChanged !== ''): ?>; last changed <?php echo escape(date('j M Y, g:ia', strtotime($mailPasswordChanged))); ?><?php endif; ?>. It is never shown here.
                                <?php else: ?>
                                    Not saved here. The app password in <code>php/config/mail.php</code> is used.
                                <?php endif; ?>
                                A different Gmail address needs its own app password.
                            </p>
                            <div class="panel-actions">
                                <button type="submit" class="btn btn-primary">Save mail settings</button>
                                <?php if ($mailPasswordIsStored): ?>
                                    <button type="submit" class="btn btn-quiet" name="action" value="forget-password"
                                            data-confirm="Remove the saved app password and use the value in php/config/mail.php instead?">
                                        Use file password
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
