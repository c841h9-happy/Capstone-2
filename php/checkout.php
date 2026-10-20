<?php
// Before any HTML: this page redirects, may place an order, and reads the basket -- all of which
// need the headers unsent.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cart.php';
require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * Checkout.
 *
 * **requireLogin() here and nowhere earlier.** That is the decision recorded when the basket was
 * built and repeated on every page since: a visitor may fill a basket without an account, and is
 * asked for one at the point where the shop needs to know who it is dealing with. Signing in from
 * here carries the basket in with them -- mergeGuestCart() runs at sign-in and the lines follow.
 *
 * This page handles its own POST and re-renders itself on failure, which is how php/profile.php
 * and php/login.php work and for the same reason: there are twelve boxes to put back, and a form
 * that empties itself over one bad phone number is a form people give up on. The write-only pages
 * in this project -- php/cart-update.php, php/design-save.php -- redirect because they have
 * nothing to redisplay. This has plenty.
 *
 * **No money crosses the form.** Not the total, not a line price, not a line id. placeOrder() reads
 * the basket again inside its own transaction and totals it with the same cartTotal() this page
 * printed. A hidden total field would be the customer's to edit, and the only way to be sure it
 * was not edited is to not have one.
 */
$userId = (int) $_SESSION['user_id'];

$account = null;
$lines = [];
$loadFailed = false;

try {
    $statement = getDatabaseConnection()->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $account = $statement->fetch() ?: null;

    $lines = fetchCartLines();
} catch (PDOException $exception) {
    error_log('checkout.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Signed in against an account that is no longer there. Same treatment php/profile.php gives it.
if ($account === null && !$loadFailed) {
    $_SESSION = [];
    session_destroy();
    redirectTo(url('php/login.php'));
}

$account = $account ?? ['name' => '', 'email' => ''];

/*
 * Two ways in that are not a checkout at all, both answered by sending them back to the basket
 * with the reason rather than by rendering a form that cannot be completed.
 *
 * The withdrawn-line case matters more than it looks: placeOrder() refuses the whole order if one
 * is present, so letting somebody fill in an address first and be refused at the end would be the
 * worst version of the same rule. php/cart.php already says which line and offers the Remove
 * button, so that is where this belongs.
 */
if (!$loadFailed) {
    $unorderable = 0;

    foreach ($lines as $line) {
        if ($line['orderable'] === false) {
            $unorderable++;
        }
    }

    if ($lines === []) {
        flashToast('error', 'Your basket is empty, so there is nothing to check out.');
        redirectTo(url('php/cart.php'));
    }

    if ($unorderable > 0) {
        flashToast(
            'error',
            $unorderable === 1
                ? 'One item in your basket is no longer on sale. Remove it before checking out.'
                : $unorderable . ' items in your basket are no longer on sale. Remove them before checking out.'
        );
        redirectTo(url('php/cart.php'));
    }
}

$total = cartTotal($lines);

$itemCount = 0;

foreach ($lines as $line) {
    $itemCount += (int) $line['quantity'];
}

$errors = [];

/*
 * What goes in the boxes. On the first visit this is the account's name and the two safe defaults;
 * after a failed submit it is whatever validateCheckout() made of what was typed, so nothing has
 * to be entered twice.
 */
$values = [
    'fulfilment' => 'delivery',
    'payment_method' => 'cod',
    'payment_reference' => '',
    'contact_name' => (string) $account['name'],
    'contact_phone' => '',
    'contact_email' => (string) $account['email'],
    'address_line' => '',
    'barangay' => '',
    'city' => '',
    'province' => '',
    'postcode' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadFailed) {
    // Before the first field is read: this POST places an order and takes a payment reference.
    requireCsrfToken();

    $checked = validateCheckout($_POST, $account);
    $errors = $checked['errors'];

    /*
     * The values come back even when there are errors -- that is the whole point of the shape
     * validateCheckout() returns. One exception: a pickup has had its address fields wiped, and
     * wiping them in the boxes too would throw away what somebody typed before they changed their
     * mind. So the boxes keep what was posted and the order keeps what was validated.
     */
    $values = $checked['values'];
    $values['address_line'] = orderTextLine($_POST['address_line'] ?? '');
    $values['barangay'] = orderTextLine($_POST['barangay'] ?? '');
    $values['city'] = orderTextLine($_POST['city'] ?? '');
    $values['province'] = orderTextLine($_POST['province'] ?? '');
    $values['postcode'] = orderTextLine($_POST['postcode'] ?? '');
    $values['payment_reference'] = orderTextLine($_POST['payment_reference'] ?? '');

    if ($errors === []) {
        try {
            $placed = placeOrder($userId, $checked['values']);

            flashToast('success', 'Order ' . $placed['reference'] . ' is in. We will be in touch shortly.');
            redirectTo(url('php/order.php') . '?ref=' . rawurlencode($placed['reference']));
        } catch (RuntimeException $exception) {
            // Thrown by placeOrder() with a sentence already written for a customer -- an emptied
            // basket, or a line withdrawn between loading this page and pressing the button.
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('checkout.php: ' . $exception->getMessage());
            $errors[] = 'We could not place your order right now. Nothing has been charged. Try again in a moment.';
        }
    }
}

$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'We could not read your basket right now. Check your database connection.',
    ];
}

// The project has no inline field errors anywhere -- php/profile.php and php/login.php both surface
// their complaints as toasts, and one form doing it differently is one form people have to learn.
foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

$isPickup = $values['fulfilment'] === 'pickup';
$needsReference = paymentNeedsReference($values['payment_method']);

renderHeader('Checkout');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Your order</p>
            <h1>Checkout</h1>
            <p class="page-intro">
                Tell us where this is going and how you would like to pay. Nothing is charged
                through this site &mdash; we confirm your payment by hand and message you either way.
            </p>
        </header>

<?php if ($loadFailed): ?>
        <div class="empty-state">
            <h2>We could not read your basket</h2>
            <p>Nothing has been lost &mdash; try again in a moment.</p>
            <p class="empty-state-actions">
                <a class="btn btn-primary" href="<?php echo escape(url('php/cart.php')); ?>">Back to your basket</a>
            </p>
        </div>
<?php else: ?>
        <?php
        /*
         * The same two-column shape as the basket, and deliberately so: the thing being decided is
         * on the left, what it comes to is on the right, and somebody arriving here from
         * php/cart.php finds the total where they left it.
         */
        ?>
        <div class="cart-layout checkout-layout">
            <form id="checkout-form" class="panel-form checkout-form" method="post"
                  action="<?php echo escape(url('php/checkout.php')); ?>" data-checkout>
                <?php echo csrfField(); ?>

                <?php
                /*
                 * Delivery or collection. Tiles rather than a <select>, because each carries a line
                 * of explanation, and the same tile the customizer's parts picker uses -- one set
                 * of rules in css/main.css styles both.
                 */
                ?>
                <fieldset class="customizer-group">
                    <legend>How would you like to get it?</legend>

                    <div class="customizer-options customizer-options-plain">
                        <label class="customizer-option">
                            <input type="radio" name="fulfilment" value="delivery"
                                   data-checkout-fulfilment
                                   <?php echo $isPickup ? '' : 'checked'; ?>>
                            <span class="customizer-option-name">Delivered</span>
                            <span class="customizer-option-hint">
                                We arrange a courier and quote the fee once we know where it is going.
                            </span>
                        </label>

                        <label class="customizer-option">
                            <input type="radio" name="fulfilment" value="pickup"
                                   data-checkout-fulfilment
                                   <?php echo $isPickup ? 'checked' : ''; ?>>
                            <span class="customizer-option-name">Collected</span>
                            <span class="customizer-option-hint">
                                We message you when it is ready and you pick it up from us. No fee.
                            </span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="customizer-group">
                    <legend>Who is receiving it?</legend>

                    <label for="checkout-name">Name</label>
                    <input id="checkout-name" name="contact_name" type="text" maxlength="100"
                           autocomplete="name" required
                           value="<?php echo escape($values['contact_name']); ?>">

                    <label for="checkout-phone">Phone number</label>
                    <input id="checkout-phone" name="contact_phone" type="tel" maxlength="30"
                           autocomplete="tel" inputmode="tel" required
                           value="<?php echo escape($values['contact_phone']); ?>">
                    <p class="field-hint">
                        A mobile or landline &mdash; however you normally write it. This is how we
                        reach you about the order.
                    </p>

                    <?php
                    /*
                     * The email is shown and not asked for. It is the address the account already
                     * has and the one the shop knows how to reach; a second address typed here
                     * would be one nobody has ever confirmed, on the one message that matters.
                     */
                    ?>
                    <p class="field-hint">
                        We will write to <strong><?php echo escape($values['contact_email']); ?></strong>.
                        Change it on your <a href="<?php echo escape(url('php/profile.php')); ?>">profile</a>
                        if that is not right.
                    </p>
                </fieldset>

                <?php
                /*
                 * Always rendered, never hidden by the server. With JavaScript on, the script below
                 * hides it while "Collected" is chosen; with JavaScript off it stays visible and
                 * the note explains when it is needed -- and validateCheckout() wipes it on a
                 * pickup either way, so a filled-in address on a collection cannot reach the
                 * database. The server is what makes that true; the hiding is a convenience.
                 */
                ?>
                <fieldset class="customizer-group checkout-address"
                          data-checkout-address<?php echo $isPickup ? ' hidden' : ''; ?>>
                    <legend>Where should we deliver it?</legend>
                    <p class="field-hint">Only needed if you chose to have it delivered.</p>

                    <label for="checkout-address">House or unit number and street</label>
                    <input id="checkout-address" name="address_line" type="text" maxlength="160"
                           autocomplete="address-line1"
                           value="<?php echo escape($values['address_line']); ?>">

                    <label for="checkout-barangay">Barangay</label>
                    <input id="checkout-barangay" name="barangay" type="text" maxlength="80"
                           autocomplete="address-level3"
                           value="<?php echo escape($values['barangay']); ?>">

                    <label for="checkout-city">City or municipality</label>
                    <input id="checkout-city" name="city" type="text" maxlength="80"
                           autocomplete="address-level2"
                           value="<?php echo escape($values['city']); ?>">

                    <label for="checkout-province">Province</label>
                    <input id="checkout-province" name="province" type="text" maxlength="80"
                           autocomplete="address-level1"
                           value="<?php echo escape($values['province']); ?>">

                    <label for="checkout-postcode">Postcode</label>
                    <input id="checkout-postcode" name="postcode" type="text" maxlength="10"
                           autocomplete="postal-code" inputmode="numeric"
                           value="<?php echo escape($values['postcode']); ?>">
                    <p class="field-hint">Optional &mdash; the courier manages without it.</p>
                </fieldset>

                <fieldset class="customizer-group">
                    <legend>How would you like to pay?</legend>

                    <div class="customizer-options customizer-options-plain">
                        <label class="customizer-option">
                            <input type="radio" name="payment_method" value="cod"
                                   data-checkout-payment
                                   <?php echo $values['payment_method'] === 'cod' ? 'checked' : ''; ?>>
                            <span class="customizer-option-name">Cash</span>
                            <span class="customizer-option-hint">
                                Pay when it reaches you, or when you collect it.
                            </span>
                        </label>

                        <label class="customizer-option">
                            <input type="radio" name="payment_method" value="gcash"
                                   data-checkout-payment
                                   <?php echo $values['payment_method'] === 'gcash' ? 'checked' : ''; ?>>
                            <span class="customizer-option-name">GCash</span>
                            <span class="customizer-option-hint">
                                Send it, then type the reference number from your receipt below.
                            </span>
                        </label>

                        <label class="customizer-option">
                            <input type="radio" name="payment_method" value="bank"
                                   data-checkout-payment
                                   <?php echo $values['payment_method'] === 'bank' ? 'checked' : ''; ?>>
                            <span class="customizer-option-name">Bank transfer</span>
                            <span class="customizer-option-hint">
                                Same again &mdash; transfer it, then give us the reference.
                            </span>
                        </label>
                    </div>

                    <div class="checkout-reference"
                         data-checkout-reference<?php echo $needsReference ? '' : ' hidden'; ?>>
                        <label for="checkout-reference">Reference number</label>
                        <input id="checkout-reference" name="payment_reference" type="text"
                               maxlength="<?php echo ORDER_REFERENCE_MAX; ?>" autocomplete="off"
                               value="<?php echo escape($values['payment_reference']); ?>">
                        <p class="field-hint">
                            Only needed for GCash or a bank transfer. Copy it exactly as your app
                            shows it &mdash; it is how we find your payment.
                        </p>
                    </div>

                    <?php
                    /*
                     * Said plainly, because it is the part of this shop a customer is most likely
                     * to be caught out by: typing a reference does not mark anything paid. Somebody
                     * at the shop looks at the receiving account and agrees, and until then the
                     * order says so on its own page.
                     */
                    ?>
                    <p class="field-hint">
                        We check every payment by hand against our own account, so an order stays
                        marked unpaid until we have seen it. You will get a message when we do.
                    </p>
                </fieldset>

                <fieldset class="customizer-group">
                    <legend>Anything we should know?</legend>

                    <label for="checkout-notes">Note</label>
                    <textarea id="checkout-notes" name="notes" rows="3"
                              maxlength="<?php echo ORDER_NOTES_MAX; ?>"><?php echo escape($values['notes']); ?></textarea>
                    <p class="field-hint">
                        Optional &mdash; a delivery instruction, or the date you need it for. Up to
                        <?php echo ORDER_NOTES_MAX; ?> characters.
                    </p>
                </fieldset>

                <?php
                /*
                 * The submit lives in the form and the form wraps only the left column, so the
                 * button in the summary on the right is this one's twin -- see below. Two buttons,
                 * one form, because in two columns this one is the foot of a form about 1700px
                 * tall, by which point the summary's button has long since scrolled away.
                 *
                 * Below 900px the columns stack and that argument stops holding: the two land a
                 * few hundred pixels apart with nothing between them but the summary, and this
                 * one comes first, before the total. So css/main.css hides this one there and
                 * keeps the summary's, which is the one sitting under the figure.
                 */
                ?>
                <button type="submit" class="btn btn-primary checkout-place">Place order</button>
            </form>

            <div class="cart-foot checkout-aside">
                <div class="cart-summary">
                    <h2 class="checkout-summary-head">Your order</h2>

                    <ul class="checkout-summary-list">
                        <?php foreach ($lines as $line): ?>
                            <li class="checkout-summary-line">
                                <span class="checkout-summary-name">
                                    <?php echo escape((string) $line['name']); ?>
                                    <?php if ((int) $line['quantity'] > 1): ?>
                                        <span class="checkout-summary-qty">&times;<?php echo (int) $line['quantity']; ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="checkout-summary-figure">
                                    <?php echo escape(formatPrice((string) $line['lineTotal'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="cart-total-row">
                        <span>Total</span>
                        <span class="cart-total-figure"><?php echo escape(formatPrice($total)); ?></span>
                    </p>
                    <p class="cart-total-note">
                        <?php echo $itemCount; ?> item<?php echo $itemCount === 1 ? '' : 's'; ?>.
                        Delivery is quoted separately, so it is not in this figure yet.
                    </p>

                    <!--
                        form= points at the form in the other column, which is what lets a button
                        outside a form submit it. Supported everywhere this project targets, and
                        the alternative -- wrapping both columns in one <form> -- would put a grid
                        container inside a form element and make the layout the form's problem.
                    -->
                    <button type="submit" form="checkout-form" class="btn btn-primary checkout-place">
                        Place order
                    </button>
                    <p class="cart-total-note">
                        You can still cancel this yourself afterwards, right up until we confirm
                        your payment.
                    </p>
                </div>

                <div class="cart-foot-actions">
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/cart.php')); ?>">Back to your basket</a>
                </div>
            </div>
        </div>
<?php endif; ?>
    </main>
<?php renderFooter(); ?>
