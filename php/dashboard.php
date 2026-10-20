<?php
// Must run before any HTML: requireLogin() can redirect, which needs the headers unsent.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/designs.php';
require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * The signed-in customer's hub.
 *
 * Note the name: this is php/dashboard.php, while the admin's is
 * php/admin/dashboard.php. navLinkClass() in php/components/navbar.php compares whole
 * paths rather than basenames for exactly this reason, so the two do not light each
 * other up in the nav.
 *
 * requireLogin() only, not requireAdmin() -- an admin is also a person with an
 * account, and there is nothing here they should be shut out of.
 */
$userId = (int) $_SESSION['user_id'];

/*
 * Read the account fresh rather than printing the session copy.
 *
 * The session is written at sign-in, so it is a snapshot: after an email change on
 * php/profile.php it is stale, and a dashboard that shows the old address while the
 * profile shows the new one looks like the change did not save. pending_email and
 * created_at are not in the session at all.
 */
$account = null;
$loadFailed = false;

try {
    $statement = getDatabaseConnection()->prepare(
        'SELECT name, email, pending_email, role, email_verified_at, created_at
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $account = $statement->fetch() ?: null;
} catch (PDOException $exception) {
    error_log('dashboard.php: ' . $exception->getMessage());
    $loadFailed = true;
}

/*
 * Signed in against an account that no longer exists -- deleted in another tab, or
 * the database was rebuilt underneath the session. Clearing the session is the only
 * honest answer; carrying on would render a page full of blanks.
 */
if ($account === null && !$loadFailed) {
    $_SESSION = [];
    session_destroy();
    redirectTo(url('php/login.php'));
}

// Falling back to the session keeps the page readable when the database is down,
// rather than replacing a whole dashboard with one error.
$name = (string) ($account['name'] ?? $_SESSION['user_name'] ?? '');
$email = (string) ($account['email'] ?? $_SESSION['user_email'] ?? '');
$pendingEmail = trim((string) ($account['pending_email'] ?? ''));

$joined = null;

if (isset($account['created_at'])) {
    // A malformed date should not take the page down with it.
    $timestamp = strtotime((string) $account['created_at']);
    $joined = $timestamp === false ? null : date('j F Y', $timestamp);
}

/*
 * The saved bouquets, for the card that used to say saving was coming.
 *
 * Its own try, and a failure here is not added to the toasts: the account read above already
 * says the database is down if it is, and two errors about one outage is one error too many.
 * The card falls back to saying it could not read them, which is what the empty list does
 * anyway.
 *
 * A count and the three most recent, not the whole list -- php/designs.php is the list. Three
 * because that is what fits the card at every width without the card becoming the page.
 */
$designs = [];
$designCount = 0;

try {
    $designs = fetchSavedDesigns($userId);
    $designCount = count($designs);
    $designs = array_slice($designs, 0, 3);
} catch (PDOException $exception) {
    error_log('dashboard.php (designs): ' . $exception->getMessage());
}

/*
 * The recent orders, for the card that used to say ordering was not switched on.
 *
 * Its own try and its own silence on failure, for the reason the designs read above gives: the
 * account read has already reported the outage if there is one, and two toasts about one database
 * being down is one too many.
 *
 * A count and the three most recent, not the whole list -- php/orders.php is the list. The count
 * comes from countOrdersForUser() rather than from counting the rows read, because those are
 * capped at ORDER_LIST_MAX and a card telling somebody with sixty orders they have fifty would be
 * wrong in the one direction nobody checks.
 */
$orders = [];
$orderCount = 0;

try {
    $orders = array_slice(fetchOrdersForUser($userId), 0, 3);
    $orderCount = countOrdersForUser($userId);
} catch (PDOException $exception) {
    error_log('dashboard.php (orders): ' . $exception->getMessage());
}

$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load your account details right now. Check your database connection.',
    ];
}

renderHeader('Your account');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Your account</p>
            <h1>Hello, <?php echo escape($name); ?></h1>
            <p class="page-intro">Everything to do with your account is here.</p>
        </header>

        <?php if ($pendingEmail !== ''): ?>
            <!-- A half-finished email change is easy to walk away from and then be
                 confused by later, so it is said plainly on the page the customer
                 lands on, not just on the profile page they would have to go
                 looking for. -->
            <p class="notice">
                You asked to move your account to <strong><?php echo escape($pendingEmail); ?></strong>.
                It is not active until you enter the code we emailed there.
                <a href="<?php echo escape(url('php/profile.php#email')); ?>">Finish that now</a>.
            </p>
        <?php endif; ?>

        <div class="card-grid">
            <section class="card">
                <h2>Account details</h2>
                <dl class="card-list">
                    <dt>Name</dt>
                    <dd><?php echo escape($name); ?></dd>

                    <dt>Email</dt>
                    <dd><?php echo escape($email); ?></dd>

                    <?php if ($joined !== null): ?>
                        <dt>Member since</dt>
                        <dd><?php echo escape($joined); ?></dd>
                    <?php endif; ?>
                </dl>
                <p class="card-action">
                    <a class="btn btn-primary" href="<?php echo escape(url('php/profile.php')); ?>">Edit your details</a>
                </p>
            </section>

            <section class="card">
                <h2>Browse</h2>
                <p>Ready-made bouquets and single flowers, all shaped by hand from fuzzy wire.</p>
                <p class="card-action">
                    <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">See the catalogue</a>
                </p>
            </section>

            <section class="card">
                <h2>Build your own</h2>
                <p>Pick the flowers, the colours and the wrap, and see it come together.</p>
                <p class="card-action">
                    <a class="btn btn-primary" href="<?php echo escape(url('php/customize.php')); ?>">Open the designer</a>
                </p>
            </section>

            <section class="card<?php echo $orders === [] ? ' card-empty' : ''; ?>">
                <h2>Your orders</h2>

                <?php if ($orders === []): ?>
                    <p class="card-empty-body">No orders yet.</p>
                    <p class="card-note">Anything you order will be here, with a reference you can quote.</p>
                    <p class="card-action">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse bouquets</a>
                    </p>
                <?php else: ?>
                    <?php
                    /*
                     * The reference, the total, and where the order has got to. Not the payment
                     * state as well -- three pieces is what fits the card, and the one somebody
                     * scanning this card wants is whether their bouquet is coming. Both states
                     * are on php/order.php, one tap away.
                     */
                    ?>
                    <ul class="card-list-plain">
                        <?php foreach ($orders as $recent): ?>
                            <li>
                                <a href="<?php echo escape(url('php/order.php') . '?ref=' . rawurlencode($recent['reference'])); ?>">
                                    <?php echo escape($recent['reference']); ?>
                                </a>
                                <span class="card-note">
                                    <?php echo escape(formatPrice($recent['total'])); ?>
                                    &middot;
                                    <?php echo escape(orderStatusLabel($recent['status'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="card-action">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/orders.php')); ?>">
                            <?php if ($orderCount > count($orders)): ?>
                                All <?php echo escape((string) $orderCount); ?> orders
                            <?php else: ?>
                                Your orders
                            <?php endif; ?>
                        </a>
                    </p>
                <?php endif; ?>
            </section>

            <section class="card<?php echo $designs === [] ? ' card-empty' : ''; ?>">
                <h2>Saved designs</h2>

                <?php if ($designs === []): ?>
                    <p class="card-empty-body">Nothing saved yet.</p>
                    <p class="card-note">Build a bouquet, give it a name, and it will be here.</p>
                    <p class="card-action">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/customize.php')); ?>">Open the designer</a>
                    </p>
                <?php else: ?>
                    <ul class="card-list-plain">
                        <?php foreach ($designs as $saved): ?>
                            <?php $stems = (int) $saved['stem_count']; ?>
                            <li>
                                <a href="<?php echo escape(url('php/customize.php') . '?design=' . (int) $saved['id']); ?>">
                                    <?php echo escape((string) $saved['name']); ?>
                                </a>
                                <span class="card-note">
                                    <?php echo escape((string) $stems); ?>
                                    flower<?php echo $stems === 1 ? '' : 's'; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="card-action">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/designs.php')); ?>">
                            <?php if ($designCount > count($designs)): ?>
                                All <?php echo escape((string) $designCount); ?> bouquets
                            <?php else: ?>
                                Your saved bouquets
                            <?php endif; ?>
                        </a>
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </main>
<?php renderFooter(); ?>
