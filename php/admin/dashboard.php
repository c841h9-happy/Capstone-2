<?php
// Admin pages start with the guard, before any output. See guard.php.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/admin.php';
require_once __DIR__ . '/../config/orders.php';
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/products.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Where an admin lands.
 *
 * Every number on this page comes from one of two calls, and every number those calls return is on
 * the page. That was not true until 2026-09-26: the screen had grown six try blocks around roughly
 * thirty-four queries, of which about seven reached the template. The rest were left over from the
 * catalogue cards the redesign removed -- product counts, component counts, order counts and a
 * nine-query inventory summary, all still being fetched every load, one of them still raising a
 * toast about a table whose numbers the page no longer showed.
 *
 * Three calls as of 2026-09-28, and the rule still holds: adminInventorySummary() is back, but it
 * is a different function now. It was rewritten from nine catalogue queries nobody rendered to
 * three stock queries all of which are in the panel below. config/inventory.php is required for
 * it -- it calls stockSummary(), rather than defining a fourth idea of what "low" means.
 *
 * config/products.php is here for formatPrice() alone, now that the three admin config files this
 * page used to pull in have no call sites left on it.
 *
 * Note the name: this is php/admin/dashboard.php, while the customer's is php/dashboard.php.
 * navLinkClass() in php/components/navbar.php compares whole paths rather than basenames for
 * exactly that reason.
 */
$statsFailed = false;
$dashboardStats = adminEmptyDashboardStats();

/*
 * This call went unguarded until 2026-09-26, alone among the page's data calls while five siblings
 * each had their own try.
 *
 * config/database.php sets PDO::ERRMODE_EXCEPTION, so on a database carrying an earlier migration
 * but not the order tables its first query threw an uncaught PDOException -- a fatal, and the
 * admin got a blank page instead of the cards that still worked. The fallback is a fully-formed
 * empty rather than [], because the template indexes into donut_stats and graph_series directly
 * and a bare [] would only trade the fatal for an undefined index.
 */
try {
    $dashboardStats = adminDashboardStats();
} catch (PDOException $exception) {
    error_log('admin/dashboard.php: ' . $exception->getMessage());
    $statsFailed = true;
}

/*
 * Two counts, and both are shown.
 *
 * The card says "Customers" and its icon links to the customer list, so it has to mean what that
 * screen means by the word. It used to print adminCountCustomers() unfiltered -- every account,
 * staff included -- while customers.php broke the same total down as "All accounts 2, Customers 1,
 * Admins 1". One number, two screens, two answers. The admin count was already being fetched on
 * the very next line and thrown away; it is the card's subtitle now.
 */
$customerCount = 0;
$adminCount = 0;
$customersFailed = false;

try {
    $customerCount = adminCountCustomers(['role' => 'customer']);
    $adminCount = adminCountCustomers(['role' => 'admin']);
} catch (PDOException $exception) {
    error_log('admin/dashboard.php: ' . $exception->getMessage());
    $customersFailed = true;
}

/*
 * Its own try, and its own toast, rather than joining either of the two above.
 *
 * The stock tables arrive in sql\inventory-upgrade.sql, which is a separate migration from the
 * one the orders figures need -- so a database carrying one and not the other is a real state,
 * and the admin should get the half that works plus an accurate message about the half that does
 * not. Folding this into the stats try would take the sales figures down with it and blame the
 * wrong file.
 */
$stock = adminEmptyInventorySummary();
$stockFailed = false;

try {
    $stock = adminInventorySummary();
} catch (PDOException $exception) {
    error_log('admin/dashboard.php: ' . $exception->getMessage());
    $stockFailed = true;
}

/*
 * A series value printed the way that series measures things. The legend uses it for the peak and
 * every bar uses it for its tooltip, so a bar's height never has to be guessed at.
 */
$seriesValue = static function (array $series, float $value): string {
    return $series['format'] === 'money'
        ? formatPrice(number_format($value, 2, '.', ''))
        : (string) (int) $value;
};

$hasWeeklyActivity = array_reduce(
    $dashboardStats['graph_series'],
    static fn (bool $hasActivity, array $series): bool => $hasActivity || (float) ($series['peak'] ?? 0) > 0,
    false
);

$toasts = takeFlashToasts();

if ($statsFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the sales figures. Check that sql\orders-upgrade.sql has been run.',
    ];
}

if ($customersFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to count the accounts. Check that sql\schema.sql has been run.',
    ];
}

if ($stockFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to read the stock figures. Check that sql\inventory-upgrade.sql has been run.',
    ];
}

renderHeader('Admin');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Dashboard</h1>
            <p class="page-intro">
                Signed in as <strong><?php echo escape($_SESSION['user_name']); ?></strong>
                with administrator access.
            </p>
        </header>

        <div class="admin-dashboard-shell">
            <div class="admin-kpi-grid">
                <div class="kpi-card kpi-card--peach">
                    <div class="kpi-header">
                        <span>Weekly Sales</span>
                        <a class="kpi-icon-link" href="<?php echo escape(url('php/admin/reports.php')); ?>" aria-label="View sales reports" title="View sales reports"><span class="kpi-icon">↗</span></a>
                    </div>
                    <div class="kpi-value"><?php echo escape(formatPrice((string) ($dashboardStats['weekly_revenue'] ?? '0.00'))); ?></div>
                    <div class="kpi-trend kpi-trend--up">See where sales came from</div>
                </div>

                <div class="kpi-card kpi-card--blue">
                    <div class="kpi-header">
                        <span>Weekly Orders</span>
                        <a class="kpi-icon-link" href="<?php echo escape(url('php/admin/orders.php')); ?>" aria-label="View recent orders" title="View recent orders"><span class="kpi-icon">▣</span></a>
                    </div>
                    <div class="kpi-value"><?php echo (int) ($dashboardStats['weekly_orders'] ?? 0); ?></div>
                    <div class="kpi-trend kpi-trend--down">Open the order queue</div>
                </div>

                <div class="kpi-card kpi-card--mint">
                    <div class="kpi-header">
                        <span>Customers</span>
                        <a class="kpi-icon-link" href="<?php echo escape(url('php/admin/customers.php')); ?>" aria-label="View customer list" title="View customer list"><span class="kpi-icon">◌</span></a>
                    </div>
                    <div class="kpi-value"><?php echo (int) $customerCount; ?></div>
                    <div class="kpi-trend kpi-trend--up">
                        Plus <?php echo (int) $adminCount; ?> staff account<?php echo $adminCount === 1 ? '' : 's'; ?>
                    </div>
                </div>
            </div>

            <div class="dashboard-panels">
                <section class="chart-panel chart-panel--wide">
                    <div class="panel-topline">
                        <h2>Last 7 days</h2>
                        <?php if ($hasWeeklyActivity): ?>
                        <div class="legend">
                            <?php foreach ($dashboardStats['graph_series'] as $series): ?>
                                <span>
                                    <i class="dot dot--<?php echo escape((string) $series['class']); ?>"></i>
                                    <?php echo escape((string) $series['label']); ?>
                                    <strong>peak <?php echo escape($seriesValue($series, (float) $series['peak'])); ?></strong>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    /*
                     * Three series, each scaled against its own peak -- so a bar's height says how
                     * that day compares with the best day of its own measurement, and nothing else.
                     * The peak is printed in the legend and every bar carries its real value as a
                     * tooltip, because without both a 1,360 week and a 136,000 week draw the same
                     * picture.
                     */
                    ?>
                    <?php if ($hasWeeklyActivity): ?>
                    <div class="bar-chart" aria-label="Revenue, orders and new accounts over the last seven days">
                        <?php foreach ($dashboardStats['graph_labels'] as $index => $label): ?>
                            <div class="bar-column">
                                <div class="bar-stack">
                                    <?php foreach ($dashboardStats['graph_series'] as $series): ?>
                                        <?php $reading = $series['label'] . ' ' . $label . ': ' . $seriesValue($series, (float) $series['values'][$index]); ?>
                                        <span class="bar bar--<?php echo escape((string) $series['class']); ?>"
                                              style="height: <?php echo (int) $series['bars'][$index]; ?>%;"
                                              title="<?php echo escape($reading); ?>"><span class="visually-hidden"><?php echo escape($reading); ?></span></span>
                                    <?php endforeach; ?>
                                </div>
                                <small><?php echo escape((string) $label); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p class="bar-chart-empty">No activity in the last 7 days.</p>
                    <?php endif; ?>
                </section>

                <section class="chart-panel">
                    <div class="panel-topline panel-topline--stacked">
                        <h2>Payment status</h2>
                    </div>

                    <div class="donut-wrap">
                        <div class="donut-chart" style="--segment-a: <?php echo (int) $dashboardStats['donut_stats'][0]['value']; ?>%; --segment-b: <?php echo (int) ($dashboardStats['donut_stats'][0]['value'] + $dashboardStats['donut_stats'][1]['value']); ?>%; --segment-c: 100%; --paid-color: <?php echo escape((string) $dashboardStats['donut_stats'][0]['color']); ?>; --submitted-color: <?php echo escape((string) $dashboardStats['donut_stats'][1]['color']); ?>; --unpaid-color: <?php echo escape((string) $dashboardStats['donut_stats'][2]['color']); ?>;"></div>
                    </div>

                    <ul class="donut-legend">
                        <?php foreach ($dashboardStats['donut_stats'] as $entry): ?>
                            <li>
                                <span class="swatch" style="background: <?php echo escape($entry['color']); ?>;"></span>
                                <span><?php echo escape($entry['label']); ?></span>
                                <strong><?php echo (int) $entry['value']; ?>%</strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>

            <div class="dashboard-lower-grid">
                <section class="chart-panel chart-panel--wide">
                    <div class="panel-topline">
                        <h2>Recent orders</h2>
                        <a href="<?php echo escape(url('php/admin/orders.php')); ?>">View all</a>
                    </div>

                    <?php if (!empty($dashboardStats['recent_orders'])): ?>
                        <div class="table-wrap mini-table">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardStats['recent_orders'] as $order): ?>
                                        <tr>
                                            <td><?php echo escape((string) $order['reference']); ?></td>
                                            <td><?php echo escape((string) $order['contact_name']); ?></td>
                                            <td>
                                                <span class="order-pill order-pill-<?php echo escape((string) $order['status']); ?>">
                                                    <?php echo escape(orderStatusLabel((string) $order['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo escape(formatPrice((string) $order['total'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="muted-copy">No orders have been placed yet.</p>
                    <?php endif; ?>
                </section>

                <section class="chart-panel">
                    <div class="panel-topline">
                        <h2>Top products</h2>
                    </div>

                    <?php if (!empty($dashboardStats['top_products'])): ?>
                        <ul class="mini-list">
                            <?php foreach ($dashboardStats['top_products'] as $product): ?>
                                <li>
                                    <span><?php echo escape((string) $product['name']); ?></span>
                                    <strong><?php echo (int) $product['units']; ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted-copy">No product data yet.</p>
                    <?php endif; ?>
                </section>
            </div>

            <?php
            /*
                What to buy, on the screen an admin opens first.

                This is the panel adminInventorySummary() waited two modules for, and it earns its
                place by being the only thing on this page that is a job rather than a figure. The
                three cards at the top report what happened; this says what to do next.

                Every number in it links to the row that explains it, because a dashboard that
                says "3 low" and makes you go and find which three is a dashboard people stop
                reading. "Out" and "Low" go to the stock list already filtered and sorted; the
                audit line goes to the ledger, which is the only screen that can explain it.

                The one caveat worth keeping next to these counts: a part reading zero does not
                stop anybody buying the bouquet it belongs to, and it never will -- warn, never
                block, which was the user's call. What has changed since 2026-09-29 is that these
                counts now move on their own: an order marked as being made takes its parts out,
                so "Out" and "Low" can grow without anybody having opened a drawer.
            */
            ?>
            <div class="dashboard-lower-grid">
                <section class="chart-panel chart-panel--wide dashboard-buy-panel">
                    <div class="panel-topline">
                        <h2>What to buy</h2>
                        <a href="<?php echo escape(url('php/admin/inventory.php')); ?>">Open stock</a>
                    </div>

                    <?php if ($stockFailed): ?>
                        <p class="muted-copy">
                            The stock figures are unavailable. Nothing has changed &mdash; run
                            <code>sql\inventory-upgrade.sql</code> and reload.
                        </p>
                    <?php elseif ($stock['parts'] === 0): ?>
                        <p class="muted-copy">
                            There are no parts to count yet.
                            <a href="<?php echo escape(url('php/admin/component-edit.php')); ?>">Add a part</a>
                            and its stock appears here.
                        </p>
                    <?php else: ?>
                        <?php if ($stock['audit'] > 0): ?>
                            <!--
                                recordStockMove() writes the balance and its ledger row in one
                                transaction, so a part can only get in here through a hand-edit in
                                the MySQL shell or a bug. It is worth interrupting the page for.
                                The ledger names the parts and the differences; this does not
                                repeat that, so there is one place to keep the wording right.
                            -->
                            <div class="notice notice-alarm">
                                <strong>The books do not add up.</strong>
                                <?php echo (int) $stock['audit']; ?>
                                part<?php echo $stock['audit'] === 1 ? '' : 's'; ?>
                                <?php echo $stock['audit'] === 1 ? 'has' : 'have'; ?>
                                a count that disagrees with its own history.
                                <a href="<?php echo escape(url('php/admin/stock-moves.php')); ?>">See which, on the ledger</a>.
                            </div>
                        <?php endif; ?>

                        <?php if ($stock['shortfalls'] === []): ?>
                            <p class="muted-copy">
                                Nothing is low. All <?php echo (int) $stock['parts']; ?> offered
                                part<?php echo $stock['parts'] === 1 ? '' : 's'; ?> are above the
                                level they are set to warn at, with
                                <?php echo number_format($stock['units']); ?> in stock altogether.
                            </p>
                        <?php else: ?>
                            <div class="table-wrap mini-table">
                                <table class="data-table dashboard-stock-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Part</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">On hand</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stock['shortfalls'] as $part): ?>
                                            <?php
                                            $onHand = (int) $part['stock_on_hand'];
                                            $warnAt = (int) $part['low_stock_threshold'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="<?php echo escape(url('php/admin/inventory.php') . '?' . http_build_query(['q' => (string) $part['name']])); ?>"><?php echo escape((string) $part['name']); ?></a>
                                                </td>
                                                <?php if ($onHand < 0): ?>
                                                    <td><span class="dashboard-stock-status dashboard-stock-status--out">Short</span></td>
                                                <?php elseif ($onHand === 0): ?>
                                                    <td><span class="dashboard-stock-status dashboard-stock-status--out">Out</span></td>
                                                <?php else: ?>
                                                    <td><span class="dashboard-stock-status dashboard-stock-status--low">Warn at <?php echo $warnAt; ?></span></td>
                                                <?php endif; ?>
                                                <td class="dashboard-stock-on-hand"><?php echo number_format($onHand); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="muted-copy">
                                <?php
                                /*
                                 * http_build_query rather than a hand-written "?low=yes&sort=low".
                                 * escape() is htmlspecialchars, so a literal &amp; in the source
                                 * would arrive in the page as &amp;amp; and the link would carry
                                 * a parameter called "amp;sort". Let the builder emit a raw & and
                                 * let escape() encode it exactly once.
                                 *
                                 * low=yes, not low=1, to match adminStockUrl() on the stock screen
                                 * -- that page reads the parameter with isset() so either works,
                                 * but two spellings of one filter is how they drift apart.
                                 */
                                $stockUrl = url('php/admin/inventory.php');
                                ?>
                                <?php if ($stock['out'] > 0): ?>
                                    <a href="<?php echo escape($stockUrl . '?' . http_build_query(['sort' => 'low'])); ?>"><?php echo (int) $stock['out']; ?> out of stock</a>
                                <?php endif; ?>
                                <?php if ($stock['out'] > 0 && $stock['low'] > 0): ?>&middot;<?php endif; ?>
                                <?php if ($stock['low'] > 0): ?>
                                    <a href="<?php echo escape($stockUrl . '?' . http_build_query(['low' => 'yes', 'sort' => 'low'])); ?>"><?php echo (int) $stock['low']; ?> below the warn level</a>
                                <?php endif; ?>
                                &middot; orders do not use stock up yet, so these move only when somebody counts.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="chart-panel">
                    <div class="panel-topline">
                        <h2>In stock</h2>
                        <a href="<?php echo escape(url('php/admin/stock-moves.php')); ?>">History</a>
                    </div>

                    <?php if ($stockFailed): ?>
                        <p class="muted-copy">Unavailable.</p>
                    <?php else: ?>
                        <ul class="mini-list">
                            <li>
                                <span>Units on hand</span>
                                <strong><?php echo number_format($stock['units']); ?></strong>
                            </li>
                            <li>
                                <span>Parts counted</span>
                                <strong><?php echo (int) $stock['parts']; ?></strong>
                            </li>
                            <li>
                                <span>Out of stock</span>
                                <strong><?php echo (int) $stock['out']; ?></strong>
                            </li>
                            <li>
                                <span>Below warn level</span>
                                <strong><?php echo (int) $stock['low']; ?></strong>
                            </li>
                        </ul>
                        <p class="muted-copy">
                            Offered parts only &mdash; a withdrawn part is not something the
                            customizer builds with, so having none of it is not a shortage.
                        </p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
<?php renderFooter(); ?>
