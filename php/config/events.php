<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Events and promos, customer-facing reads.
 *
 * An event is a dated occasion -- Valentine's, Mother's Day, a fiesta -- with one featured product
 * and a whole-percent discount covering a hand-picked set of products.
 *
 * THIS FILE IS THE READ HALF. Writes live in php/config/events-admin.php, the same split
 * products.php / products-admin.php and components.php / components-admin.php use, and for the same
 * reason: a customer-facing read must never see a draft or a finished event, and keeping the two
 * apart means the filter that enforces it cannot be forgotten by a caller.
 *
 * WHERE THE DISCOUNT IS APPLIED, because it is the one decision the whole feature rests on.
 * Commerce freezes a basket line's price when it is added -- php/config/cart.php binds
 * products.price straight into cart_items.unit_price -- and placeOrder() copies that frozen figure
 * into order_items without re-reading anything. So the discount is applied in ONE place:
 * decorateProductPricing(), called by fetchProduct() and fetchProducts() in php/config/products.php,
 * which are the only customer-facing readers of products.price.
 *
 * Everything downstream then inherits it for free, including the freeze:
 *
 *   catalogue grid   -> fetchProducts()  -> discounted
 *   product page     -> fetchProduct()   -> discounted
 *   add to basket    -> fetchProduct()   -> the DISCOUNTED price is what gets frozen
 *   basket, checkout, order, receipt, reports -> read the frozen figure, unchanged code
 *
 * Nothing in cart.php, checkout.php, orders.php, orders-admin.php or admin.php changes, and there
 * is no discount column on orders: orders.total stays exactly SUM(order_items.line_total), which is
 * what every report in php/config/admin.php assumes.
 *
 * THE CONSEQUENCE, STATED RATHER THAN DISCOVERED: a price frozen during a promo stays frozen. Add a
 * bouquet on 14 February at 20% off, check out in March, and you pay the February price. That is
 * not a hole to plug -- it is the freeze rule working as designed, and the alternative is
 * re-pricing somebody's basket behind their back, which this project refuses to do anywhere else.
 *
 * What this does NOT discount: a custom bouquet. Those price from product_components through
 * designSnapshot() in php/config/customizer.php, which this file never touches.
 */

/**
 * Whole percent is the only shape a promo takes here.
 *
 * Capped at 90 in PHP and by chk_events_percent in SQL. 0 is allowed and is meaningful: an event
 * that features a flower and discounts nothing at all.
 */
const EVENT_PERCENT_MAX = 90;

const EVENT_NAME_MAX = 80;
const EVENT_TAGLINE_MAX = 160;

/**
 * A counter the per-request caches compare themselves against.
 *
 * This exists so the test suite can create an event, read prices, end the event and read again
 * inside one PHP process. A `static` inside activeEvent() cannot be reached from outside, and
 * passing a "$fresh = true" flag down through decorateProductPricing() to every caller would put a
 * testing concern in the signature of the function the whole catalogue uses.
 *
 * Bumping this invalidates every cache below at once, which is the point: the event and its product
 * list must never come from two different generations.
 */
function eventCacheGeneration(bool $bump = false): int
{
    static $generation = 0;

    if ($bump) {
        $generation++;
    }

    return $generation;
}

/**
 * Drop the per-request event caches.
 *
 * For php/tools/events-http-test.php. Nothing in the site calls this: one request must never see two
 * different events, which is what the caches are for.
 */
function forgetActiveEvent(): void
{
    eventCacheGeneration(true);
}

/**
 * The event that is on today, or null.
 *
 * "On" is two separate things and they are deliberately not one column. `is_active` is publication
 * -- whether the shop considers this event to exist -- so one can be drafted weeks ahead or pulled
 * mid-run without editing its dates and destroying the record of when it was meant to run. The
 * dates are the schedule. Both have to agree.
 *
 * CURDATE() and not PHP's date(): the comparison is made by the same server that stores the DATE
 * columns, so a PHP timezone disagreeing with MariaDB's cannot make the banner and the discount
 * switch on an hour apart. php/config/admin.php's seven-day chart made the opposite call -- it
 * computes its dates in PHP -- because there the cards, the bars and the labels all have to agree
 * with each other and one source beats two. Here the only thing to agree with is the column.
 *
 * WHEN SEVERAL EVENTS QUALIFY, the one that STARTED MOST RECENTLY wins, ties broken by the higher
 * id. Overlap is not worth refusing -- a shop may well run a fiesta offer across the end of a
 * Valentine's one -- but two active promos silently fighting over a price is. "The one that started
 * last" is the rule a person would guess, and it means starting a new event always takes precedence
 * over one already running.
 *
 * Cached per request, so the catalogue's one call and the home page's two are one query, and so the
 * banner and the prices on a page can never come from different events.
 *
 * A MISSING TABLE IS NOT A FATAL. If sql/events-upgrade.sql has not been run, this returns null and
 * the whole site behaves exactly as it did before promos existed -- flat prices, no banner. That is
 * the same discipline php/config/admin.php's dashboard follows and for a sharper reason: this
 * function is now on the read path of the PUBLIC CATALOGUE and the home page, so an unguarded query
 * here would turn a missing migration into a blank shop rather than into a missing feature.
 * PDOException only -- a bug in this file should still be loud.
 */
function activeEvent(): ?array
{
    static $event = null;
    static $generation = -1;

    if ($generation === eventCacheGeneration()) {
        return $event;
    }

    $generation = eventCacheGeneration();
    $event = null;

    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT id, name, tagline, starts_on, ends_on, featured_product_id, discount_percent
               FROM events
              WHERE is_active = 1
                AND starts_on <= CURDATE()
                AND ends_on >= CURDATE()
              ORDER BY starts_on DESC, id DESC
              LIMIT 1'
        );
        $statement->execute();

        $row = $statement->fetch();
        $event = $row === false ? null : $row;
    } catch (PDOException $exception) {
        error_log('events.php: no events table? ' . $exception->getMessage());
        $event = null;
    }

    return $event;
}

/**
 * The ids of every product the current promo covers, as a set.
 *
 * [id => true] rather than a list, because every caller asks "is this one in it?" once per row of a
 * page and in_array() over a list would make that a scan per product.
 *
 * One query per request, invalidated by the same generation counter as activeEvent() -- the two must
 * never disagree about which event is on. An event discounting 0% still reports its members;
 * whether 0% is worth a badge is the badge's decision, not this function's.
 */
function eventProductIds(): array
{
    static $ids = [];
    static $generation = -1;

    if ($generation === eventCacheGeneration()) {
        return $ids;
    }

    $generation = eventCacheGeneration();
    $event = activeEvent();

    if ($event === null) {
        $ids = [];

        return $ids;
    }

    $ids = [];

    // Guarded for the same reason activeEvent() is: this runs on the public catalogue, and a
    // half-applied migration should cost the promo, not the shop.
    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT product_id FROM event_products WHERE event_id = :event'
        );
        $statement->execute(['event' => (int) $event['id']]);

        foreach ($statement->fetchAll() as $row) {
            $ids[(int) $row['product_id']] = true;
        }
    } catch (PDOException $exception) {
        error_log('events.php: no event_products table? ' . $exception->getMessage());
        $ids = [];
    }

    return $ids;
}

/**
 * A price with a whole-percent discount taken off it, as a string with two decimals.
 *
 * COMPUTED IN CENTAVOS, never in floats, which is the same discipline every other price in this
 * project follows: prices are DECIMAL(10,2) in the column and strings in PHP, and 0.1 + 0.2 is the
 * reason. The arithmetic here is integer throughout -- centavos in, centavos out -- so the figure
 * the catalogue prints, the figure the badge computes against, and the figure frozen into
 * cart_items.unit_price are bit-identical rather than merely close.
 *
 * Rounding is half-up on the centavo, which is the direction that favours the shop by at most one
 * centavo and is what a till does. 15% off ₱38.00 is 3800 * 85 / 100 = 3230 exactly; 15% off
 * ₱38.05 is 3805 * 85 = 323425, / 100 = 3234.25, rounded to 3234 -- ₱32.34.
 *
 * A percent of 0 returns the price unchanged, and so does anything out of range: this function
 * never invents a discount, which matters because it is reachable from a row somebody edited in the
 * MySQL shell.
 */
function eventPromoPrice(string $price, int $percent): string
{
    if ($percent <= 0 || $percent > EVENT_PERCENT_MAX) {
        return $price;
    }

    // (float) only to reach centavos, and only ever multiplied by 100 -- the value is an integer
    // from here on. round() rather than (int) because (int) truncates and 3799.9999 is a real
    // artefact of decimal-to-binary on a two-decimal string.
    $centavos = (int) round(((float) $price) * 100);

    if ($centavos <= 0) {
        return $price;
    }

    $discounted = (int) round(($centavos * (100 - $percent)) / 100);

    return number_format($discounted / 100, 2, '.', '');
}

/**
 * One product row, with promo fields added when the current event covers it.
 *
 * Adds three keys and leaves everything else alone:
 *
 *   list_price    -- what it costs normally, always present
 *   promo_percent -- the whole percent off, 0 when there is no promo on this product
 *   price         -- REPLACED with the promo price when there is one
 *
 * `price` is overwritten rather than supplemented, and that is the decision that makes this feature
 * two files instead of twenty. Every existing consumer of a product row -- the card, the product
 * page, addProductToCart(), the customizer's single-flower path -- reads `price` and keeps working
 * without knowing promos exist. A new `promo_price` key alongside an untouched `price` would mean
 * finding and editing every one of them, and the one that got missed would charge list price at the
 * till while the badge promised a discount.
 *
 * Rows with no promo come back with promo_percent 0 and list_price equal to price, so a template can
 * read all three unconditionally.
 */
function decorateProductPricing(array $product): array
{
    $listPrice = (string) ($product['price'] ?? '0.00');

    $product['list_price'] = $listPrice;
    $product['promo_percent'] = 0;

    $event = activeEvent();

    if ($event === null) {
        return $product;
    }

    $percent = (int) $event['discount_percent'];

    if ($percent <= 0) {
        return $product;
    }

    $id = (int) ($product['id'] ?? 0);

    if ($id <= 0 || !isset(eventProductIds()[$id])) {
        return $product;
    }

    $promoPrice = eventPromoPrice($listPrice, $percent);

    // A discount that rounds to no change at all is not a discount, and a "0% off" badge over an
    // unchanged price is worse than no badge. Can happen on a 1-centavo product at 1%.
    if ($promoPrice === $listPrice) {
        return $product;
    }

    $product['price'] = $promoPrice;
    $product['promo_percent'] = $percent;

    return $product;
}

/**
 * decorateProductPricing() over a list.
 *
 * Separate function rather than array_map at each call site so the two readers in products.php say
 * what they mean, and so this file owns the shape of the loop.
 */
function decorateProductList(array $products): array
{
    foreach ($products as $index => $product) {
        $products[$index] = decorateProductPricing($product);
    }

    return $products;
}

/**
 * The current event's featured product, already discounted, or null.
 *
 * Goes through fetchProduct() rather than its own query, which means a featured product that has
 * been withdrawn since the event was written simply disappears from the banner -- fetchProduct()
 * filters on is_active = 1. That is the right answer: the alternative is the home page advertising
 * something the catalogue will not sell.
 *
 * Requires products.php at call time rather than at the top of this file. The two files would
 * otherwise require each other -- products.php needs decorateProductPricing() -- and a cycle is the
 * thing php/config/csrf.php's docblock already warns about on this project.
 */
function eventFeaturedProduct(): ?array
{
    $event = activeEvent();

    if ($event === null || $event['featured_product_id'] === null) {
        return null;
    }

    require_once __DIR__ . '/products.php';

    return fetchProduct((int) $event['featured_product_id']);
}

/**
 * "10 - 14 February" or "14 February", for the banner.
 *
 * Collapses a single-day event to one date, and drops the repeated month on a range inside one
 * month, because "10 February - 14 February" is a sentence nobody writes by hand. The year is left
 * out entirely: an event is only ever shown while it is running, so the year is always this one and
 * printing it is noise.
 */
function eventDateLabel(array $event): string
{
    $start = strtotime((string) $event['starts_on']);
    $end = strtotime((string) $event['ends_on']);

    if ($start === false || $end === false) {
        return '';
    }

    if (date('Y-m-d', $start) === date('Y-m-d', $end)) {
        return date('j F', $start);
    }

    if (date('m', $start) === date('m', $end)) {
        return date('j', $start) . ' - ' . date('j F', $end);
    }

    return date('j F', $start) . ' - ' . date('j F', $end);
}

/**
 * How many days are left, as a sentence, or '' when that is not worth saying.
 *
 * Only speaks up on the last three days, because "ends in 27 days" is not urgency, it is noise --
 * and a banner that always says something stops being read.
 */
function eventEndsSoonLabel(array $event): string
{
    $end = strtotime((string) $event['ends_on'] . ' 23:59:59');

    if ($end === false) {
        return '';
    }

    $daysLeft = (int) floor(($end - time()) / 86400);

    if ($daysLeft < 0 || $daysLeft > 2) {
        return '';
    }

    return match ($daysLeft) {
        0 => 'Last day',
        1 => 'Ends tomorrow',
        default => 'Ends in 2 days',
    };
}
