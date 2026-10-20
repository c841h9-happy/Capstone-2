<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to run
// before the redirect below, which needs the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/orders-admin.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * The order queue.
 *
 * Every order the shop has taken, filtered by default to the ones that are still work. It owns the
 * three actions that need no form of their own -- mark paid, take that back, and cancel -- and
 * php/admin/order.php is where one order is read in full, including what to actually make.
 *
 * Why those three and not "Being made" and "Delivered": see the comment at the top of
 * php/config/orders-admin.php. Payment is Commerce's, order management is the Orders module's, and
 * this page is the Commerce half.
 */

$status = adminOrderStatusFilter($_GET['status'] ?? null);
$payment = adminOrderPaymentFilter($_GET['payment'] ?? null);
$search = adminOrderSearch($_GET['q'] ?? null);

$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

/**
 * A link to this page with the filters on it.
 *
 * Empty values, the default state filter and page 1 are left out, so the plain queue stays at
 * /Capstone/php/admin/orders.php with nothing after it.
 */
function adminOrdersUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/orders.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * Everything on the page carries the filters that are already on, for the reason the products list
 * gives: marking the third order of a filtered page 2 as paid should leave the admin looking at
 * that page, not back at the top of the queue hunting for where they were.
 *
 * The default state filter is dropped from the URL rather than written out, so the bare link and
 * the "still open" link are the same page rather than two that look different.
 */
$filterParams = [
    'status' => $status === ADMIN_ORDER_STATUS_DEFAULT ? '' : $status,
    'payment' => $payment === ADMIN_ORDER_PAYMENT_DEFAULT ? '' : $payment,
    'q' => $search,
];

$listUrl = adminOrdersUrl($filterParams + ['page' => $page]);

/*
 * Mark paid, take that back, cancel, start making, delivered.
 *
 * Answered with a redirect and a flashed message rather than by rendering the queue inline, exactly
 * as php/admin/products.php does: a POST left as the current request means a refresh offers to send
 * it again, and "mark paid" is not a thing to offer twice.
 *
 * The work itself is applyAdminOrderAction() in php/config/orders-admin.php, because
 * php/admin/order.php offers the same buttons and two copies of a branch this long is how the two
 * end up disagreeing about what "already paid" says. It hands back the message; this page decides
 * where to land afterwards, which is the one part the two screens differ on. It is also where the
 * customer gets emailed, so neither screen can move an order without word going out.
 *
 * This page names no action at all -- whatever arrives is passed straight through and answered,
 * including the undo, which this page draws no button for. The check that matters is in the SQL,
 * not in the markup, so a hand-written POST for 'unpack' is answered correctly rather than by a
 * page that assumed nobody would send one.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    try {
        $result = applyAdminOrderAction($action, $_POST['ref'] ?? '');
        flashToast($result['type'], $result['message']);
    } catch (PDOException $exception) {
        error_log('admin/orders.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change right now. Check your database connection.');
    }

    redirectTo($listUrl);
}

$filters = ['status' => $status, 'payment' => $payment, 'search' => $search];

/*
 * 'open' is a filter, so the bare page is a filtered view. $grandTotal is therefore read whenever
 * anything at all is set, and the empty state uses it to tell "nothing matches these filters" from
 * "there are no orders at all" -- two very different things to be told.
 */
$isFiltered = $status !== 'all' || $payment !== 'all' || $search !== '';

$orders = [];
$total = 0;
$grandTotal = 0;
$waitingCount = 0;
$lastPage = 1;
$loadFailed = false;

try {
    $total = adminCountOrders($filters);
    $grandTotal = $isFiltered ? adminCountOrders(['status' => 'all']) : $total;

    // Said in the toolbar whatever the filter is, because it is the one number this page exists
    // for: how many people are waiting on somebody to open their GCash app.
    $waitingCount = adminCountOrders(['status' => 'all', 'payment' => 'submitted']);

    $lastPage = max(1, (int) ceil($total / ADMIN_ORDERS_PER_PAGE));
    $page = min($page, $lastPage);

    $orders = adminFetchOrders($filters, ADMIN_ORDERS_PER_PAGE, ($page - 1) * ADMIN_ORDERS_PER_PAGE);
} catch (PDOException $exception) {
    error_log('admin/orders.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Messages left by the redirect that got here.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the orders right now. Check your database connection, and that sql\orders-upgrade.sql has been run.',
    ];
}

renderHeader('Orders');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Orders</h1>
            <p class="page-intro">
                Every order the shop has taken, newest first.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <!--
            The same filter row the catalogue and the parts list use, so script.js makes the two
            dropdowns submit on change without a line being added to it. No hidden page field, so
            changing a filter starts again at page 1 rather than landing on a page that no longer
            exists.
        -->
        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/admin/orders.php')); ?>" data-catalogue-filters>
            <div class="filter-field">
                <label for="filter-status">State</label>
                <select id="filter-status" name="status" data-filter-select>
                    <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Still open</option>
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="placed" <?php echo $status === 'placed' ? 'selected' : ''; ?>>Placed</option>
                    <option value="packed" <?php echo $status === 'packed' ? 'selected' : ''; ?>>Being made</option>
                    <option value="delivered" <?php echo $status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-payment">Payment</label>
                <select id="filter-payment" name="payment" data-filter-select>
                    <option value="all" <?php echo $payment === 'all' ? 'selected' : ''; ?>>Any</option>
                    <option value="submitted" <?php echo $payment === 'submitted' ? 'selected' : ''; ?>>Waiting to be checked</option>
                    <option value="unpaid" <?php echo $payment === 'unpaid' ? 'selected' : ''; ?>>Not paid yet</option>
                    <option value="paid" <?php echo $payment === 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>"
                       maxlength="<?php echo ADMIN_ORDER_SEARCH_MAX; ?>"
                       placeholder="Reference, name, phone, payment reference&hellip;">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo $total; ?> order<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>
                        of <?php echo $grandTotal; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($waitingCount > 0): ?>
                <!-- A link rather than a sentence: the thing to do about it is to go and look at
                     them, and that is one filter away. -->
                <a class="btn btn-quiet" href="<?php echo escape(adminOrdersUrl(['status' => 'all', 'payment' => 'submitted'])); ?>">
                    <?php echo $waitingCount; ?>
                    payment<?php echo $waitingCount === 1 ? '' : 's'; ?> to check
                </a>
            <?php endif; ?>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the orders</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php elseif ($orders === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>No orders yet</h2>
                    <p>Anything a customer checks out with appears here straight away.</p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(adminOrdersUrl(['status' => 'all'])); ?>">Show every order</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. Nine columns do not fit a phone at
                any readable size, and a table that widens the document makes the whole page slide
                sideways -- including the navbar. Inside its own box, only the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table admin-table-orders">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Placed</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Items</th>
                            <th scope="col">Total</th>
                            <th scope="col">Sending</th>
                            <th scope="col">Payment</th>
                            <th scope="col">State</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $reference = (string) $order['reference'];
                            $allowed = adminOrderActions($order);
                            $orderUrl = url('php/admin/order.php') . '?ref=' . rawurlencode($reference);

                            // A malformed date should not take the page down with it, which is
                            // what orderDate() returning the raw string is for. The short form
                            // here: nine columns, and the month spelled out was the widest of
                            // them.
                            $placed = orderDateShort((string) $order['placedAt']);
                            $paymentRef = trim((string) $order['paymentReference']);
                            ?>
                            <tr>
                                <th scope="row" class="admin-cell-name">
                                    <a href="<?php echo escape($orderUrl); ?>"><?php echo escape($reference); ?></a>
                                </th>
                                <td class="admin-cell-date"><?php echo escape($placed); ?></td>
                                <td class="admin-cell-name">
                                    <?php echo escape((string) $order['contactName']); ?>
                                    <span class="admin-cell-sub"><?php echo escape((string) $order['contactPhone']); ?></span>
                                </td>
                                <td class="admin-cell-number"><?php echo (int) $order['itemCount']; ?></td>
                                <td class="admin-cell-number"><?php echo escape(formatPrice((string) $order['total'])); ?></td>
                                <td class="admin-cell-kind">
                                    <?php echo escape(orderFulfilmentLabel((string) $order['fulfilment'])); ?>
                                </td>
                                <td class="admin-cell-kind">
                                    <span class="order-pill order-pill-pay-<?php echo escape((string) $order['paymentStatus']); ?>">
                                        <?php echo escape(orderPaymentStatusLabel((string) $order['paymentStatus'])); ?>
                                    </span>
                                    <?php
                                    /*
                                     * The method under the state, and the customer's own reference
                                     * under that when they gave one. This is the cell the whole
                                     * page is for: it is what gets matched against a GCash app.
                                     *
                                     * A PHP comment, not an HTML one -- this sits inside the row
                                     * loop, so an HTML comment here would be sent to the browser
                                     * once per order.
                                     */
                                    ?>
                                    <span class="admin-cell-sub">
                                        <?php echo escape(paymentMethodLabel((string) $order['paymentMethod'], (string) $order['fulfilment'])); ?>
                                    </span>
                                    <?php if ($paymentRef !== ''): ?>
                                        <span class="admin-cell-sub admin-cell-ref"><?php echo escape($paymentRef); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="order-pill order-pill-<?php echo escape((string) $order['status']); ?>">
                                        <?php echo escape(orderStatusLabel((string) $order['status'])); ?>
                                    </span>
                                </td>
                                <td class="admin-cell-act">
                                    <?php
                                    /*
                                     * One form per row with a button per action, rather than a
                                     * form per button: a submit button carries its own name and
                                     * value, so which one was pressed arrives in $_POST['action']
                                     * on its own.
                                     *
                                     * The reference and not the id, because that is what this
                                     * page deals in everywhere else -- and every statement behind
                                     * these buttons is written against it.
                                     *
                                     * A PHP comment for the same reason as the one in the payment
                                     * cell above: this is inside the row loop, and an HTML comment
                                     * here would be sent to the browser once per order.
                                     */
                                    ?>
                                    <form class="admin-row-actions" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="ref" value="<?php echo escape($reference); ?>">

                                        <a class="btn btn-quiet btn-small" href="<?php echo escape($orderUrl); ?>">Open</a>

                                        <?php
                                        /*
                                         * Forwards only. Pack needs 'placed' and deliver needs
                                         * 'packed', so these two can never both be offered -- the
                                         * elseif says that rather than leaving it to be noticed --
                                         * and the row grows by exactly one button, which is what
                                         * kept this cell from wrapping at 1280px.
                                         *
                                         * "Not started after all" is deliberately not here. An
                                         * undo should be a deliberate act taken while looking at
                                         * the order, and this cell already carries three controls.
                                         */
                                        ?>
                                        <?php if ($allowed['pack']): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="pack">Start</button>
                                        <?php elseif ($allowed['deliver']): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="deliver"
                                                    data-confirm="Mark <?php echo escape($reference); ?> delivered? There is no way back from delivered.">Delivered</button>
                                        <?php endif; ?>

                                        <?php if ($allowed['pay']): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="pay">Mark paid</button>
                                        <?php elseif ($allowed['unpay']): ?>
                                            <?php /* The undo. Quiet rather than dangerous: it changes
                                                      a note about money, it does not move any. */ ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="unpay"
                                                    data-confirm="Take back the paid mark on <?php echo escape($reference); ?>?">Not paid</button>
                                        <?php endif; ?>

                                        <?php if ($allowed['cancel']): ?>
                                            <button type="submit" class="btn btn-danger btn-small" name="action" value="cancel"
                                                    data-confirm="Cancel <?php echo escape($reference); ?>? The customer will see it as cancelled.">Cancel</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($lastPage > 1): ?>
            <nav class="pager" aria-label="Order list pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminOrdersUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Previous</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminOrdersUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
