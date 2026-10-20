<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to run before
// the redirect and the 404/503 below, which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/orders-admin.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * One order, from the shop's side.
 *
 * php/order.php is the customer's view of the same row and the two are deliberately close -- same
 * panels, same layout classes, same frozen copies, same timeline from the same function -- because
 * an admin on the phone to a customer should be looking at what the customer is looking at. What
 * this one adds is the parts list read as instructions, the account behind the order, who moved
 * each step, and the buttons.
 *
 * Found by reference, like every other order screen. fetchOrderForAdmin() has no user_id in its
 * WHERE, which is exactly why it is a separate function from fetchOrderForUser() rather than one
 * with a nullable argument.
 */
$reference = orderReferenceFromInput($_GET['ref'] ?? '');

/*
 * Every action the queue offers plus the one it does not, answered the same way: do it, flash,
 * redirect. Back to this page rather than to the queue -- somebody who opened one order to check a
 * payment reference wants to still be looking at that order afterwards.
 *
 * applyAdminOrderAction() is what decides which actions exist; this page names none of them. That
 * is also where the customer gets written to, so the queue and this page cannot end up disagreeing
 * about who was told what.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Being an admin is not the same as having pressed the button. deliver is why this line is
    // here: it cannot be undone from any screen, and it emails the customer on the way past.
    requireCsrfToken();

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $posted = orderReferenceFromInput($_POST['ref'] ?? '');

    try {
        $result = applyAdminOrderAction($action, $posted);
        flashToast($result['type'], $result['message']);
    } catch (PDOException $exception) {
        error_log('admin/order.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change right now. Check your database connection.');
    }

    // The posted reference, falling back to the one in the address bar. A POST carrying junk would
    // otherwise redirect to a page with no reference on it, which answers 404 -- a confusing reply
    // to "that action is not something this page can do".
    $back = $posted !== '' ? $posted : $reference;

    redirectTo($back === ''
        ? url('php/admin/orders.php')
        : url('php/admin/order.php') . '?ref=' . rawurlencode($back));
}

$order = null;
$customer = null;
$events = [];
$loadFailed = false;

/*
 * The stock side of this order, defaulted here for the reason $events is: a database that goes
 * away mid-load leaves $order set and the page still rendering, and an undefined variable would
 * turn a 503 into a fatal.
 */
$stockRequirement = ['parts' => [], 'materials' => [], 'blind' => []];
$stockOut = [];
$stockLedger = [];
$stockLost = 0;

if ($reference !== '') {
    try {
        $order = fetchOrderForAdmin($reference);

        if ($order !== null) {
            $customer = adminOrderCustomer((int) $order['userId']);

            // Inside the same try as the order itself, so a database that has gone away answers
            // 503 once rather than rendering half a page and then failing on the history.
            $events = fetchOrderEvents((int) $order['id']);

            /*
             * What making this will take, what it has already taken, and the movements themselves.
             * Same try for the same reason.
             *
             * Four reads on a page that already does three, which is affordable here and would not
             * be on the queue: this is one order, opened deliberately, and "will I have the parts"
             * is the question somebody opening it before pressing Start making is actually asking.
             */
            $orderId = (int) $order['id'];
            $stockRequirement = orderStockRequirement($orderId);
            $stockLedger = orderStockLedger($orderId);
            $stockLost = orderStockLostUnits($orderId);
        }
    } catch (PDOException $exception) {
        error_log('admin/order.php: ' . $exception->getMessage());
        $loadFailed = true;
    }
}

$toasts = takeFlashToasts();

/*
 * Not there. A real page with a real status code, the treatment php/product.php settled on and
 * php/order.php follows. A database failure is 503 rather than 404, because "that order does not
 * exist" is a claim and the truth is that nobody can tell right now.
 */
if ($order === null) {
    http_response_code($loadFailed ? 503 : 404);

    renderHeader($loadFailed ? 'Something went wrong' : 'Order not found');

    if ($loadFailed) {
        $toasts[] = [
            'type' => 'error',
            'message' => 'Unable to load that order right now. Check your database connection.',
        ];
    }

    renderToasts($toasts);
    ?>
    <main class="page">
        <div class="empty-state">
            <?php if ($loadFailed): ?>
                <h1>We could not load that</h1>
                <p>The orders are unavailable for a moment. Try again shortly.</p>
            <?php else: ?>
                <h1>Order not found</h1>
                <p>No order has that reference. Check it against the queue.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/orders.php')); ?>">Back to orders</a></p>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

$isPickup = $order['fulfilment'] === 'pickup';
$allowed = adminOrderActions($order);
$timeline = orderTimeline($order, $events);
$selfUrl = url('php/admin/order.php') . '?ref=' . rawurlencode($order['reference']);

renderHeader('Order ' . $order['reference']);
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb">
            <a href="<?php echo escape(url('php/admin/orders.php')); ?>">Orders</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span><?php echo escape($order['reference']); ?></span>
        </p>

        <header class="page-head">
            <p class="page-eyebrow">Placed <?php echo escape(orderDate($order['placedAt'])); ?></p>
            <h1>Order <?php echo escape($order['reference']); ?></h1>
            <p class="page-intro">
                What was agreed when this was ordered. Nothing on this page re-reads the catalogue,
                so editing a product later does not change what is written here.
            </p>
        </header>

        <?php
        /*
         * The same two panels the customer sees, in the same order, so a conversation about
         * "it says waiting to be checked" is about the same words on both screens.
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
                This order is cancelled. Do not make it, and do not collect anything for it.
            </p>
        <?php elseif ($order['paymentStatus'] === 'submitted'): ?>
            <p class="cart-warning" role="status">
                The customer says they have paid and has given a reference. Check it against your
                account, then mark it paid or cancel the order.
            </p>
        <?php endif; ?>

        <div class="cart-layout order-layout">
            <div class="order-main">
                <?php
                /*
                 * The history, drawn by the same orderTimeline() the customer's page calls and
                 * placed first for the same reason the two state pills are: an admin on the phone
                 * to somebody asking "has it moved" should be looking at the answer they are.
                 *
                 * Two things here the customer's copy does not need. The date is the full
                 * spelled-out one, because this page has the width for it and a shop reconciling
                 * "you said Tuesday" wants the date without doing arithmetic. And each dot says
                 * who moved it -- a cancellation the customer made themselves and one the shop
                 * made look identical in the column, and they are not the same conversation.
                 */
                ?>
                <section class="panel">
                    <h2>Where it has got to</h2>

                    <ol class="order-timeline">
                        <?php foreach ($timeline as $dot): ?>
                            <li class="order-timeline-step order-timeline-<?php echo escape($dot['state']); ?>">
                                <span class="order-timeline-label">
                                    <?php echo escape($dot['label']); ?>
                                    <?php if ($dot['actor'] !== ''): ?>
                                        <span class="order-timeline-who">
                                            by the <?php echo escape($dot['actor']); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="order-timeline-at">
                                    <?php echo $dot['at'] === '' ? 'Not yet' : escape(orderDate($dot['at'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php
                    // PHP comment and not HTML: this sits outside the loop, but the loop above is
                    // one line away and a stray <!-- --> in there would be sent once per dot.
                    ?>
                    <p class="card-note">
                        Placed is read from the order itself. Everything under it was written when
                        somebody pressed a button, and nothing here is ever rewritten.
                    </p>
                </section>

                <section class="panel">
                    <h2>What to make</h2>

                    <ul class="cart-list order-list">
                        <?php foreach ($order['lines'] as $line): ?>
                            <li class="cart-line">
                                <div class="cart-line-main">
                                    <h3 class="cart-line-name"><?php echo escape($line['name']); ?></h3>

                                    <p class="cart-line-meta">
                                        <?php echo escape(formatPrice($line['unitPrice'])); ?> each
                                        &times; <?php echo (int) $line['quantity']; ?>
                                        <?php if ($line['kind'] === 'design' && $line['arrangement'] !== ''): ?>
                                            &middot; <?php echo escape($line['arrangement']); ?> arrangement
                                        <?php endif; ?>
                                    </p>

                                    <?php if ($line['parts'] !== []): ?>
                                        <?php
                                        /*
                                         * The build instructions, and the one real difference from
                                         * the customer's copy of this list: each part says which
                                         * kind it is. "Sage green" next to "Satin ribbon" is a
                                         * different instruction from "Sage green" next to "Stem
                                         * wire", and the person at the bench needs both.
                                         *
                                         * The colour goes through cartSwatchColour() on its way
                                         * into a style attribute. It was checked when it was
                                         * written, but it is being put somewhere new now.
                                         */
                                        ?>
                                        <ul class="cart-parts order-parts">
                                            <?php foreach ($line['parts'] as $part): ?>
                                                <li class="cart-part">
                                                    <span class="colour-swatch" aria-hidden="true"
                                                          style="background: <?php echo escape(cartSwatchColour($part['colour'])); ?>"></span>
                                                    <span class="cart-part-name"><?php echo escape($part['name']); ?></span>
                                                    <span class="cart-part-kind">
                                                        <?php echo escape(componentKindLabel($part['kind'])); ?>
                                                    </span>
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

                <?php
                /*
                 * What it takes out of stock. Inventory Stage 2, 2026-09-29.
                 *
                 * Two panels' worth of question answered by one, because which question matters
                 * depends entirely on where the order has got to, and the ledger already knows
                 * which: nothing out yet means the useful answer is a forecast, and something out
                 * means the useful answer is the record.
                 *
                 * The forecast is the whole point of putting this here rather than only on the
                 * ledger. A shortfall found *before* Start making is pressed is a shortfall the
                 * shop can do something about -- buy more, ring the customer, make it tomorrow --
                 * and one found afterwards is just an apology. The stock is never blocked from
                 * going negative, which was the user's call, so this panel is the warning.
                 *
                 * Placed after "What to make" on purpose: that panel is the instruction, and this
                 * is what following it costs.
                 */
                $stockOrderId = (int) $order['id'];
                $stockLedgerUrl = url('php/admin/stock-moves.php') . '?' . http_build_query(['order' => $stockOrderId]);
                $materialLedgerUrl = url('php/admin/material-moves.php') . '?' . http_build_query(['order' => $stockOrderId]);

                // The ledger is what decides which panel this is, not the order's status. A
                // cancelled or delivered order shows whichever mode is actually true of it.
                $stockTaken = $stockLedger !== [];
                ?>
                <section class="panel">
                    <h2>What it takes out of stock</h2>

                    <?php if ($stockTaken): ?>
                        <p class="order-stock-lead">
                            These parts are already out of the drawer for this order. Moving it back
                            to Placed, or cancelling it, puts back exactly what is listed here.
                        </p>

                        <div class="admin-table-wrap">
                            <table class="admin-table order-stock-table">
                                <thead>
                                    <tr>
                                        <th scope="col">When</th>
                                        <th scope="col">Part</th>
                                        <th scope="col">Why</th>
                                        <th scope="col">Change</th>
                                        <th scope="col">On hand after</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockLedger as $move): ?>
                                        <?php
                                        $moveDelta = (float) $move['delta'];
                                        $moveBalance = (float) $move['balance_after'];
                                        // A malformed date should not take the page down with it.
                                        $movedAt = strtotime((string) $move['created_at']);
                                        ?>
                                        <tr>
                                            <td class="admin-cell-date">
                                                <?php if ($movedAt === false): ?>
                                                    <span class="admin-cell-blank">&mdash;</span>
                                                <?php else: ?>
                                                    <?php echo escape(date('j M Y', $movedAt)); ?>
                                                    <span class="admin-cell-sub"><?php echo escape(date('g:ia', $movedAt)); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <th scope="row" class="admin-cell-name">
                                                <?php echo escape((string) $move['component_name']); ?>
                                                <?php if ($move['component_id'] === null): ?>
                                                    <span class="admin-cell-sub">Part deleted</span>
                                                <?php endif; ?>
                                            </th>
                                            <td class="admin-cell-kind">
                                                <?php echo escape(stockReasonLabel((string) $move['reason'])); ?>
                                            </td>
                                            <td class="admin-cell-number">
                                                <span class="<?php echo $moveDelta < 0 ? 'ledger-out' : 'ledger-in'; ?>">
                                                    <?php echo $moveDelta > 0 ? '+' : ''; ?><?php echo escape(materialQuantity($moveDelta)); ?>
                                                </span>
                                            </td>
                                            <td class="admin-cell-number">
                                                <?php echo escape(materialQuantity($moveBalance)); ?>
                                                <?php if ($moveBalance < 0): ?>
                                                    <span class="pill pill-danger">Short</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <p class="card-note">
                            <a href="<?php echo escape($stockLedgerUrl); ?>">Component history</a>
                            &middot;
                            <a href="<?php echo escape($materialLedgerUrl); ?>">Material history</a>
                        </p>
                    <?php elseif ($stockRequirement['parts'] !== [] || $stockRequirement['materials'] !== []): ?>
                        <p class="order-stock-lead">
                            Nothing is out of the drawer for this order yet. This is what pressing
                            Start making will take, counted from the recipes as they are right now.
                        </p>

                        <div class="admin-table-wrap">
                            <table class="admin-table order-stock-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Part</th>
                                        <th scope="col">Kind</th>
                                        <th scope="col">This order needs</th>
                                        <th scope="col">On hand</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockRequirement['parts'] as $part): ?>
                                        <?php
                                        $needed = (float) $part['needed'];
                                        $onHand = (float) $part['onHand'];
                                        ?>
                                        <tr>
                                            <th scope="row" class="admin-cell-name">
                                                <?php echo escape((string) $part['name']); ?>
                                            </th>
                                            <td class="admin-cell-kind">
                                                <?php echo escape(componentKindLabel((string) $part['kind'])); ?>
                                            </td>
                                            <td class="admin-cell-number"><?php echo escape(materialQuantity($needed)); ?></td>
                                            <td class="admin-cell-number">
                                                <?php echo escape(materialQuantity($onHand)); ?>
                                                <?php if ($needed > $onHand): ?>
                                                    <!-- The warning, arriving before the button is
                                                         pressed rather than after. -->
                                                    <span class="pill pill-danger">Short by <?php echo escape(materialQuantity($needed - $onHand)); ?></span>
                                                <?php elseif ($onHand - $needed <= (int) $part['lowAt']): ?>
                                                    <span class="pill pill-warn">Low after</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($stockRequirement['materials'] as $material): ?>
                                        <?php
                                        $needed = (float) $material['needed'];
                                        $onHand = (float) $material['stock_on_hand'];
                                        ?>
                                        <tr>
                                            <th scope="row" class="admin-cell-name"><?php echo escape((string) $material['name']); ?></th>
                                            <td class="admin-cell-kind">Material</td>
                                            <td class="admin-cell-number"><?php echo escape(materialQuantity($needed)); ?> <?php echo escape((string) $material['unit']); ?></td>
                                            <td class="admin-cell-number">
                                                <?php echo escape(materialQuantity($onHand)); ?> <?php echo escape((string) $material['unit']); ?>
                                                <?php if ($needed > $onHand): ?><span class="pill pill-danger">Short by <?php echo escape(materialQuantity($needed - $onHand)); ?></span><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="card-note">
                            Nothing on this order has a recipe, so making it takes nothing out of
                            stock. Give its products a materials recipe and this will fill itself in.
                        </p>
                    <?php endif; ?>

                    <?php if ($stockRequirement['blind'] !== []): ?>
                        <?php
                        /*
                         * The honest half. A pack that quietly took nothing out is worse than a
                         * shortfall, because a shortfall at least gets written down -- so every
                         * line that cannot be costed is named here with the reason.
                         *
                         * Shown in both modes. An order already being made still needs somebody
                         * to be told which of its lines came off the shelf without being counted.
                         */
                        ?>
                        <ul class="order-stock-blind">
                            <?php foreach ($stockRequirement['blind'] as $blind): ?>
                                <li>
                                    <strong><?php echo escape((string) $blind['line']); ?></strong>
                                    &mdash; <?php echo escape((string) $blind['why']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($stockLost > 0): ?>
                        <p class="cart-warning" role="status">
                            <?php echo (int) $stockLost; ?>
                            <?php echo $stockLost === 1 ? 'part' : 'parts'; ?>
                            went out for this order against a material that has since been deleted.
                            Those cannot be put back &mdash; there is nothing left to put them into.
                        </p>
                    <?php endif; ?>
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
                            <dd>The customer is picking this up. Message them when it is ready.</dd>
                        <?php else: ?>
                            <dt>Address</dt>
                            <dd>
                                <?php
                                /*
                                 * Built as a list and filtered, rather than five lines each
                                 * wrapped in a conditional: the postcode is optional, and a fixed
                                 * template would leave a blank line in the middle of the address
                                 * on most orders.
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
                            <dt>Their note</dt>
                            <dd class="order-note"><?php echo nl2br(escape($order['notes'])); ?></dd>
                        <?php endif; ?>

                        <dt>Account</dt>
                        <dd>
                            <?php
                            /*
                             * The account, which is a different question from the contact details
                             * above: those were frozen at checkout and are where this order goes,
                             * while this is who to find if something needs chasing.
                             *
                             * The null branch should be unreachable -- fk_orders_user is ON DELETE
                             * RESTRICT, so an account with orders cannot be deleted -- but a page
                             * that would print an empty cell instead of saying so is a page that
                             * hides the day that stops being true.
                             */
                            ?>
                            <?php if ($customer === null): ?>
                                <span class="admin-cell-blank">The account behind this order is gone.</span>
                            <?php else: ?>
                                <?php echo escape((string) $customer['name']); ?>
                                (<?php echo escape((string) $customer['email']); ?>)
                            <?php endif; ?>
                        </dd>
                    </dl>
                </section>

                <section class="panel">
                    <h2>Payment</h2>

                    <dl class="order-facts">
                        <dt>Method</dt>
                        <dd><?php echo escape(paymentMethodLabel($order['paymentMethod'], $order['fulfilment'])); ?></dd>

                        <?php if ($order['paymentReference'] !== ''): ?>
                            <dt>Their reference</dt>
                            <?php
                            /*
                             * Monospaced, because this is the one value on the page that gets
                             * compared rather than read -- see .admin-cell-ref in css/main.css.
                             * The queue shows it the same way, and an admin moving between the
                             * two is comparing the same shape of string in both places.
                             */
                            ?>
                            <dd class="admin-cell-ref"><?php echo escape($order['paymentReference']); ?></dd>
                        <?php endif; ?>

                        <dt>State</dt>
                        <dd><?php echo escape(orderPaymentStatusLabel($order['paymentStatus'])); ?></dd>

                        <dt>Total</dt>
                        <dd><?php echo escape(formatPrice($order['total'])); ?></dd>
                    </dl>

                    <?php
                    /*
                     * What to actually do, said once, in the panel the doing happens next to. The
                     * shop confirms a transfer by hand in their own app -- there is no gateway and
                     * no API key anywhere in this project, which is the decision the whole payment
                     * design rests on.
                     */
                    ?>
                    <?php if ($order['paymentMethod'] === 'cod'): ?>
                        <p class="card-note">
                            Cash. Mark it paid once the money is in your hand
                            <?php echo $isPickup ? 'at collection' : 'on delivery'; ?>.
                        </p>
                    <?php elseif ($order['paymentStatus'] !== 'paid'): ?>
                        <p class="card-note">
                            Open your own
                            <?php echo $order['paymentMethod'] === 'gcash' ? 'GCash' : 'bank'; ?>
                            app and find that reference. Mark it paid only once you can see the money.
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
                        item<?php echo (int) $order['itemCount'] === 1 ? '' : 's'; ?>
                        in <?php echo count($order['lines']); ?>
                        line<?php echo count($order['lines']) === 1 ? '' : 's'; ?>.
                        Delivery is quoted separately and is not in this figure.
                    </p>

                    <?php
                    /*
                     * One form, a button per action, and every one of them checked again in SQL --
                     * adminOrderActions() decides what to draw and the UPDATEs decide what actually
                     * happens, so a page left open while the row moved gets told no rather than
                     * doing the wrong thing.
                     *
                     * Ordered the way the work is: what to make, then the money, then the two ways
                     * back. Cancel stays last because it is the only one that ends the order, and
                     * the undo sits beside it because both are corrections rather than progress.
                     */
                    ?>
                    <form class="order-actions" method="post" action="<?php echo escape($selfUrl); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="ref" value="<?php echo escape($order['reference']); ?>">

                        <?php if ($allowed['pack']): ?>
                            <button type="submit" class="btn btn-primary" name="action" value="pack">
                                Start making
                            </button>
                        <?php endif; ?>

                        <?php if ($allowed['deliver']): ?>
                            <?php
                            /*
                             * The one confirm on a button that is not destructive. Delivered is
                             * where the ladder ends -- there is no way back from it by design --
                             * so it is worth one question, exactly as the delete guard in
                             * js/script.js is.
                             */
                            ?>
                            <button type="submit" class="btn btn-primary" name="action" value="deliver"
                                    data-confirm="Mark this delivered? There is no way back from delivered.">
                                Delivered
                            </button>
                        <?php endif; ?>

                        <?php if ($allowed['pay']): ?>
                            <button type="submit" class="btn btn-primary" name="action" value="pay">Mark paid</button>
                        <?php elseif ($allowed['unpay']): ?>
                            <button type="submit" class="btn btn-quiet" name="action" value="unpay"
                                    data-confirm="Take back the paid mark on this order?">
                                Not paid after all
                            </button>
                        <?php endif; ?>

                        <?php if ($allowed['unpack']): ?>
                            <?php
                            /*
                             * Here and not in the queue. An undo should be a deliberate act taken
                             * while looking at the order, and the queue row already carries three
                             * controls. The SQL guard is the real check either way.
                             */
                            ?>
                            <button type="submit" class="btn btn-quiet" name="action" value="unpack"
                                    data-confirm="Put this back to Placed? The customer is not told either way.">
                                Not started after all
                            </button>
                        <?php endif; ?>

                        <?php if ($allowed['cancel']): ?>
                            <button type="submit" class="btn btn-danger" name="action" value="cancel"
                                    data-confirm="Cancel this order? The customer will see it as cancelled.">
                                Cancel this order
                            </button>
                        <?php endif; ?>
                    </form>

                    <?php
                    /*
                     * All six, counted rather than named. A list written out by hand would quietly
                     * stop covering a button the day a seventh is added, and the sentence it guards
                     * is a claim about every button on the page.
                     */
                    ?>
                    <?php if (array_filter($allowed) === []): ?>
                        <p class="cart-total-note">
                            There is nothing left to do to this one here.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="cart-foot-actions">
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/orders.php')); ?>">Back to orders</a>
                </div>
            </div>
        </div>
    </main>
<?php renderFooter(); ?>
