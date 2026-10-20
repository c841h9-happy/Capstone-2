<?php
// Must run before any HTML: this page can answer 404, which needs the headers unsent.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/products.php';
// For CART_QUANTITY_MIN and CART_QUANTITY_MAX on the add form. The navbar pulls this in as well,
// but a page that names a constant should say where it comes from rather than depend on a
// component it includes for other reasons happening to have loaded it.
require_once __DIR__ . '/config/cart.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/toast.php';
ensureSession();

/*
 * One product.
 *
 * is_numeric before the cast, because (int) turns "abc" into 0 quietly and an array
 * into a warning. fetchProduct() then treats 0 as "no such product", so a missing
 * id, a non-numeric one and a hidden row all arrive at the same 404 below.
 */
$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;

$product = null;
$loadFailed = false;

try {
    $product = fetchProduct($id);
} catch (PDOException $exception) {
    error_log('product.php: ' . $exception->getMessage());
    $loadFailed = true;
}

/*
 * Not there. A real page with a real 404 status, not a redirect to the catalogue:
 * a redirect tells the browser the request succeeded and the address bar then shows
 * a page nobody asked for, which reads as the link having worked. It also stops a
 * crawler from indexing a dead product as though it were live.
 *
 * A database failure is deliberately not a 404 -- that would claim the product does
 * not exist, when the truth is that nobody can tell right now.
 */
if ($product === null) {
    http_response_code($loadFailed ? 503 : 404);

    renderHeader($loadFailed ? 'Something went wrong' : 'Product not found');

    if ($loadFailed) {
        renderToasts([[
            'type' => 'error',
            'message' => 'Unable to load this product right now. Check your database connection.',
        ]]);
    }
    ?>
    <main class="page">
        <div class="empty-state">
            <?php if ($loadFailed): ?>
                <h1>We could not load that</h1>
                <p>The catalogue is unavailable for a moment. Try again shortly.</p>
            <?php else: ?>
                <h1>Product not found</h1>
                <p>That product does not exist, or it is no longer on sale.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse the catalogue</a></p>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

$imageUrl = productImageUrl($product);
$kindLabel = productKindLabel((string) $product['kind']);
$stemCount = $product['stem_count'] === null ? null : (int) $product['stem_count'];

renderHeader((string) $product['name']);
?>

    <main class="page">
        <p class="breadcrumb">
            <a data-product-back href="<?php echo escape(url('php/products.php')); ?>">
                <span aria-hidden="true">&lsaquo;</span> Back
            </a>
            <span aria-hidden="true">&rsaquo;</span>
            <span><?php echo escape($kindLabel); ?></span>
        </p>

        <div class="product-detail">
            <div class="product-detail-media">
                <?php if ($imageUrl !== null): ?>
                    <img src="<?php echo escape($imageUrl); ?>" alt="<?php echo escape((string) $product['name']); ?>">
                <?php else: ?>
                    <!-- Same placeholder as the catalogue tile, larger. No photographs
                         exist yet; upload belongs to the Products module. -->
                    <span class="product-card-placeholder" aria-hidden="true">
                        <svg viewBox="0 0 48 48" width="120" height="120" fill="none"
                             stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
                            <path d="M24 27 V43"/>
                            <circle cx="24" cy="11" r="6"/>
                            <circle cx="32.6" cy="17.2" r="6"/>
                            <circle cx="29.3" cy="27.3" r="6"/>
                            <circle cx="18.7" cy="27.3" r="6"/>
                            <circle cx="15.4" cy="17.2" r="6"/>
                            <circle cx="24" cy="20" r="4" fill="currentColor" stroke="none"/>
                        </svg>
                    </span>
                <?php endif; ?>
            </div>

            <div class="product-detail-body">
                <p class="product-card-kind"><?php echo escape($kindLabel); ?></p>
                <h1><?php echo escape((string) $product['name']); ?></h1>
                <?php
                /*
                 * The promo, if this product is in one. fetchProduct() has already replaced `price`
                 * with the discounted figure and left the original in `list_price`, so the line
                 * below prints `price` exactly as it always did -- see php/config/events.php.
                 *
                 * The event's name is said out loud here where the card only shows a percentage,
                 * because this is the page somebody reads before pressing Add to basket: "20% off"
                 * with no reason attached invites the question of whether it will still be 20% off
                 * tomorrow, and the dates answer it.
                 *
                 * (string) on every escape() argument -- escape() is escape(string $value), and
                 * handing it an int is a TypeError raised mid-render, which arrives as a 200 with a
                 * truncated body. This file does not declare strict_types so it would coerce
                 * today, but the cast is what stops that being luck.
                 */
                $promoPercent = (int) ($product['promo_percent'] ?? 0);
                $promoEvent = $promoPercent > 0 ? activeEvent() : null;
                ?>
                <?php if ($promoPercent > 0): ?>
                    <p class="product-detail-price product-detail-price-promo">
                        <?php echo escape(formatPrice((string) $product['price'])); ?>
                        <span class="visually-hidden">reduced from</span>
                        <s class="product-detail-was"><?php echo escape(formatPrice((string) $product['list_price'])); ?></s>
                        <span class="promo-pill"><?php echo escape((string) $promoPercent); ?>% off</span>
                    </p>
                    <?php if ($promoEvent !== null): ?>
                        <p class="product-detail-promo-note">
                            Part of <strong><?php echo escape((string) $promoEvent['name']); ?></strong>,
                            <?php echo escape(eventDateLabel($promoEvent)); ?>.
                            The price is held when you add it to your basket.
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="product-detail-price"><?php echo escape(formatPrice((string) $product['price'])); ?></p>
                <?php endif; ?>

                <p class="product-detail-description"><?php echo escape((string) $product['description']); ?></p>

                <dl class="product-specs">
                    <?php if (trim((string) $product['colour']) !== ''): ?>
                        <dt>Colour</dt>
                        <dd><?php echo escape((string) $product['colour']); ?></dd>
                    <?php endif; ?>

                    <dt>Made from</dt>
                    <dd>Fuzzy wire and chenille &mdash; it will not wilt</dd>

                    <?php if ($stemCount !== null): ?>
                        <dt>Flowers included</dt>
                        <dd><?php echo $stemCount; ?></dd>
                    <?php endif; ?>
                </dl>

                <?php
                /*
                 * Adding needs no account, the decision php/cart-update.php records: a basket is
                 * filled by a visitor and an account is asked for at checkout.
                 *
                 * back is this product page, so adding leaves you here -- somebody looking at one
                 * bouquet is often about to look at the one next to it, and being thrown into the
                 * basket after every add is how a shop makes you browse twice.
                 */
                ?>
                <div class="product-actions">
                    <form class="product-add" method="post" action="<?php echo escape(url('php/cart-update.php')); ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add-product">
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                        <input type="hidden" name="back" value="<?php echo escape(url('php/product.php') . '?id=' . (int) $product['id']); ?>">

                        <label class="visually-hidden" for="product-quantity">How many</label>
                        <input id="product-quantity" name="quantity" type="number" value="1"
                               min="<?php echo CART_QUANTITY_MIN; ?>" max="<?php echo CART_QUANTITY_MAX; ?>"
                               step="1" inputmode="numeric">

                        <button type="submit" class="btn btn-primary">Add to basket</button>
                    </form>

                    <p class="product-actions-note">
                        <a href="<?php echo escape(url('php/cart.php')); ?>">Your basket</a>
                        &mdash; checkout when you&rsquo;re ready.
                    </p>
                </div>

                <p class="product-detail-back">
                    <a href="<?php echo escape(url('php/customize.php')); ?>">Or build your own bouquet &rarr;</a>
                </p>
            </div>
        </div>
    </main>
<?php renderFooter(); ?>
