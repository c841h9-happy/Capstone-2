<?php
// Admin pages start with the guard, before any output. See guard.php. This page also
// answers 404 and redirects, both of which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/products-admin.php';
require_once __DIR__ . '/../config/uploads.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Add a product, or change one.
 *
 * One page for both, because they differ by a heading, a button label and the presence
 * of an id. Two pages would mean two copies of this form and two places for the rules
 * about a valid product to drift apart -- and the rules themselves are in
 * config/products-admin.php for the same reason, where validateProductInput() is the
 * single answer to "may this be saved".
 *
 * A form that succeeded answers with a redirect and leaves its message in the session,
 * so a refresh cannot add the same product twice. A form that failed re-renders with
 * what was typed still in the boxes, the way php/profile.php does -- except for the
 * photo, which no browser will let a page re-fill, so that one says so out loud.
 */

/*
 * ?id=abc is a broken link, not a request to add something new.
 *
 * So the two are told apart by whether the parameter is there at all: no id means the
 * add form, and an id that is not a real product means 404. Quietly showing a blank
 * add form for a mistyped id would look like the product had been deleted.
 */
$hasId = isset($_GET['id']);
$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;

$product = null;
$loadFailed = false;

if ($hasId) {
    try {
        $product = adminFetchProduct($id);
    } catch (PDOException $exception) {
        error_log('admin/product-edit.php: ' . $exception->getMessage());
        $loadFailed = true;
    }

    /*
     * A real page with a real status code, not a redirect to the list -- the same
     * reasoning as php/product.php. A database failure is deliberately 503 rather than
     * 404: that would claim the product does not exist, when the truth is that nobody
     * can tell right now.
     */
    if ($product === null) {
        http_response_code($loadFailed ? 503 : 404);

        renderHeader($loadFailed ? 'Something went wrong' : 'Product not found');
        ?>
        <main class="page">
            <div class="empty-state">
                <?php if ($loadFailed): ?>
                    <h1>We could not load that</h1>
                    <p>The catalogue is unavailable for a moment. Nothing has changed.</p>
                <?php else: ?>
                    <h1>Product not found</h1>
                    <p>There is no product with that id. It may have been deleted.</p>
                <?php endif; ?>
                <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/products.php')); ?>">Back to products</a></p>
            </div>
        </main>
        <?php
        renderFooter();
        exit;
    }
}

$isEdit = $product !== null;

/*
 * What goes in the boxes.
 *
 * Strings throughout, because that is what an input holds: stem_count is '' rather than
 * null for a single flower, and the price arrives from the column already as '450.00'.
 */
$form = [
    'name' => '',
    'kind' => 'bouquet',
    'price' => '',
    'colour' => '',
    'description' => '',
    'stem_count' => '',
    // A new product is on sale unless the box is unticked. Adding something and then
    // having to remember a second step to publish it is the more surprising default.
    'is_active' => 1,
    // Only ever ticked on the edit form, and only while there is a photo to take off.
    'remove_image' => 0,
];

if ($isEdit) {
    $form = [
        'name' => (string) $product['name'],
        'kind' => (string) $product['kind'],
        'price' => (string) $product['price'],
        'colour' => (string) $product['colour'],
        'description' => (string) $product['description'],
        'stem_count' => $product['stem_count'] === null ? '' : (string) (int) $product['stem_count'],
        'is_active' => (int) $product['is_active'],
        'remove_image' => 0,
    ];
}

$errors = [];

/*
 * True when the photo box was filled in on a submission that then failed.
 *
 * A file input cannot be re-filled by a page -- only a person may put a file into one --
 * so re-rendering the form silently drops the photo the admin chose. Saying so is the
 * only honest option; leaving it unsaid means the second attempt saves the text and
 * quietly no picture.
 */
$photoNeedsChoosingAgain = false;

/*
 * The whole request was thrown away for being too big.
 *
 * PHP discards a POST body over post_max_size before any of this runs, so $_POST and
 * $_FILES arrive empty and there is no error code to find. Without this branch the page
 * would run the validator over nothing and answer a 60 MB photo with "enter a name,
 * enter a price, write a description".
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && uploadPostWasDiscarded()) {
    $errors[] = 'That photo is too big for the server to accept at all. Nothing has been saved.';
    $photoNeedsChoosingAgain = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /*
     * The token check goes here and not above the branch. A POST over post_max_size arrives with
     * $_POST empty, token included, so checking first would answer a 60 MB photo with "that form
     * had expired" -- a message about a problem the admin does not have and cannot act on.
     */
    requireCsrfToken();

    // Back in the boxes goes what was typed, not what is stored -- correcting one field
    // should not throw away the other six.
    $form = [
        'name' => productInputText($_POST, 'name'),
        'kind' => productInputText($_POST, 'kind'),
        'price' => productInputText($_POST, 'price'),
        'colour' => productInputText($_POST, 'colour'),
        'description' => productInputText($_POST, 'description'),
        'stem_count' => productInputText($_POST, 'stem_count'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $photo = is_array($_FILES['image'] ?? null) ? $_FILES['image'] : ['error' => UPLOAD_ERR_NO_FILE];
    $photoChosen = uploadWasAttempted($photo);
    $removePhoto = isset($_POST['remove_image']);

    // Where a newly stored file ended up, so it can be cleaned away again if the save
    // falls over after it was written.
    $storedPath = '';

    try {
        // The id being edited is excused from the duplicate-name check, or saving a
        // product without renaming it would complain that it already exists.
        $checked = validateProductInput($_POST, $isEdit ? $id : null);
        $errors = $checked['errors'];

        /*
         * The photo is only moved into place once the text is known to be good.
         *
         * Order matters. Storing it first would leave a file in uploads/ every time
         * somebody mistyped a price, and nothing would ever point at it.
         */
        if ($errors === [] && $photoChosen) {
            $stored = storeUploadedImage($photo, 'products');

            if ($stored['error'] !== '') {
                // A refused photo refuses the whole save. Writing the text and dropping
                // the picture would look like it worked.
                $errors[] = $stored['error'];
            } else {
                $storedPath = $stored['path'];
            }
        }

        if ($errors === []) {
            $name = (string) $checked['values']['name'];

            if ($isEdit) {
                updateProduct($id, $checked['values']);
                $savedId = $id;
            } else {
                // The row first: a photo is attached to an id, and a new product does
                // not have one until it exists.
                $savedId = createProduct($checked['values']);
            }

            /*
             * A new photo wins over the remove box.
             *
             * Both can be sent at once -- tick remove, then think better of it and
             * choose a replacement without unticking. Replacing is what was meant, and
             * setProductImage() deletes the old file either way.
             */
            if ($storedPath !== '') {
                setProductImage($savedId, $storedPath);
            } elseif ($isEdit && $removePhoto) {
                setProductImage($savedId, '');
            }

            if ($isEdit) {
                flashToast('success', '"' . $name . '" is saved.');
            } else {
                flashToast(
                    'success',
                    $checked['values']['is_active'] === 1
                        ? '"' . $name . '" is added and on the catalogue.'
                        : '"' . $name . '" is added, hidden for now.'
                );
            }

            redirectTo(url('php/admin/products.php'));
        }
    } catch (PDOException $exception) {
        error_log('admin/product-edit.php: ' . $exception->getMessage());
        $errors[] = 'Unable to save this product right now. Check your database connection.';

        // The file was written before the statement that failed, and now nothing will
        // ever reference it. Take it back out rather than leaving it in uploads/ for good.
        if ($storedPath !== '') {
            deleteStoredImage($storedPath);
            $storedPath = '';
        }
    }

    // Only worth saying when there was a photo to lose.
    $photoNeedsChoosingAgain = $photoChosen && $errors !== [];
    $form['remove_image'] = $removePhoto ? 1 : 0;
}

// An unknown kind is refused above; the select still has to show something, and the
// error already says what to do about it.
$kindValue = in_array($form['kind'], PRODUCT_KINDS, true) ? $form['kind'] : 'bouquet';

$toasts = takeFlashToasts();

foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

if ($photoNeedsChoosingAgain) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Choose the photo again as well -- a browser will not let a page put a file back into the box for you.',
    ];
}

// The photo this product has now, if it still exists on disk. productImageUrl() checks
// the file rather than trusting the column, so a path left behind by a photo that was
// deleted by hand shows as "no photo" instead of a broken image.
$currentImageUrl = $isEdit ? productImageUrl($product) : null;

$heading = $isEdit ? 'Edit product' : 'Add a product';

renderHeader($heading);
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb">
            <a href="<?php echo escape(url('php/admin/products.php')); ?>">Products</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span><?php echo escape($isEdit ? (string) $product['name'] : 'New'); ?></span>
        </p>

        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1><?php echo escape($heading); ?></h1>
            <p class="page-intro">
                <?php if ($isEdit): ?>
                    Changes show on the catalogue as soon as you save.
                <?php else: ?>
                    Bouquets and single flowers live in the same list, told apart by what
                    you choose below.
                <?php endif; ?>
            </p>
        </header>

        <div class="profile-panels">
            <section class="panel">
                <h2>Details</h2>

                <!--
                    enctype="multipart/form-data" is what makes the photo box work. Without
                    it a browser sends only the file's name and $_FILES arrives empty, which
                    looks exactly like nothing having been chosen.
                -->
                <form class="panel-form" method="post" enctype="multipart/form-data"
                      action="<?php echo escape(url('php/admin/product-edit.php' . ($isEdit ? '?id=' . $id : ''))); ?>">
                    <?php echo csrfField(); ?>
                    <label for="product-name">Name</label>
                    <input id="product-name" name="name" type="text" value="<?php echo escape((string) $form['name']); ?>"
                           maxlength="<?php echo PRODUCT_NAME_MAX; ?>" required autofocus>

                    <label for="product-kind">Kind</label>
                    <!-- data-product-kind is what script.js watches to show and hide the
                         flower count below. The server decides regardless. -->
                    <select id="product-kind" name="kind" data-product-kind>
                        <option value="bouquet" <?php echo $kindValue === 'bouquet' ? 'selected' : ''; ?>>Bouquet</option>
                        <option value="flower" <?php echo $kindValue === 'flower' ? 'selected' : ''; ?>>Single flower</option>
                    </select>

                    <label for="product-price">Price</label>
                    <!--
                        type="text" with inputmode, not type="number". A number input
                        disagrees with itself about decimals across browsers and quietly
                        empties itself on anything it dislikes, which would hand back a
                        blank box instead of the price that needs correcting.
                    -->
                    <input id="product-price" name="price" type="text" inputmode="decimal"
                           value="<?php echo escape((string) $form['price']); ?>"
                           maxlength="14" aria-describedby="product-price-hint" required>
                    <p class="field-hint" id="product-price-hint">In pesos, digits only &mdash; 450 or 450.50.</p>

                    <!--
                        Three elements carry data-kind-only rather than one wrapper: the
                        panel is a grid, and a wrapper would collapse three rows of it
                        into one and take the spacing with it.

                        Note there is no `required` here. A required field that is hidden
                        cannot be focused, and the browser then refuses to submit the
                        form while pointing at something nobody can see.
                    -->
                    <label for="product-stems" data-kind-only="bouquet">Flowers included</label>
                    <input id="product-stems" name="stem_count" type="text" inputmode="numeric"
                           value="<?php echo escape((string) $form['stem_count']); ?>"
                           maxlength="3" aria-describedby="product-stems-hint" data-kind-only="bouquet">
                    <p class="field-hint" id="product-stems-hint" data-kind-only="bouquet">
                        How many stems are in the bouquet, 1 to <?php echo PRODUCT_STEM_COUNT_MAX; ?>.
                        A single flower does not need this.
                    </p>

                    <label for="product-colour">Colour</label>
                    <input id="product-colour" name="colour" type="text" value="<?php echo escape((string) $form['colour']); ?>"
                           maxlength="<?php echo PRODUCT_COLOUR_MAX; ?>" aria-describedby="product-colour-hint">
                    <p class="field-hint" id="product-colour-hint">Optional. However you would describe it &mdash; &ldquo;blush pink&rdquo;, &ldquo;mixed&rdquo;.</p>

                    <label for="product-description">Description</label>
                    <textarea id="product-description" name="description" rows="5"
                              maxlength="<?php echo PRODUCT_DESCRIPTION_MAX; ?>" required><?php echo escape((string) $form['description']); ?></textarea>

                    <label class="auth-check">
                        <input type="checkbox" name="is_active" value="1" <?php echo (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                        <span>Show this on the catalogue</span>
                    </label>

                    <label for="product-image">Photo</label>

                    <?php if ($currentImageUrl !== null): ?>
                        <!--
                            The photo it has now, shown at the size it is stored at rather
                            than as a thumbnail: "is this the right picture" is hard to
                            answer from 48 pixels. It sits above the box on purpose, so
                            choosing a file reads as replacing what is visible.
                        -->
                        <div class="image-preview">
                            <img src="<?php echo escape($currentImageUrl); ?>" alt="The photo currently on this product">
                        </div>
                    <?php endif; ?>

                    <!--
                        accept is a hint to the file picker, not a check. The real one is
                        storeUploadedImage(), which reads the bytes: accept can be worked
                        around by dragging a file in, and a filename ending .jpg says
                        nothing about what is inside it.
                    -->
                    <input id="product-image" name="image" type="file"
                           accept="image/jpeg,image/png,image/webp"
                           aria-describedby="product-image-hint">
                    <p class="field-hint" id="product-image-hint">
                        JPEG, PNG or WebP, up to <?php echo (int) (UPLOAD_MAX_BYTES / 1024 / 1024); ?> MB.
                        <?php if ($isEdit && $currentImageUrl !== null): ?>
                            Choosing one replaces the photo above. Leave this empty to keep it.
                        <?php elseif ($isEdit): ?>
                            This product has no photo yet, so the catalogue draws a placeholder tile.
                        <?php else: ?>
                            Optional &mdash; a product with no photo draws a placeholder tile.
                        <?php endif; ?>
                    </p>

                    <?php if ($currentImageUrl !== null): ?>
                        <label class="auth-check">
                            <input type="checkbox" name="remove_image" value="1" <?php echo (int) $form['remove_image'] === 1 ? 'checked' : ''; ?>>
                            <span>Remove the photo and go back to the placeholder</span>
                        </label>
                    <?php endif; ?>

                    <div class="panel-actions">
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save changes' : 'Add product'; ?></button>
                        <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/products.php')); ?>">Cancel</a>
                    </div>
                </form>
            </section>

            <?php if ($isEdit): ?>
                <section class="panel">
                    <h2>This product</h2>
                    <dl class="card-list">
                        <dt>Status</dt>
                        <dd>
                            <span class="pill <?php echo (int) $product['is_active'] === 1 ? 'pill-active' : 'pill-hidden'; ?>">
                                <?php echo escape(productStatusLabel((int) $product['is_active'])); ?>
                            </span>
                        </dd>

                        <dt>Added</dt>
                        <dd>
                            <?php
                            $addedAt = strtotime((string) $product['created_at']);
                            echo escape($addedAt === false ? 'Unknown' : date('j F Y', $addedAt));
                            ?>
                        </dd>

                        <dt>Public page</dt>
                        <dd>
                            <?php if ((int) $product['is_active'] === 1): ?>
                                <a href="<?php echo escape(url('php/product.php?id=' . $id)); ?>">See it on the shop</a>
                            <?php else: ?>
                                Hidden, so the shop answers &ldquo;not found&rdquo; for it.
                            <?php endif; ?>
                        </dd>

                        <dt>Parts</dt>
                        <dd>
                            <!-- The recipe is its own screen rather than a section of this
                                 form: it is one number beside every part the shop stocks, and
                                 it is saved against the parts list rather than against any of
                                 the fields above. -->
                            <a href="<?php echo escape(url('php/admin/product-parts.php?id=' . $id)); ?>">What it&rsquo;s made of</a>
                        </dd>
                    </dl>
                    <p class="card-note">Deleting is on the product list, and only once a product is hidden.</p>
                </section>
            <?php endif; ?>
        </div>
    </main>
<?php renderFooter(); ?>
