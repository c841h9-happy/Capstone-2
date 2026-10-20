<?php
// Before any HTML: reading the basket can mint a cookie, and a cookie is a header.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cart.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

/*
 * The basket.
 *
 * **No requireLogin(), on purpose.** A visitor may fill a basket without an account and is asked
 * for one at checkout -- the decision recorded in php/cart-update.php and the reason the carts
 * table has a token_hash column. Somebody made to sign up before they can see what three things
 * come to mostly just leaves.
 *
 * Read-only, like php/designs.php: every button here posts to php/cart-update.php, which owns the
 * writes and comes back with a flash. So a refresh after changing a quantity re-renders, it does
 * not re-apply.
 *
 * Nothing on this page re-prices anything. The names and prices shown are the ones frozen into the
 * line when it was added, which is what fetchCartLines() reads and why it joins to nothing.
 */
$lines = [];
$loadFailed = false;

try {
    $lines = fetchCartLines();
} catch (PDOException $exception) {
    error_log('cart.php: ' . $exception->getMessage());
    $loadFailed = true;
}

$total = cartTotal($lines);
$cartUrl = url('php/cart.php');
$updateUrl = url('php/cart-update.php');

// How many things, not how many lines -- three of one is three things in the basket.
$itemCount = 0;

// A line whose product has been withdrawn since it was added. Shown, not charged for, and not
// quietly deleted: a basket that empties itself while nobody is looking is worse than one that
// explains itself.
$unorderable = 0;

foreach ($lines as $line) {
    $itemCount += (int) $line['quantity'];

    if ($line['orderable'] === false) {
        $unorderable++;
    }
}

$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'We could not read your basket right now. Check your database connection.',
    ];
}

renderHeader('Your basket');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Your order</p>
            <h1>Your basket</h1>
            <p class="page-intro">
                Everything you have picked out, at the price it was when you added it. Nothing here
                is reserved until an order is placed.
            </p>
        </header>

<?php if ($lines === []): ?>
        <div class="empty-state">
            <h2><?php echo $loadFailed ? 'We could not read your basket' : 'Your basket is empty'; ?></h2>
            <p>
                <?php if ($loadFailed): ?>
                    Nothing has been lost &mdash; try again in a moment.
                <?php else: ?>
                    Have a look through the bouquets, or build one of your own.
                <?php endif; ?>
            </p>
            <p class="empty-state-actions">
                <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse bouquets</a>
                <a class="btn btn-quiet" href="<?php echo escape(url('php/customize.php')); ?>">Build your own</a>
            </p>
        </div>
<?php else: ?>
        <p class="catalogue-count">
            <?php echo $itemCount; ?>
            item<?php echo $itemCount === 1 ? '' : 's'; ?>
            in <?php echo count($lines); ?>
            line<?php echo count($lines) === 1 ? '' : 's'; ?>
        </p>

        <?php if ($unorderable > 0): ?>
            <!--
                Said once at the top as well as on each line. Somebody who scrolls straight to the
                total needs to know why it is lower than they expected before they read the lines.
            -->
            <p class="cart-warning" role="status">
                <?php echo $unorderable === 1 ? 'One item is' : $unorderable . ' items are'; ?>
                no longer on sale and <?php echo $unorderable === 1 ? 'has' : 'have'; ?>
                been left out of the total. You can remove
                <?php echo $unorderable === 1 ? 'it' : 'them'; ?> below.
            </p>
        <?php endif; ?>

        <?php
        /*
         * The lines on the left, what they come to on the right. Two children and no more: the
         * count and the warning above are about the basket rather than about either column, and
         * the warning has to be read before the total it explains.
         */
        ?>
        <div class="cart-layout">
            <ul class="cart-list">
                <?php foreach ($lines as $line): ?>
                    <?php
                    $lineId = (int) $line['id'];
                    $isDesign = $line['kind'] === 'design';
                    $orderable = $line['orderable'] === true;

                    // A catalogue line links back to its product page; a designed one has no page
                    // of its own to link to. A withdrawn product has no page either -- the link
                    // would 404, so it is dropped rather than left to break.
                    $productUrl = (!$isDesign && $orderable && $line['productId'] !== null)
                        ? url('php/product.php') . '?id=' . (int) $line['productId']
                        : null;
                    ?>
                    <li class="cart-line<?php echo $orderable ? '' : ' cart-line-gone'; ?>">
                        <div class="cart-line-main">
                            <h2 class="cart-line-name">
                                <?php if ($productUrl !== null): ?>
                                    <a href="<?php echo escape($productUrl); ?>"><?php echo escape((string) $line['name']); ?></a>
                                <?php else: ?>
                                    <?php echo escape((string) $line['name']); ?>
                                <?php endif; ?>
                            </h2>

                            <p class="cart-line-meta">
                                <?php echo escape(formatPrice((string) $line['unitPrice'])); ?> each
                            </p>

                            <?php if (!$orderable): ?>
                                <p class="cart-line-gone-note">
                                    No longer on sale. It is not included in the total.
                                </p>
                            <?php endif; ?>

                            <?php if ($isDesign && $line['parts'] !== []): ?>
                                <?php
                                /*
                                 * What the bouquet is made of, frozen at the moment it was added.
                                 * The swatch is the same one the admin parts list uses, and the
                                 * colour goes through cartSwatchColour() on the way out -- it was
                                 * checked when it was written, but it is being put inside a style
                                 * attribute now.
                                 */
                                ?>
                                <ul class="cart-parts">
                                    <?php foreach ($line['parts'] as $part): ?>
                                        <li class="cart-part">
                                            <span class="colour-swatch" aria-hidden="true"
                                                  style="background: <?php echo escape(cartSwatchColour((string) $part['colour'])); ?>"></span>
                                            <span class="cart-part-name">
                                                <?php echo escape((string) $part['name']); ?>
                                            </span>
                                            <span class="cart-part-count">
                                                <?php echo (int) $part['quantity'] > 1 ? '&times;' . (int) $part['quantity'] : ''; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="cart-line-side">
                            <?php
                            /*
                             * One form per line, each carrying its own item_id. A single form
                             * around the whole basket would need array field names and would
                             * rewrite every line on every change, which is a lot of moving parts
                             * for a page whose job is to change one number.
                             *
                             * back is this page, so the Update lands where it was pressed.
                             * cartSafeDestination() checks it on arrival regardless -- a hidden
                             * field is the visitor's to edit.
                             */
                            ?>
                            <form class="cart-qty" method="post" action="<?php echo escape($updateUrl); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="item_id" value="<?php echo $lineId; ?>">
                                <input type="hidden" name="back" value="<?php echo escape($cartUrl); ?>">

                                <label class="visually-hidden" for="cart-qty-<?php echo $lineId; ?>">
                                    Quantity of <?php echo escape((string) $line['name']); ?>
                                </label>
                                <input id="cart-qty-<?php echo $lineId; ?>" name="quantity" type="number"
                                       value="<?php echo (int) $line['quantity']; ?>"
                                       min="<?php echo CART_QUANTITY_MIN; ?>"
                                       max="<?php echo CART_QUANTITY_MAX; ?>" step="1" inputmode="numeric">

                                <button type="submit" class="btn btn-quiet">Update</button>
                            </form>

                            <p class="cart-line-total">
                                <?php echo escape(formatPrice((string) $line['lineTotal'])); ?>
                            </p>

                            <form method="post" action="<?php echo escape($updateUrl); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="item_id" value="<?php echo $lineId; ?>">
                                <input type="hidden" name="back" value="<?php echo escape($cartUrl); ?>">
                                <button type="submit" class="btn btn-danger">Remove</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="cart-foot">
                <div class="cart-summary">
                    <p class="cart-total-row">
                        <span>Total</span>
                        <span class="cart-total-figure"><?php echo escape(formatPrice($total)); ?></span>
                    </p>
                    <p class="cart-total-note">
                        Delivery is arranged after you order, so it is not in this figure yet.
                    </p>

                    <?php
                    /*
                     * Live, at last -- checkout is the second half of Commerce and this is where it
                     * starts. Three states rather than one button, because two of them are things
                     * the customer can act on and a button that bounces straight back is worse
                     * than one that says why:
                     *
                     *   * a withdrawn line refuses the order in placeOrder(), so it is refused
                     *     here too, next to the Remove buttons that fix it -- being asked for an
                     *     address and then turned down at the end is the worst version of the
                     *     same rule;
                     *   * a signed-out visitor is sent to sign in, which is the rule this page has
                     *     always stated: a basket needs no account, an order does. mergeGuestCart()
                     *     carries these lines onto the account when they do;
                     *   * otherwise, checkout.
                     *
                     * A link and not a form. Nothing is written by arriving at checkout, and a GET
                     * means the back button behaves.
                     */
                    ?>
                    <?php if ($unorderable > 0): ?>
                        <button type="button" class="btn btn-primary cart-checkout" disabled>Checkout</button>
                        <p class="cart-total-note">
                            Remove the
                            <?php echo $unorderable === 1 ? 'item' : 'items'; ?>
                            that <?php echo $unorderable === 1 ? 'is' : 'are'; ?>
                            no longer on sale and you can check out.
                        </p>
                    <?php elseif (isUserLoggedIn()): ?>
                        <a class="btn btn-primary cart-checkout"
                           href="<?php echo escape(url('php/checkout.php')); ?>">Checkout</a>
                    <?php else: ?>
                        <a class="btn btn-primary cart-checkout"
                           href="<?php echo escape(url('php/login.php')); ?>">Sign in to check out</a>
                    <?php endif; ?>

                    <?php if (!isUserLoggedIn()): ?>
                        <p class="cart-total-note">
                            Your basket is kept on this device.
                            <a href="<?php echo escape(url('php/login.php')); ?>">Sign in</a>
                            and it moves to your account &mdash; you will need one to check out.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="cart-foot-actions">
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/products.php')); ?>">Keep shopping</a>

                    <form method="post" action="<?php echo escape($updateUrl); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="empty">
                        <input type="hidden" name="back" value="<?php echo escape($cartUrl); ?>">
                        <!--
                            data-confirm is the hook js/script.js already wires on the admin's
                            delete buttons. With no JavaScript the button simply empties the
                            basket, which is why the label says exactly that.
                        -->
                        <button type="submit" class="btn btn-danger"
                                data-confirm="Empty your basket? This cannot be undone.">
                            Empty basket
                        </button>
                    </form>
                </div>
            </div>
        </div>
<?php endif; ?>
    </main>
<?php renderFooter(); ?>
