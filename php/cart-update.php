<?php
// Nothing but redirects come out of this file, so nothing may be printed before them. The basket
// also sets a cookie for a signed-out visitor, which is a header and would be lost after output.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cart.php';
// For readSavedDesign(), which only the add-saved action below needs. Required here rather than
// inside config/cart.php because cart.php is loaded on every sign-in -- see signInUser() -- and
// there is no reason for signing in to parse the saved-design layer as well.
require_once __DIR__ . '/config/designs.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
ensureSession();

/*
 * Every write the basket has: add, change a quantity, remove a line, empty it.
 *
 * One file for the four, following php/design-save.php: they are the same write in four shapes
 * -- find this browser's basket, change it, flash, go back -- and four files would be four copies
 * of the scoping. Never renders; every path ends in redirectTo(), so a refresh after adding
 * something does not add it twice.
 *
 * **No requireLogin(), and that is the decision.** A signed-out visitor may fill a basket, which
 * is why carts has a token_hash column and why this file may set a cookie. The account is
 * required at checkout, not before -- somebody who has to make an account before they can see
 * what something costs in a basket mostly just leaves. mergeGuestCart() in config/cart.php is
 * what makes that safe: the lines follow them onto the account when they do sign in.
 */
$action = (string) ($_POST['action'] ?? '');
$back = (string) ($_POST['back'] ?? '');
$cartUrl = url('php/cart.php');

/*
 * A GET here is somebody typing the address in, or a bookmark of a POST. The basket is where they
 * meant to go.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo($cartUrl);
}

/*
 * Where to send them afterwards. Adding from a product page should leave you on that product
 * page -- you may want the one next to it as well -- while changing a quantity happens on the
 * basket and belongs back there.
 *
 * The value is checked rather than trusted: an open redirect is a real hole, and a hidden field
 * is the customer's to edit. Only a path inside this site is allowed, so ?back=http://evil.test
 * lands on the basket instead.
 */
$destination = cartSafeDestination($back, $cartUrl);

/*
 * And the other half of trusting a form field: that the form was ours at all. Checked after the
 * destination is worked out so the refusal lands where the visitor was standing, exactly like every
 * other refusal below. Without this, another site's page can empty this basket by autosubmitting a
 * form at it -- the browser attaches the session cookie whoever asked for the request.
 */
requireCsrfTokenOrRedirect($destination);

$itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
$itemId = $itemId === false ? 0 : (int) $itemId;

try {
    if ($action === 'update') {
        if ($itemId > 0 && updateCartQuantity($itemId, $_POST['quantity'] ?? null)) {
            flashToast('success', 'Basket updated.');
        } else {
            flashToast('error', 'We could not find that in your basket.');
        }

        redirectTo($destination);
    }

    if ($action === 'remove') {
        if ($itemId > 0 && removeCartLine($itemId)) {
            flashToast('success', 'Removed from your basket.');
        } else {
            flashToast('error', 'We could not find that in your basket.');
        }

        redirectTo($destination);
    }

    if ($action === 'empty') {
        emptyCart();
        flashToast('success', 'Your basket is empty.');

        redirectTo($cartUrl);
    }

    if ($action === 'add-product') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $productId = $productId === false ? 0 : (int) $productId;

        /*
         * null covers three different things -- no such product, a hidden one, and a basket
         * already at its ceiling -- and they are deliberately not told apart here. The first two
         * must not differ, because a message that distinguishes "hidden" from "missing" tells a
         * stranger which ids exist. The ceiling is rare enough that one clear sentence covering
         * all three is better than three branches.
         */
        if ($productId > 0 && addProductToCart($productId, $_POST['quantity'] ?? 1) !== null) {
            flashToast('success', 'Added to your basket.');
        } else {
            flashToast('error', 'We could not add that to your basket. It may no longer be on sale.');
        }

        redirectTo($destination);
    }

    if ($action === 'add-design') {
        /*
         * The bouquet arrives as a query string in a hidden field and is parsed with parse_str,
         * exactly as php/design-save.php does it -- so the thing added is the bouquet whose price
         * was printed next to the button, including on the enhanced path where the script has
         * been rewriting that field while somebody clicked.
         *
         * Then it goes through readDesign() like any URL, so a forged field cannot add a bouquet
         * the builder would refuse to draw, and the parts are read from the database rather than
         * taken from the form: a price is the shop's to state.
         */
        $query = [];
        parse_str((string) ($_POST['design'] ?? ''), $query);

        $design = readDesign($query, fetchComponentsByKind());

        if ($design['blooms'] === []) {
            flashToast('error', 'Add at least one flower before putting it in your basket.');
            redirectTo($destination);
        }

        if (addDesignToCart($design, $_POST['quantity'] ?? 1) !== null) {
            flashToast('success', 'Your bouquet is in the basket.');
        } else {
            flashToast('error', 'We could not add that bouquet. Your basket may be full.');
        }

        redirectTo($destination);
    }

    if ($action === 'add-saved') {
        /*
         * Adding straight from php/designs.php. Needs an account, because a saved design has an
         * owner -- and readSavedDesign() scopes by user_id, so somebody else's id comes back null
         * rather than being added.
         */
        if (!isUserLoggedIn()) {
            flashToast('error', 'Sign in to order a saved bouquet.');
            redirectTo(url('php/login.php'));
        }

        $designId = filter_var($_POST['design_id'] ?? null, FILTER_VALIDATE_INT);
        $designId = $designId === false ? 0 : (int) $designId;

        $design = $designId > 0
            ? readSavedDesign((int) $_SESSION['user_id'], $designId)
            : null;

        if ($design === null || $design['blooms'] === []) {
            flashToast('error', 'We could not find that bouquet, or its flowers are no longer offered.');
            redirectTo($destination);
        }

        if (addDesignToCart($design, $_POST['quantity'] ?? 1) !== null) {
            flashToast('success', 'Your bouquet is in the basket.');
        } else {
            flashToast('error', 'We could not add that bouquet. Your basket may be full.');
        }

        redirectTo($destination);
    }

    // An unknown action is not an error worth a page. Somebody replayed a stale form, or typed.
    redirectTo($cartUrl);
} catch (PDOException $exception) {
    error_log('cart-update.php: ' . $exception->getMessage());

    flashToast('error', 'We could not change your basket just now. Nothing has been changed -- try again in a moment.');
    redirectTo($destination);
}
