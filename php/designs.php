<?php
// Must run before any HTML: requireLogin() can redirect, which needs the headers unsent.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/designs.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();
requireLogin();

/*
 * The bouquets one customer has kept.
 *
 * requireLogin() and not requireAdmin(), for the reason php/dashboard.php gives: an admin is
 * also a person with an account, and there is nothing here they should be shut out of. What
 * they cannot do is see anybody else's -- fetchSavedDesigns() is scoped by user_id in the SQL,
 * so this page has no idea other accounts have designs at all.
 *
 * Read-only. The rename and delete forms post to php/design-save.php, which owns every write
 * in the module and comes back here with a flash.
 *
 * No prices on this page, and that is deliberate rather than an omission. A design re-prices
 * from product_components every time it is read, so a figure in this list would have to be
 * twenty designs' worth of component lookups to print twenty numbers that are only true until
 * the shop changes a price. The count of flowers is what identifies a bouquet in a list; the
 * price belongs on the builder, next to the bouquet it is for.
 */
$userId = (int) $_SESSION['user_id'];

$designs = [];
$loadFailed = false;

try {
    $designs = fetchSavedDesigns($userId);
} catch (PDOException $exception) {
    error_log('designs.php: ' . $exception->getMessage());
    $loadFailed = true;
}

$toasts = takeFlashToasts();

if ($loadFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'We could not load your saved bouquets right now. Check your database connection.',
    ];
}

renderHeader('Your saved bouquets');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Your account</p>
            <h1>Saved bouquets</h1>
            <p class="page-intro">
                Every bouquet you have kept. Open one to change it, or start something new in
                the designer. You can keep up to <?php echo escape((string) SAVED_DESIGN_MAX); ?>.
            </p>
        </header>

<?php if ($designs === []): ?>
        <div class="empty-state">
            <h2><?php echo $loadFailed ? 'We could not read your bouquets' : 'Nothing saved yet'; ?></h2>
            <p>
                <?php if ($loadFailed): ?>
                    Nothing has been lost &mdash; try again in a moment.
                <?php else: ?>
                    Build a bouquet in the designer and give it a name, and it will be here.
                <?php endif; ?>
            </p>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/customize.php')); ?>">Open the designer</a></p>
        </div>
<?php else: ?>
        <p class="catalogue-count">
            <?php echo count($designs); ?>
            bouquet<?php echo count($designs) === 1 ? '' : 's'; ?>, most recently changed first
        </p>

        <ul class="design-list">
            <?php foreach ($designs as $row): ?>
                <?php
                $designId = (int) $row['id'];
                $stems = (int) $row['stem_count'];
                $openUrl = url('php/customize.php') . '?design=' . $designId;

                // A malformed date should not take the page down with it, the same guard
                // php/dashboard.php puts around created_at.
                $changed = strtotime((string) ($row['updated_at'] ?? ''));
                ?>
                <li class="design-card">
                    <div class="design-card-head">
                        <h2 class="design-card-name">
                            <a href="<?php echo escape($openUrl); ?>"><?php echo escape((string) $row['name']); ?></a>
                        </h2>
                        <p class="design-card-meta">
                            <?php echo escape((string) $stems); ?>
                            flower<?php echo $stems === 1 ? '' : 's'; ?>,
                            <?php echo escape(strtolower(designArrangementLabel((string) $row['arrangement']))); ?>
                            <?php if ($changed !== false): ?>
                                &middot; changed <?php echo escape(date('j F Y', $changed)); ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="design-card-actions">
                        <a class="btn btn-primary" href="<?php echo escape($openUrl); ?>">Open</a>

                        <?php
                        /*
                         * Straight into the basket, without going through the builder first.
                         * php/cart-update.php reads the bouquet back with readSavedDesign(), which
                         * is scoped by user_id in its SQL -- so this posts an id and nothing else,
                         * and somebody else's id comes back as "not found" rather than as a
                         * bouquet. The parts and prices are read from the database there, never
                         * taken from this form: a price is the shop's to state.
                         *
                         * back is this page, so ordering one bouquet leaves the list open to order
                         * another.
                         */
                        ?>
                        <form method="post" action="<?php echo escape(url('php/cart-update.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="add-saved">
                            <input type="hidden" name="design_id" value="<?php echo $designId; ?>">
                            <input type="hidden" name="back" value="<?php echo escape(url('php/designs.php')); ?>">
                            <button type="submit" class="btn btn-quiet">Add to basket</button>
                        </form>

                        <?php
                        /*
                         * Rename in place rather than on a page of its own. It is one field,
                         * and a whole page to change one word is a page nobody wants to load.
                         *
                         * The name box is pre-filled with the current name, so a rename that
                         * is really a small edit -- a typo, a year -- is a couple of
                         * keystrokes. POST because it writes; php/design-save.php redirects
                         * back here, so a refresh afterwards is harmless.
                         */
                        ?>
                        <form class="design-card-rename" method="post"
                              action="<?php echo escape(url('php/design-save.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="design_id" value="<?php echo $designId; ?>">

                            <label class="visually-hidden" for="design-name-<?php echo $designId; ?>">
                                Rename <?php echo escape((string) $row['name']); ?>
                            </label>
                            <input type="text" id="design-name-<?php echo $designId; ?>" name="name"
                                   value="<?php echo escape((string) $row['name']); ?>"
                                   maxlength="<?php echo escape((string) SAVED_DESIGN_NAME_MAX); ?>"
                                   required>

                            <button type="submit" class="btn btn-quiet">Rename</button>
                        </form>

                        <?php
                        /*
                         * A confirm attribute rather than a confirm() call written here:
                         * js/script.js already wires data-confirm on the admin's delete
                         * buttons, so this reuses the one that exists. Without JavaScript the
                         * button simply deletes, which is why the label says what it does --
                         * a form whose only field is a design id has nothing else to
                         * accidentally submit.
                         */
                        ?>
                        <form method="post" action="<?php echo escape(url('php/design-save.php')); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="design_id" value="<?php echo $designId; ?>">
                            <button type="submit" class="btn btn-danger"
                                    data-confirm="Delete &quot;<?php echo escape((string) $row['name']); ?>&quot;? This cannot be undone.">
                                Delete
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="card-action">
            <a class="btn btn-primary" href="<?php echo escape(url('php/customize.php')); ?>">Build another</a>
        </p>
<?php endif; ?>
    </main>
<?php renderFooter(); ?>
