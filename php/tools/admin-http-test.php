<?php

declare(strict_types=1);

/**
 * The admin screens, over Apache, and the data layer behind them.
 *
 * Written for the five defects and the two waste items found reviewing the 2026-09-24 Copilot build
 * and fixed on 2026-09-26. Before this file there was no automated coverage of any admin screen
 * except `orders.php` and the CSRF token sweep, which is why the review found what it found.
 *
 *     C:\xampp\php\php.exe php\tools\admin-http-test.php
 *
 * Nothing here is asserted against a seeded row. Every fixture is created by this file, prefixed
 * ZZTest, and removed at both ends; where the shop's own data would move a number, the assertion is
 * on the delta rather than the absolute, so the suite does not care what else is in the database.
 *
 * Two of the checks need arranging rather than observing, and are worth knowing about:
 *
 *  - The Accounts series is proved by creating an account on a day with **no** sales. Under the old
 *    drawing its bar was `max(0, revenue - 20)`; a day where revenue is zero and signups are not is
 *    the one fixture that cannot pass by coincidence.
 *  - The missing-table render is proved by renaming `orders` out from under a live request. DB_NAME
 *    is a hard const and getDatabaseConnection() memoises in a function-local static, so there is no
 *    seam to point this at a throwaway database. The rename is reversed inline *and* in a shutdown
 *    function, because a shutdown function runs even on a fatal and a half-renamed schema is not
 *    something to leave behind; the FK on order_items is re-checked afterwards.
 */

// Same guard as the suites beside this one. This file is under the webroot, so without it a stranger
// could run it -- and it creates accounts, deletes products, and briefly renames a table.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BASE = 'http://localhost/Capstone/';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/admin.php';

$database = getDatabaseConnection();

// A notice has to be as loud as a failure: the point is to catch an undefined index the browser
// would swallow.
$notices = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$notices): bool {
    $notices[] = sprintf('%s in %s:%d', $message, basename($file), $line);
    return true;
});
error_reporting(E_ALL);

$failures = 0;
$checks = 0;

/*
 * Assertions this run deliberately did not make, and why.
 *
 * Counted apart from failures on purpose: a skip is neither, and the one thing a summary must never
 * do is let an un-made assertion look like a made one. Printed in full beside the counts.
 */
$skipped = 0;
$skips = [];

function heading(string $text): void
{
    echo "\n--- $text ---\n";
}

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
        echo "  ok    $label\n";
        return;
    }

    $failures++;
    printf("  FAIL  %s\n          expected: %s\n          actual:   %s\n", $label, var_export($expected, true), var_export($actual, true));
}

function checkTrue(string $label, mixed $actual): void
{
    check($label, $actual === true, true);
}

function tell(string $label, mixed $value): void
{
    echo sprintf("  %-46s %s\n", $label, is_scalar($value) || $value === null ? var_export($value, true) : gettype($value));
}

/** Queries run on this connection so far, for counting the dashboard's appetite. */
function queryCount(PDO $database): int
{
    $rows = $database->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetchAll();

    return (int) ($rows[0]['Value'] ?? 0);
}

/* ------------------------------------------------------------------ *
 * Baselines, taken before a single fixture exists.
 * ------------------------------------------------------------------ */

$adminEmail = 'zztest.fix.admin@capstone.test';
$buyerEmail = 'zztest.fix.buyer@capstone.test';
$newbieEmail = 'zztest.fix.newbie@capstone.test';
$password = 'ZzTestFix123!';
$cleanupEmails = "('$adminEmail', '$buyerEmail', '$newbieEmail')";

$days = adminDashboardWeek();
$since = $days[0] . ' 00:00:00';

// Anything a previous run stranded goes before the baseline is read, not after: measure the shop,
// then add fixtures to it.
$stale = $database->query("SELECT id FROM users WHERE email IN $cleanupEmails")->fetchAll(PDO::FETCH_COLUMN);

if ($stale !== []) {
    echo 'clearing ' . count($stale) . " account(s) a previous run left behind\n";
    sweepFixtures($database, implode(', ', array_map('intval', $stale)));
}

$before = adminDashboardStats();
$baseRevenue = (float) $before['weekly_revenue'];
$baseOrders = (int) $before['weekly_orders'];

/*
 * How many custom-bouquet units the shop had sold before this run.
 *
 * Taken as a baseline for the same reason the revenue and order figures above are: the ranking
 * counts the shop's own trading, and the fixture only adds to it. Pinning the absolute is what broke
 * on 2026-10-08 -- a real customer checked out a custom bouquet, the count went from 1 to 2, and a
 * check titled "a custom bouquet is in the ranking" failed while the ranking was working perfectly.
 */
$baseCustomUnits = 0;

foreach (adminTopProducts([], [], 200) as $row) {
    if ((string) $row['name'] === 'Custom bouquet') {
        $baseCustomUnits = (int) $row['units'];
        break;
    }
}

heading('the week the dashboard is looking at');
tell('first day', $days[0]);
tell('last day', $days[6]);
tell('revenue before fixtures', $before['weekly_revenue']);
tell('orders before fixtures', $baseOrders);

/* ------------------------------------------------------------------ *
 * Fixtures.
 * ------------------------------------------------------------------ */

/**
 * Remove every trace of the given accounts, and every ZZTest product, innermost first.
 *
 * Used at both ends: once before the fixtures exist, to clear anything a previous run stranded, and
 * once after. Each statement stands alone and a failure is reported rather than thrown -- a cleanup
 * block that aborts halfway is worse than none, which is exactly how the first run of this file left
 * three users and two products behind.
 *
 * order_item_parts and cart_item_parts are ON DELETE CASCADE from their item tables, so they are not
 * listed: removing the item takes its parts with it.
 *
 * @return string[] the statements that failed, if any
 */
function sweepFixtures(PDO $database, string $ids): array
{
    if (trim($ids) === '') {
        $ids = '0';
    }

    $orders = "(SELECT id FROM orders WHERE user_id IN ($ids))";
    $carts = "(SELECT id FROM carts WHERE user_id IN ($ids))";
    $broken = [];

    foreach ([
        "DELETE FROM order_items WHERE order_id IN $orders",
        "DELETE FROM order_events WHERE order_id IN $orders",
        "DELETE FROM orders WHERE user_id IN ($ids)",
        "DELETE FROM cart_items WHERE cart_id IN $carts",
        "DELETE FROM carts WHERE user_id IN ($ids)",
        "DELETE FROM trusted_devices WHERE user_id IN ($ids)",
        "DELETE FROM email_codes WHERE user_id IN ($ids)",
        "DELETE FROM users WHERE id IN ($ids)",
        "DELETE FROM products WHERE name LIKE 'ZZTest Fix%' OR name LIKE 'ZZTest Doomed%'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            $broken[] = $sql . ' -- ' . explode("\n", $exception->getMessage())[0];
        }
    }

    return $broken;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$makeUser = $database->prepare(
    'INSERT INTO users (name, email, password_hash, role, email_verified_at, created_at)
     VALUES (:name, :email, :hash, :role, NOW(), :created)'
);
$makeUser->execute(['name' => 'ZZTest Fix Admin', 'email' => $adminEmail, 'hash' => $hash, 'role' => 'admin', 'created' => $days[6] . ' 08:00:00']);
$adminId = (int) $database->lastInsertId();
$makeUser->execute(['name' => 'ZZTest Fix Buyer', 'email' => $buyerEmail, 'hash' => $hash, 'role' => 'customer', 'created' => $days[6] . ' 08:00:00']);
$buyerId = (int) $database->lastInsertId();
// Created on a day with no sales at all -- the one fixture that proves the Accounts series is its
// own measurement and not the revenue series shifted down twenty points.
$makeUser->execute(['name' => 'ZZTest Fix Newbie', 'email' => $newbieEmail, 'hash' => $hash, 'role' => 'customer', 'created' => $days[5] . ' 10:00:00']);
$newbieId = (int) $database->lastInsertId();

$makeProduct = $database->prepare(
    'INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES (:name, :description, :price, 7, :colour, 1)'
);
$makeProduct->execute(['name' => 'ZZTest Fix Bouquet', 'description' => 'Fixture.', 'price' => '275.00', 'colour' => 'Test']);
$keptProductId = (int) $database->lastInsertId();
$makeProduct->execute(['name' => 'ZZTest Doomed Bouquet', 'description' => 'Deleted mid-test.', 'price' => '100.00', 'colour' => 'Test']);
$doomedProductId = (int) $database->lastInsertId();

$makeOrder = $database->prepare(
    "INSERT INTO orders
         (reference, user_id, status, payment_status, payment_method, fulfilment,
          contact_name, contact_phone, contact_email, address_line, barangay, city, province, postcode,
          total, placed_at)
     VALUES (:reference, :user, :status, :payment, 'cod', 'delivery',
             'ZZTest Fix Buyer', '0917 555 0100', :email, '9 ZZTest Lane', 'Poblacion', 'Cebu City', 'Cebu', '6000',
             :total, :placed)"
);

$makeItem = $database->prepare(
    'INSERT INTO order_items (order_id, kind, product_id, name, unit_price, quantity, line_total, arrangement)
     VALUES (:order, :kind, :product, :name, :unit, :quantity, :line, :arrangement)'
);

/**
 * @return int the new order's id
 */
$placeOrder = static function (array $order, array $item) use ($makeOrder, $makeItem, $database, $buyerId, $buyerEmail): int {
    $makeOrder->execute([
        'reference' => $order['reference'],
        'user' => $buyerId,
        'status' => $order['status'],
        'payment' => $order['payment'],
        'email' => $buyerEmail,
        'total' => $order['total'],
        'placed' => $order['placed'],
    ]);
    $orderId = (int) $database->lastInsertId();

    $makeItem->execute([
        'order' => $orderId,
        'kind' => $item['kind'],
        'product' => $item['product'],
        'name' => $item['name'],
        'unit' => $item['unit'],
        'quantity' => $item['quantity'],
        'line' => $item['line'],
        'arrangement' => $item['arrangement'] ?? 'dome',
    ]);

    return $orderId;
};

// Four orders. Three count as money, all four count as orders.
$placeOrder(
    ['reference' => 'ZZT-20260101-AAA1', 'status' => 'delivered', 'payment' => 'paid', 'total' => '550.00', 'placed' => $days[2] . ' 12:00:00'],
    ['kind' => 'product', 'product' => $keptProductId, 'name' => 'ZZTest Fix Bouquet', 'unit' => '275.00', 'quantity' => 2, 'line' => '550.00']
);
$placeOrder(
    ['reference' => 'ZZT-20260101-AAA2', 'status' => 'placed', 'payment' => 'unpaid', 'total' => '1000.00', 'placed' => $days[4] . ' 12:00:00'],
    ['kind' => 'design', 'product' => null, 'name' => 'Custom bouquet: 6 flowers, domed', 'unit' => '1000.00', 'quantity' => 1, 'line' => '1000.00']
);
$placeOrder(
    ['reference' => 'ZZT-20260101-AAA3', 'status' => 'placed', 'payment' => 'unpaid', 'total' => '300.00', 'placed' => $days[4] . ' 15:00:00'],
    ['kind' => 'product', 'product' => $doomedProductId, 'name' => 'ZZTest Doomed Bouquet', 'unit' => '100.00', 'quantity' => 3, 'line' => '300.00']
);
// The cancelled one, deliberately enormous: if it leaks into a sum it cannot be missed.
$placeOrder(
    ['reference' => 'ZZT-20260101-AAA4', 'status' => 'cancelled', 'payment' => 'unpaid', 'total' => '9999.00', 'placed' => $days[6] . ' 09:00:00'],
    ['kind' => 'product', 'product' => $keptProductId, 'name' => 'ZZTest Fix Bouquet', 'unit' => '9999.00', 'quantity' => 1, 'line' => '9999.00']
);

echo "\nfixtures: buyer $buyerId, admin $adminId, newbie $newbieId, products $keptProductId/$doomedProductId\n";

/* ------------------------------------------------------------------ *
 * (d) One definition of revenue.
 * ------------------------------------------------------------------ */

// Two readings back to back tell us what the reading itself costs, so the figure below is the
// dashboard's own appetite and not the instrument's.
$calibrateA = queryCount($database);
$calibrateB = queryCount($database);
$overhead = $calibrateB - $calibrateA;

$queriesBefore = queryCount($database);
$after = adminDashboardStats();
$queriesAfter = queryCount($database);

heading('(d) money excludes cancelled orders, counts do not');
tell('weekly revenue now', $after['weekly_revenue']);
tell('weekly orders now', $after['weekly_orders']);

check(
    'revenue rose by the three live orders, not the cancelled one',
    round((float) $after['weekly_revenue'] - $baseRevenue, 2),
    1850.00
);
check('the 9,999 cancelled order is not in the money', ((float) $after['weekly_revenue'] - $baseRevenue) < 9999.0, true);
check('but all four orders are counted as orders', $after['weekly_orders'] - $baseOrders, 4);

$listed = adminFetchCustomers(['search' => 'zztest.fix.buyer'], 5, 0);
check('the customer list found the buyer', count($listed), 1);
check("'Lifetime spent' excludes the cancelled order", (float) ($listed[0]['total_spent'] ?? -1), 1850.00);
check("'Orders' still counts all four", (int) ($listed[0]['order_count'] ?? -1), 4);

/* ------------------------------------------------------------------ *
 * (b) Three series, three measurements.
 * ------------------------------------------------------------------ */

heading('(b) the revenue chart draws three real series');

$series = $after['graph_series'];
check('there are three of them', count($series), 3);
check('named', implode(', ', array_column($series, 'label')), 'Revenue, Orders, Accounts');

foreach ($series as $entry) {
    printf(
        "  %-9s peak %-10s bars %s\n",
        $entry['label'],
        rtrim(rtrim(number_format((float) $entry['peak'], 2, '.', ''), '0'), '.'),
        implode(',', array_map('strval', $entry['bars']))
    );
}
echo '  days      ' . implode(',', $after['graph_labels']) . "\n";

[$revenue, $orders, $accounts] = $series;

/*
 * The old chart was revenue, revenue-10 and revenue-20, so a day with an account and NO sales is
 * the one fixture that cannot pass by coincidence: under the old drawing its Accounts bar was
 * max(0, 0-20) = 0, and under the real one it is the account count normalised against its own peak.
 *
 * THE QUIET DAY IS FOUND, NOT ASSUMED, and that is a fix rather than a flourish. This used to be a
 * hard-coded `$quietDay = 5` -- day six of the window -- which was quiet on the day it was written
 * and stopped being quiet the moment the shop took a real order on that weekday. On 2026-10-08 it
 * failed with ₱677 of genuine revenue on day five, which looks like a broken chart and is a test
 * asserting an absolute where this project's own rule says to assert a delta: *never pin a figure
 * the shop's own trading can move.*
 *
 * So: scan the window for a day that has an account and no revenue. That is the condition the
 * assertion actually needs, and it holds whatever else is in the database. If the shop has traded
 * on every one of the seven days the fixture account could land on, no day qualifies -- which is
 * not a failure, it is a week with no quiet day in it, and it is said out loud rather than failed.
 */
$quietDay = null;

foreach ($after['graph_labels'] as $index => $ignored) {
    if ((float) $revenue['values'][$index] === 0.0 && (float) $accounts['values'][$index] >= 1.0) {
        $quietDay = $index;
        break;
    }
}

if ($quietDay === null) {
    $skipped += 4;
    $skips[] = "SKIP  no day in the seven-day window has an account and zero revenue, so the four\n"
        . "      assertions proving the Accounts series is not derived from Revenue cannot be made.\n"
        . '      That means the shop traded on every day one was created -- not a defect.';
} else {
    printf("  quiet day found at index %d (%s)\n", $quietDay, $after['graph_labels'][$quietDay]);

    check('the fixture day has no revenue', (float) $revenue['values'][$quietDay], 0.0);
    check('  but an account was created on it', (float) $accounts['values'][$quietDay] >= 1.0, true);
    check('  so the Accounts bar is not the Revenue bar minus 20', $accounts['bars'][$quietDay] > 0, true);
    check('  which is what the old chart would have drawn', max(0, $revenue['bars'][$quietDay] - 20), 0);
}

$staircase = true;
foreach ($after['graph_labels'] as $index => $ignored) {
    if ($orders['bars'][$index] !== max(0, $revenue['bars'][$index] - 10)
        || $accounts['bars'][$index] !== max(0, $revenue['bars'][$index] - 20)) {
        $staircase = false;
        break;
    }
}
check('no day is still a 100/90/80 staircase', $staircase, false);

check('each series carries its own peak for the legend', count(array_unique(array_column($series, 'peak'))) > 1, true);
check('the revenue peak is money', $revenue['format'], 'money');
check('the orders peak is a count', $orders['format'], 'count');
check('graph_bars still means the revenue series', $after['graph_bars'], $revenue['bars']);

/* ------------------------------------------------------------------ *
 * Top products: custom bouquets and withdrawn products.
 * ------------------------------------------------------------------ */

heading('top products counts the customizer and survives a deletion');

$ranking = adminTopProducts([], [], 50);
$units = [];
foreach ($ranking as $row) {
    $units[(string) $row['name']] = (int) $row['units'];
}

// On the DELTA, not the absolute. The ranking includes the shop's own sales, so the figure that
// means something is "this run's one custom bouquet turned up", which holds however many the shop
// has sold. The old absolute of 1 failed the first time a real customer bought one.
check('a custom bouquet is in the ranking', ($units['Custom bouquet'] ?? 0) - $baseCustomUnits, 1);
check('the live product shows its two live units', $units['ZZTest Fix Bouquet'] ?? null, 2);
check('  and not the third from the cancelled order', ($units['ZZTest Fix Bouquet'] ?? 0) === 3, false);
check('the doomed product is there while it exists', $units['ZZTest Doomed Bouquet'] ?? null, 3);

$database->exec("DELETE FROM products WHERE id = $doomedProductId");
$orphaned = (int) $database->query("SELECT COUNT(*) FROM order_items WHERE name = 'ZZTest Doomed Bouquet' AND product_id IS NULL")->fetchColumn();
check('deleting the product nulled its order line', $orphaned, 1);

$rankingAfter = [];
foreach (adminTopProducts([], [], 50) as $row) {
    $rankingAfter[(string) $row['name']] = (int) $row['units'];
}
check('its sales survive the product being withdrawn', $rankingAfter['ZZTest Doomed Bouquet'] ?? null, 3);

/* ------------------------------------------------------------------ *
 * (c) The shape a failure degrades to, and how many queries this costs.
 * ------------------------------------------------------------------ */

heading('(c) the fallback the page renders when the query throws');

$empty = adminEmptyDashboardStats();
check('same keys as a real load', array_keys($empty), array_keys($after));
check('donut_stats[0] exists', isset($empty['donut_stats'][0]['value']), true);
check('donut_stats[1] exists', isset($empty['donut_stats'][1]['value']), true);
check('three series', count($empty['graph_series']), 3);
check('seven labels', count($empty['graph_labels']), 7);

$shapeOk = true;
foreach ($empty['graph_series'] as $entry) {
    if (count($entry['bars']) !== 7 || count($entry['values']) !== 7) {
        $shapeOk = false;
    }
}
check('every series has a bar and a value for all seven days', $shapeOk, true);
check('recent_orders is a list, not missing', $empty['recent_orders'], []);
check('top_products is a list, not missing', $empty['top_products'], []);

heading('what one dashboard load costs');
tell('SELECTs for adminDashboardStats()', $queriesAfter - $queriesBefore - $overhead);
check('it is well under the thirty-four the page used to run', ($queriesAfter - $queriesBefore - $overhead) <= 10, true);

/* ------------------------------------------------------------------ *
 * The screens, over Apache, as a real admin.
 * ------------------------------------------------------------------ */

function request(string $path, array &$jar, ?array $post = null): array
{
    $context = ['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'follow_location' => 0,
        'ignore_errors' => true,
        'timeout' => 30,
        'header' => [],
    ]];

    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $context['http']['header'][] = 'Cookie: ' . implode('; ', $pairs);
    }

    if ($post !== null) {
        $context['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $context['http']['content'] = http_build_query($post);
    }

    $context['http']['header'] = implode("\r\n", $context['http']['header']);

    $body = @file_get_contents(BASE . $path, false, stream_context_create($context));
    $headers = $http_response_header ?? [];
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
        // A session id is a capability, so the jar is never printed.
        if (stripos($header, 'Set-Cookie:') === 0
            && preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $header, $m) === 1) {
            if ($m[2] === '') {
                unset($jar[trim($m[1])]);
            } else {
                $jar[trim($m[1])] = $m[2];
            }
        }
    }

    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

$jar = [];
$loginPage = request('php/login.php', $jar)['body'];
preg_match('#name="csrf_token" value="([a-f0-9]+)"#', $loginPage, $m);
$token = $m[1] ?? '';

// Trust this browser so the emailed code is skipped -- the mail log holds live codes for real
// accounts and this test has no business reading it.
$deviceToken = bin2hex(random_bytes(32));
$database->prepare(
    'INSERT INTO trusted_devices (user_id, token_hash, label, expires_at)
     VALUES (:user, :hash, :label, DATE_ADD(NOW(), INTERVAL 1 DAY))'
)->execute(['user' => $adminId, 'hash' => hash('sha256', $deviceToken), 'label' => 'ZZTest admin fix']);
$jar['capstone_device'] = $deviceToken;

request('php/login.php', $jar, [
    'csrf_token' => $token,
    'form_type' => 'login',
    'identifier' => $adminEmail,
    'password' => $password,
]);

heading('the admin screens over Apache');
tell('display_errors', ini_get('display_errors'));

$screens = [
    'dashboard' => 'php/admin/dashboard.php',
    'customers' => 'php/admin/customers.php',
    'customers + role' => 'php/admin/customers.php?role=admin',
    'reports' => 'php/admin/reports.php',
    'reports + dates' => 'php/admin/reports.php?start=' . $days[0] . '&end=' . $days[6],
    'orders' => 'php/admin/orders.php',
    'products' => 'php/admin/products.php',
    'components' => 'php/admin/components.php',
];

$pages = [];
foreach ($screens as $label => $path) {
    $response = request($path, $jar);
    $pages[$label] = $response['body'];

    $complaints = [];
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
        if (str_contains($response['body'], $needle)) {
            $complaints[] = $needle;
        }
    }

    printf("  %-20s %d  %6d bytes  %s\n", $label, $response['status'], strlen($response['body']), $complaints === [] ? 'clean' : 'SAYS: ' . implode(', ', $complaints));
    check("$label answered 200", $response['status'], 200);
    check("$label said nothing PHP was unhappy about", $complaints, []);
}

/* (a) the KPI that counted admins as customers. */
heading('(a) the Customers KPI and the customer list agree');

$shoppers = adminCountCustomers(['role' => 'customer']);
$staff = adminCountCustomers(['role' => 'admin']);
$everyone = adminCountCustomers();

tell('customers', $shoppers);
tell('admins', $staff);
tell('all accounts', $everyone);
check('the two roles really do differ from the total', $everyone > $shoppers, true);

preg_match('#<span>Customers</span>.*?<div class="kpi-value">(\d+)</div>#s', $pages['dashboard'], $kpi);
check('the dashboard KPI shows the shopper count', (int) ($kpi[1] ?? -1), $shoppers);
check('  not every account', (int) ($kpi[1] ?? -1) === $everyone, false);
checkTrue('the staff count is on the card too', str_contains($pages['dashboard'], "Plus $staff staff account"));

preg_match('#<span class="admin-stat-label">Customers</span>\s*<strong>(\d+)</strong>#s', $pages['customers'], $listCard);
check('and the customer list says the same number', (int) ($listCard[1] ?? -1), $shoppers);

/* (b) again, from the rendered page. */
heading('(b) from the rendered page');
checkTrue('the panel no longer claims to be a revenue trend', !str_contains($pages['dashboard'], '<h2>Revenue trend</h2>'));
checkTrue('the legend prints each peak', substr_count($pages['dashboard'], '<strong>peak ') === 3);
checkTrue('every bar carries its real reading', str_contains($pages['dashboard'], 'title="Revenue '));
checkTrue('accounts are read out too', str_contains($pages['dashboard'], 'title="Accounts '));

/* (e) the search form. */
heading('(e) searching from a role tab keeps the tab');
preg_match('#<form class="catalogue-filters".*?</form>#s', $pages['customers + role'], $formMatch);
$form = $formMatch[0] ?? '';
preg_match_all('#name="([^"]+)"#', $form, $fields);
tell("the form's fields", implode(', ', $fields[1] ?: ['(none)']));
checkTrue('it carries the role forward', str_contains($form, 'name="role"'));
checkTrue('  set to the tab being viewed', str_contains($form, 'value="admin"'));

$searched = request('php/admin/customers.php?role=admin&q=zztest', $jar)['body'];
checkTrue('a search inside the Admins tab stays in it', str_contains($searched, 'customer-tab active') || str_contains($searched, 'aria-current="page"'));
checkTrue('  and finds the ZZTest admin', str_contains($searched, 'ZZTest Fix Admin'));
checkTrue('  without showing the ZZTest shopper', !str_contains($searched, 'ZZTest Fix Buyer'));

/* Unfiltered, the form should not invent a role. */
preg_match('#<form class="catalogue-filters".*?</form>#s', $pages['customers'], $plainMatch);
checkTrue('on All accounts it adds no hidden role', !str_contains($plainMatch[0] ?? '', 'name="role"'));

/* ------------------------------------------------------------------ *
 * (c) for real: the dashboard with the orders table out from under it.
 *
 * The rename is reversed in a shutdown function as well as inline, because a shutdown function
 * runs even on a fatal error and a half-renamed schema is not something to leave behind.
 * ------------------------------------------------------------------ */

heading('(c) the dashboard with the orders table missing');

$renamed = false;

try {
    $database->exec('RENAME TABLE orders TO zztest_orders_hidden');
    $renamed = true;
} catch (PDOException $exception) {
    echo '  skipped: the table could not be renamed (' . explode("\n", $exception->getMessage())[0] . ")\n";
    echo "  the fallback's shape is asserted above instead\n";
}

if ($renamed) {
    register_shutdown_function(static function () use ($database): void {
        try {
            $database->exec('RENAME TABLE zztest_orders_hidden TO orders');
        } catch (PDOException $ignored) {
            // Already back.
        }
    });

    $broken = request('php/admin/dashboard.php', $jar);

    $database->exec('RENAME TABLE zztest_orders_hidden TO orders');

    check('the page still answers 200', $broken['status'], 200);
    checkTrue('it is not a fatal', !str_contains($broken['body'], 'Fatal error'));
    checkTrue('  nor an uncaught exception', !str_contains($broken['body'], 'Uncaught'));
    checkTrue('  nor an undefined index', !str_contains($broken['body'], 'Undefined'));
    checkTrue('it says what is wrong', str_contains($broken['body'], 'Unable to load the sales figures'));
    checkTrue('and the cards that still work are still there', str_contains($broken['body'], '<h1>Dashboard</h1>'));
    checkTrue('  including the customer count, which never needed orders', str_contains($broken['body'], 'staff account'));
}

check('the orders table is back', (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'capstone' AND TABLE_NAME = 'orders'")->fetchColumn(), 1);
check('nothing is left renamed', (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'capstone' AND TABLE_NAME = 'zztest_orders_hidden'")->fetchColumn(), 0);
check('order_items still points at it', (string) $database->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'capstone' AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'order_id'")->fetchColumn(), 'orders');

/* ------------------------------------------------------------------ *
 * Cleanup.
 * ------------------------------------------------------------------ */

heading('cleanup');

foreach (sweepFixtures($database, "$buyerId, $adminId, $newbieId") as $broken) {
    $failures++;
    echo "  FAIL  $broken\n";
}

check('no ZZTest users left', (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zztest%'")->fetchColumn(), 0);
check('no ZZTest products left', (int) $database->query("SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest%'")->fetchColumn(), 0);
check('no ZZTest orders left', (int) $database->query("SELECT COUNT(*) FROM orders WHERE reference LIKE 'ZZT-%'")->fetchColumn(), 0);
tell('real users still here', (int) $database->query("SELECT COUNT(*) FROM users WHERE email NOT LIKE 'zztest%'")->fetchColumn());
tell('real products still here', (int) $database->query("SELECT COUNT(*) FROM products WHERE name NOT LIKE 'ZZTest%' AND name NOT LIKE 'ZZShot%'")->fetchColumn());
tell('real orders still here', (int) $database->query("SELECT COUNT(*) FROM orders")->fetchColumn());

$restored = adminDashboardStats();
check('the week is back to where it started', $restored['weekly_orders'], $baseOrders);
check('  and so is the money', round((float) $restored['weekly_revenue'], 2), round($baseRevenue, 2));

heading('anything PHP complained about');
if ($notices === []) {
    echo "  nothing\n";
} else {
    foreach (array_unique($notices) as $notice) {
        echo "  $notice\n";
    }
    $failures += count(array_unique($notices));
}

echo implode("\n", $skips) . ($skips === [] ? '' : "\n");
printf("\nadmin fixes: %d checks, %d failed, %d skipped\n", $checks, $failures, $skipped);
exit($failures === 0 ? 0 : 1);
