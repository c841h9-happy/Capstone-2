<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to
// run before the redirect below, which needs the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/products-admin.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Outgoing mail settings.
 *
 * The address that sends the shop's verification codes, sign-in codes and order notifications --
 * changeable here rather than by editing php/config/mail.php, which is what this screen is for. The
 * shop will change the sending Gmail eventually, and that should not need somebody to open a PHP
 * file.
 *
 * WHAT IS HERE AND WHAT IS NOT.
 *
 * Here: the sender address, its app password, and the display name a recipient sees. Those three
 * are what "who is this from" means, and they move together -- a new Gmail account needs its own
 * app password, so offering the address without the password would be a setting that cannot
 * actually be used.
 *
 * Not here: MAIL_TRANSPORT. It decides whether codes reach anybody at all, and it is the one mail
 * setting where a wrong value locks every customer out of their own account. It stays a const in
 * php/config/mail.php, where changing it means reading that file's instructions first. This screen
 * reports which transport is live so the shop is never guessing.
 *
 * THE PASSWORD IS NEVER RENDERED BACK. The field is always empty and leaving it empty keeps
 * whatever is saved. A password in a `value` attribute is a password in the page source, in the
 * browser's autofill and in any screenshot of this screen -- so what is shown is whether one is
 * stored and when it was last changed, which is all an admin needs in order to decide whether to
 * replace it.
 */

$currentAddress = mailSenderAddress();
$currentFromName = mailFromName();
$returnToProfile = ($_GET['return'] ?? '') === 'profile';
$mailSettingsAction = url('php/admin/settings.php') . ($returnToProfile ? '?return=profile' : '');
$settingsReturnUrl = $returnToProfile
    ? url('php/profile.php#mail-settings')
    : url('php/admin/settings.php');

/**
 * Who is saving, for the `updated_by` column, or null.
 *
 * This project has no currentUserId() -- auth.php offers isUserLoggedIn() and currentUserRole() and
 * nothing in between. The idiom everywhere that needs the id is the one line below, as at
 * php/config/inventory.php:315 and php/config/orders-admin.php:393, so this is that line and not a
 * new accessor. (A first draft of this screen called an invented currentUserId(); the GET path
 * never touched it, so the page rendered perfectly and only the save fataled -- which is the
 * argument for grepping for a function before calling it.)
 */
function settingsActorId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = productInputText($_POST, 'action');
    $address = productInputText($_POST, 'sender_address');
    $fromName = productInputText($_POST, 'from_name');
    // NOT productInputText(): that trims, and a trim is a change. Gmail app passwords are shown as
    // four space-separated groups and the mailer strips whitespace itself, so what is typed is
    // stored as typed and the stripping stays in one place.
    $password = (string) ($_POST['sender_password'] ?? '');

    if ($action === 'forget-password') {
        /*
         * Remove the stored password and fall back to php/config/mail.php's constant. Offered
         * because the alternative -- an admin who wants to undo a change having to know what the
         * file says -- is not an alternative.
         */
        if (saveSetting(SETTING_MAIL_SENDER_PASSWORD, '', settingsActorId())) {
            flashToast('success', 'The stored app password is removed. Mail now uses whatever php/config/mail.php holds.');
        } else {
            flashToast('error', 'That could not be saved. Nothing has changed.');
        }

        redirectTo($settingsReturnUrl);
    }

    if ($address === '') {
        $errors[] = 'Give the address the shop sends from.';
    } elseif (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'That does not look like an email address.';
    } elseif (mb_strlen($address) > 255) {
        $errors[] = 'That address is too long.';
    }

    if ($fromName === '') {
        $errors[] = 'Give the name recipients should see.';
    } elseif (mb_strlen($fromName) > 80) {
        $errors[] = 'The sender name has to be 80 characters or fewer.';
    }

    /*
     * A newline in either of these would be a mail header injection -- two values that end up in a
     * From: line. config/mailer.php's mailHeaderValue() already strips them on the way out, and
     * this refuses them on the way in, because a value stored with a newline in it is a trap left
     * for the next thing that reads the table.
     */
    if (preg_match('/[\r\n]/', $address . $fromName) === 1) {
        $errors[] = 'Neither of those may contain a line break.';
    }

    if ($password !== '' && preg_match('/[\r\n]/', $password) === 1) {
        $errors[] = 'The app password may not contain a line break.';
    }

    if ($errors === []) {
        $saved = saveSetting(SETTING_MAIL_SENDER_ADDRESS, $address, settingsActorId())
            && saveSetting(SETTING_MAIL_FROM_NAME, $fromName, settingsActorId());

        // Empty means "keep what is stored", which is the whole reason the field renders blank.
        if ($saved && $password !== '') {
            $saved = saveSetting(SETTING_MAIL_SENDER_PASSWORD, $password, settingsActorId());
        }

        if ($saved) {
            flashToast(
                'success',
                'Mail settings saved. The shop now sends from ' . $address
                    . ($password !== '' ? ', with the new app password.' : '.')
            );
            redirectTo($settingsReturnUrl);
        }

        $errors[] = 'That could not be saved. Check the settings table exists and try again.';
    }

    // On a failure, show back what was typed -- except the password, which is never echoed.
    $currentAddress = $address;
    $currentFromName = $fromName;
}

$passwordIsStored = settingIsStored(SETTING_MAIL_SENDER_PASSWORD);
$passwordChanged = settingUpdatedAt(SETTING_MAIL_SENDER_PASSWORD);
$addressIsStored = settingIsStored(SETTING_MAIL_SENDER_ADDRESS);

$toasts = takeFlashToasts();

foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

renderHeader('Settings');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Settings</h1>
            <p class="page-intro">
                Mail delivery settings for sign-in codes and order emails.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <section class="panel">
            <h2>How mail is being sent</h2>

            <?php if (MAIL_TRANSPORT === 'smtp'): ?>
                <p class="field-hint">
                    <span class="pill pill-active">Sending for real</span>
                    Messages go out through <?php echo escape(SMTP_HOST); ?>.
                </p>
            <?php else: ?>
                <p class="field-hint">
                    <span class="pill pill-hidden">Writing to a file</span>
                    <strong>No mail is leaving this machine.</strong> Messages are appended to
                    <code>capstone-mail.log</code> in the system temp folder, codes included, which is how
                    the sign-in flow can be tested without sending anything.
                </p>
            <?php endif; ?>

            <p class="field-hint">
                Which of those two it is lives in <code>php/config/mail.php</code> and is not editable
                here on purpose: it decides whether verification codes reach anybody, and a wrong value
                would lock every customer out of their own account.
            </p>
        </section>

        <section class="panel">
            <h2>Who the mail comes from</h2>

            <form class="panel-form" method="post" action="<?php echo escape($mailSettingsAction); ?>">
                <?php echo csrfField(); ?>

                <label for="sender-address">Sending address</label>
                <input id="sender-address" name="sender_address" type="email" required
                       maxlength="255" autocomplete="off"
                       value="<?php echo escape($currentAddress); ?>">
                <p class="field-hint">
                    The Gmail account that sends and signs in to SMTP.
                    <?php if ($addressIsStored): ?>
                        Saved here, overriding <code>php/config/mail.php</code>.
                    <?php else: ?>
                        Currently coming from <code>php/config/mail.php</code>.
                    <?php endif; ?>
                </p>

                <label for="from-name">Sender name</label>
                <input id="from-name" name="from_name" type="text" required
                       maxlength="80" value="<?php echo escape($currentFromName); ?>">
                <p class="field-hint">What a recipient sees in their inbox beside the address.</p>

                <label for="sender-password">App password</label>
                <!--
                    Deliberately empty, every time, and type="password" so it is not shouted at
                    anybody standing behind the till. autocomplete="new-password" stops a browser
                    helpfully filling in the admin's own saved password for this site.
                -->
                <input id="sender-password" name="sender_password" type="password"
                       autocomplete="new-password" maxlength="255"
                       placeholder="<?php echo $passwordIsStored ? 'Leave empty to keep the saved one' : 'Not set here yet'; ?>">
                <p class="field-hint">
                    <?php if ($passwordIsStored): ?>
                        <span class="pill pill-active">A password is saved</span>
                        <?php if ($passwordChanged !== ''): ?>
                            Last changed <?php echo escape(date('j M Y, g:ia', strtotime($passwordChanged))); ?>.
                        <?php endif; ?>
                        It is never shown back on this page. Leave the box empty to keep it.
                    <?php else: ?>
                        <span class="pill pill-hidden">Using the file</span>
                        Nothing is saved here, so mail uses whatever <code>php/config/mail.php</code> holds.
                    <?php endif; ?>
                </p>
                <p class="field-hint">
                    Not your Google password &mdash; a 16-character app password from
                    <code>myaccount.google.com/apppasswords</code>. Spaces in it are fine. A different
                    sending address needs its own app password, so change both together.
                </p>

                <div class="panel-actions">
                    <button type="submit" class="btn btn-primary">Save mail settings</button>
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Cancel</a>
                </div>
            </form>
        </section>

        <?php if ($passwordIsStored): ?>
            <section class="panel">
                <h2>Go back to the file</h2>
                <p class="field-hint">
                    Removes the password saved here so mail falls back to
                    <code>php/config/mail.php</code>. The address and the sender name are left alone.
                </p>
                <form class="panel-form" method="post" action="<?php echo escape($mailSettingsAction); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="forget-password">
                    <div class="panel-actions">
                        <button type="submit" class="btn btn-danger"
                                data-confirm="Remove the saved app password? Mail will use whatever php/config/mail.php holds.">
                            Forget the saved password
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Checking it works</h2>
            <p class="field-hint">
                There is no &ldquo;send a test&rdquo; button here, because a button that silently
                succeeds is worse than none: Gmail refuses a bad app password at the AUTH step and the
                reason only appears in PHP&rsquo;s error log. Run this from the project folder and read
                what it says:
            </p>
            <p class="field-hint"><code>php php/tools/mail-test.php you@example.com</code></p>
        </section>
    </main>
<?php renderFooter(); ?>
