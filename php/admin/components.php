<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to
// run before the redirects and the 302 below, which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/components-admin.php';
// For productsUsingComponent() and recipeCountsForComponents(): product_parts cascades from
// product_components, so deleting a part now silently guts every recipe naming it. This page
// is the only place that deletes one.
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * The customizer's parts list.
 *
 * A component is what a bouquet is made *of* -- a bloom, the wire it sits on, the paper
 * around it, a ribbon -- and the 3D builder assembles a design out of these and prices it
 * by adding them up. They are not products and they are not in `products`; see
 * sql/components-upgrade.sql for that argument in full.
 *
 * This is the same screen as php/admin/products.php with three differences worth naming.
 * There is no public page to link a name to, because the builder that would show one is
 * not written yet. There is a colour the builder paints with, which is the one column
 * that exists purely for it. And the words are different: withdrawing a part takes it out
 * of the picker rather than off a shop front, so the pill says "Withdrawn" rather than
 * "Hidden".
 *
 * Withdrawing is the reversible action and the one to reach for. A real DELETE is offered
 * only on a part that is already withdrawn, so no single mis-click can destroy something
 * a saved design may be built from, and no single forged cross-site request can either.
 * Editing is on php/admin/component-edit.php.
 */

$status = componentStatus($_GET['status'] ?? null);
$kind = componentKindFilter($_GET['kind'] ?? null);
$sort = componentSortKey($_GET['sort'] ?? null);

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
 * /Capstone/php/admin/components.php with nothing after it.
 */
function adminComponentsUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/components.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * Everything on the page has to carry the filters that are already on, so that
 * withdrawing the third row of a filtered page 2 leaves the admin looking at that same
 * page rather than back at the top of an unfiltered list hunting for where they were.
 */
$filterParams = [
    'status' => $status === 'all' ? '' : $status,
    'kind' => $kind,
    'sort' => $sort === COMPONENT_SORT_DEFAULT ? '' : $sort,
    'q' => $search,
];

$listUrl = adminComponentsUrl($filterParams + ['page' => $page]);

/*
 * Withdraw, put back and delete.
 *
 * Answered with a redirect and a flashed message rather than by rendering the list inline
 * -- a POST left as the current request means a refresh offers to send it again, and
 * "delete" is not a thing to offer twice. That includes the failures: a refused delete is
 * not a form to correct, so there is nothing to keep on screen.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = productInputText($_POST, 'action');
    $targetId = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;

    try {
        // Read first, for the name. A message that says which part it was is worth one
        // extra query, and it is the only way to tell "withdrawn" from "never there".
        $target = adminFetchComponent($targetId);

        if ($target === null) {
            flashToast('error', 'That part is no longer there. The list below is up to date.');
        } elseif ($action === 'withdraw') {
            setComponentActive($targetId, false);
            flashToast('success', '"' . $target['name'] . '" is withdrawn. The customizer will not offer it until you put it back.');
        } elseif ($action === 'offer') {
            setComponentActive($targetId, true);
            flashToast('success', '"' . $target['name'] . '" is back in the picker.');
        } elseif ($action === 'delete') {
            /*
             * What breaks if this part goes away.
             *
             * product_parts.component_id is NOT NULL and ON DELETE CASCADE -- a recipe is an
             * instruction, not a receipt, and one naming a part the shop no longer has is an
             * instruction nobody can follow. Right, and the reason sql/inventory-upgrade.sql
             * gives at length. It also means deleting one rose head quietly takes a line out
             * of every bouquet built from it, with no undo and nothing on screen to say so.
             *
             * So this refuses rather than warns. A warning is a sentence about something that
             * has already happened; the recipes have to be edited first, which takes a minute
             * and leaves the shop knowing which bouquets changed. A part that is never going
             * to be deleted can simply stay withdrawn, which costs nothing.
             *
             * The button is not drawn for a part that is in a recipe, so getting here means a
             * stale page or a request made by hand -- the same position as the withdraw-first
             * check below it, and answered the same way.
             */
            $usedIn = productsUsingComponent($targetId);

            if ($usedIn !== []) {
                $names = [];

                foreach ($usedIn as $product) {
                    $names[] = '"' . $product['name'] . '"';
                }

                flashToast(
                    'error',
                    'Cannot delete "' . $target['name'] . '": it is still part of '
                        . implode(', ', $names) . '. Take it out of '
                        . (count($names) === 1 ? 'that recipe' : 'those recipes') . ' first.'
                );
            } elseif ((int) $target['is_active'] === 1) {
                // The button is not drawn for a part that is still offered, so getting
                // here means a stale page or a request made by hand. Either way the
                // answer is the same, and it says what to do instead.
                flashToast('error', 'Withdraw "' . $target['name'] . '" first. Only a withdrawn part can be deleted.');
            } elseif (deleteComponent($targetId, (string) $target['image_path'])) {
                flashToast('success', '"' . $target['name'] . '" is deleted for good.');
            } else {
                // deleteComponent() refuses a row that went back into the picker between
                // the read above and the statement itself.
                flashToast('error', 'Unable to delete "' . $target['name'] . '". Check whether it is still withdrawn.');
            }
        } else {
            flashToast('error', 'That action is not something this page can do.');
        }
    } catch (PDOException $exception) {
        error_log('admin/components.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change right now. Check your database connection.');
    }

    redirectTo($listUrl);
}

$filters = ['status' => $status, 'kind' => $kind, 'search' => $search, 'sort' => $sort];

// Sort is not in this: it changes the order, not which rows are in the list.
$isFiltered = $status !== 'all' || $kind !== '' || $search !== '';

$components = [];
$recipeCounts = [];
$total = 0;
$grandTotal = 0;
$lastPage = 1;
$loadFailed = false;

try {
    $total = adminCountComponents($filters);

    // Separate from $total so the empty state can tell "nothing matches these filters"
    // from "there are no parts at all" -- two very different things to be told, and only
    // the second of them should offer to add the first part.
    $grandTotal = $isFiltered ? adminCountComponents() : $total;

    $lastPage = max(1, (int) ceil($total / ADMIN_COMPONENTS_PER_PAGE));
    $page = min($page, $lastPage);

    $components = adminFetchComponents($filters, ADMIN_COMPONENTS_PER_PAGE, ($page - 1) * ADMIN_COMPONENTS_PER_PAGE);

    // One query for the page, to decide which rows may be offered a Delete button. See the
    // delete branch above: a part still in a recipe must not be deletable in one press.
    $recipeCounts = recipeCountsForComponents(array_column($components, 'id'));
} catch (PDOException $exception) {
    error_log('admin/components.php: ' . $exception->getMessage());
    $loadFailed = true;
}

// Messages left by the redirect that got here.
$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load the parts list right now. Check your database connection, and that sql\components-upgrade.sql has been run.',
    ];
}

renderHeader('Components');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Components</h1>
            <p class="page-intro">
                The parts a custom bouquet is built from, and what each one adds to the price.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <!--
            The same filter row the catalogue and the product list use. It is a real GET
            form with a real Apply button; script.js makes the dropdowns submit on change.
            No hidden page field, so changing a filter starts again at page 1 rather than
            landing on a page that no longer exists.
        -->
        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/admin/components.php')); ?>" data-catalogue-filters>
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
                        <!-- Built from the constant rather than typed out, so a fifth kind
                             added to the ENUM turns up in this filter on its own. -->
                        <option value="<?php echo escape($kindOption); ?>" <?php echo $kind === $kindOption ? 'selected' : ''; ?>>
                            <?php echo escape(componentKindLabel($kindOption)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-sort">Sort by</label>
                <select id="filter-sort" name="sort" data-filter-select>
                    <option value="order" <?php echo $sort === 'order' ? 'selected' : ''; ?>>Picker order</option>
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="price-low" <?php echo $sort === 'price-low' ? 'selected' : ''; ?>>Price: low to high</option>
                    <option value="price-high" <?php echo $sort === 'price-high' ? 'selected' : ''; ?>>Price: high to low</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name: A to Z</option>
                </select>
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
            <?php endif; ?>

            <a class="btn btn-primary" href="<?php echo escape(url('php/admin/component-edit.php')); ?>">Add a part</a>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the parts list</h2>
                <p>Nothing has changed. Try again in a moment.</p>
            </div>
        <?php elseif ($components === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>There are no parts yet</h2>
                    <p>Add the first one and the customizer has something to build with.</p>
                    <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/component-edit.php')); ?>">Add a part</a></p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/components.php')); ?>">Show everything</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. Nine columns do not fit a
                phone at any readable size, and a table that widens the document makes the
                whole page slide sideways -- including the navbar. Inside its own box, only
                the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="visually-hidden">Photo</span></th>
                            <th scope="col">Name</th>
                            <th scope="col">Part</th>
                            <th scope="col">Price</th>
                            <th scope="col">Colour</th>
                            <th scope="col">Position</th>
                            <th scope="col">Status</th>
                            <th scope="col">Added</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($components as $component): ?>
                            <?php
                            $componentId = (int) $component['id'];
                            $isActive = (int) $component['is_active'] === 1;
                            $colour = trim((string) $component['colour']);

                            // Re-validated on the way out, because this value goes inside
                            // a style attribute. See componentColourHex().
                            $hex = componentColourHex($component);
                            $thumbUrl = componentImageUrl($component);

                            // Through the helper rather than straight off the row, so a
                            // non-bloom carrying a stale shape -- possible only if the row
                            // was edited outside the form -- shows nothing here, which is
                            // what the builder will draw for it.
                            $shape = componentShape($component);

                            // A malformed date should not take the page down with it.
                            $addedAt = strtotime((string) $component['created_at']);
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
                                             parts still need photographing is the main
                                             thing this column is here to answer. -->
                                        <span class="admin-cell-blank">No photo</span>
                                    <?php endif; ?>
                                </td>
                                <!--
                                    A row header, not a cell, and not a link either: there
                                    is no public page for a component. The 3D builder that
                                    will show one is the next module.
                                -->
                                <th scope="row" class="admin-cell-name"><?php echo escape((string) $component['name']); ?></th>
                                <td class="admin-cell-kind">
                                    <?php echo escape(componentKindLabel((string) $component['kind'])); ?>
                                    <?php
                                    /*
                                     * A second line in this cell rather than a tenth column:
                                     * nine already need a scroll box to fit a phone, and a
                                     * shape is a fact about what sort of part this is --
                                     * which is what this column says.
                                     *
                                     * Only for a flower head. The other three kinds all hold
                                     * 'none', and a line saying so on ten rows would be a
                                     * column of noise.
                                     *
                                     * The bare key rather than componentShapeLabel(): those
                                     * labels are written long on purpose, because the edit
                                     * form's select needs "Sunflower -- wide, dark centre" to
                                     * be choosable. A sentence in a table cell is what put
                                     * nine columns in a scroll box in the first place.
                                     *
                                     * A PHP comment, not an HTML one. This sits inside the
                                     * row loop, so an HTML comment here would be sent to the
                                     * browser once per part.
                                     */
                                    if ($shape !== 'none'):
                                    ?>
                                        <span class="admin-cell-shape"><?php echo escape(ucfirst($shape)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-cell-number"><?php echo escape(formatPrice((string) $component['price'])); ?></td>
                                <td>
                                    <!--
                                        The swatch is what makes this column worth reading:
                                        "Sage green" and "Ivory" are guesses until you see
                                        them, and the hex is what the builder actually
                                        paints with. Both, because the words are what an
                                        admin searches for and the hex is what goes wrong.
                                    -->
                                    <span class="colour-cell">
                                        <span class="colour-swatch" style="background: <?php echo escape($hex); ?>"></span>
                                        <span class="colour-cell-text">
                                            <?php echo $colour === '' ? '<span class="admin-cell-blank">&mdash;</span>' : escape($colour); ?>
                                            <span class="colour-cell-hex"><?php echo escape($hex); ?></span>
                                        </span>
                                    </span>
                                </td>
                                <td class="admin-cell-number"><?php echo (int) $component['sort_order']; ?></td>
                                <td>
                                    <span class="pill <?php echo $isActive ? 'pill-active' : 'pill-hidden'; ?>">
                                        <?php echo escape(componentStatusLabel((int) $component['is_active'])); ?>
                                    </span>
                                </td>
                                <td class="admin-cell-date"><?php echo escape($added); ?></td>
                                <td>
                                    <!--
                                        One form per row with a button per action, rather
                                        than a form per button: a submit button carries its
                                        own name and value, so which one was pressed
                                        arrives in $_POST['action'] on its own.
                                    -->
                                    <form class="admin-row-actions" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $componentId; ?>">

                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/component-edit.php?id=' . $componentId)); ?>">Edit</a>

                                        <!--
                                            How many of it the shop has, and the form for
                                            receiving more.

                                            By name and not by id, because the stock screen is
                                            a filtered list rather than a page per part -- one
                                            row filtered to this name is the closest thing it
                                            has to "this part's stock", and the name is unique
                                            (componentNameExists() sees to that). A name over
                                            100 characters arrives truncated, which still finds
                                            it: the search is a LIKE on a substring.
                                        -->
                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/inventory.php?q=' . urlencode((string) $component['name']))); ?>">Stock</a>

                                        <?php if ($isActive): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="withdraw">Withdraw</button>
                                        <?php elseif (($recipeCounts[$componentId] ?? 0) > 0): ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="offer">Put back</button>

                                            <!--
                                                No Delete button while a recipe still names it.
                                                Deleting it would cascade the part out of every
                                                one of them with nothing on screen to say so, so
                                                the row says how many instead of offering a
                                                press that the handler would only refuse.

                                                All on one line: the PHP between the words would
                                                otherwise put the file's indentation inside the
                                                sentence, and "In 1" followed by forty spaces and
                                                "recipe" is not a phrase anything can search for.
                                            -->
                                            <?php $recipeCount = (int) $recipeCounts[$componentId]; ?>
                                            <span class="admin-cell-blank">In <?php echo $recipeCount; ?> recipe<?php echo $recipeCount === 1 ? '' : 's'; ?></span>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-quiet btn-small" name="action" value="offer">Put back</button>

                                            <!--
                                                Only on a withdrawn row that nothing is built
                                                from. data-confirm is a second pair of eyes
                                                where JavaScript is running; having to withdraw
                                                the part first is what protects it where it is
                                                not.
                                            -->
                                            <button type="submit" class="btn btn-danger btn-small" name="action" value="delete"
                                                    data-confirm="Delete &quot;<?php echo escape((string) $component['name']); ?>&quot; for good? This cannot be undone.">Delete</button>
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
            <nav class="pager" aria-label="Component list pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminComponentsUrl($filterParams + ['page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Previous</span>
                <?php endif; ?>

                <span class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-quiet" href="<?php echo escape(adminComponentsUrl($filterParams + ['page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-quiet is-disabled" aria-hidden="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
