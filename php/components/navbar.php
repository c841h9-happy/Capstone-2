<?php
// Needs escape(), isUserLoggedIn(), isAdmin() and url(). The session itself is
// started by the page including this file, before it echoes any HTML.
//
// Included by components/header.php, which owns the doctype, <head> and <body>.
// This file used to open its own copy of all three and was then included inside
// the page's, so every page shipped two nested documents -- along with a <link>
// to css/navbar.css, a file that does not exist. The navbar's styles are in
// css/components.css, which header.php links.
require_once __DIR__ . '/../config/auth.php';
// For cartSummary(). Loaded here rather than by each page because the basket link is on every page,
// and a count that appeared on some pages and not others would read as a basket that empties
// itself. cartSummary() returns an empty basket rather than raising if the database is unreachable,
// so a broken basket cannot take down the navbar -- and findCartId() only ever reads, so a visitor
// who never adds anything is never given a cookie or a row just for loading a page.
require_once __DIR__ . '/../config/cart.php';
// For csrfField(). The log-out button is a form on every page, and a form without the token is a
// form this site will refuse -- so the navbar carries its own dependency rather than trusting that
// whichever page included it happens to have loaded csrf.php for its own reasons.
require_once __DIR__ . '/../config/csrf.php';

/**
 * "nav-link", plus "active" when the given page is the one being viewed.
 *
 * Compares the whole path rather than basename($_SERVER['PHP_SELF']): with pages
 * in both php/ and php/admin/, a basename match would light up two different
 * dashboard.php or index.php pages as though they were the same one.
 */
function navLinkClass(string $path): string
{
    $isCurrent = ($_SERVER['SCRIPT_NAME'] ?? '') === url($path);

    return 'nav-link' . ($isCurrent ? ' active' : '');
}

/*
 * One read for the badge and the hover panel both, so the two can never disagree about what is in
 * the basket -- and one query, because this runs on every page of the site.
 */
$cart = cartSummary();
$cartCount = $cart['count'];
$cartPreview = array_slice($cart['lines'], 0, CART_PREVIEW_LINES);
$cartMore = count($cart['lines']) - count($cartPreview);
?>
<nav class="navbar" data-navbar>
    <?php
    /*
     * The logo and the shop's name, and together they are one link home -- which is where a logo
     * has gone on every site anybody visiting this one has ever used. There is a Home link in the
     * row below as well, and that is not a duplicate worth deleting: the nav link is the one a
     * keyboard reaches in order and a screen reader reads out, this is the one a mouse goes to
     * without having read anything.
     *
     * The anchor is .nav-con1 itself rather than something wrapped around it, so the flex row that
     * was already here keeps working untouched. It does mean the link styling has to be turned off
     * in css/components.css, or the shop's name goes blue and underlined.
     *
     * alt="" on the image, even though it is a logo with the shop's name drawn into it. The name is
     * already the text of this same link, an inch to the right of it; a filled-in alt would have a
     * screen reader announce "Sky and LJ's Craft logo, Sky and LJ's Craft" for one link. An empty
     * alt is how you say "this picture adds nothing the text beside it has not already said", and
     * here that is simply true.
     *
     * width and height match the 50px box css/components.css gives it. They are not styling -- they
     * are what lets the browser reserve the space before the file arrives, and without them the
     * whole page jolts downward on a cold load when 22KB of logo lands in a row that was 0px tall.
     */
    ?>
    <a class="nav-con1" href="<?php echo escape(url('php/index.php')); ?>">
        <img class="logo" src="<?php echo escape(asset('images/Logo.jpg')); ?>" alt=""
             width="50" height="50">
        <p class="site-name"><?php echo escape(SITE_NAME); ?></p>
    </a>

    <?php
    /*
     * Outside .nav-con2 on purpose, so it survives the narrow-screen menu.
     *
     * Inside that container it was collapsed into the hamburger below 720px, which put the count
     * behind a tap -- and a badge you have to open a menu to see is not a badge, it is a second
     * page. Out here it keeps its place beside the logo at every width, and the CSS orders it back
     * to the right of the links on a desktop.
     *
     * A cart icon rather than the word "Basket": it is the one link in this row whose meaning
     * survives being shrunk to a symbol, and it is what the badge needs to sit on. The word is
     * still there for screen readers, which is why the visually-hidden span is not optional -- a
     * link whose only content is an aria-hidden drawing has no name at all.
     *
     * The count is only printed when there is something to count. A red "0" is a warning about
     * nothing, and on a phone this row is already tight.
     *
     * cartCount() sums quantities, so 50 lines of 99 is 4,950 -- four digits would stretch the
     * circle into a lozenge. Anything past 99 is shown as "99+", because the exact number stops
     * being the point long before then; the real figure is on the basket page.
     */
    $badge = $cartCount > 99 ? '99+' : (string) $cartCount;
    ?>
    <a href="<?php echo escape(url('php/cart.php')); ?>" class="<?php echo navLinkClass('php/cart.php'); ?> nav-cart">
        <svg class="icon-cart" viewBox="0 0 24 24" width="22" height="22" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="21" r="1"/>
            <circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>

        <span class="visually-hidden">Basket<?php if ($cartCount > 0): ?>, <?php echo $cartCount; ?> item<?php echo $cartCount === 1 ? '' : 's'; ?><?php endif; ?></span>

        <?php if ($cartCount > 0): ?>
            <span class="nav-cart-count" aria-hidden="true"><?php echo escape($badge); ?></span>
        <?php endif; ?>

        <?php
        /*
         * The hover panel: a glance at the basket without leaving the page.
         *
         * Inside the link rather than beside it, which settles three things at once. Moving the
         * pointer down onto the panel never leaves the element that is keeping it open, so there is
         * no gap to fall through and no timer to hold it. Clicking anywhere on it goes to the
         * basket, which is what the link already did and what it must keep doing. And it needs no
         * JavaScript at all -- CSS opens it on :hover and on :focus-visible, so a keyboard tab
         * through the navbar shows it too.
         *
         * That is also why every element in here is a span and the whole thing is aria-hidden. A
         * link may contain flow content but not interactive content, so nothing in here can be a
         * link or a button; and a screen reader reading the panel would be reading it as part of
         * this link's name -- "Basket, 4 items Sunflower Trio 2 760.00 Total ...". The link already
         * announces the count, and the full basket is one press away on the page itself, so hiding
         * a decorative duplicate costs nothing. Nothing inside is focusable, which is the condition
         * aria-hidden has.
         */
        ?>
        <span class="nav-cart-panel" aria-hidden="true">
            <span class="nav-cart-panel-head">Your basket</span>

            <?php if ($cartPreview === []): ?>
                <span class="nav-cart-panel-empty">Nothing in it yet.</span>
            <?php else: ?>
                <span class="nav-cart-panel-list">
                    <?php foreach ($cartPreview as $line): ?>
                        <span class="nav-cart-panel-line<?php echo $line['orderable'] === false ? ' nav-cart-panel-gone' : ''; ?>">
                            <span class="nav-cart-panel-name"><?php echo escape($line['name']); ?></span>
                            <span class="nav-cart-panel-qty">&times;<?php echo (int) $line['quantity']; ?></span>
                            <span class="nav-cart-panel-money"><?php echo escape(formatPrice($line['lineTotal'])); ?></span>
                        </span>
                    <?php endforeach; ?>
                </span>

                <?php if ($cartMore > 0): ?>
                    <span class="nav-cart-panel-more">and <?php echo $cartMore; ?> more line<?php echo $cartMore === 1 ? '' : 's'; ?></span>
                <?php endif; ?>

                <?php
                /*
                 * The same figure the basket page prints, from the same cartTotal(), which leaves
                 * out anything the shop has withdrawn. The note below is why that is not a
                 * discrepancy -- without it a struck-through line and a total that ignores it look
                 * like an arithmetic bug.
                 */
                ?>
                <span class="nav-cart-panel-total">
                    <span>Total</span>
                    <span class="nav-cart-panel-figure"><?php echo escape(formatPrice($cart['total'])); ?></span>
                </span>

                <?php if ($cart['gone'] > 0): ?>
                    <span class="nav-cart-panel-note">
                        <?php echo $cart['gone'] === 1 ? 'One line is' : $cart['gone'] . ' lines are'; ?>
                        no longer available and not counted.
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <span class="nav-cart-panel-go">View basket</span>
        </span>
    </a>

    <!--
        Only appears on narrow screens, and only once script.js has run -- with no
        JavaScript the menu stays open and wraps instead, so the links never become
        unreachable behind a button that cannot be pressed.
    -->
    <button type="button" class="nav-toggle" data-nav-toggle
            aria-label="Menu" aria-controls="nav-menu" aria-expanded="false">
        <svg class="icon-menu" viewBox="0 0 24 24" width="22" height="22" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
        <svg class="icon-close" viewBox="0 0 24 24" width="22" height="22" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>

    <!-- other Btns -->
    <div class="nav-con2" id="nav-menu">
        <a href="<?php echo escape(url('php/index.php')); ?>" class="<?php echo navLinkClass('php/index.php'); ?>">Home</a>
        <a href="<?php echo escape(url('php/products.php')); ?>" class="<?php echo navLinkClass('php/products.php'); ?>">Products</a>
        <a href="<?php echo escape(url('php/customize.php')); ?>" class="<?php echo navLinkClass('php/customize.php'); ?>">Create</a>

        <?php if (isAdmin()): ?>
            <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>" class="<?php echo navLinkClass('php/admin/dashboard.php'); ?>">Dashboard</a>
        <?php endif; ?>

           <?php if (isUserLoggedIn() && !isAdmin()): ?>
            <!-- The greeting is the way into the account area. A fifth link would
                 have to go somewhere, and this row already wraps on a phone. -->
            <a href="<?php echo escape(url('php/dashboard.php')); ?>" class="<?php echo navLinkClass('php/dashboard.php'); ?> nav-user">Hi, <?php echo escape($_SESSION['user_name']); ?></a>
            <!-- POST so the link cannot be triggered from another site or a prefetch. -->
            <form class="nav-logout" method="post" action="<?php echo escape(url('php/logout.php')); ?>">
                <?php echo csrfField(); ?>
                <button type="submit" class="nav-link nav-logout-btn">Log out</button>
            </form>
        <?php elseif (isAdmin()): ?>
            <!-- Admins use the logout action at the bottom of the admin sidebar. -->
        <?php else: ?>
            <a href="<?php echo escape(url('php/login.php')); ?>" class="<?php echo navLinkClass('php/login.php'); ?>">Sign in</a>
        <?php endif; ?>
    </div>
</nav>
