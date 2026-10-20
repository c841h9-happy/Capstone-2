<?php
// Before any HTML: sessions and a possible redirect to sign in.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * Every order on this account, newest first.
 *
 * Read-only, and a summary rather than a receipt: a reference, a date, where it has got to and what
 * it came to. Drawing the lines of every order to print that would be reading the whole history to
 * show a list of it, which is why fetchOrdersForUser() counts in SQL and does not touch order_items
 * beyond the count.
 *
 * Capped at ORDER_LIST_MAX and it says so when the cap is reached. A pager is a lot of machinery
 * for a list nobody has yet -- fifty orders from one customer of a handicraft shop is already an
 * unusual account -- but silently showing the first fifty of sixty would be worse than either.
 */
$userId = (int) $_SESSION['user_id'];

$orders = [];
$totalOrders = 0;
$loadFailed = false;

try {
    $orders = fetchOrdersForUser($userId);
    $totalOrders = countOrdersForUser($userId);
} catch (PDOException $exception) {
    error_log('orders.php: ' . $exception->getMessage());
    $loadFailed = true;
}

$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'We could not read your orders right now. Check your database connection.',
    ];
}

renderHeader('Your orders');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Your account</p>
            <h1>Your orders</h1>
            <p class="page-intro">
                Everything you have ordered, newest first. Open one to see what is in it, where it
                is going and how it is being paid for.
            </p>
        </header>

<?php if ($orders === []): ?>
        <div class="empty-state">
            <h2><?php echo $loadFailed ? 'We could not read your orders' : 'No orders yet'; ?></h2>
            <p>
                <?php if ($loadFailed): ?>
                    Nothing has been lost &mdash; try again in a moment.
                <?php else: ?>
                    When you order something it will show up here, with a reference you can quote.
                <?php endif; ?>
            </p>
            <p class="empty-state-actions">
                <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse bouquets</a>
                <a class="btn btn-quiet" href="<?php echo escape(url('php/customize.php')); ?>">Build your own</a>
            </p>
        </div>
<?php else: ?>
        <p class="catalogue-count">
            <?php echo $totalOrders; ?>
            order<?php echo $totalOrders === 1 ? '' : 's'; ?>
            <?php if ($totalOrders > count($orders)): ?>
                &mdash; showing the most recent <?php echo count($orders); ?>
            <?php endif; ?>
        </p>

        <ul class="order-history">
            <?php foreach ($orders as $order): ?>
                <?php $orderUrl = url('php/order.php') . '?ref=' . rawurlencode($order['reference']); ?>
                <li class="order-history-row">
                    <div class="order-history-main">
                        <h2 class="order-history-reference">
                            <a href="<?php echo escape($orderUrl); ?>"><?php echo escape($order['reference']); ?></a>
                        </h2>
                        <p class="order-history-meta">
                            <?php echo escape(orderDate($order['placedAt'])); ?>
                            &middot;
                            <?php echo (int) $order['itemCount']; ?>
                            item<?php echo (int) $order['itemCount'] === 1 ? '' : 's'; ?>
                            &middot;
                            <?php echo escape(orderFulfilmentLabel($order['fulfilment'])); ?>
                        </p>
                    </div>

                    <?php
                    /*
                     * Both states, as on the order page and for the same reason: an order can be
                     * delivered and unpaid, or paid and not yet made. The pill classes are the
                     * order's own rather than a generic badge, because the colours have to mean
                     * something specific -- amber for waiting on us, grey for cancelled.
                     */
                    ?>
                    <div class="order-history-states">
                        <span class="order-pill order-pill-<?php echo escape($order['status']); ?>">
                            <?php echo escape(orderStatusLabel($order['status'])); ?>
                        </span>
                        <span class="order-pill order-pill-pay-<?php echo escape($order['paymentStatus']); ?>">
                            <?php echo escape(orderPaymentStatusLabel($order['paymentStatus'])); ?>
                        </span>
                    </div>

                    <div class="order-history-side">
                        <p class="order-history-total">
                            <?php echo escape(formatPrice($order['total'])); ?>
                        </p>
                        <a class="btn btn-quiet" href="<?php echo escape($orderUrl); ?>">View</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
<?php endif; ?>
    </main>
<?php renderFooter(); ?>
