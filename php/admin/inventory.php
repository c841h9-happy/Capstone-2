<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to run
// before the redirects and the 302 below, which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Stock: how many of each part the shop actually has.
 *
 * The counts are on the parts and not on finished bouquets, because the shop makes to order
 * -- it holds rose heads, chenille stems and kraft sleeves, and assembles a bouquet when
 * somebody buys one. What a product needs is on php/admin/product-parts.php, and how many of
 * it could be made now is worked out from the two together.
 *
 * Every number in the "On hand" column was put there by recordStockMove(), which writes a
 * row in stock_moves in the same transaction. Nothing on this page changes a count without
 * leaving that trail, which is why there is no "just fix the number" field: "Set to" is a
 * stock-take and is recorded as one.
 *
 * Three things this screen deliberately does not do.
 *
 * It does not stop anybody buying anything. A count at or below zero is shown as a
 * shortfall and warned about; checkout is untouched, because the shop's answer to "two
 * people ordered the last bouquet" is to make another one.
 *
 * It does not take stock out when an order is placed. That happens when the order is marked
 * as being made, on php/admin/order.php -- so the number here always means what is in the
 * drawer, not what is left after promises.
 *
 * And "Warn at" is not a movement. It changes when the shop wants to be told, not what it
 * has, so it writes no ledger row. See setComponentLowStock().
 */

$status = componentStatus($_GET['status'] ?? null);
$kind = componentKindFilter($_GET['kind'] ?? null);
$sort = stockSortKey($_GET['sort'] ?? null);

// Presence, not value: an unticked checkbox is not submitted at all.
$low = isset($_GET['low']);

// is_string first: ?q[]=x makes $_GET['q'] an array.
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

/**
 * A link to this page with the filters on it.
 *
 * Empty values, the default sort and page 1 are left out, so the plain list stays at
 * /Capstone/php/admin/inventory.php with nothing after it.
 */
function adminStockUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/inventory.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * Everything on the page carries the filters that are already on, so that receiving stock
 * for the third row of a filtered page 2 leaves the admin looking at that same page.
 *
 * 'low' is the exception to the array_filter above: its only useful value IS 1, so it is
 * spelled 'yes' here and read back as presence. Filtering it out by value would make the
 * checkbox forget itself the moment anything else was pressed.
 */
$filterParams = [
    'status' => $status === 'all' ? '' : $status,
    'kind' => $kind,
    'sort' => $sort === STOCK_SORT_DEFAULT ? '' : $sort,
    'q' => $search,
    'low' => $low ? 'yes' : '',
];

$listUrl = adminStockUrl($filterParams + ['page' => $page]);

/*
 * Receive, stock-take, waste, and the warning level.
 *
 * Answered with a redirect and a flashed message rather than by rendering the list inline --
 * a POST left as the current request means a refresh offers to send it again, and offering
 * to receive forty more rose heads twice is exactly the sort of thing that makes a stock
 * system stop being believed.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = productInputText($_POST, 'action');
    $targetId = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;
    $note = productInputText($_POST, 'note');
    $countInput = productInputText($_POST, 'qty');

    try {
        // Read first, for the name. A message that says which part it was is worth one extra
        // query, and it is the only way to tell "moved" from "never there".
        $target = adminFetchComponent($targetId);

        /*
         * The number, checked once for all four actions.
         *
         * A blank box is refused rather than treated as 0: "Set to" with an empty box would
         * mean "throw away everything you have", and a mis-click must not be able to say
         * that. preg_match rather than is_numeric, because "12 " and "1e2" are both numeric
         * to PHP and neither is a count of anything.
         */
        $count = null;

        if (preg_match('/^\d{1,6}$/', $countInput) === 1) {
            $count = (int) $countInput;
        }

        if ($target === null) {
            flashToast('error', 'That part is no longer there. The list below is up to date.');
        } elseif ($count === null) {
            flashToast('error', 'Type how many first -- a whole number, in the box on that row.');
        } elseif ($action === 'receive') {
            $result = recordStockMove($targetId, $count, 'received', ['note' => $note]);

            if ($result['ok']) {
                // Plain words, no entities: renderToasts() escapes the message, so a
                // "&times;" in here would reach the screen as those seven characters.
                flashToast('success', 'Received ' . $count . ' of "' . $target['name'] . '". On hand: ' . $result['balance'] . '.');
            } else {
                flashToast('error', $result['error']);
            }
        } elseif ($action === 'waste') {
            // Negated here rather than inside recordStockMove(). The reason says which way
            // stock went; the sign is the caller's to state, and 'wasted' arriving positive
            // would be a bug worth being able to see in the ledger.
            $result = recordStockMove($targetId, -$count, 'wasted', ['note' => $note]);

            if ($result['ok']) {
                flashToast(
                    $result['balance'] < 0 ? 'error' : 'success',
                    'Wrote off ' . $count . ' of "' . $target['name'] . '". On hand: ' . $result['balance'] . '.'
                        . ($result['balance'] < 0 ? ' That is below zero -- the books and the drawer disagree, so count them.' : '')
                );
            } else {
                flashToast('error', $result['error']);
            }
        } elseif ($action === 'set') {
            $result = setComponentStock($targetId, $count, $note);

            if (!$result['ok']) {
                flashToast('error', $result['error']);
            } elseif ($result['changed']) {
                flashToast('success', '"' . $target['name'] . '" counted at ' . $count . '. The difference is in the ledger.');
            } else {
                // Not an error, and worth saying out loud: a stock-take that confirms the
                // books is the outcome the shop wants, and silence would look like a
                // button that did nothing.
                flashToast('success', '"' . $target['name'] . '" was already ' . $count . '. Nothing to correct.');
            }
        } elseif ($action === 'warn') {
            $result = setComponentLowStock($targetId, $count);

            if (!$result['ok']) {
                flashToast('error', $result['error']);
            } elseif ($count === 0) {
                flashToast('success', 'No more low-stock warnings for "' . $target['name'] . '".');
            } else {
                flashToast('success', 'Warning about "' . $target['name'] . '" at ' . $count . ' or fewer.');
            }
        } else {
            flashToast('error', 'That action is not something this page can do.');
        }
    } catch (PDOException $exception) {
        error_log('admin/inventory.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change right now. Check your database connection.');
    }

    redirectTo($listUrl);
}

$filters = [
    'status' => $status,
    'kind' => $kind,
    'search' => $search,
    'sort' => $sort,
    'low' => $low,
];

// Sort is not in this: it changes the order, not which rows are in the list.
$isFiltered = $status !== 'all' || $kind !== '' || $search !== '' || $low;

$levels = [];
$summary = ['parts' => 0, 'low' => 0, 'out' => 0, 'units' => 0];
$total = 0;
$grandTotal = 0;
$lastPage = 1;
$loadFailed = false;

try {
    $summary = stockSummary();
    $total = countStockLevels($filters);

    // Separate from $total so the empty state can tell "nothing matches these filters" from
    // "there are no parts at all" -- and only the second of those should be offering to add
    // the first part.
    $grandTotal = $isFiltered ? countStockLevels() : $total;

    $lastPage = max(1, (int) ceil($total / ADMIN_STOCK_PER_PAGE));
    $page = min($page, $lastPage);

    $levels = fetchStockLevels($filters, ADMIN_STOCK_PER_PAGE, ($page - 1) * ADMIN_STOCK_PER_PAGE);
} catch (PDOException $exception) {
    error_log('admin/inventory.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Messages left by the redirect that got here.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the stock list right now. Check your database connection, and that sql\inventory-upgrade.sql has been run.',
    ];
}

renderHeader('Stock');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Stock</h1>
            <p class="page-intro">
                How many of each part the shop has, and every change recorded as it happens.
                <a href="<?php echo escape(url('php/admin/stock-moves.php')); ?>">See the movement history</a>,
                or <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">back to the Dashboard</a>.
            </p>
        </header>

        <!--
            The same filter row the catalogue and the other admin lists use. A real GET form
            with a real Apply button; script.js makes the dropdowns submit on change. No
            hidden page field, so changing a filter starts again at page 1 rather than
            landing on a page that no longer exists.
        -->
        <form class="catalogue-filters admin-inventory-filters" method="get" action="<?php echo escape(url('php/admin/inventory.php')); ?>" data-catalogue-filters>
            <div class="filter-field">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status" data-filter-select>
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Offered only</option>
                    <option value="hidden" <?php echo $status === 'hidden' ? 'selected' : ''; ?>>Withdrawn only</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-kind">Part</label>
                <select id="filter-kind" name="kind" data-filter-select>
                    <option value="" <?php echo $kind === '' ? 'selected' : ''; ?>>Everything</option>
                    <?php foreach (COMPONENT_KINDS as $kindOption): ?>
                        <option value="<?php echo escape($kindOption); ?>" <?php echo $kind === $kindOption ? 'selected' : ''; ?>>
                            <?php echo escape(componentKindLabel($kindOption)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-sort">Sort by</label>
                <select id="filter-sort" name="sort" data-filter-select>
                    <option value="order" <?php echo $sort === 'order' ? 'selected' : ''; ?>>Build order</option>
                    <option value="low" <?php echo $sort === 'low' ? 'selected' : ''; ?>>Least in stock first</option>
                    <option value="high" <?php echo $sort === 'high' ? 'selected' : ''; ?>>Most in stock first</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name: A to Z</option>
                </select>
            </div>

            <!--
                A checkbox rather than a fourth dropdown: "only what's low" is the one filter
                an admin comes to this page already wanting, and a thing you tick is quicker
                to reach than a thing you open. value="yes" so it survives the round trip
                through adminStockUrl(), which drops a value of 1.
            -->
            <div class="filter-field filter-field-check">
                <label for="filter-low">
                    <input id="filter-low" name="low" type="checkbox" value="yes" <?php echo $low ? 'checked' : ''; ?> data-filter-select>
                    Only what&rsquo;s low
                </label>
            </div>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>"
                       maxlength="100" placeholder="rose, stem, ribbon&hellip;">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo $total; ?> part<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>
                        of <?php echo $grandTotal; ?>
                    <?php endif; ?>
                </p>

                <!--
                    The summary is over every offered part rather than over this page, so it
                    does not change as you page through. Only the counts worth acting on are
                    shown: "0 low" is good news that does not need saying on every load.
                -->
                <p class="catalogue-count">
                    <?php echo number_format($summary['units']); ?> in stock
                    <?php if ($summary['low'] > 0): ?>
                        &middot;
                        <a href="<?php echo escape(adminStockUrl(['low' => 'yes', 'sort' => 'low'])); ?>">
                            <?php echo $summary['low']; ?> low
                        </a>
                    <?php endif; ?>
                    <?php if ($summary['out'] > 0): ?>
                        &middot; <?php echo $summary['out']; ?> out
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/components.php')); ?>">Manage parts</a>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the stock list</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php elseif ($levels === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>There are no parts yet</h2>
                    <p>Stock is counted per part, so there is nothing to count until the parts list has something in it.</p>
                    <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/component-edit.php')); ?>">Add a part</a></p>
                <?php elseif ($low): ?>
                    <!-- The happiest empty state in the project, and worth its own words:
                         "nothing matches that" would read as a broken filter. -->
                    <h2>Nothing is low</h2>
                    <p>Every part is above the level you asked to be warned at.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/inventory.php')); ?>">Show everything</a></p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/inventory.php')); ?>">Show everything</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. A row with two forms in it
                does not fit a phone at any readable size, and a table that widens the
                document makes the whole page slide sideways -- including the navbar. Inside
                its own box, only the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Part</th>
                            <th scope="col">Sort</th>
                            <th scope="col">On hand</th>
                            <th scope="col">Warn at</th>
                            <th scope="col">Used in</th>
                            <th scope="col">Move stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($levels as $level): ?>
                            <?php
                            $componentId = (int) $level['id'];
                            $onHand = (int) $level['stock_on_hand'];
                            $threshold = (int) $level['low_stock_threshold'];
                            $usedIn = (int) $level['used_in'];
                            $isActive = (int) $level['is_active'] === 1;
                            $isLow = isLowStock($level);

                            // Re-validated on the way out, because this value goes inside a
                            // style attribute. See componentColourHex().
                            $hex = componentColourHex($level);
                            ?>
                            <tr>
                                <th scope="row" class="admin-cell-name">
                                    <span class="colour-cell">
                                        <span class="colour-swatch" style="background: <?php echo escape($hex); ?>"></span>
                                        <span class="colour-cell-text">
                                            <?php echo escape((string) $level['name']); ?>
                                            <?php if (!$isActive): ?>
                                                <!-- Withdrawn parts are still counted: what
                                                     is in the drawer does not depend on
                                                     whether the customizer offers it. -->
                                                <span class="pill pill-hidden">Withdrawn</span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </th>
                                <td class="admin-cell-kind"><?php echo escape(componentKindLabel((string) $level['kind'])); ?></td>
                                <td class="admin-cell-number">
                                    <strong><?php echo $onHand; ?></strong>
                                    <?php if ($onHand < 0): ?>
                                        <!--
                                            Below zero is not a rendering mistake. The shop is
                                            never blocked from selling, so more can go out
                                            than the books had -- and this is the books saying
                                            they disagree with the drawer, which is the most
                                            useful thing they ever say.
                                        -->
                                        <span class="pill pill-danger">Short <?php echo abs($onHand); ?></span>
                                    <?php elseif ($onHand === 0): ?>
                                        <span class="pill pill-danger">Out</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="pill pill-warn">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!--
                                        Its own form, because it is its own kind of change: a
                                        warning level is a setting and writes no ledger row.
                                        Sharing the movement form's number box would make
                                        "Warn at" and "Receive" read the same box and mean
                                        completely different things.
                                    -->
                                    <form class="admin-row-actions" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $componentId; ?>">

                                        <label class="visually-hidden" for="warn-<?php echo $componentId; ?>">
                                            Warn when "<?php echo escape((string) $level['name']); ?>" drops to
                                        </label>
                                        <!--
                                            inputmode="numeric" and a plain text input, the
                                            convention the rest of the admin forms use: a
                                            type="number" box disagrees with itself across
                                            browsers about what it will accept. maxlength
                                            matches PART_QUANTITY_MAX's three digits.
                                        -->
                                        <input class="admin-row-number" id="warn-<?php echo $componentId; ?>" name="qty"
                                               type="text" inputmode="numeric" maxlength="3"
                                               value="<?php echo $threshold; ?>">
                                        <button type="submit" class="btn btn-quiet btn-small" name="action" value="warn">Save</button>
                                    </form>
                                    <?php if ($threshold === 0): ?>
                                        <span class="admin-cell-blank">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-cell-number">
                                    <?php if ($usedIn === 0): ?>
                                        <span class="admin-cell-blank">&mdash;</span>
                                    <?php else: ?>
                                        <!-- The column that turns "we are out of pearl pins"
                                             into "and four bouquets need them". -->
                                        <?php echo $usedIn; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!--
                                        One form, one number box, three buttons. A submit
                                        button carries its own name and value, so which one
                                        was pressed arrives in $_POST['action'] on its own --
                                        and the admin types the number once whichever they
                                        then decide it was.
                                    -->
                                    <form class="admin-row-actions admin-row-move" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $componentId; ?>">

                                        <label class="visually-hidden" for="qty-<?php echo $componentId; ?>">
                                            How many of "<?php echo escape((string) $level['name']); ?>"
                                        </label>
                                        <input class="admin-row-number" id="qty-<?php echo $componentId; ?>" name="qty"
                                               type="text" inputmode="numeric" maxlength="6" placeholder="0">

                                        <label class="visually-hidden" for="note-<?php echo $componentId; ?>">
                                            Why, for the record
                                        </label>
                                        <!--
                                            The note is what makes the ledger readable a month
                                            later: "Restock from Lazada" against a row of +40
                                            is the difference between a history and a column
                                            of numbers. Optional, because making it required
                                            would teach everybody to type a full stop.
                                        -->
                                        <input class="admin-row-note" id="note-<?php echo $componentId; ?>" name="note"
                                               type="text" maxlength="<?php echo STOCK_NOTE_MAX; ?>" placeholder="Note (optional)">

                                        <button type="submit" class="btn btn-primary btn-small" name="action" value="receive">Receive</button>
                                        <button type="submit" class="btn btn-quiet btn-small" name="action" value="set">Set to</button>
                                        <!--
                                            data-confirm because this one only ever takes
                                            stock away, and unlike a delete there is no
                                            withdrawn-first step in front of it. The undo is
                                            a stock-take, which is a thing you can do -- so
                                            one pair of extra eyes is enough.
                                        -->
                                        <button type="submit" class="btn btn-danger btn-small" name="action" value="waste"
                                                data-confirm="Write off stock of &quot;<?php echo escape((string) $level['name']); ?>&quot; as spoiled or lost?">Waste</button>

                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/stock-moves.php?component=' . $componentId)); ?>">History</a>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($lastPage > 1): ?>
            <nav class="pager" aria-label="Stock list pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminStockUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Previous</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminStockUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
