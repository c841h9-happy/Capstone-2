<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to
// run before the redirects and the 302 below, which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/products-admin.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * The catalogue, from the other side.
 *
 * Until this page existed, adding a bouquet meant writing an INSERT by hand in the
 * MySQL shell and taking one off sale meant an UPDATE. It lists everything -- hidden
 * products included, which is the one thing config/products.php cannot do -- and owns
 * the three actions that do not need a form of their own: hide, show and delete.
 *
 * Hiding is the reversible one and the one to reach for. A real DELETE is offered only
 * on a product that is already hidden, so no single mis-click can destroy something
 * that is on sale, and no single forged cross-site request can either. Editing is on
 * php/admin/product-edit.php.
 */

$status = adminProductStatus($_GET['status'] ?? null);
$kind = productKindFilter($_GET['kind'] ?? null);
$sort = productSortKey($_GET['sort'] ?? null);

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
 * /Capstone/php/admin/products.php with nothing after it.
 */
function adminProductsUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/products.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * Everything on the page has to carry the filters that are already on. It matters more
 * here than on the public catalogue: hiding the third row of a filtered page 2 should
 * leave the admin looking at that same page, not back at the top of an unfiltered list
 * hunting for where they were.
 */
$filterParams = [
    'status' => $status === 'all' ? '' : $status,
    'kind' => $kind,
    'sort' => $sort === PRODUCT_SORT_DEFAULT ? '' : $sort,
    'q' => $search,
];

$listUrl = adminProductsUrl($filterParams + ['page' => $page]);

/*
 * Hide, show and delete.
 *
 * Answered with a redirect and a flashed message rather than by rendering the list
 * inline -- a POST left as the current request means a refresh offers to send it again,
 * and "delete" is not a thing to offer twice. That includes the failures: a refused
 * delete is not a form to correct, so there is nothing to keep on screen.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = productInputText($_POST, 'action');
    $targetId = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;

    try {
        // Read first, for the name. A message that says which product it was is worth
        // one extra query, and it is the only way to tell "hidden" from "never there".
        $target = adminFetchProduct($targetId);

        if ($target === null) {
            flashToast('error', 'That product is no longer there. The list below is up to date.');
        } elseif ($action === 'hide') {
            setProductActive($targetId, false);
            flashToast('success', '"' . $target['name'] . '" is hidden. It is off the catalogue until you show it again.');
        } elseif ($action === 'show') {
            setProductActive($targetId, true);
            flashToast('success', '"' . $target['name'] . '" is back on the catalogue.');
        } elseif ($action === 'delete') {
            if ((int) $target['is_active'] === 1) {
                // The button is not drawn for a visible product, so getting here means
                // a stale page or a request made by hand. Either way the answer is the
                // same, and it says what to do instead.
                flashToast('error', 'Hide "' . $target['name'] . '" first. Only a hidden product can be deleted.');
            } elseif (deleteProduct($targetId, (string) $target['image_path'])) {
                flashToast('success', '"' . $target['name'] . '" is deleted for good.');
            } else {
                // deleteProduct() refuses a row that went back on sale between the read
                // above and the statement itself.
                flashToast('error', 'Unable to delete "' . $target['name'] . '". Check whether it is still hidden.');
            }
        } else {
            flashToast('error', 'That action is not something this page can do.');
        }
    } catch (PDOException $exception) {
        error_log('admin/products.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change right now. Check your database connection.');
    }

    redirectTo($listUrl);
}

$filters = ['status' => $status, 'kind' => $kind, 'search' => $search, 'sort' => $sort];

// Sort is not in this: it changes the order, not which rows are in the list.
$isFiltered = $status !== 'all' || $kind !== '' || $search !== '';

$products = [];
$total = 0;
$grandTotal = 0;
$lastPage = 1;
$loadFailed = false;

try {
    $total = adminCountProducts($filters);

    // Separate from $total so the empty state can tell "nothing matches these filters"
    // from "there are no products at all" -- two very different things to be told, and
    // only the second of them should offer to add the first product.
    $grandTotal = $isFiltered ? adminCountProducts() : $total;

    $lastPage = max(1, (int) ceil($total / ADMIN_PRODUCTS_PER_PAGE));
    $page = min($page, $lastPage);

    $products = adminFetchProducts($filters, ADMIN_PRODUCTS_PER_PAGE, ($page - 1) * ADMIN_PRODUCTS_PER_PAGE);
} catch (PDOException $exception) {
    error_log('admin/products.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Messages left by the redirect that got here.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the catalogue right now. Check your database connection, and that sql\products-upgrade.sql has been run.',
    ];
}

renderHeader('Products');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Products</h1>
            <p class="page-intro">
                Everything the shop sells, on sale or not.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <!--
            The same filter row the public catalogue uses, with a status dropdown on the
            front. It is a real GET form with a real Apply button; script.js makes the
            dropdowns submit on change. No hidden page field, so changing a filter starts
            again at page 1 rather than landing on a page that no longer exists.
        -->
        <form class="catalogue-filters admin-products-filters" method="get" action="<?php echo escape(url('php/admin/products.php')); ?>" data-catalogue-filters>
            <div class="filter-field">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status" data-filter-select>
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Visible only</option>
                    <option value="hidden" <?php echo $status === 'hidden' ? 'selected' : ''; ?>>Hidden only</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-kind">Kind</label>
                <select id="filter-kind" name="kind" data-filter-select>
                    <option value="" <?php echo $kind === '' ? 'selected' : ''; ?>>Everything</option>
                    <option value="bouquet" <?php echo $kind === 'bouquet' ? 'selected' : ''; ?>>Bouquets</option>
                    <option value="flower" <?php echo $kind === 'flower' ? 'selected' : ''; ?>>Single flowers</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-sort">Sort by</label>
                <select id="filter-sort" name="sort" data-filter-select>
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="price-low" <?php echo $sort === 'price-low' ? 'selected' : ''; ?>>Price: low to high</option>
                    <option value="price-high" <?php echo $sort === 'price-high' ? 'selected' : ''; ?>>Price: high to low</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name: A to Z</option>
                </select>
            </div>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>"
                       maxlength="100" placeholder="rose, tulip, sunflower&hellip;">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo $total; ?> product<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>
                        of <?php echo $grandTotal; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <a class="btn btn-primary" href="<?php echo escape(url('php/admin/product-edit.php')); ?>">Add a product</a>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the catalogue</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php elseif ($products === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>There are no products yet</h2>
                    <p>Add the first one and it appears on the catalogue straight away.</p>
                    <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/product-edit.php')); ?>">Add a product</a></p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/products.php')); ?>">Show everything</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. Nine columns do not fit
                a phone at any readable size, and a table that widens the document makes
                the whole page slide sideways -- including the navbar. Inside its own
                box, only the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="visually-hidden">Photo</span></th>
                            <th scope="col">Name</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Price</th>
                            <th scope="col">Colour</th>
                            <th scope="col">Flowers</th>
                            <th scope="col">Status</th>
                            <th scope="col">Added</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $productId = (int) $product['id'];
                            $isActive = (int) $product['is_active'] === 1;
                            $stemCount = $product['stem_count'] === null ? null : (int) $product['stem_count'];
                            $colour = trim((string) $product['colour']);
                            $thumbUrl = productImageUrl($product);

                            // A malformed date should not take the page down with it.
                            $addedAt = strtotime((string) $product['created_at']);
                            $added = $addedAt === false ? '' : date('j M Y', $addedAt);
                            ?>
                            <tr>
                                <td class="admin-cell-thumb">
                                    <?php if ($thumbUrl !== null): ?>
                                        <!-- Decorative: the name is the next cell along, so
                                             alt text here would only repeat it. -->
                                        <img src="<?php echo escape($thumbUrl); ?>" alt="" width="48" height="48" loading="lazy">
                                    <?php else: ?>
                                        <!-- Worth a word rather than an empty cell: which
                                             products still need photographing is the main
                                             thing this column is here to answer. -->
                                        <span class="admin-cell-blank">No photo</span>
                                    <?php endif; ?>
                                </td>
                                <th scope="row" class="admin-cell-name">
                                    <?php if ($isActive): ?>
                                        <!-- Only worth linking while it is on sale: the
                                             public page answers 404 for a hidden row. -->
                                        <a href="<?php echo escape(url('php/product.php?id=' . $productId)); ?>"><?php echo escape((string) $product['name']); ?></a>
                                    <?php else: ?>
                                        <?php echo escape((string) $product['name']); ?>
                                    <?php endif; ?>
                                </th>
                                <td><?php echo escape(productKindLabel((string) $product['kind'])); ?></td>
                                <td class="admin-cell-number"><?php echo escape(formatPrice((string) $product['price'])); ?></td>
                                <td><?php echo $colour === '' ? '<span class="admin-cell-blank">&mdash;</span>' : escape($colour); ?></td>
                                <td class="admin-cell-number">
                                    <?php echo $stemCount === null ? '<span class="admin-cell-blank">&mdash;</span>' : $stemCount; ?>
                                </td>
                                <td>
                                    <span class="pill <?php echo $isActive ? 'pill-active' : 'pill-hidden'; ?>">
                                        <?php echo escape(productStatusLabel((int) $product['is_active'])); ?>
                                    </span>
                                </td>
                                <td class="admin-cell-date"><?php echo escape($added); ?></td>
                                <td>
                                    <!--
                                        One form per row with a button per action, rather
                                        than a form per button: a submit button carries
                                        its own name and value, so which one was pressed
                                        arrives in $_POST['action'] on its own.
                                    -->
                                    <form class="admin-row-actions" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $productId; ?>">

                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/product-edit.php?id=' . $productId)); ?>">Edit</a>

                                        <!--
                                            What one of these is built from, and how many the
                                            shop could make right now. A link and not a column,
                                            because a recipe is five parts and a number each --
                                            there is no sensible way to put that in a cell, and
                                            this table already has nine.
                                        -->
                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/product-materials.php?id=' . $productId)); ?>">Materials</a>
                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/product-parts.php?id=' . $productId)); ?>">Customizer parts</a>

                                        <?php if ($isActive): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="hide">Hide</button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="show">Show</button>

                                            <!--
                                                Only on a hidden row. data-confirm is a
                                                second pair of eyes where JavaScript is
                                                running; having to hide the product first
                                                is what protects it where it is not.
                                            -->
                                            <button type="submit" class="btn btn-danger btn-small" name="action" value="delete"
                                                    data-confirm="Delete &quot;<?php echo escape((string) $product['name']); ?>&quot; for good? This cannot be undone.">Delete</button>
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
            <nav class="pager" aria-label="Product list pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminProductsUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Previous</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminProductsUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
