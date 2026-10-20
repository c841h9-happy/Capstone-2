<?php
// Must run before any HTML: the navbar needs the session, and a save POST redirects.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/designs.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

/*
 * Build your own bouqwuet.
 *
 * Public to read, and deliberately so -- the same argument php/products.php:13 makes for the
 * catalogue. Playing with the builder is how somebody decides to make an account. Saving is
 * the one thing here that needs a login, and it asks for one at the moment it is used rather
 * than at the door.
 *
 * The design is the query string --
 * ?mix[1]=4&mix[5]=2&wire=8&wrap=11&accent=14&arrangement=dome -- which is what makes the
 * picker below a real GET form: with no JavaScript the Update button applies a choice
 * server-side and the total is still right, and with JavaScript the form never submits at
 * all. It also means a bouquet is shareable as a link, and ?design=N opens a saved one.
 *
 * Every input is treated as hostile and none of them can produce an error page.
 * readDesignSelection() falls back to the first part of a kind for an id that is missing,
 * junk, withdrawn since the link was shared, or real but of the wrong kind; readBloomMix()
 * drops an unknown bloom rather than substituting one, and clamps the mix to CUSTOM_MIX_MAX
 * entries and CUSTOM_BLOOM_MAX stems. See php/config/customizer.php for why falling back
 * beats refusing on a URL people edit by hand.
 *
 * The one POST is the save, and it is a POST because it writes. It redirects afterwards
 * rather than rendering, so a refresh does not offer to save the same bouquet again.
 */
$parts = [];
$loadFailed = false;

try {
    $parts = fetchComponentsByKind();
} catch (PDOException $exception) {
    error_log('customize.php: ' . $exception->getMessage());
    $loadFailed = true;
}

/*
 * A real page with a real status code, exactly as php/product.php does it.
 *
 * 503 and not a blank stage: the builder cannot price a bouquet it could not read the
 * parts for, and an empty canvas with a working Update button would look like a broken
 * renderer rather than a database that is down.
 */
if ($loadFailed) {
    http_response_code(503);

    renderHeader('Something went wrong');
    ?>
    <main class="page">
        <div class="empty-state">
            <h1>The designer is unavailable</h1>
            <p>
                We could not load the parts to build with. Nothing has been lost &mdash;
                try again in a moment.
            </p>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse ready-made bouquets</a></p>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

/*
 * ?design=N opens a saved bouquet, and only its owner's.
 *
 * Read before the query string is, and it *replaces* it rather than merging: a saved design is
 * a whole bouquet, and half of one plus half a URL is a design nobody built. A design that is
 * not this account's, or does not exist, falls through to the query string with a note -- the
 * two are one answer, from readSavedDesign(), and neither is worth an error page.
 *
 * The name is kept separately so the save box can be pre-filled with it: opening a saved
 * bouquet, changing the ribbon and pressing Save should update that bouquet, which is exactly
 * what saveDesign() does with a name it already has.
 */
$design = null;
$designName = '';
$openedId = 0;
$openFailed = false;

$wantedDesign = filter_var($_GET['design'] ?? null, FILTER_VALIDATE_INT);

if ($wantedDesign !== false && $wantedDesign > 0 && isUserLoggedIn()) {
    try {
        $saved = fetchSavedDesign((int) $_SESSION['user_id'], $wantedDesign);

        if ($saved !== null) {
            $design = readSavedDesign((int) $_SESSION['user_id'], $wantedDesign);
            $designName = (string) $saved['name'];
            $openedId = $wantedDesign;
        } else {
            $openFailed = true;
        }
    } catch (PDOException $exception) {
        error_log('customize.php (design): ' . $exception->getMessage());
        $openFailed = true;
    }
}

/*
 * A saved design whose every bloom has been withdrawn comes back with an empty mix. Falling
 * back to the query string reading -- which is itself a fallback to the first offered bloom --
 * means the page draws what is on offer today instead of nothing at all.
 */
if ($design === null || ($design['blooms'] === [] && $parts['bloom'] !== [])) {
    $design = readDesign($_GET, $parts);
}

$summary = designSummary($design);
$total = designTotal($design);
$sentence = designSentence($design);
$count = designCount($design);

/*
 * True when there is nothing to build with at all.
 *
 * Possible without anything being broken: an admin may withdraw every part. The page then
 * says so instead of drawing an empty stage and charging nothing for it.
 */
$hasAnything = $summary !== [];

/*
 * The quantity every bloom's box should show: what is in the mix, or 0 for one that is not.
 *
 * 0 rather than blank, so the boxes are a complete statement of the bouquet -- "none of this
 * one" is an answer, and a blank box next to a filled one reads as a field somebody forgot.
 */
$quantities = [];

foreach ($parts['bloom'] as $bloom) {
    $quantities[(int) $bloom['id']] = 0;
}

foreach ($design['blooms'] as $entry) {
    $quantities[(int) $entry['part']['id']] = (int) $entry['quantity'];
}

$toasts = takeFlashToasts();

if ($openFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'We could not find that saved bouquet. Here is a fresh one to start from.',
    ];
}

renderHeader('Create your own', ['js/customizer.js']);
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Make it yours</p>
            <h1>Build your own bouquet</h1>
            <p class="page-intro">
                Pick your flowers &mdash; as many kinds as you like, up to
                <?php echo escape((string) CUSTOM_MIX_MAX); ?> &mdash; then the stem, the paper,
                any foliage, and something to tie it with. Every piece is shaped by hand from fuzzy wire, so
                what you build will not wilt.
            </p>
        </header>
<?php if (!$hasAnything): ?>
        <div class="empty-state">
            <h1>Nothing to build with just yet</h1>
            <p>The parts list is empty at the moment. Our ready-made bouquets are still here.</p>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse bouquets</a></p>
        </div>
<?php else: ?>
        <!--
            The parts list reaches JavaScript as JSON in an attribute, not an inline script.
            escape() is htmlspecialchars(ENT_QUOTES), which makes an attribute safe with no
            further thought, and a part described as "</script>" cannot end the document
            from inside one. See designPayload().
        -->
        <div class="customizer" data-customizer="<?php echo escape(designPayload($parts, $design)); ?>">
            <div class="customizer-main">
                <div class="customizer-stage">
                    <!--
                        Hidden until the renderer says it works, and the summary below it
                        starts visible. That way the page degrades in both directions
                        without CSS having to guess: with no JavaScript nothing unhides the
                        canvas, and with no WebGL the script leaves it hidden. A blank
                        rectangle is never what a visitor is left with.
                    -->
                    <canvas class="customizer-canvas" data-customizer-stage
                            width="960" height="720" role="img"
                            aria-label="<?php echo escape($sentence); ?>" hidden></canvas>

                    <div class="customizer-static" data-customizer-static>
                        <p class="customizer-static-lead"><?php echo escape($sentence); ?></p>

                        <ul class="customizer-static-list">
                            <?php foreach ($summary as $line): ?>
                                <li>
                                    <span class="colour-swatch" style="background: <?php echo escape((string) $line['colourHex']); ?>"></span>
                                    <span class="customizer-static-kind"><?php echo escape((string) $line['kindLabel']); ?></span>
                                    <span class="customizer-static-name">
                                        <?php if ($line['perStem']): ?><?php echo escape((string) $line['quantity']); ?> &times; <?php endif; ?>
                                        <?php echo escape((string) $line['name']); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <p class="customizer-static-note">
                            The three-dimensional preview needs JavaScript and WebGL. Everything
                            else on this page &mdash; the choices and the price &mdash; works
                            without either.
                        </p>
                    </div>
                </div>

                <!--
                    The figure and the breakdown both come from designSummary(), so a line
                    and the sum under it cannot disagree. data-customizer-total marks the
                    whole block for the script to rewrite while somebody clicks; what the
                    page ships with is the server's arithmetic either way.
                -->
                <div class="customizer-total" data-customizer-total>
                    <h2 class="customizer-total-head">What it comes to</h2>

                    <ul class="customizer-breakdown" data-customizer-breakdown>
                        <?php foreach ($summary as $line): ?>
                            <li>
                                <span class="customizer-breakdown-what">
                                    <?php if ($line['perStem']): ?>
                                        <?php echo escape((string) $line['quantity']); ?> &times;
                                    <?php endif; ?>
                                    <?php echo escape((string) $line['name']); ?>
                                </span>
                                <?php
                                /*
                                 * The unit price only where it tells you something. A wrap
                                 * and an accent are bought once, so "P60.00 each" beside
                                 * "P60.00" is the same number twice.
                                 *
                                 * A PHP comment: this is inside the loop, and an HTML one
                                 * would be sent to the browser once per line.
                                 */
                                ?>
                                <?php if ($line['perStem']): ?>
                                    <span class="customizer-breakdown-each"><?php echo escape(formatPrice((string) $line['each'])); ?> each</span>
                                <?php else: ?>
                                    <span class="customizer-breakdown-each">just the one</span>
                                <?php endif; ?>
                                <span class="customizer-breakdown-line"><?php echo escape(formatPrice((string) $line['lineTotal'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="customizer-total-figure">
                        <span class="customizer-total-label">Total</span>
                        <span class="customizer-total-amount" data-customizer-amount><?php echo escape(formatPrice($total)); ?></span>
                    </p>

                    <p class="customizer-total-count" data-customizer-count-read>
                        <?php echo escape((string) $count); ?>
                        <?php echo $count === 1 ? 'flower' : 'flowers'; ?>,
                        <?php echo escape(strtolower(designArrangementLabel((string) $design['arrangement']))); ?>.
                    </p>

                    <?php
                    /*
                     * Into the basket, no account needed -- the decision php/cart-update.php
                     * records. Saving below needs one; adding does not, and the two sit next to
                     * each other here precisely because they are different things: one keeps a
                     * bouquet to come back to, the other buys it.
                     *
                     * The design travels as a query string in a hidden field, exactly as the save
                     * form beneath does it, and the script keeps that field in step. So what goes
                     * in the basket is the bouquet whose price is printed above the button, not
                     * whatever the page happened to open with. cart-update.php still puts it
                     * through readDesign(), so a hand-edited field cannot buy a bouquet the
                     * builder would refuse to draw.
                     */
                    ?>
                    <form class="customizer-add" method="post"
                          action="<?php echo escape(url('php/cart-update.php')); ?>"
                          data-customizer-add>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add-design">
                        <input type="hidden" name="back" value="<?php echo escape(url('php/cart.php')); ?>">
                        <input type="hidden" name="design" value="<?php echo escape(designQuery($design)); ?>"
                               data-customizer-add-design>

                        <button type="submit" class="btn btn-primary">Add to basket</button>
                    </form>

                    <p class="customizer-total-note">
                        <a href="<?php echo escape(url('php/cart.php')); ?>">Your basket</a>
                        &mdash; checkout when you&rsquo;re ready.
                    </p>

                    <?php
                    /*
                     * Saving is the one thing on this page that writes, so it is the one thing
                     * that needs an account. Asked for here, at the moment it is used, rather
                     * than at the door -- the builder itself is public on purpose.
                     *
                     * A POST, because it writes, and the design travels in hidden fields
                     * rather than being re-read from the query string: what gets saved is then
                     * exactly the bouquet whose price is printed above it, including on the
                     * enhanced path where the script has been rewriting these fields as
                     * somebody clicks.
                     */
                    ?>
                    <?php if (isUserLoggedIn()): ?>
                        <form class="customizer-save" method="post"
                              action="<?php echo escape(url('php/design-save.php')); ?>"
                              data-customizer-save>
                            <?php echo csrfField(); ?>
                            <h3 class="customizer-save-head">Keep this bouquet</h3>

                            <label for="customizer-name">Call it something</label>
                            <input type="text" id="customizer-name" name="name"
                                   value="<?php echo escape($designName); ?>"
                                   maxlength="<?php echo escape((string) SAVED_DESIGN_NAME_MAX); ?>"
                                   placeholder="Mum's birthday" required
                                   aria-describedby="customizer-name-hint">
                            <p class="field-hint" id="customizer-name-hint">
                                Saving under a name you have used before replaces that bouquet.
                            </p>

                            <!--
                                The design as it stands, written into the form. The script
                                keeps this one field in step, so the enhanced path saves what
                                is on the stage rather than what the page opened with.
                            -->
                            <input type="hidden" name="design" value="<?php echo escape(designQuery($design)); ?>"
                                   data-customizer-save-design>

                            <button type="submit" class="btn btn-primary">Save to my account</button>

                            <p class="customizer-total-note">
                                <a href="<?php echo escape(url('php/designs.php')); ?>">Your saved bouquets</a>
                            </p>
                        </form>
                    <?php else: ?>
                        <p class="customizer-total-note">
                            <a href="<?php echo escape(url('php/login.php')); ?>">Sign in</a> to keep a
                            bouquet in your account. You do not need one to build or share it.
                        </p>
                    <?php endif; ?>

                    <!--
                        The address bar is the other save button, which is the whole reason
                        the design lives in the query string. designQuery() rather than the
                        URL as typed, so the link is the tidy version: every chosen part
                        named once and nothing that was ignored.
                    -->
                    <p class="customizer-total-note">
                        This bouquet
                        <a data-customizer-share href="?<?php echo escape(designQuery($design)); ?>">has its own link</a>
                        &mdash; bookmark it or send it to somebody.
                    </p>
                </div>
            </div>

            <!--
                A real GET form, the same shape .catalogue-filters uses on
                php/products.php:102. With no JavaScript the Update button applies a choice
                server-side and everything above is redrawn correctly; with JavaScript the
                submit is cancelled and nothing round-trips. Either way the design ends up
                in the address bar, which is what makes it shareable.

                Radios rather than a <select> per kind: each option has to show a colour, a
                name and a price, and a <select> can only show text. The blooms are number
                boxes instead of radios, because a bouquet may hold several kinds at once and
                a radio group is the one control that cannot say that.
            -->
            <form class="customizer-picker" method="get" action="<?php echo escape(url('php/customize.php')); ?>" data-customizer-form>
                <?php if ($parts['bloom'] !== []): ?>
                    <fieldset class="customizer-group">
                        <legend><?php echo escape(componentKindLabel('bloom')); ?></legend>
                        <p class="field-hint">
                            How many of each. Up to <?php echo escape((string) CUSTOM_MIX_MAX); ?>
                            kinds and <?php echo escape((string) CUSTOM_BLOOM_MAX); ?> flowers
                            in one bouquet.
                        </p>

                        <div class="customizer-mix">
                            <?php foreach ($parts['bloom'] as $bloom): ?>
                                <?php
                                $bloomId = (int) $bloom['id'];
                                $fieldId = 'customizer-mix-' . $bloomId;
                                ?>
                                <div class="customizer-mix-row<?php echo $quantities[$bloomId] > 0 ? ' is-chosen' : ''; ?>">
                                    <span class="colour-swatch" style="background: <?php echo escape(componentColourHex($bloom)); ?>"></span>
                                    <label class="customizer-mix-name" for="<?php echo escape($fieldId); ?>">
                                        <?php echo escape((string) $bloom['name']); ?>
                                    </label>
                                    <span class="customizer-mix-price"><?php echo escape(formatPrice((string) $bloom['price'])); ?> each</span>
                                    <!--
                                        min="0", not min="1": zero is how a bloom is taken out
                                        of the bouquet, and a control that can add but not
                                        remove is half a control. bloomMixWanted() drops a
                                        quantity below 1, so a zero simply does not arrive.
                                    -->
                                    <input class="customizer-mix-qty" type="number"
                                           id="<?php echo escape($fieldId); ?>"
                                           name="mix[<?php echo $bloomId; ?>]"
                                           value="<?php echo escape((string) $quantities[$bloomId]); ?>"
                                           min="0" max="<?php echo escape((string) CUSTOM_BLOOM_MAX); ?>"
                                           step="1" inputmode="numeric">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <?php foreach (['wire', 'wrap', 'foliage', 'accent'] as $kind): ?>
                    <?php
                    $available = $parts[$kind] ?? [];

                    /*
                     * A kind with nothing offered is left out entirely rather than
                     * rendered as an empty fieldset. An admin may withdraw every accent,
                     * and a legend over a blank box reads as a page that failed to load.
                     *
                     * The design already has that kind as null, so the stage and the total
                     * agree with the picker without being told.
                     */
                    if ($available === []) {
                        continue;
                    }

                    $chosenId = $design[$kind] === null ? 0 : (int) $design[$kind]['id'];
                    $perStem = designKindIsPerStem($kind);
                    ?>
                    <fieldset class="customizer-group">
                        <legend><?php echo escape(componentKindLabel($kind)); ?></legend>

                        <div class="customizer-options">
                            <?php foreach ($available as $part): ?>
                                <?php $partId = (int) $part['id']; ?>
                                <label class="customizer-option">
                                    <input type="radio" name="<?php echo escape($kind); ?>"
                                           value="<?php echo $partId; ?>"
                                           <?php echo $partId === $chosenId ? 'checked' : ''; ?>>
                                    <span class="colour-swatch" style="background: <?php echo escape(componentColourHex($part)); ?>"></span>
                                    <span class="customizer-option-name"><?php echo escape((string) $part['name']); ?></span>
                                    <span class="customizer-option-price">
                                        <?php echo escape(formatPrice((string) $part['price'])); ?><?php echo $perStem ? ' each' : ''; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <?php
                /*
                 * How the stems sit. No swatch and no price, because it is neither a part nor
                 * a cost -- it changes the picture and nothing else, which is what the hint
                 * under each one says in words a florist would use.
                 */
                ?>
                <fieldset class="customizer-group">
                    <legend>Arrangement</legend>

                    <div class="customizer-options customizer-options-plain">
                        <?php foreach (DESIGN_ARRANGEMENTS as $arrangement): ?>
                            <label class="customizer-option">
                                <input type="radio" name="arrangement"
                                       value="<?php echo escape($arrangement); ?>"
                                       <?php echo $arrangement === $design['arrangement'] ? 'checked' : ''; ?>>
                                <span class="customizer-option-name"><?php echo escape(designArrangementLabel($arrangement)); ?></span>
                                <span class="customizer-option-hint"><?php echo escape(DESIGN_ARRANGEMENT_HINTS[$arrangement]); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <!--
                    Not hidden when JavaScript is on. The script cancels the submit, so the
                    button is harmless there, and a control that vanishes the moment the
                    page finishes loading is worse than one that stays and agrees with
                    what is already on screen.
                -->
                <button type="submit" class="btn btn-primary">Update the bouquet</button>
            </form>

            <form class="customizer-add customizer-add-bottom" method="post"
                  action="<?php echo escape(url('php/cart-update.php')); ?>"
                  data-customizer-add>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add-design">
                <input type="hidden" name="back" value="<?php echo escape(url('php/cart.php')); ?>">
                <input type="hidden" name="design" value="<?php echo escape(designQuery($design)); ?>"
                       data-customizer-add-design>
                <button type="submit" class="btn btn-primary">Add to basket</button>
            </form>

            <?php
            /*
             * The bar that sticks to the bottom of a phone screen.
             *
             * On a phone this page is about three and a half screens tall and the order is stage,
             * then "What it comes to", then the pickers -- so the moment somebody starts choosing
             * flowers the price scrolls out of sight, and the thing they are adjusting it with is
             * the thing they can no longer see. This keeps the running total and the button on
             * screen while they work. Hidden entirely above the width where the layout goes
             * two-column, because there the total is already beside the pickers.
             *
             * A REAL FORM, not a button that scripts a click on another one, so it works with
             * JavaScript off exactly like the two add forms above it -- this page is a plain GET
             * form by design and every POST on it is a plain submit.
             *
             * THIS IS THE SECOND [data-customizer-amount] ON THE PAGE, which is why
             * js/customizer.js was changed to write every one of them rather than
             * the first: a stale figure next to a live Add to basket button is worse than no
             * figure. The number carries aria-hidden because it is a visual repeat of the panel's
             * own total -- the button beside it is a real control and is not hidden.
             */
            ?>
            <div class="customizer-sticky">
                <p class="customizer-sticky-total" aria-hidden="true">
                    <span class="customizer-sticky-label">Total</span>
                    <span class="customizer-sticky-amount" data-customizer-amount><?php echo escape(formatPrice($total)); ?></span>
                </p>

                <form class="customizer-sticky-add" method="post"
                      action="<?php echo escape(url('php/cart-update.php')); ?>"
                      data-customizer-add>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add-design">
                    <input type="hidden" name="back" value="<?php echo escape(url('php/cart.php')); ?>">
                    <input type="hidden" name="design" value="<?php echo escape(designQuery($design)); ?>"
                           data-customizer-add-design>
                    <button type="submit" class="btn btn-primary">Add to basket</button>
                </form>
            </div>
        </div>
<?php endif; ?>
    </main>
<?php renderFooter(); ?>
