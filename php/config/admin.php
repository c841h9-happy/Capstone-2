<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Admin account listing: one page of signed-up shoppers, with filters that match the rest of the
 * app.
 *
 * One rule runs through every number in this file, because two screens quietly disagreeing about
 * what "revenue" means is worse than either answer on its own: **a count of orders includes
 * cancelled ones, and a sum of money excludes them.** An order the shop cancelled was still taken,
 * so it belongs in "how much work came in"; the money was never collected, so it does not belong in
 * "how much the shop made". Every SUM() here carries `status <> 'cancelled'` and every COUNT() does
 * not, and the screens say so where a reader could otherwise be misled.
 */
const ADMIN_CUSTOMERS_PER_PAGE = 20;
const ADMIN_CUSTOMER_ROLES = ['all', 'customer', 'admin'];

function adminCustomerRoleFilter(mixed $value): string
{
    return is_string($value) && in_array($value, ADMIN_CUSTOMER_ROLES, true)
        ? $value
        : 'all';
}

function adminCustomerSearch(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $search = trim($value);

    return mb_strlen($search) > 100 ? mb_substr($search, 0, 100) : $search;
}

function adminCustomerFilterSql(array $filters): array
{
    $where = [];
    $params = [];

    $role = adminCustomerRoleFilter($filters['role'] ?? null);

    if ($role !== 'all') {
        $where[] = 'u.role = :role';
        $params['role'] = $role;
    }

    $search = adminCustomerSearch($filters['search'] ?? null);

    if ($search !== '') {
        $escaped = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        $where[] = '(u.name LIKE :search_name ESCAPE "!" OR u.email LIKE :search_email ESCAPE "!")';
        $params['search_name'] = $escaped;
        $params['search_email'] = $escaped;
    }

    return [
        'sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

function adminFetchCustomers(array $filters = [], int $limit = ADMIN_CUSTOMERS_PER_PAGE, int $offset = 0): array
{
    $filter = adminCustomerFilterSql($filters);

    $statement = getDatabaseConnection()->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.created_at,
                (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
                (SELECT COALESCE(SUM(o.total), 0)
                 FROM orders o
                 WHERE o.user_id = u.id AND o.status <> 'cancelled') AS total_spent
         FROM users u"
        . $filter['sql']
        . ' ORDER BY u.created_at DESC, u.id DESC
           LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function adminCountCustomers(array $filters = []): int
{
    $filter = adminCustomerFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM users u' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * Summary numbers for the admin reports page and the dashboard cards.
 */
function adminReportDate(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
}

/**
 * The two lists that both the dashboard and the reports page put on screen.
 *
 * They are their own functions so the dashboard does not have to call adminReportsSummary() --
 * eleven queries -- to borrow two of them. $conditions are SQL fragments about the orders table
 * under the alias `o`, already parameterised by the caller; pass [] for "everything".
 *
 * @param string[] $conditions
 * @param array<string, mixed> $params
 */
function adminTopProducts(array $conditions, array $params = [], int $limit = 5): array
{
    /*
     * Grouped on the line's own frozen name, not on a join to `products`.
     *
     * Joining dropped two kinds of sale on the floor. A custom bouquet has no product_id at all,
     * so every sale the customizer made -- the shop's headline feature -- was missing from a
     * ranking titled "Top products". And product_id is ON DELETE SET NULL, so withdrawing a
     * product erased its entire sales history from the report as well. order_items.name is the
     * snapshot taken when the order was placed, which is exactly the thing that survives both.
     *
     * The cost is that two different products sharing a name merge into one row. That is a fair
     * trade for a by-name ranking, and much cheaper than silently under-reporting.
     */
    $label = "CASE WHEN oi.kind = 'design' THEN 'Custom bouquet' ELSE oi.name END";
    $conditions[] = "o.status <> 'cancelled'";

    $statement = getDatabaseConnection()->prepare(
        "SELECT $label AS name,
                SUM(oi.quantity) AS units,
                SUM(oi.line_total) AS revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE " . implode(' AND ', $conditions) . "
         GROUP BY $label
         ORDER BY units DESC, revenue DESC
         LIMIT " . max(1, $limit)
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @param string[] $conditions
 * @param array<string, mixed> $params
 */
function adminRecentOrders(array $conditions, array $params = [], int $limit = 5): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT o.reference, o.contact_name, o.status, o.payment_status, o.total, o.placed_at
         FROM orders o'
        . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
        . ' ORDER BY o.placed_at DESC, o.id DESC
           LIMIT ' . max(1, $limit)
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function adminReportsSummary(array $filters = []): array
{
    $connection = getDatabaseConnection();

    $customerCount = (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $startDate = adminReportDate($filters['start'] ?? null);
    $endDate = adminReportDate($filters['end'] ?? null);
    $where = [];
    $aliasWhere = [];
    $params = [];

    // Two spellings of the same conditions: the bare one for the queries that read `orders` on its
    // own, and the `o.` one for the two helpers above, which join order_items alongside it.
    if ($startDate !== '') {
        $where[] = 'placed_at >= :report_start';
        $aliasWhere[] = 'o.placed_at >= :report_start';
        $params['report_start'] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $endExclusive = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
        $where[] = 'placed_at < :report_end';
        $aliasWhere[] = 'o.placed_at < :report_end';
        $params['report_end'] = $endExclusive . ' 00:00:00';
    }

    $orderWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $activeWhere = $orderWhere === ''
        ? " WHERE status <> 'cancelled'"
        : $orderWhere . " AND status <> 'cancelled'";

    $reportQuery = static function (PDO $connection, string $sql, array $params): mixed {
        $statement = $connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    };

    $orderCount = (int) $reportQuery($connection, 'SELECT COUNT(*) FROM orders' . $orderWhere, $params);
    $revenue = (string) $reportQuery($connection, 'SELECT COALESCE(SUM(total), 0) FROM orders' . $activeWhere, $params);
    $openOrderCount = (int) $reportQuery($connection, "SELECT COUNT(*) FROM orders" . ($orderWhere === '' ? " WHERE status IN ('placed', 'packed')" : $orderWhere . " AND status IN ('placed', 'packed')"), $params);
    $waitingPayments = (int) $reportQuery($connection, "SELECT COUNT(*) FROM orders" . ($orderWhere === '' ? " WHERE payment_status = 'submitted'" : $orderWhere . " AND payment_status = 'submitted'"), $params);
    $paidOrderCount = (int) $reportQuery($connection, "SELECT COUNT(*) FROM orders" . ($orderWhere === '' ? " WHERE payment_status = 'paid'" : $orderWhere . " AND payment_status = 'paid'"), $params);
    $averageOrderValue = (string) $reportQuery($connection, 'SELECT COALESCE(AVG(total), 0) FROM orders' . $activeWhere, $params);

    $statusStatement = $connection->prepare(
        'SELECT status, COUNT(*) AS total
         FROM orders
         ' . $orderWhere . '
         GROUP BY status
         ORDER BY FIELD(status, "placed", "packed", "delivered", "cancelled")'
    );
    $statusStatement->execute($params);
    $statusBreakdown = $statusStatement->fetchAll();

    $paymentStatement = $connection->prepare(
        'SELECT payment_status, COUNT(*) AS total
         FROM orders
         ' . $orderWhere . '
         GROUP BY payment_status
         ORDER BY FIELD(payment_status, "unpaid", "submitted", "paid")'
    );
    $paymentStatement->execute($params);
    $paymentBreakdown = $paymentStatement->fetchAll();

    $topProducts = adminTopProducts($aliasWhere, $params);
    $recentOrders = adminRecentOrders($aliasWhere, $params);

    return [
        'customers' => $customerCount,
        'orders' => $orderCount,
        'revenue' => $revenue,
        'open_orders' => $openOrderCount,
        'waiting_payments' => $waitingPayments,
        'paid_orders' => $paidOrderCount,
        'average_order_value' => $averageOrderValue,
        'status_breakdown' => $statusBreakdown,
        'payment_breakdown' => $paymentBreakdown,
        'top_products' => $topProducts,
        'recent_orders' => $recentOrders,
    ];
}

/**
 * What the dashboard says about stock: the parts that need buying, and whether the books agree.
 *
 * Rewritten 2026-09-28, and the history is the point. This function shipped with the Admin module
 * as nine queries returning a complete, working, entirely uncalled answer: product counts, part
 * counts, the mix of kinds, the average part price and the most expensive part. The dashboard
 * called it and rendered none of it. On 2026-09-26 the call was deleted and the function kept,
 * because Inventory was the module that would want it -- and then Inventory did not want it
 * either. Its three screens each ask a narrower question, and none of those nine numbers is one
 * of them.
 *
 * So rather than build a panel for the answer it had, this now returns the answer the dashboard
 * actually needs. **Every number here is one an admin can act on before lunch:** how many parts
 * have run out, how many are low, what to buy first. What went is what nobody could act on -- an
 * average part price is a fact about a price list, not a thing to do, and the dashboard's rule
 * since 2026-09-26 is that every number it fetches is a number it shows.
 *
 * Three queries, not nine, and the first is stockSummary() rather than a fourth definition of
 * "what counts as low". A screen and a panel quietly disagreeing about that would be the same
 * fault as the two "revenue" figures this file's docblock exists to prevent.
 *
 * `shortfalls` is the buying list: the parts at or below zero first (the shop owes these), then
 * the ones under their warn level, worst first. `audit` is the count of parts whose stored
 * balance disagrees with their own ledger -- always 0 in a healthy database, and a link to the
 * one screen that explains it when it is not.
 *
 * Requires config/inventory.php, which is where the stock tables and stockSummary() live. This
 * file does not require it: admin.php is loaded by screens that have nothing to do with stock,
 * and the caller that wants this pulls in both. See php/admin/dashboard.php.
 *
 * @return array{parts: int, low: int, out: int, units: int, shortfalls: array<int, array<string, mixed>>, audit: int}
 */
function adminInventorySummary(int $shortfallLimit = 4): array
{
    $connection = getDatabaseConnection();

    $summary = stockSummary();

    /*
     * Offered parts only, exactly as stockSummary() counts them -- otherwise the panel would say
     * "3 low" and list four, because a withdrawn part nobody can order still has a count.
     *
     * The ORDER BY puts what is owed above what is merely low: stock_on_hand <= 0 first, then by
     * how far under the warn level the part is. A part 20 below its threshold outranks one that
     * is 1 below, which is not the same as ordering by stock_on_hand -- 2 pearl pins left of a
     * warn-at-50 is more urgent than 10 rose heads left of a warn-at-10.
     */
    $statement = $connection->prepare(
        'SELECT id, name, kind, stock_on_hand, low_stock_threshold
           FROM product_components
          WHERE is_active = 1
            AND (stock_on_hand <= 0 OR (low_stock_threshold > 0 AND stock_on_hand <= low_stock_threshold))
          ORDER BY (stock_on_hand <= 0) DESC,
                   (low_stock_threshold - stock_on_hand) DESC,
                   name ASC,
                   id ASC
          LIMIT :limit'
    );

    // PARAM_INT, not through execute(): emulated prepares are off, so everything in an execute()
    // array goes as a string and LIMIT '4' is a syntax error to MySQL.
    $statement->bindValue(':limit', max(1, $shortfallLimit), PDO::PARAM_INT);
    $statement->execute();

    /*
     * COUNT over the audit rather than the rows themselves. The dashboard only needs to know
     * whether to shout; stock-moves.php is the screen that names the parts and the differences,
     * and duplicating that here would be a second place to keep the wording right.
     */
    $audit = (int) $connection->query(
        'SELECT COUNT(*) FROM (
             SELECT c.id
               FROM product_components c
               LEFT JOIN stock_moves m ON m.component_id = c.id
              GROUP BY c.id, c.stock_on_hand
             HAVING c.stock_on_hand <> COALESCE(SUM(m.delta), 0)
         ) AS wrong'
    )->fetchColumn();

    return $summary + [
        'shortfalls' => $statement->fetchAll(),
        'audit' => $audit,
    ];
}

/**
 * The payment slices the donut draws, in drawing order, with the colours css/main.css expects.
 */
const ADMIN_PAYMENT_SLICES = [
    'paid' => '#8bb99a',
    'submitted' => '#d99b22',
    'unpaid' => '#e7805f',
];

/**
 * The three series the revenue chart draws, in drawing order. `format` tells the template whether
 * that series' peak is money or a plain count; `class` is the CSS modifier for its bar and dot.
 */
const ADMIN_DASHBOARD_SERIES = [
    'revenue' => ['label' => 'Revenue', 'class' => 'pink', 'format' => 'money'],
    'orders' => ['label' => 'Orders', 'class' => 'violet', 'format' => 'count'],
    'accounts' => ['label' => 'Accounts', 'class' => 'blue', 'format' => 'count'],
];

/**
 * The seven dates the chart covers, oldest first, as Y-m-d.
 *
 * Computed in PHP rather than with CURDATE() so the cards, the bars and the labels cannot disagree
 * about where the week starts when PHP and MariaDB are set to different timezones.
 *
 * @return string[]
 */
function adminDashboardWeek(): array
{
    $start = (new DateTimeImmutable('today'))->modify('-6 days');
    $days = [];

    for ($offset = 0; $offset < 7; $offset++) {
        $days[] = $start->modify('+' . $offset . ' day')->format('Y-m-d');
    }

    return $days;
}

/**
 * The exact shape adminDashboardStats() returns, with every number at zero.
 *
 * The dashboard indexes straight into this -- $donutStats[0], $series[1]['bars'][3] -- so a catch
 * that assigned a bare [] would trade a missing table for an undefined index and take the page
 * down just the same. Degrading gracefully means degrading to a well-formed empty.
 */
function adminEmptyDashboardStats(): array
{
    $days = adminDashboardWeek();

    $donut = [];
    foreach (ADMIN_PAYMENT_SLICES as $status => $colour) {
        $donut[] = ['label' => ucfirst($status), 'value' => 0, 'color' => $colour];
    }

    $series = [];
    foreach (ADMIN_DASHBOARD_SERIES as $key => $meta) {
        $series[] = $meta + [
            'key' => $key,
            'values' => array_fill(0, count($days), 0.0),
            'bars' => array_fill(0, count($days), 0),
            'peak' => 0.0,
        ];
    }

    return [
        'weekly_revenue' => '0.00',
        'weekly_orders' => 0,
        'graph_labels' => array_map(
            static fn (string $day): string => date('D', (int) strtotime($day)),
            $days
        ),
        'graph_bars' => array_fill(0, count($days), 0),
        'graph_series' => $series,
        'donut_stats' => $donut,
        'recent_orders' => [],
        'top_products' => [],
    ];
}

/**
 * The exact shape adminInventorySummary() returns, with nothing in it.
 *
 * Same argument as adminEmptyDashboardStats() above, and the same trap it was written to avoid:
 * the panel reads $stock['out'] and loops $stock['shortfalls'] directly, so a catch that assigned
 * [] would swap a missing table for an undefined index and take the whole page down -- which is
 * precisely the fault this file's 2026-09-26 cleanup found on the unguarded stats call.
 *
 * It matters more here than there. This function needs sql\inventory-upgrade.sql to have been
 * run, and a marker's database may be a day behind: the dashboard has to survive stock_moves not
 * existing yet, and say so, rather than showing a blank page.
 *
 * @return array{parts: int, low: int, out: int, units: int, shortfalls: array<int, array<string, mixed>>, audit: int}
 */
function adminEmptyInventorySummary(): array
{
    return [
        'parts' => 0,
        'low' => 0,
        'out' => 0,
        'units' => 0,
        'shortfalls' => [],
        'audit' => 0,
    ];
}

function adminDashboardStats(): array
{
    $connection = getDatabaseConnection();

    $stats = adminEmptyDashboardStats();
    $days = adminDashboardWeek();
    $since = $days[0] . ' 00:00:00';

    /*
     * Money excludes cancelled orders; the count does not. See the rule at the top of this file --
     * the reports page has always drawn that line and this card used to sit on the other side of
     * it, so one week was worth two different amounts depending which screen you opened. They only
     * ever agreed because nothing had been cancelled yet.
     */
    $weekly = $connection->prepare(
        "SELECT COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END), 0) AS revenue,
                COUNT(*) AS orders
         FROM orders
         WHERE placed_at >= :since"
    );
    $weekly->execute(['since' => $since]);
    $weeklyRow = $weekly->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['weekly_revenue'] = (string) ($weeklyRow['revenue'] ?? '0.00');
    $stats['weekly_orders'] = (int) ($weeklyRow['orders'] ?? 0);

    /*
     * Three series, three queries, three sets of real numbers.
     *
     * The chart used to draw one query three times: the revenue bar at its true height, then an
     * "Orders" bar ten points below it and an "Accounts" bar twenty below that. On any day with
     * sales that is a 100/90/80 staircase standing behind a legend that promises three different
     * measurements -- it invited the reader to conclude orders track revenue exactly, because it
     * was drawn that way.
     */
    $sources = [
        "SELECT DATE(placed_at) AS day, COALESCE(SUM(total), 0) AS value
         FROM orders
         WHERE placed_at >= :since AND status <> 'cancelled'
         GROUP BY DATE(placed_at)",
        'SELECT DATE(placed_at) AS day, COUNT(*) AS value
         FROM orders
         WHERE placed_at >= :since
         GROUP BY DATE(placed_at)',
        'SELECT DATE(created_at) AS day, COUNT(*) AS value
         FROM users
         WHERE created_at >= :since
         GROUP BY DATE(created_at)',
    ];

    foreach ($sources as $index => $sql) {
        $statement = $connection->prepare($sql);
        $statement->execute(['since' => $since]);

        $byDay = [];
        foreach ($statement->fetchAll() as $row) {
            $byDay[(string) $row['day']] = (float) $row['value'];
        }

        $values = [];
        foreach ($days as $date) {
            $values[] = (float) ($byDay[$date] ?? 0.0);
        }

        /*
         * Each series is scaled against its own peak, because three orders and three thousand
         * pesos cannot share an axis. That makes a bar's height meaningless on its own, so the
         * peak travels with the series and the legend prints it -- without one, a 1,360 week and a
         * 136,000 week draw exactly the same picture.
         */
        $peak = max($values);
        $stats['graph_series'][$index]['values'] = $values;
        $stats['graph_series'][$index]['peak'] = $peak;
        $stats['graph_series'][$index]['bars'] = $peak > 0
            ? array_map(static fn (float $value): int => (int) round(($value / $peak) * 100), $values)
            : array_fill(0, count($days), 0);
    }

    // The page has read graph_bars since the redesign; it is the revenue series under its old name.
    $stats['graph_bars'] = $stats['graph_series'][0]['bars'];

    $statusRows = $connection->query(
        'SELECT payment_status, COUNT(*) AS total
         FROM orders
         GROUP BY payment_status'
    )->fetchAll();

    $counts = array_fill_keys(array_keys(ADMIN_PAYMENT_SLICES), 0);

    foreach ($statusRows as $row) {
        $status = (string) $row['payment_status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['total'];
        }
    }

    $paymentTotal = array_sum($counts);

    if ($paymentTotal > 0) {
        foreach (array_values($counts) as $index => $count) {
            $stats['donut_stats'][$index]['value'] = (int) round(($count / $paymentTotal) * 100);
        }

        /*
         * Three rounded percentages rarely add to exactly 100, and the ring's last arc runs to
         * 100% regardless -- so the rounding error is absorbed by a slice rather than left to
         * show as a gap or an overlap.
         */
        $drawn = array_sum(array_column($stats['donut_stats'], 'value'));

        if ($drawn > 100) {
            $stats['donut_stats'][0]['value'] = max(0, $stats['donut_stats'][0]['value'] - ($drawn - 100));
        } elseif ($drawn < 100) {
            $stats['donut_stats'][count($stats['donut_stats']) - 1]['value'] += 100 - $drawn;
        }
    }

    $stats['recent_orders'] = adminRecentOrders([]);
    $stats['top_products'] = adminTopProducts([]);

    return $stats;
}
