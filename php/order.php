<?php
// Before any HTML: sessions, a possible redirect, and a status code on the not-found path.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * One order: the page a customer lands on straight after placing it, and the one they come back to
 * afterwards to see where it got to.
 *
 * Read-only. The single action on it -- cancelling -- posts to php/order-cancel.php, which owns the
 * write and comes back with a flash, the same split php/cart.php and php/cart-update.php use. So a
 * refresh after cancelling re-renders rather than re-applying.
 *
 * Found by reference rather than by id, because the reference is what a customer has: it is in the
 * flash they just saw, in the message the shop sends, and readable off a screenshot. fetchOrderForUser()
 * scopes the lookup by user_id in the WHERE, so another account's reference is simply not found --
 * indistinguishable from one that never existed, which is the point.
 */
$userId = (int) $_SESSION['user_id'];
$reference = orderReferenceFromInput($_GET['ref'] ?? '');

$order = null;
$events = [];
$loadFailed = false;

if ($reference !== '') {
    try {
        $order = fetchOrderForUser($userId, $reference);

        if ($order !== null) {
            // Only after the order was found, and so only for an order this account owns:
            // fetchOrderEvents() takes an id and has no user_id of its own, which is safe
            // precisely because the id came out of a lookup that was scoped.
            $events = fetchOrderEvents((int) $order['id']);
        }
    } catch (PDOException $exception) {
        error_log('order.php: ' . $exception->getMessage());
        $loadFailed = true;
    }
}

$toasts = takeFlashToasts();

/*
 * Not there. A real page with a real status code, the treatment php/product.php already settled on:
 * a redirect would tell the browser the request succeeded and leave a page nobody asked for in the
 * address bar. And a database failure is a 503 rather than a 404, because "that order does not
 * exist" is a claim, and the truth is that nobody can tell right now.
 */
if ($order === null) {
    http_response_code($loadFailed ? 503 : 404);

    renderHeader($loadFailed ? 'Something went wrong' : 'Order not found');

    if ($loadFailed) {
        $toasts[] = [
            'type' => 'error',
            'message' => 'We could not load that order right now. Check your database connection.',
        ];
    }

    renderToasts($toasts);
    ?>
    <main class="page">
        <div class="empty-state">
            <?php if ($loadFailed): ?>
                <h1>We could not load that</h1>
                <p>Your orders are unavailable for a moment. Try again shortly.</p>
            <?php else: ?>
                <h1>Order not found</h1>
                <p>That reference does not match any order on your account. Check it against your receipt.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/orders.php')); ?>">Your orders</a></p>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

$isPickup = $order['fulfilment'] === 'pickup';
$canCancel = orderCanBeCancelled($order);
$timeline = orderTimeline($order, $events);

renderHeader('Order ' . $order['reference']);
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb">
            <a href="<?php echo escape(url('php/orders.php')); ?>">Your orders</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span><?php echo escape($order['reference']); ?></span>
        </p>

        <header class="page-head">
            <p class="page-eyebrow">Placed <?php echo escape(orderDate($order['placedAt'])); ?></p>
            <h1>Order <?php echo escape($order['reference']); ?></h1>
            <p class="page-intro">
                Quote this reference if you message us about it. Everything below is what was agreed
                when you ordered, and it does not change afterwards.
            </p>
        </header>

        <?php
        /*
         * Two states side by side rather than one line, because they are two clocks that do not run
         * together: a transfer can land before anything is wrapped, and a cash order is delivered
         * before it is paid for. A single "status" would have to invent a word for every pairing.
         */
        ?>
        <div class="order-states">
            <p class="order-state order-state-<?php echo escape($order['status']); ?>">
                <span class="order-state-label">Order</span>
                <span class="order-state-value"><?php echo escape(orderStatusLabel($order['status'])); ?></span>
            </p>
            <p class="order-state order-state-pay-<?php echo escape($order['paymentStatus']); ?>">
                <span class="order-state-label">Payment</span>
                <span class="order-state-value">
                    <?php echo escape(orderPaymentStatusLabel($order['paymentStatus'])); ?>
                </span>
            </p>
        </div>

        <?php if ($order['status'] === 'cancelled'): ?>
            <p class="cart-warning" role="status">
                This order is cancelled. Nothing is being made and nothing is owed.
            </p>
        <?php elseif ($order['paymentStatus'] === 'submitted'): ?>
            <p class="cart-warning" role="status">
                We have your reference and we are checking it against our account. You will get a
                message once it clears &mdash; there is nothing else to do in the meantime.
            </p>
        <?php endif; ?>

        <div class="cart-layout order-layout">
            <div class="order-main">
                <?php
                /*
                 * Where it has got to, and when. The pills above say which word applies right now;
                 * this says how it got there and what is still coming, which is the question that
                 * otherwise turns into a message to the shop.
                 *
                 * Payment draws no dot here, deliberately and for the reason given above the pills:
                 * the two are clocks that do not run together, and folding payment into the status
                 * ladder would contradict that on the same page. A payment still sends a message --
                 * see php/config/orders-mail.php -- because a notification is not a state.
                 *
                 * First on the page, above what was ordered, because somebody opening this a week
                 * later is opening it for this and not for the receipt.
                 */
                ?>
                <section class="panel">
                    <h2>Where it has got to</h2>

                    <ol class="order-timeline">
                        <?php foreach ($timeline as $dot): ?>
                            <li class="order-timeline-step order-timeline-<?php echo escape($dot['state']); ?>">
                                <span class="order-timeline-label"><?php echo escape($dot['label']); ?></span>
                                <span class="order-timeline-at">
                                    <?php echo $dot['at'] === '' ? 'Still to come' : escape(orderDate($dot['at'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>

                <section class="panel">
                    <h2>What you ordered</h2>

                    <ul class="cart-list order-list">
                        <?php foreach ($order['lines'] as $line): ?>
                            <li class="cart-line">
                                <div class="cart-line-main">
                                    <h3 class="cart-line-name">
                                        <?php
                                        /*
                                         * Not a link, even for a catalogue line. The name and price
                                         * here are frozen copies, and a link to a product page that
                                         * now says something different would quietly contradict the
                                         * receipt it sits on.
                                         */
                                        echo escape($line['name']);
                                        ?>
                                    </h3>

                                    <p class="cart-line-meta">
                                        <?php echo escape(formatPrice($line['unitPrice'])); ?> each
                                        &times; <?php echo (int) $line['quantity']; ?>
                                    </p>

                                    <?php if ($line['parts'] !== []): ?>
                                        <?php
                                        /*
                                         * What the shop has to make, and in which colour. The same
                                         * swatch the basket and the admin parts list use, and the
                                         * colour goes through cartSwatchColour() on its way into a
                                         * style attribute -- it was checked when it was written,
                                         * but it is being put somewhere new now.
                                         */
                                        ?>
                                        <ul class="cart-parts">
                                            <?php foreach ($line['parts'] as $part): ?>
                                                <li class="cart-part">
                                                    <span class="colour-swatch" aria-hidden="true"
                                                          style="background: <?php echo escape(cartSwatchColour($part['colour'])); ?>"></span>
                                                    <span class="cart-part-name"><?php echo escape($part['name']); ?></span>
                                                    <span class="cart-part-count">
                                                        <?php echo $part['quantity'] > 1 ? '&times;' . (int) $part['quantity'] : ''; ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>

                                <div class="cart-line-side">
                                    <p class="cart-line-total">
                                        <?php echo escape(formatPrice($line['lineTotal'])); ?>
                                    </p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="panel">
                    <h2><?php echo $isPickup ? 'Collection' : 'Delivery'; ?></h2>

                    <dl class="order-facts">
                        <dt>Name</dt>
                        <dd><?php echo escape($order['contactName']); ?></dd>

                        <dt>Phone</dt>
                        <dd><?php echo escape($order['contactPhone']); ?></dd>

                        <dt>Email</dt>
                        <dd><?php echo escape($order['contactEmail']); ?></dd>

                        <?php if ($isPickup): ?>
                            <dt>Collecting</dt>
                            <dd>You are picking this up from us. We will message you when it is ready.</dd>
                        <?php else: ?>
                            <dt>Address</dt>
                            <dd>
                                <?php
                                /*
                                 * Built as a list and filtered, rather than five lines each wrapped
                                 * in a conditional: the postcode is optional, and a fixed template
                                 * would leave a blank line in the middle of the address on most
                                 * orders.
                                 *
                                 * One part per line and no commas. The line break is already the
                                 * separator, and an address that has both reads as a sentence
                                 * someone pressed Enter in.
                                 */
                                $addressParts = array_values(array_filter([
                                    $order['addressLine'],
                                    $order['barangay'],
                                    $order['city'],
                                    $order['province'],
                                    $order['postcode'],
                                ], static fn (string $part): bool => $part !== ''));
                                ?>
                                <?php foreach ($addressParts as $part): ?>
                                    <?php echo escape($part); ?><br>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <?php if ($order['notes'] !== ''): ?>
                            <dt>Your note</dt>
                            <dd class="order-note"><?php echo nl2br(escape($order['notes'])); ?></dd>
                        <?php endif; ?>
                    </dl>
                </section>

                <section class="panel">
                    <h2>Payment</h2>

                    <dl class="order-facts">
                        <dt>Method</dt>
                        <dd><?php echo escape(paymentMethodLabel($order['paymentMethod'], $order['fulfilment'])); ?></dd>

                        <?php if ($order['paymentReference'] !== ''): ?>
                            <dt>Your reference</dt>
                            <dd><?php echo escape($order['paymentReference']); ?></dd>
                        <?php endif; ?>

                        <dt>State</dt>
                        <dd><?php echo escape(orderPaymentStatusLabel($order['paymentStatus'])); ?></dd>
                    </dl>

                    <?php if ($order['paymentMethod'] === 'cod' && $order['status'] !== 'cancelled'): ?>
                        <p class="card-note">
                            <?php echo $isPickup ? 'Pay when you collect it.' : 'Pay when it reaches you.'; ?>
                        </p>
                    <?php endif; ?>
                </section>
            </div>

            <div class="cart-foot order-aside">
                <div class="cart-summary">
                    <p class="cart-total-row">
                        <span>Total</span>
                        <span class="cart-total-figure"><?php echo escape(formatPrice($order['total'])); ?></span>
                    </p>
                    <p class="cart-total-note">
                        <?php echo (int) $order['itemCount']; ?>
                        item<?php echo (int) $order['itemCount'] === 1 ? '' : 's'; ?>.
                        Delivery is quoted separately and is not in this figure.
                    </p>

                    <?php if ($canCancel): ?>
                        <?php
                        /*
                         * Only while it is placed and unpaid, which is what orderCanBeCancelled()
                         * says and what the UPDATE in cancelOrderForUser() says again in SQL --
                         * between drawing this button and pressing it the shop could have confirmed
                         * the payment, and the check has to be in the statement that changes the row.
                         *
                         * data-confirm is the hook js/script.js already wires on the admin's delete
                         * buttons. With no JavaScript it simply cancels, which is why the label says
                         * exactly that.
                         */
                        ?>
                        <form method="post" action="<?php echo escape(url('php/order-cancel.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="ref" value="<?php echo escape($order['reference']); ?>">
                            <button type="submit" class="btn btn-danger"
                                    data-confirm="Cancel this order? We will stop work on it.">
                                Cancel this order
                            </button>
                        </form>
                        <p class="cart-total-note">
                            You can cancel until we confirm your payment. After that, message us.
                        </p>
                    <?php elseif ($order['status'] === 'placed'): ?>
                        <p class="cart-total-note">
                            Your payment is confirmed, so this can no longer be cancelled here.
                            Message us if something has changed.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="cart-foot-actions">
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/products.php')); ?>">Keep shopping</a>
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/orders.php')); ?>">Your orders</a>
                </div>
            </div>
        </div>
    </main>
<?php renderFooter(); ?>
