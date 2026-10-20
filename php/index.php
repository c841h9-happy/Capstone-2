<?php
// Must run before any HTML so the navbar can tell whether you are signed in.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/products.php';
require_once __DIR__ . '/config/events.php';
require_once __DIR__ . '/components/header.php';
require_once __DIR__ . '/components/footer.php';
require_once __DIR__ . '/components/product-card.php';
require_once __DIR__ . '/components/toast.php';
require_once __DIR__ . '/config/flash.php';
ensureSession();

/*
 * The home page.
 *
 * The featured row is sorted by price, highest first, so the showcase bouquets are
 * what greets somebody rather than four single flowers at fifty pesos -- 'newest'
 * would be arbitrary here, because the seeded rows all share a created_at.
 *
 * A database problem leaves the row out and the rest of the page standing. The home
 * page is the one page that should never be a stack trace, and it has nothing on it
 * that a visitor was promised, so there is nothing to apologise for -- no toast.
 */
$featured = [];

try {
    $featured = fetchProducts(['sort' => 'price-high'], 4);
} catch (PDOException $exception) {
    error_log('index.php: ' . $exception->getMessage());
}

/*
 * The event that is on, if any, and the one flower it showcases.
 *
 * Its own try, separate from the featured row above, so a problem reading the events tables costs
 * the banner and not the catalogue row -- the two are independent and failing together would be a
 * choice nobody made. activeEvent() already swallows a missing table (the migration not having been
 * run must not blank the shop), so this catch is for the rest: a connection lost between the two
 * calls, a permissions change mid-request.
 *
 * The featured product goes through fetchProduct(), which filters on is_active -- so a bouquet
 * withdrawn since the event was written simply stops appearing, rather than the home page
 * advertising something the catalogue will not sell.
 */
$event = null;
$eventFlower = null;

try {
    $event = activeEvent();

    if ($event !== null) {
        $eventFlower = eventFeaturedProduct();
    }
} catch (PDOException $exception) {
    error_log('index.php events: ' . $exception->getMessage());
    $event = null;
    $eventFlower = null;
}

/*
 * Anything another page flashed on its way here.
 *
 * The home page is where several refusals land -- a logout whose token had expired, a saved
 * bouquet somebody tried to order while signed out -- and until now it was the one common
 * destination that read no messages, so those sentences were swallowed and then turned up
 * unannounced on whatever page the visitor opened next. Taken before renderHeader(), like every
 * other page that shows them, because taking a toast is a session write.
 */
$toasts = takeFlashToasts();

renderHeader('Home');
renderToasts($toasts);
?>

    <main class="home-page">
        <section class="home-hero-band">
            <div class="hero">
                <div class="hero-copy">
                    <p class="page-eyebrow">Handmade, and it keeps</p>
                    <h1>Flowers twisted from wire, not picked</h1>
                    <p class="hero-intro">
                        Every stem here is shaped by hand from fuzzy wire and chenille, so a
                        bouquet looks the same in a year as it does on the day it arrives.
                        Take one as it comes, buy single flowers to arrange yourself, or build
                        your own from scratch.
                    </p>
                    <p class="hero-actions">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">Browse the catalogue</a>
                        <a class="btn btn-quiet" href="<?php echo escape(url('php/customize.php')); ?>">Build your own</a>
                    </p>
                </div>
                <div class="hero-art">
                    <img src="<?php echo escape(asset('images/Logo.jpg')); ?>" alt="" width="390" height="390">
                </div>
            </div>
        </section>

        <?php if ($event !== null): ?>
            <?php
            /*
             * The event banner.
             *
             * Drawn whenever an event is on, with or without a featured flower and with or without a
             * discount -- an occasion the shop wants named is worth naming even if it is selling
             * nothing differently that week. The three optional parts each stand on their own.
             */
            $endsSoon = eventEndsSoonLabel($event);
            $eventPercent = (int) $event['discount_percent'];
            ?>
            <section class="event-band">
                <div class="event-banner">
                    <div class="event-banner-copy">
                        <p class="event-banner-eyebrow">
                            <span class="event-banner-dates"><?php echo escape(eventDateLabel($event)); ?></span>
                            <?php if ($endsSoon !== ''): ?>
                                <span class="event-banner-urgent"><?php echo escape($endsSoon); ?></span>
                            <?php endif; ?>
                        </p>
                        <h2><?php echo escape((string) $event['name']); ?></h2>
                        <?php if (trim((string) $event['tagline']) !== ''): ?>
                            <p class="event-banner-tagline"><?php echo escape((string) $event['tagline']); ?></p>
                        <?php endif; ?>
                        <?php if ($eventPercent > 0): ?>
                            <p class="event-banner-offer"><?php echo escape((string) $eventPercent); ?>% off selected flowers</p>
                        <?php endif; ?>
                        <p class="event-banner-actions">
                            <a class="btn btn-primary" href="<?php echo escape(url('php/products.php')); ?>">See what is on</a>
                        </p>
                    </div>

                    <?php if ($eventFlower !== null): ?>
                        <?php
                        /*
                         * The featured flower, as the same card the catalogue uses rather than as a
                         * hand-written tile. That is the rule product-card.php's own docblock sets
                         * out -- a second, slightly different card is how two pages end up
                         * disagreeing about what something costs -- and it matters more here than
                         * anywhere, because this card sits directly above a row of the other kind.
                         */
                        ?>
                        <div class="event-banner-flower">
                            <p class="event-banner-flower-label">This <?php echo escape((string) $event['name']); ?>, we are making</p>
                            <?php renderProductCard($eventFlower); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($featured !== []): ?>
            <section class="home-featured">
                <header class="section-head">
                    <h2>Our bigger bouquets</h2>
                    <div class="home-featured-links">
                        <a href="<?php echo escape(url('php/products.php?kind=flower')); ?>">Shop single flowers</a>
                        <a href="<?php echo escape(url('php/products.php?kind=bouquet')); ?>">See all bouquets &rarr;</a>
                    </div>
                </header>

                <div class="product-grid">
                    <?php foreach ($featured as $product): ?>
                        <?php renderProductCard($product); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="home-points-band">
            <div class="home-points">
                <div class="home-point">
                    <h2>Nothing wilts</h2>
                    <p>Wire and yarn, not stems. No water, no dropping petals, no week-long shelf life.</p>
                </div>
                <div class="home-point">
                    <h2>Made one at a time</h2>
                    <p>Each flower is wound by hand, so no two bouquets come out quite identical.</p>
                </div>
                <div class="home-point">
                    <h2>Yours to arrange</h2>
                    <p>Buy single flowers and put them together yourself, or design a bouquet on screen first.</p>
                </div>
            </div>
        </section>
    </main>
<?php renderFooter(); ?>
