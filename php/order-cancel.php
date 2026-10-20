<?php
// Nothing but redirects come out of this file, so nothing may be printed before them.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
ensureSession();
requireLogin();

/*
 * Cancelling your own order.
 *
 * A separate file rather than a branch in php/order.php, following php/cart-update.php and
 * php/design-save.php: a write with nothing to redisplay answers with a redirect and leaves its
 * message in the session, so a refresh afterwards re-renders the order rather than cancelling it
 * again. php/checkout.php is the other shape -- twelve boxes to put back, so it handles its own
 * POST.
 *
 * The rule about when this is allowed lives in the UPDATE inside cancelOrderForUser(), not here.
 * Reading the order, checking it, then writing would leave a gap in which the shop could confirm
 * the payment, and the row would be cancelled anyway. So the conditions are in the statement, and
 * a refusal comes back as rowCount() === 0.
 */
$reference = orderReferenceFromInput($_POST['ref'] ?? '');
$ordersUrl = url('php/orders.php');
$orderUrl = $reference === '' ? $ordersUrl : url('php/order.php') . '?ref=' . rawurlencode($reference);

// A GET here is somebody typing the address in, or a bookmarked POST. Their orders are where they
// meant to go -- and a cancellation must never be something a link can do.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo($ordersUrl);
}

// The other half of "a cancellation must never be something a link can do": another site's form
// posts here just as easily as a link loads. Checked before the reference so a forged request is
// refused as forged rather than as malformed, and sent back to the order it aimed at.
requireCsrfTokenOrRedirect($orderUrl);

if ($reference === '') {
    flashToast('error', 'We could not tell which order that was.');
    redirectTo($ordersUrl);
}

try {
    if (cancelOrderForUser((int) $_SESSION['user_id'], $reference)) {
        flashToast('success', 'Order ' . $reference . ' is cancelled.');
    } else {
        /*
         * One message for four different refusals -- no such reference, somebody else's order, one
         * already being made, one already paid for -- and they are deliberately not told apart. The
         * first two must not differ, because a message that says "that is not yours" confirms the
         * reference is real. The other two are answered by the page it redirects to, which shows
         * the actual state rather than guessing at it here.
         */
        flashToast('error', 'We could not cancel that order. Check where it has got to below.');
    }

    redirectTo($orderUrl);
} catch (PDOException $exception) {
    error_log('order-cancel.php: ' . $exception->getMessage());

    flashToast('error', 'We could not cancel that just now. Nothing has been changed -- try again in a moment.');
    redirectTo($orderUrl);
}
