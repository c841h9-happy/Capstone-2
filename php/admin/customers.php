<?php
// Admin pages start with the guard, before any output. See guard.php.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/admin.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';

$status = adminCustomerRoleFilter($_GET['role'] ?? null);
$search = adminCustomerSearch($_GET['q'] ?? null);
$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

function adminCustomersUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/customers.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

$filterParams = [
    'role' => $status === 'all' ? '' : $status,
    'q' => $search,
];

$customers = [];
$total = 0;
$grandTotal = 0;
$lastPage = 1;
$loadFailed = false;
$customerTotal = 0;
$adminTotal = 0;

try {
    $total = adminCountCustomers(['role' => $status, 'search' => $search]);
    $grandTotal = $status !== 'all' || $search !== '' ? adminCountCustomers() : $total;
    $customerTotal = adminCountCustomers(['role' => 'customer']);
    $adminTotal = adminCountCustomers(['role' => 'admin']);
    $lastPage = max(1, (int) ceil($total / ADMIN_CUSTOMERS_PER_PAGE));
    $page = min($page, $lastPage);

    $customers = adminFetchCustomers(
        ['role' => $status, 'search' => $search],
        ADMIN_CUSTOMERS_PER_PAGE,
        ($page - 1) * ADMIN_CUSTOMERS_PER_PAGE
    );
} catch (PDOException $exception) {
    error_log('admin/customers.php: ' . $exception->getMessage());
    $loadFailed = true;
}

renderHeader('Customers');
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Customers</h1>
            <p class="page-intro">
                People who have signed in and placed orders, with the admin's view of their account history.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/admin/customers.php')); ?>" data-catalogue-filters>
            <?php
            /*
             * The tab has to travel with the search.
             *
             * The tabs below already carry the search term into each role, but the form carried
             * nothing back: searching from Admins dropped the role and dumped the visitor on All
             * accounts with results they had not asked for. There is no field for page, which is
             * right -- a new search belongs on page one.
             */
            ?>
            <?php if ($status !== 'all'): ?>
                <input type="hidden" name="role" value="<?php echo escape($status); ?>">
            <?php endif; ?>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>" maxlength="100" placeholder="name or email">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo $total; ?> account<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>matching the current filters<?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$loadFailed): ?>
            <div class="admin-stat-grid admin-stat-grid--customers">
                <section class="admin-stat-card">
                    <span class="admin-stat-label">All accounts</span>
                    <strong><?php echo $grandTotal; ?></strong>
                    <span class="admin-stat-note">Registered profiles</span>
                </section>
                <section class="admin-stat-card admin-stat-card--mint">
                    <span class="admin-stat-label">Customers</span>
                    <strong><?php echo $customerTotal; ?></strong>
                    <span class="admin-stat-note">Shopper accounts</span>
                </section>
                <section class="admin-stat-card admin-stat-card--peach">
                    <span class="admin-stat-label">Admins</span>
                    <strong><?php echo $adminTotal; ?></strong>
                    <span class="admin-stat-note">Staff access accounts</span>
                </section>
            </div>

            <nav class="customer-tabs" aria-label="Account type">
                <a class="customer-tab<?php echo $status === 'all' ? ' active' : ''; ?>" href="<?php echo escape(adminCustomersUrl(['role' => '', 'q' => $search])); ?>"<?php echo $status === 'all' ? ' aria-current="page"' : ''; ?>>
                    <span>All accounts</span><strong><?php echo $grandTotal; ?></strong>
                </a>
                <a class="customer-tab<?php echo $status === 'customer' ? ' active' : ''; ?>" href="<?php echo escape(adminCustomersUrl(['role' => 'customer', 'q' => $search])); ?>"<?php echo $status === 'customer' ? ' aria-current="page"' : ''; ?>>
                    <span>Customers</span><strong><?php echo $customerTotal; ?></strong>
                </a>
                <a class="customer-tab<?php echo $status === 'admin' ? ' active' : ''; ?>" href="<?php echo escape(adminCustomersUrl(['role' => 'admin', 'q' => $search])); ?>"<?php echo $status === 'admin' ? ' aria-current="page"' : ''; ?>>
                    <span>Admins</span><strong><?php echo $adminTotal; ?></strong>
                </a>
            </nav>
        <?php endif; ?>

        <?php if ($loadFailed): ?>
            <div class="card card-error">
                <h2>Unable to load customers</h2>
                <p>Check the database connection and confirm the project schema has been run.</p>
            </div>
        <?php elseif ($customers === []): ?>
            <div class="card">
                <h2>No customers found</h2>
                <p>Nothing matches the current filters yet.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table-customers">
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col">Role</th>
                            <th scope="col">Joined</th>
                            <th scope="col">Orders</th>
                            <th scope="col">Lifetime spent<span class="admin-cell-sub">excludes cancelled</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <th scope="row" class="admin-cell-name">
                                    <?php echo escape((string) $customer['name']); ?>
                                    <span class="admin-cell-sub"><?php echo escape((string) $customer['email']); ?></span>
                                </th>
                                <td><span class="admin-badge admin-badge--<?php echo escape((string) $customer['role']); ?>"><?php echo escape(ucfirst((string) $customer['role'])); ?></span></td>
                                <td><?php echo escape(date('j M Y', strtotime((string) $customer['created_at']))); ?></td>
                                <td><?php echo (int) $customer['order_count']; ?></td>
                                <td><?php echo escape(formatPrice((string) $customer['total_spent'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!$loadFailed && $lastPage > 1): ?>
            <nav class="pagination" aria-label="Customer pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminCustomersUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>

                <span>Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminCustomersUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
