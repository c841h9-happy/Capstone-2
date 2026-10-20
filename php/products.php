<?php
// Must run before any HTML so the navbar can tell whether you are signed in.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/products.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/product-card.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

/*
 * The catalogue.
 *
 * Deliberately public. Browsing is how somebody decides whether to make an account,
 * so putting requireLogin() here would hide the shop from everybody who has not
 * already committed to it.
 *
 * Every one of the four inputs below arrives from the query string and is treated as
 * hostile: kind and sort are reduced to a known value by the helpers in
 * config/products.php, the search term becomes a bound LIKE parameter, and the page
 * number is clamped. A bad value gives the default view rather than an error page --
 * this is the URL people share and edit by hand.
 */
$kind = productKindFilter($_GET['kind'] ?? null);
$sort = productSortKey($_GET['sort'] ?? null);

// is_string first: ?q[]=x makes $_GET['q'] an array, and casting that to string is
// both a warning and the literal text "Array" going into the query.
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

// Longer than this is not a search, and the column is only 120 characters anyway.
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

$filters = ['kind' => $kind, 'search' => $search, 'sort' => $sort];

$products = [];
$total = 0;
$lastPage = 1;
$errors = [];

try {
    $total = countProducts($filters);
    $lastPage = max(1, (int) ceil($total / PRODUCTS_PER_PAGE));

    // Asking for page 9 of 3 should show page 3, not an empty grid that looks like
    // the catalogue is broken.
    $page = min($page, $lastPage);

    $products = fetchProducts($filters, PRODUCTS_PER_PAGE, ($page - 1) * PRODUCTS_PER_PAGE);
} catch (PDOException $exception) {
    error_log('products.php: ' . $exception->getMessage());
    $errors[] = 'Unable to load the catalogue right now. Check your database connection, and that sql\products-upgrade.sql has been run.';
}

/**
 * A link to this page with some of the filters changed.
 *
 * Every link on the page has to carry the filters that are already on: a Next button
 * that dropped the search term would silently take you to page 2 of something else.
 * Empty values are left out so the common URL stays /Capstone/php/products.php.
 */
function catalogueUrl(array $params): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/products.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

$toasts = [];

foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

renderHeader('Products');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Handmade to order</p>
            <h1>Our flowers</h1>
            <p class="page-intro">
                Every stem is shaped by hand from fuzzy wire, so nothing here wilts.
                Buy a bouquet as it comes, or single flowers to arrange yourself.
            </p>
        </header>

        <!--
            A real GET form with a real submit button. script.js makes the two selects
            submit on change, but with no JavaScript the button is what applies the
            filters -- and there is no hidden page field, so changing a filter always
            starts again at page 1 instead of landing on a page that no longer exists.
        -->
        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/products.php')); ?>" data-catalogue-filters>
            <div class="filter-field">
                <label for="filter-kind">Show</label>
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

        <?php if ($errors === []): ?>
            <p class="catalogue-count">
                <?php if ($search === ''): ?>
                    <?php echo $total; ?> product<?php echo $total === 1 ? '' : 's'; ?>
                <?php else: ?>
                    <?php echo $total; ?> product<?php echo $total === 1 ? '' : 's'; ?>
                    matching &ldquo;<?php echo escape($search); ?>&rdquo;
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($products === [] && $errors === []): ?>
            <div class="empty-state">
                <h2>Nothing matches that</h2>
                <p>Try a different word, or a wider filter.</p>
                <p><a class="btn btn-quiet" href="<?php echo escape(url('php/products.php')); ?>">Show everything</a></p>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <?php renderProductCard($product); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($lastPage > 1): ?>
            <nav class="pager" aria-label="Catalogue pages">
                <?php if ($page > 1): ?>
                    <a class="btn pager-arrow" href="<?php echo escape(catalogueUrl(['kind' => $kind, 'sort' => $sort === PRODUCT_SORT_DEFAULT ? '' : $sort, 'q' => $search, 'page' => $page - 1])); ?>" aria-label="Previous page">&lt;</a>
                <?php else: ?>
                    <!-- Present but inert, so the row does not jump sideways between pages. -->
                    <span class="btn pager-arrow is-disabled" aria-hidden="true">&lt;</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn pager-arrow" href="<?php echo escape(catalogueUrl(['kind' => $kind, 'sort' => $sort === PRODUCT_SORT_DEFAULT ? '' : $sort, 'q' => $search, 'page' => $page + 1])); ?>" aria-label="Next page">&gt;</a>
                <?php else: ?>
                    <span class="btn pager-arrow is-disabled" aria-hidden="true">&gt;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
