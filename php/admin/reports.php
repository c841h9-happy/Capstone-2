<?php
// Admin pages start with the guard, before any output. See guard.php.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/admin.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';

$summary = [];
$loadFailed = false;
$startDate = adminReportDate($_GET['start'] ?? null);
$endDate = adminReportDate($_GET['end'] ?? null);

if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

try {
    $summary = adminReportsSummary(['start' => $startDate, 'end' => $endDate]);
} catch (PDOException $exception) {
    error_log('admin/reports.php: ' . $exception->getMessage());
    $loadFailed = true;
}

renderHeader('Reports');
?>

    <main class="page">
        <?php
        /*
         * THE PAPER HEADING. Only ever visible when printing -- see .print-only in css/main.css.
         *
         * It exists because everything that tells you WHAT a printed report is would otherwise be
         * missing from the paper. The period lives in two <input type="date"> boxes, and a printed
         * input prints its box and not its meaning; the shop's name is in the navbar, which print
         * styles correctly strip. A page of figures with no idea which shop or which dates is a
         * page somebody has to throw away, and worse, one they might file.
         *
         * The generated-at stamp is here for the same reason: these numbers move, so a printout is
         * a statement about a moment and should say which moment.
         */
        ?>
        <div class="print-only print-heading">
            <p class="print-heading-shop"><?php echo escape(SITE_NAME); ?></p>
            <h1>Sales record</h1>
            <p class="print-heading-period">
                <?php if ($startDate !== '' && $endDate !== ''): ?>
                    <?php echo escape(date('j F Y', strtotime($startDate))); ?>
                    &ndash;
                    <?php echo escape(date('j F Y', strtotime($endDate))); ?>
                <?php elseif ($startDate !== ''): ?>
                    From <?php echo escape(date('j F Y', strtotime($startDate))); ?>
                <?php elseif ($endDate !== ''): ?>
                    Up to <?php echo escape(date('j F Y', strtotime($endDate))); ?>
                <?php else: ?>
                    All dates
                <?php endif; ?>
            </p>
            <p class="print-heading-stamp">Printed <?php echo escape(date('j F Y, g:ia')); ?></p>
        </div>

        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Reports</h1>
            <p class="page-intro">
                A working start for the admin reporting module: totals, order flow, and the products that are moving.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <form class="report-date-filter" method="get" action="<?php echo escape(url('php/admin/reports.php')); ?>">
            <div class="report-date-filter-heading">
                <strong>Report period</strong>
                <span>Filter orders, revenue, payments, and product sales.</span>
            </div>
            <div class="filter-field">
                <label for="report-start">From</label>
                <input id="report-start" name="start" type="date" value="<?php echo escape($startDate); ?>">
            </div>
            <div class="filter-field">
                <label for="report-end">To</label>
                <input id="report-end" name="end" type="date" value="<?php echo escape($endDate); ?>">
            </div>
            <button type="submit" class="btn btn-primary filter-submit">Update report</button>
            <?php if ($startDate !== '' || $endDate !== ''): ?>
                <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/reports.php')); ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!$loadFailed): ?>
            <?php
            /*
             * hidden until js/script.js unhides it, so a browser with JavaScript off is never shown
             * a button that cannot do anything. Ctrl+P on this page produces the same paper either
             * way -- all of the work is in the @media print rules, not in the click handler.
             */
            ?>
            <div class="admin-toolbar no-print">
                <p class="catalogue-count">
                    <?php if ($startDate !== '' || $endDate !== ''): ?>
                        Showing the period above.
                    <?php else: ?>
                        Showing every order ever placed. Pick a period to narrow it.
                    <?php endif; ?>
                </p>
                <button type="button" class="btn btn-primary" data-print hidden>Print this record</button>
            </div>
        <?php endif; ?>

        <?php if ($loadFailed): ?>
            <div class="card card-error">
                <h2>Unable to load reports</h2>
                <p>Check the database connection and confirm the order tables have been installed.</p>
            </div>
        <?php else: ?>
        <div class="admin-stat-grid admin-stat-grid--reports">
            <section class="admin-stat-card admin-stat-card--peach">
                <span class="admin-stat-label">Net revenue</span>
                <strong><?php echo escape(formatPrice((string) ($summary['revenue'] ?? '0.00'))); ?></strong>
                <span class="admin-stat-note">Excludes cancelled orders</span>
            </section>
            <section class="admin-stat-card admin-stat-card--blue">
                <span class="admin-stat-label">Average order</span>
                <strong><?php echo escape(formatPrice((string) ($summary['average_order_value'] ?? '0.00'))); ?></strong>
                <span class="admin-stat-note">Across active orders</span>
            </section>
            <section class="admin-stat-card admin-stat-card--mint">
                <span class="admin-stat-label">Needs attention</span>
                <strong><?php echo (int) ($summary['waiting_payments'] ?? 0); ?></strong>
                <span class="admin-stat-note">Payments waiting to check</span>
            </section>
            <section class="admin-stat-card">
                <span class="admin-stat-label">Accounts</span>
                <strong><?php echo (int) ($summary['customers'] ?? 0); ?></strong>
                <span class="admin-stat-note"><?php echo (int) ($summary['orders'] ?? 0); ?> total orders</span>
            </section>
        </div>

        <div class="report-grid">
            <section class="card report-breakdown-card">
                <div class="report-section-heading"><h2>Order status</h2><span><?php echo (int) ($summary['open_orders'] ?? 0); ?> open</span></div>
                <div class="report-bars">
                    <?php foreach (($summary['status_breakdown'] ?? []) as $entry): ?>
                        <div class="report-bar-row">
                            <div><span><?php echo escape(ucfirst((string) $entry['status'])); ?></span><strong><?php echo (int) $entry['total']; ?></strong></div>
                            <span class="report-bar"><i style="width: <?php echo (int) round(((int) $entry['total'] / max(1, (int) ($summary['orders'] ?? 0))) * 100); ?>%;"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card report-breakdown-card">
                <div class="report-section-heading"><h2>Payment status</h2><span><?php echo (int) ($summary['paid_orders'] ?? 0); ?> paid</span></div>
                <div class="report-bars">
                    <?php foreach (($summary['payment_breakdown'] ?? []) as $entry): ?>
                        <div class="report-bar-row">
                            <div><span><?php echo escape(ucfirst((string) $entry['payment_status'])); ?></span><strong><?php echo (int) $entry['total']; ?></strong></div>
                            <span class="report-bar report-bar--blue"><i style="width: <?php echo (int) round(((int) $entry['total'] / max(1, (int) ($summary['orders'] ?? 0))) * 100); ?>%;"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card report-products-card">
                <div class="report-section-heading"><h2>Top products</h2><span>Units sold, excludes cancelled</span></div>
                <?php if (!empty($summary['top_products'])): ?>
                    <ol class="report-ranking">
                        <?php foreach ($summary['top_products'] as $product): ?>
                            <li><span><?php echo escape((string) $product['name']); ?></span><strong><?php echo (int) $product['units']; ?> sold</strong><small><?php echo escape(formatPrice((string) $product['revenue'])); ?></small></li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p>No product sales yet.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="card report-orders-card">
            <div class="report-section-heading"><h2>Recent orders</h2><a href="<?php echo escape(url('php/admin/orders.php')); ?>">Open order queue</a></div>
            <?php if (!empty($summary['recent_orders'])): ?>
                <div class="admin-table-wrap">
                    <table class="admin-table admin-table-reports">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Order</th>
                                <th scope="col">Payment</th>
                                <th scope="col">Total</th>
                                <th scope="col">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary['recent_orders'] as $order): ?>
                                <tr>
                                    <th scope="row" class="admin-cell-name"><?php echo escape((string) $order['reference']); ?></th>
                                    <td><?php echo escape((string) $order['contact_name']); ?></td>
                                    <td><span class="admin-badge admin-badge--<?php echo escape((string) $order['status']); ?>"><?php echo escape(ucfirst((string) $order['status'])); ?></span></td>
                                    <td><span class="admin-badge admin-badge--<?php echo escape((string) $order['payment_status']); ?>"><?php echo escape(ucfirst((string) $order['payment_status'])); ?></span></td>
                                    <td><?php echo escape(formatPrice((string) $order['total'])); ?></td>
                                    <td><?php echo escape(date('j M Y, g:ia', strtotime((string) $order['placed_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No orders have been placed yet.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
