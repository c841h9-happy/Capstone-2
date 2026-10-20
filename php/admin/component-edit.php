<?php
// Admin pages start with the guard, before any output. See guard.php. This page also
// answers 404 and redirects, both of which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/components-admin.php';
// For productsUsingComponent(): which bouquets are built from this part, which is what
// makes deleting it a data-loss path rather than a tidy-up. See the "Built into" row below.
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/uploads.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Add a part, or change one.
 *
 * One page for both, because they differ by a heading, a button label and the presence of
 * an id. Two pages would mean two copies of this form and two places for the rules about
 * a valid component to drift apart -- and the rules themselves are in
 * config/components-admin.php for the same reason, where validateComponentInput() is the
 * single answer to "may this be saved".
 *
 * The shape of this page is php/admin/product-edit.php's, deliberately: the same order of
 * operations, the same 404-with-a-real-page, the same honesty about a file input that no
 * browser will re-fill. What is different is the builder colour, which is the one field a
 * product does not have and the one the 3D customizer cannot work without.
 */

/*
 * ?id=abc is a broken link, not a request to add something new.
 *
 * So the two are told apart by whether the parameter is there at all: no id means the add
 * form, and an id that is not a real component means 404. Quietly showing a blank add
 * form for a mistyped id would look like the part had been deleted.
 */
$hasId = isset($_GET['id']);
$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;

$component = null;
$loadFailed = false;

/*
 * The bouquets built from this part, read only when there is a part to read them for.
 *
 * Its own try/catch and its own variable rather than a call down in the panel: a failure
 * here must not take an editable form off the screen. An empty list then reads as "nothing
 * is built from it", which would be the wrong thing to tell somebody who is deciding
 * whether to delete it -- hence $partsFailed, which makes the panel say so instead.
 */
$builtInto = [];
$partsFailed = false;

if ($hasId) {
    try {
        // adminFetchComponent(), not fetchComponent(): a withdrawn part has to be
        // editable, because putting it back is an edit.
        $component = adminFetchComponent($id);
    } catch (PDOException $exception) {
        error_log('admin/component-edit.php: ' . $exception->getMessage());
        $loadFailed = true;
    }

    /*
     * A real page with a real status code, not a redirect to the list -- the same
     * reasoning as php/product.php. A database failure is deliberately 503 rather than
     * 404: that would claim the part does not exist, when the truth is that nobody can
     * tell right now.
     */
    if ($component === null) {
        http_response_code($loadFailed ? 503 : 404);

        renderHeader($loadFailed ? 'Something went wrong' : 'Part not found');
        ?>
        <main class="page">
            <div class="empty-state">
                <?php if ($loadFailed): ?>
                    <h1>We could not load that</h1>
                    <p>The parts list is unavailable for a moment. Nothing has changed.</p>
                <?php else: ?>
                    <h1>Part not found</h1>
                    <p>There is no component with that id. It may have been deleted.</p>
                <?php endif; ?>
                <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/components.php')); ?>">Back to components</a></p>
            </div>
        </main>
        <?php
        renderFooter();
        exit;
    }

    try {
        $builtInto = productsUsingComponent($id);
    } catch (PDOException $exception) {
        error_log('admin/component-edit.php: ' . $exception->getMessage());
        $partsFailed = true;
    }
}

$isEdit = $component !== null;
/*
 * What goes in the boxes.
 *
 * Strings throughout, because that is what an input holds: sort_order arrives from the
 * column as an int and the price arrives already as '38.00'.
 */
$form = [
    'name' => '',
    'kind' => 'bloom',
    'price' => '',
    'colour' => '',
    // Not black. <input type="color"> has no empty state -- it shows #000000 for anything
    // it cannot parse -- so the add form has to open on *some* colour, and a mid grey
    // reads as "not chosen yet" where black reads as a deliberate choice.
    'colour_hex' => COMPONENT_FALLBACK_HEX,
    // Only read when the kind is 'bloom'; the write forces 'none' for the other three. The
    // add form opens on a rose because that is what most of the picker is, and because the
    // select deliberately does not offer 'none' -- see the field itself for why.
    'shape' => 'rose',
    'description' => '',
    'sort_order' => '',
    // A new part goes into the picker unless the box is unticked. Adding something and
    // then having to remember a second step to offer it is the more surprising default.
    'is_active' => 1,
    // Only ever ticked on the edit form, and only while there is a photo to take off.
    'remove_image' => 0,
];

if ($isEdit) {
    $form = [
        'name' => (string) $component['name'],
        'kind' => (string) $component['kind'],
        'price' => (string) $component['price'],
        'colour' => (string) $component['colour'],
        // Through componentColourHex() rather than straight out of the column, so a value
        // written by hand in the MySQL shell cannot land in a style attribute.
        'colour_hex' => componentColourHex($component),
        'shape' => (string) $component['shape'],
        'description' => (string) $component['description'],
        'sort_order' => (string) (int) $component['sort_order'],
        'is_active' => (int) $component['is_active'],
        'remove_image' => 0,
    ];
}

$errors = [];
/*
 * True when the photo box was filled in on a submission that then failed.
 *
 * A file input cannot be re-filled by a page -- only a person may put a file into one --
 * so re-rendering the form silently drops the photo the admin chose. Saying so is the only
 * honest option; leaving it unsaid means the second attempt saves the text and quietly no
 * picture.
 */
$photoNeedsChoosingAgain = false;

/*
 * The whole request was thrown away for being too big.
 *
 * PHP discards a POST body over post_max_size before any of this runs, so $_POST and
 * $_FILES arrive empty and there is no error code to find. Without this branch the page
 * would run the validator over nothing and answer a 60 MB photo with "enter a name, enter
 * a price, write a description".
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && uploadPostWasDiscarded()) {
    $errors[] = 'That photo is too big for the server to accept at all. Nothing has been saved.';
    $photoNeedsChoosingAgain = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // After the discarded-POST branch above, not before it: an oversized photo empties $_POST, so
    // a token check placed first would report an expired form instead of a photo that is too big.
    requireCsrfToken();

    // Back in the boxes goes what was typed, not what is stored -- correcting one field
    // should not throw away the other seven.
    $form = [
        'name' => productInputText($_POST, 'name'),
        'kind' => productInputText($_POST, 'kind'),
        'price' => productInputText($_POST, 'price'),
        'colour' => productInputText($_POST, 'colour'),
        'colour_hex' => productInputText($_POST, 'colour_hex'),
        'shape' => productInputText($_POST, 'shape'),
        'description' => productInputText($_POST, 'description'),
        'sort_order' => productInputText($_POST, 'sort_order'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $photo = is_array($_FILES['image'] ?? null) ? $_FILES['image'] : ['error' => UPLOAD_ERR_NO_FILE];
    $photoChosen = uploadWasAttempted($photo);
    $removePhoto = isset($_POST['remove_image']);

    // Where a newly stored file ended up, so it can be cleaned away again if the save
    // falls over after it was written.
    $storedPath = '';

    try {
        // The id being edited is excused from the duplicate-name check, or saving a part
        // without renaming it would complain that it already exists.
        $checked = validateComponentInput($_POST, $isEdit ? $id : null);
        $errors = $checked['errors'];

        /*
         * The photo is only moved into place once the text is known to be good.
         *
         * Order matters. Storing it first would leave a file in uploads/ every time
         * somebody mistyped a price, and nothing would ever point at it.
         */
        if ($errors === [] && $photoChosen) {
            // 'components', not 'products'. storeUploadedImage() takes the folder for
            // exactly this reason -- one idea of what a safe file is, two places to put
            // one. uploads/.htaccess sits at the parent level, so this folder is already
            // covered by it.
            $stored = storeUploadedImage($photo, 'components');

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
                updateComponent($id, $checked['values']);
                $savedId = $id;
            } else {
                // The row first: a photo is attached to an id, and a new part does not
                // have one until it exists.
                $savedId = createComponent($checked['values']);
            }

            /*
             * A new photo wins over the remove box.
             *
             * Both can be sent at once -- tick remove, then think better of it and choose
             * a replacement without unticking. Replacing is what was meant, and
             * setComponentImage() deletes the old file either way.
             */
            if ($storedPath !== '') {
                setComponentImage($savedId, $storedPath);
            } elseif ($isEdit && $removePhoto) {
                setComponentImage($savedId, '');
            }

            if ($isEdit) {
                flashToast('success', '"' . $name . '" is saved.');
            } else {
                flashToast(
                    'success',
                    $checked['values']['is_active'] === 1
                        ? '"' . $name . '" is added and in the picker.'
                        : '"' . $name . '" is added, withdrawn for now.'
                );
            }

            redirectTo(url('php/admin/components.php'));
        }
    } catch (PDOException $exception) {
        error_log('admin/component-edit.php: ' . $exception->getMessage());
        $errors[] = 'Unable to save this part right now. Check your database connection.';

        // The file was written before the statement that failed, and now nothing will ever
        // reference it. Take it back out rather than leaving it in uploads/ for good.
        if ($storedPath !== '') {
            deleteStoredImage($storedPath);
            $storedPath = '';
        }
    }

    // Only worth saying when there was a photo to lose.
    $photoNeedsChoosingAgain = $photoChosen && $errors !== [];
    $form['remove_image'] = $removePhoto ? 1 : 0;
}

// An unknown kind is refused above; the select still has to show something, and the error
// already says what to do about it.
$kindValue = in_array($form['kind'], COMPONENT_KINDS, true) ? $form['kind'] : 'bloom';

/*
 * Which shape the select shows, for display only.
 *
 * 'none' resolves to 'rose' here rather than being shown. The select does not offer 'none'
 * at all, so a wrap being edited -- whose column genuinely is 'none' -- would otherwise
 * land on whichever option happened to be first in the list. Pinning it means the hidden
 * field holds something sensible if the kind is switched to bloom while the page is open.
 */
$shapeValue = in_array($form['shape'], COMPONENT_SHAPES, true) && $form['shape'] !== 'none'
    ? (string) $form['shape']
    : 'rose';

/*
 * A value the colour input can actually hold, for display only.
 *
 * <input type="color"> silently becomes #000000 for anything it cannot parse, so feeding
 * it the raw submission would answer "e8b4b" with a black swatch and no hint of why. The
 * error above already says what is wrong with it; this only decides what the box shows
 * while the admin reads that.
 */
$hexValue = componentColourHex(['colour_hex' => $form['colour_hex']]);

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

// The photo this part has now, if it still exists on disk. componentImageUrl() checks the
// file rather than trusting the column, so a path left behind by a photo that was deleted
// by hand shows as "no photo" instead of a broken image.
$currentImageUrl = $isEdit ? componentImageUrl($component) : null;

$heading = $isEdit ? 'Edit part' : 'Add a part';

renderHeader($heading);
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb">
            <a href="<?php echo escape(url('php/admin/components.php')); ?>">Components</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span><?php echo escape($isEdit ? (string) $component['name'] : 'New'); ?></span>
        </p>

        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1><?php echo escape($heading); ?></h1>
            <p class="page-intro">
                <?php if ($isEdit): ?>
                    The customizer picks this up as soon as you save.
                <?php else: ?>
                    A part is one piece of a custom bouquet &mdash; a bloom, the stem under
                    it, the paper around it, a ribbon. The builder adds them up to price a
                    design.
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
                      action="<?php echo escape(url('php/admin/component-edit.php' . ($isEdit ? '?id=' . $id : ''))); ?>">
                    <?php echo csrfField(); ?>
                    <label for="component-name">Name</label>
                    <input id="component-name" name="name" type="text" value="<?php echo escape((string) $form['name']); ?>"
                           maxlength="<?php echo COMPONENT_NAME_MAX; ?>" required autofocus>

                    <label for="component-kind">What sort of part</label>
                    <select id="component-kind" name="kind" data-product-kind>
                        <?php foreach (COMPONENT_KINDS as $kindOption): ?>
                            <!-- From the constant, so a fifth kind added to the ENUM and to
                                 COMPONENT_KINDS is selectable without touching this page. -->
                            <option value="<?php echo escape($kindOption); ?>" <?php echo $kindValue === $kindOption ? 'selected' : ''; ?>>
                                <?php echo escape(componentKindLabel($kindOption)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!--
                        Only a flower head has a shape. The label, the select and the hint
                        each carry data-kind-only rather than sharing a wrapper, because
                        .panel-form is a grid and a wrapper would collapse three of its rows
                        into one -- the same reason the product form's flower count is
                        marked up this way.

                        Hiding it is JavaScript's job and enforcing it is not:
                        validateComponentInput() writes 'none' for a wire, a wrap or an
                        accent whatever arrives in this field.
                    -->
                    <label for="component-shape" data-kind-only="bloom">Shape in the 3D builder</label>
                    <select id="component-shape" name="shape" data-kind-only="bloom"
                            aria-describedby="component-shape-hint">
                        <?php
                        /*
                         * 'none' is skipped on purpose. It is the value the other three kinds
                         * are stored with, and offering it here would be an option that always
                         * fails -- a bloom with no shape is an invisible bloom, which
                         * validateComponentInput() refuses.
                         *
                         * A PHP comment rather than an HTML one because this sits inside the
                         * loop, and an HTML comment here would be sent to the browser five
                         * times over.
                         */
                        foreach (COMPONENT_SHAPES as $shapeOption):
                            if ($shapeOption === 'none') {
                                continue;
                            }
                        ?>
                            <option value="<?php echo escape($shapeOption); ?>" <?php echo $shapeValue === $shapeOption ? 'selected' : ''; ?>>
                                <?php echo escape(componentShapeLabel($shapeOption)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint" id="component-shape-hint" data-kind-only="bloom">
                        What the customizer draws. The colour below is what it paints with;
                        this is the form it paints.
                    </p>

                    <label for="component-price">Price</label>
                    <!--
                        type="text" with inputmode, not type="number". A number input
                        disagrees with itself about decimals across browsers and quietly
                        empties itself on anything it dislikes, which would hand back a
                        blank box instead of the price that needs correcting.
                    -->
                    <input id="component-price" name="price" type="text" inputmode="decimal"
                           value="<?php echo escape((string) $form['price']); ?>"
                           maxlength="14" aria-describedby="component-price-hint" required>
                    <p class="field-hint" id="component-price-hint">
                        What this part adds to a bouquet, in pesos &mdash; 38 or 38.50.
                        Use 0 for something that costs nothing extra.
                    </p>

                    <label for="component-colour">Colour name</label>
                    <input id="component-colour" name="colour" type="text" value="<?php echo escape((string) $form['colour']); ?>"
                           maxlength="<?php echo COMPONENT_COLOUR_MAX; ?>" aria-describedby="component-colour-hint">
                    <p class="field-hint" id="component-colour-hint">Optional, and for people to read &mdash; &ldquo;blush pink&rdquo;, &ldquo;sage green&rdquo;.</p>

                    <label for="component-hex">Builder colour</label>
                    <!--
                        The one field a product does not have. A colour input rather than a
                        text box because six hex digits are not something to ask anybody to
                        type, and Chrome's picker has a hex field inside it for when they
                        want to. A browser without support renders a plain text box instead,
                        which is why validateComponentInput() adds a missing '#' rather than
                        refusing it.
                    -->
                    <input id="component-hex" name="colour_hex" type="color"
                           value="<?php echo escape($hexValue); ?>" aria-describedby="component-hex-hint">
                    <p class="field-hint" id="component-hex-hint">
                        What the 3D builder actually paints this part with, currently
                        <code><?php echo escape($hexValue); ?></code>. Pick the material
                        colour rather than a brand one &mdash; chenille is matte, so a pure
                        red reads as plastic.
                    </p>

                    <label for="component-description">Description</label>
                    <textarea id="component-description" name="description" rows="4"
                              maxlength="<?php echo COMPONENT_DESCRIPTION_MAX; ?>" required><?php echo escape((string) $form['description']); ?></textarea>

                    <label for="component-sort">Picker position</label>
                    <input id="component-sort" name="sort_order" type="text" inputmode="numeric"
                           value="<?php echo escape((string) $form['sort_order']); ?>"
                           maxlength="4" aria-describedby="component-sort-hint">
                    <p class="field-hint" id="component-sort-hint">
                        Low numbers come first, within this kind of part. Leave it empty for
                        0. The seeded parts go up in tens, so 25 slips one between 20 and 30.
                    </p>

                    <label class="auth-check">
                        <input type="checkbox" name="is_active" value="1" <?php echo (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                        <span>Offer this in the customizer</span>
                    </label>

                    <label for="component-image">Photo</label>

                    <?php if ($currentImageUrl !== null): ?>
                        <!--
                            The photo it has now, shown at the size it is stored at rather
                            than as a thumbnail: "is this the right picture" is hard to
                            answer from 48 pixels. It sits above the box on purpose, so
                            choosing a file reads as replacing what is visible.
                        -->
                        <div class="image-preview">
                            <img src="<?php echo escape($currentImageUrl); ?>" alt="The photo currently on this part">
                        </div>
                    <?php endif; ?>

                    <!--
                        accept is a hint to the file picker, not a check. The real one is
                        storeUploadedImage(), which reads the bytes: accept can be worked
                        around by dragging a file in, and a filename ending .jpg says
                        nothing about what is inside it.
                    -->
                    <input id="component-image" name="image" type="file"
                           accept="image/jpeg,image/png,image/webp"
                           aria-describedby="component-image-hint">
                    <p class="field-hint" id="component-image-hint">
                        JPEG, PNG or WebP, up to <?php echo (int) (UPLOAD_MAX_BYTES / 1024 / 1024); ?> MB.
                        <?php if ($isEdit && $currentImageUrl !== null): ?>
                            Choosing one replaces the photo above. Leave this empty to keep it.
                        <?php elseif ($isEdit): ?>
                            This part has no photo yet, so the picker will fall back to the
                            builder colour above.
                        <?php else: ?>
                            Optional &mdash; the picker falls back to the builder colour
                            without one.
                        <?php endif; ?>
                    </p>

                    <?php if ($currentImageUrl !== null): ?>
                        <label class="auth-check">
                            <input type="checkbox" name="remove_image" value="1" <?php echo (int) $form['remove_image'] === 1 ? 'checked' : ''; ?>>
                            <span>Remove the photo and fall back to the colour</span>
                        </label>
                    <?php endif; ?>

                    <div class="panel-actions">
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save changes' : 'Add part'; ?></button>
                        <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/components.php')); ?>">Cancel</a>
                    </div>
                </form>
            </section>

            <?php if ($isEdit): ?>
                <section class="panel">
                    <h2>This part</h2>
                    <dl class="card-list">
                        <dt>Status</dt>
                        <dd>
                            <span class="pill <?php echo (int) $component['is_active'] === 1 ? 'pill-active' : 'pill-hidden'; ?>">
                                <?php echo escape(componentStatusLabel((int) $component['is_active'])); ?>
                            </span>
                        </dd>

                        <dt>Colour</dt>
                        <dd>
                            <span class="colour-cell">
                                <span class="colour-swatch" style="background: <?php echo escape($hexValue); ?>"></span>
                                <span class="colour-cell-text"><?php echo escape($hexValue); ?></span>
                            </span>
                        </dd>

                        <dt>Added</dt>
                        <dd>
                            <?php
                            $addedAt = strtotime((string) $component['created_at']);
                            echo escape($addedAt === false ? 'Unknown' : date('j F Y', $addedAt));
                            ?>
                        </dd>

                        <dt>Built into</dt>
                        <dd>
                            <?php if ($partsFailed): ?>
                                <span class="admin-cell-blank">Unknown &mdash; the recipes could not be read.</span>
                            <?php elseif ($builtInto === []): ?>
                                <span class="admin-cell-blank">Nothing yet</span>
                            <?php else: ?>
                                <?php foreach ($builtInto as $index => $usedBy): ?>
                                    <?php echo $index === 0 ? '' : ', '; ?>
                                    <!-- To the bouquet's recipe, which is where a part is taken
                                         out of one. That is the only way to make this part
                                         deletable, so it is the link worth having here. -->
                                    <a href="<?php echo escape(url('php/admin/product-parts.php?id=' . (int) $usedBy['id'])); ?>"><?php echo escape((string) $usedBy['name']); ?></a>
                                    &times;<?php echo (int) $usedBy['quantity']; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <?php
                    /*
                     * No "see it on the shop" row, unlike the product panel: a component has
                     * no public page. The 3D builder that will show one is the next module,
                     * and a link to nothing would be worse than the absence.
                     *
                     * A PHP comment rather than an HTML one on purpose. A note explaining why
                     * something is missing is for whoever reads this file, and an HTML comment
                     * would ship it to every admin's browser -- where it also put the words
                     * "see it on the shop" into a page that deliberately has no such link,
                     * which is exactly the sort of thing a test greps for.
                     */
                    ?>
                    <p class="card-note">
                        <?php if ($builtInto === []): ?>
                            Deleting is on the parts list, and only once a part is withdrawn.
                        <?php else: ?>
                            <!-- Not a scolding: the list simply does not draw the button, and
                                 this says why before somebody goes looking for it. -->
                            Deleting is on the parts list, but not for this part while
                            <?php echo count($builtInto); ?>
                            recipe<?php echo count($builtInto) === 1 ? '' : 's'; ?>
                            still name<?php echo count($builtInto) === 1 ? 's' : ''; ?> it.
                            Take it out of
                            <?php echo count($builtInto) === 1 ? 'that one' : 'those'; ?>
                            first, or leave it withdrawn.
                        <?php endif; ?>
                    </p>
                </section>
            <?php endif; ?>
        </div>
    </main>
<?php renderFooter(); ?>

