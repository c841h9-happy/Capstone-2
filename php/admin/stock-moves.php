<?php
// Admin pages start with the guard, before any output. See guard.php.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Transactions: every movement of stock, in the order it happened.
 *
 * The one screen in the admin with no POST handler at all, and that is the point of it. A
 * ledger you can edit is not a ledger. Nothing here offers to change a row, because the way
 * to correct a wrong movement is another movement -- a stock-take on php/admin/inventory.php,
 * which records the difference and leaves both rows standing. What went wrong and what was
 * done about it are then both in the history, which is the only version of events worth
 * keeping.
 *
 * "On hand after" is read from the row, not worked out here. stock_moves.balance_after was
 * written inside the same transaction as the movement, so page 2 reads the same as page 1,
 * a filtered view still shows the real balance rather than a running total of whichever rows
 * survived the filter, and the number cannot drift when somebody adds a sort.
 *
 * The banner at the top is this screen's real job. A stored balance is only worth trusting
 * if something checks it, and stockAuditDiscrepancies() is that check: it re-adds every
 * part's movements and names any part whose stored count disagrees. It should always be
 * empty. The day it is not, this is where the shop finds out.
 *
 * The Part column shows stock_moves.component_name, frozen when the movement was written,
 * and never the live name from the join. A part that has been deleted still has a history,
 * and "(deleted)" beside its name is a truer answer than a blank cell.
 */

$componentId = is_numeric($_GET['component'] ?? null) ? max(0, (int) $_GET['component']) : 0;
$reason = stockReasonFilter($_GET['reason'] ?? null);
$sort = stockMoveSortKey($_GET['sort'] ?? null);

/*
 * ?order=N -- everything one order took out of the drawer and put back.
 *
 * Inventory Stage 2, 2026-09-29. This is where php/admin/order.php's "This order in the stock
 * history" link lands. Unlike the part filter there is no dropdown for it: nobody picks an order
 * out of a list of every order the shop has ever taken, they arrive here from the order itself,
 * so it exists as a chip that can be taken off and never as a control to put on.
 */
$orderId = is_numeric($_GET['order'] ?? null) ? max(0, (int) $_GET['order']) : 0;

// is_string first: ?q[]=x makes $_GET['q'] an array.
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

/**
 * A link to this page with the filters on it.
 *
 * Empty values, the default sort and page 1 are left out, so the plain ledger stays at
 * /Capstone/php/admin/stock-moves.php with nothing after it.
 */
function adminMovesUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/stock-moves.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * The component id goes in as a string on purpose.
 *
 * array_filter above drops a value of 1 so that page 1 never reaches the query string, and
 * it is a strict comparison -- so the part whose id happens to be 1 would quietly stop being
 * filterable if this were left an int. Same trap the stock screen's 'low' => 'yes' avoids.
 */
$filterParams = [
    'component' => $componentId > 0 ? (string) $componentId : '',
    'order' => $orderId > 0 ? (string) $orderId : '',
    'reason' => $reason,
    'sort' => $sort === STOCK_MOVE_SORT_DEFAULT ? '' : $sort,
    'q' => $search,
];

$filters = [
    'component' => $componentId,
    'order' => $orderId,
    'reason' => $reason,
    'search' => $search,
    'sort' => $sort,
];

// Sort is not in this: it changes the order, not which rows are in the list.
$isFiltered = $componentId > 0 || $orderId > 0 || $reason !== '' || $search !== '';

$moves = [];
$parts = [];
$discrepancies = [];
$total = 0;
$grandTotal = 0;
$lastPage = 1;
$loadFailed = false;

try {
    $discrepancies = stockAuditDiscrepancies();
    $parts = componentsWithMoves();
    $total = countStockMoves($filters);

    // Separate from $total so the empty state can tell "nothing matches these filters" from
    // "nothing has ever moved" -- and only the second of those should be sending somebody to
    // the stock screen to receive the first delivery.
    $grandTotal = $isFiltered ? countStockMoves() : $total;

    $lastPage = max(1, (int) ceil($total / ADMIN_STOCK_MOVES_PER_PAGE));
    $page = min($page, $lastPage);

    $moves = fetchStockMoves($filters, ADMIN_STOCK_MOVES_PER_PAGE, ($page - 1) * ADMIN_STOCK_MOVES_PER_PAGE);
} catch (PDOException $exception) {
    error_log('admin/stock-moves.php: ' . $exception->getMessage());
    $loadFailed = true;
}

/*
 * The name of the part being filtered by, for the heading.
 *
 * Taken from the dropdown's own list rather than looked up again: the list is already here,
 * and a second query to learn a name this page has already read would be a query for nothing.
 * An id that is not in the list -- a deleted part, or a number somebody typed -- leaves this
 * null, and the page says "a part that is no longer listed" rather than inventing a name.
 */
$filteredName = null;

foreach ($parts as $part) {
    if ((int) $part['id'] === $componentId) {
        $filteredName = (string) $part['name'];
        break;
    }
}

/*
 * The reference of the order being filtered by, taken off the rows themselves.
 *
 * Same reasoning as $filteredName, and the same honesty when it comes out null. fetchStockMoves()
 * already joins orders and selects order_reference, and with ?order=N on, every row in $moves
 * belongs to that one order -- so the first row that has a reference has the reference. No second
 * query, and no query from this page into the orders module, which is an edge this project has
 * kept pointing the other way.
 *
 * Null means either that nothing matched the filters at all -- in which case the empty state is
 * what the reader is looking at, not this line -- or that the order itself has been destroyed
 * since, because fk_stock_moves_order is ON DELETE SET NULL precisely so the movements outlive it.
 */
$filteredOrderReference = null;

if ($orderId > 0) {
    foreach ($moves as $move) {
        $reference = trim((string) ($move['order_reference'] ?? ''));

        if ($reference !== '') {
            $filteredOrderReference = $reference;
            break;
        }
    }
}

// Messages left by a redirect that got here. Nothing on this page writes one, but the stock
// screen links straight in and its flash has to land somewhere.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the movement history right now. Check your database connection, and that sql\inventory-upgrade.sql has been run.',
    ];
}

renderHeader('Transactions');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Transactions</h1>
            <p class="page-intro">
                Every change to every count, with the reason it happened and who did it.
                <a href="<?php echo escape(url('php/admin/inventory.php')); ?>">Back to Stock</a>,
                or <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">to the Dashboard</a>.
            </p>
        </header>

        <?php if ($discrepancies !== []): ?>
            <!--
                The one thing on this page worth shouting about, and the reason the audit
                query exists. Every part in here has a stored count that does not equal the
                sum of its own movements, which recordStockMove() makes impossible in one
                transaction -- so a row here means a hand-edit in the MySQL shell or a bug.

                It names the parts and the difference rather than saying "something is wrong",
                because a banner nobody can act on is a banner everybody learns to ignore.
            -->
            <div class="notice notice-alarm">
                <strong>The books do not add up.</strong>
                <?php echo count($discrepancies); ?>
                part<?php echo count($discrepancies) === 1 ? '' : 's'; ?>
                <?php echo count($discrepancies) === 1 ? 'has' : 'have'; ?>
                a count that disagrees with its own history:
                <?php foreach ($discrepancies as $index => $wrong): ?>
                    <?php
                    $difference = (int) $wrong['difference'];
                    ?>
                    <?php echo $index === 0 ? '' : ';'; ?>
                    <a href="<?php echo escape(adminMovesUrl(['component' => (string) (int) $wrong['id']])); ?>"><?php echo escape((string) $wrong['name']); ?></a>
                    says <?php echo (int) $wrong['stock_on_hand']; ?>
                    and its movements add up to <?php echo (int) $wrong['ledger_total']; ?>
                    (<?php echo $difference > 0 ? '+' . $difference : $difference; ?>)
                <?php endforeach; ?>.
                Count what is in the drawer and put it right with <em>Set to</em> on the
                <a href="<?php echo escape(url('php/admin/inventory.php')); ?>">stock screen</a>,
                which records the correction instead of hiding it.
            </div>
        <?php endif; ?>

        <!--
            The same filter row every other admin list uses. A real GET form with a real Apply
            button; script.js makes the dropdowns submit on change. No hidden page field, so
            changing a filter starts again at page 1 rather than landing on a page that no
            longer exists.
        -->
        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/admin/stock-moves.php')); ?>" data-catalogue-filters>
            <?php if ($orderId > 0): ?>
                <!--
                    The one hidden field on this form, and the reason is that the order filter has
                    no control of its own: it arrives in the URL from an order page. Without this,
                    narrowing by reason while looking at one order would silently widen the view to
                    every order the shop has ever taken. The count line below has the link that
                    takes it off deliberately.
                -->
                <input type="hidden" name="order" value="<?php echo (int) $orderId; ?>">
            <?php endif; ?>

            <div class="filter-field filter-field-grow">
                <label for="filter-component">Part</label>
                <select id="filter-component" name="component" data-filter-select>
                    <option value="" <?php echo $componentId === 0 ? 'selected' : ''; ?>>Everything</option>
                    <?php foreach ($parts as $part): ?>
                        <!-- Name and count on one line, so the option reads as "Blush Rose Head
                             (14)" rather than with this file's indentation inside it. -->
                        <option value="<?php echo (int) $part['id']; ?>" <?php echo $componentId === (int) $part['id'] ? 'selected' : ''; ?>><?php echo escape((string) $part['name']); ?> (<?php echo (int) $part['moves']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-reason">Why</label>
                <select id="filter-reason" name="reason" data-filter-select>
                    <option value="" <?php echo $reason === '' ? 'selected' : ''; ?>>Any reason</option>
                    <?php foreach (STOCK_REASONS as $key => $label): ?>
                        <option value="<?php echo escape($key); ?>" <?php echo $reason === $key ? 'selected' : ''; ?>>
                            <?php echo escape($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-sort">Order</label>
                <select id="filter-sort" name="sort" data-filter-select>
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                </select>
            </div>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <!-- Name and note together, so "Lazada" finds the deliveries and a part that
                     has since been deleted is still findable by the name it had. -->
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>"
                       maxlength="100" placeholder="rose, Lazada, spoiled&hellip;">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo number_format($total); ?>
                    movement<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>
                        of <?php echo number_format($grandTotal); ?>
                    <?php endif; ?>
                    <?php if ($componentId > 0): ?>
                        &middot;
                        <?php if ($filteredName === null): ?>
                            a part that is no longer listed
                        <?php else: ?>
                            <?php echo escape($filteredName); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($orderId > 0): ?>
                        &middot;
                        <?php if ($filteredOrderReference === null): ?>
                            one order
                        <?php else: ?>
                            Order <?php echo escape($filteredOrderReference); ?>
                        <?php endif; ?>
                        <!-- Takes off the order filter and leaves the others on, which is the
                             thing "Show everything" cannot do. -->
                        <a href="<?php echo escape(adminMovesUrl(array_merge($filterParams, ['order' => '']))); ?>">all orders</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($isFiltered): ?>
                <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/stock-moves.php')); ?>">Show everything</a>
            <?php endif; ?>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the history</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php elseif ($moves === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>Nothing has moved yet</h2>
                    <p>Receive a delivery or count what is in the drawer, and it appears here the moment you do.</p>
                    <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/inventory.php')); ?>">Go to Stock</a></p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, a different part, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/stock-moves.php')); ?>">Show everything</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. Seven columns do not fit a
                phone at any readable size, and a table that widens the document makes the
                whole page slide sideways -- including the navbar. Inside its own box, only
                the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Part</th>
                            <th scope="col">Why</th>
                            <th scope="col">Change</th>
                            <th scope="col">On hand after</th>
                            <th scope="col">Note</th>
                            <th scope="col">Who</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($moves as $move): ?>
                            <?php
                            $delta = (int) $move['delta'];
                            $movedComponent = $move['component_id'] === null ? 0 : (int) $move['component_id'];
                            $note = trim((string) $move['note']);
                            $who = trim((string) ($move['user_name'] ?? ''));
                            $orderReference = trim((string) ($move['order_reference'] ?? ''));

                            // A malformed date should not take the page down with it.
                            $movedAt = strtotime((string) $move['created_at']);
                            ?>
                            <tr>
                                <td class="admin-cell-date">
                                    <?php if ($movedAt === false): ?>
                                        <span class="admin-cell-blank">&mdash;</span>
                                    <?php else: ?>
                                        <?php echo escape(date('j M Y', $movedAt)); ?>
                                        <!-- The time on its own line, because two movements of
                                             the same part on the same day is the normal case and
                                             the date alone cannot tell them apart. -->
                                        <span class="admin-cell-sub"><?php echo escape(date('g:ia', $movedAt)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <th scope="row" class="admin-cell-name">
                                    <?php if ($movedComponent > 0): ?>
                                        <!-- To this part's own history, which is the next thing
                                             anybody wants after finding one of its rows. -->
                                        <a href="<?php echo escape(adminMovesUrl(['component' => (string) $movedComponent])); ?>"><?php echo escape((string) $move['component_name']); ?></a>
                                    <?php else: ?>
                                        <?php echo escape((string) $move['component_name']); ?>
                                        <!-- ON DELETE SET NULL, on purpose: the movement outlives
                                             the part, and the frozen name is all that is left to
                                             say what moved. -->
                                        <span class="admin-cell-sub">Part deleted</span>
                                    <?php endif; ?>
                                </th>
                                <td class="admin-cell-kind"><?php echo escape(stockReasonLabel((string) $move['reason'])); ?></td>
                                <td class="admin-cell-number">
                                    <span class="<?php echo $delta < 0 ? 'ledger-out' : 'ledger-in'; ?>">
                                        <?php echo $delta > 0 ? '+' . $delta : $delta; ?>
                                    </span>
                                </td>
                                <td class="admin-cell-number">
                                    <?php echo (int) $move['balance_after']; ?>
                                    <?php if ((int) $move['balance_after'] < 0): ?>
                                        <!-- The shop is never blocked from selling, so more can
                                             go out than the books had. This is that, recorded. -->
                                        <span class="pill pill-danger">Short</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($note === '' && $orderReference === ''): ?>
                                        <span class="admin-cell-blank">&mdash;</span>
                                    <?php else: ?>
                                        <?php echo escape($note); ?>
                                        <?php if ($orderReference !== ''): ?>
                                            <!-- Written by packOrderAndConsume() and put back by
                                                 its two twins, as of Inventory Stage 2. These
                                                 rows carry no note -- "Used" and the order beside
                                                 it already say everything a note could, and one
                                                 naming a single product would be a lie whenever
                                                 two lines of the order share a material. -->
                                            <span class="admin-cell-sub">
                                                <a href="<?php echo escape(url('php/admin/order.php?ref=' . urlencode($orderReference))); ?>"><?php echo escape($orderReference); ?></a>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($who !== ''): ?>
                                        <?php echo escape($who); ?>
                                    <?php elseif ($move['user_id'] === null && (string) $move['reason'] === 'opening'): ?>
                                        <!-- The seed's opening count had nobody signed in to do
                                             it. "The shop" is truer than a dash. -->
                                        <span class="admin-cell-blank">The shop</span>
                                    <?php else: ?>
                                        <!-- user_id is ON DELETE SET NULL, so an admin account
                                             that has since been closed leaves its movements
                                             behind without a name on them. -->
                                        <span class="admin-cell-blank">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($lastPage > 1): ?>
            <nav class="pager" aria-label="Movement history pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminMovesUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Previous</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminMovesUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
