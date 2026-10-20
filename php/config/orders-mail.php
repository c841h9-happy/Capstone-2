<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/orders.php';

/*
 * Telling a customer their order moved.
 *
 * The third of the three things ROADMAP.md gives the Orders module, and the one Commerce left
 * most visibly undone: a customer who typed a GCash reference was shown "Waiting for us to
 * check" and then told nothing at all when it cleared. sql/orders-upgrade.sql said where this
 * would go in the comment on the column it reads -- "The email is also where the Orders module
 * will send word".
 *
 * **Four events send mail**, agreed with the user on 2026-09-22: the order being started, the
 * order being delivered, the shop cancelling it, and the payment being confirmed. The customer
 * cancelling their own order is deliberately not one of them -- they did it, they know, and a
 * message confirming their own click is the kind of mail people set up filters to delete.
 *
 * **Payment is in here even though it is not in the timeline**, and both halves of that are
 * deliberate. php/order.php draws payment and status as two clocks that do not run together, so
 * payment draws no dot on the status ladder; but "we can see your money" is the single message a
 * customer most wants and the one Commerce could not send. A notification is not a state.
 *
 * A separate file from config/orders.php and config/orders-admin.php because it is the only
 * thing in the module that talks to the outside world. Neither of those two requires it -- the
 * admin pages do -- so nothing that merely reads an order drags the mailer in behind it.
 *
 * MAIL_TRANSPORT is 'log' in this project, so everything here lands in the file named by
 * MAIL_LOG_FILE rather than in an inbox. That is a setting, not a limitation of this code: the
 * path through sendMail() is the same one, and switching the transport is what changes where it
 * comes out.
 */

/**
 * The four events that send word, matching what a customer would call them.
 *
 * Named constants rather than the bare strings at each call site, for the reason ROLE_ADMIN is
 * one: a misspelt string in a match() falls through to the default and sends a message titled
 * for the wrong thing, while a misspelt constant is a fatal error the first time it runs.
 */
const ORDER_MAIL_PACKED = 'packed';
const ORDER_MAIL_DELIVERED = 'delivered';
const ORDER_MAIL_CANCELLED = 'cancelled';
const ORDER_MAIL_PAID = 'paid';

const ORDER_MAIL_EVENTS = [
    ORDER_MAIL_PACKED,
    ORDER_MAIL_DELIVERED,
    ORDER_MAIL_CANCELLED,
    ORDER_MAIL_PAID,
];

/**
 * The subject line for one event.
 *
 * A match() over all four rather than a branch on "is this good news", following the pattern
 * issueEmailCode() settled on in config/twofactor.php and for the reason recorded there: a
 * message that arrives titled for a different event is both wrong and the particular sort of
 * wrong that makes a real message look like a phishing attempt.
 *
 * The reference is in every subject because it is what the customer has written down, and it is
 * what makes two messages about two orders tell themselves apart in a threaded inbox.
 *
 * The default is the harmless one: an event this function has never heard of gets a subject that
 * is true of any change at all, rather than a claim that might not be.
 */
function orderMailSubject(string $event, string $reference): string
{
    return match ($event) {
        ORDER_MAIL_PACKED => 'We have started on order ' . $reference,
        ORDER_MAIL_DELIVERED => 'Order ' . $reference . ' has been delivered',
        ORDER_MAIL_CANCELLED => 'Order ' . $reference . ' has been cancelled',
        ORDER_MAIL_PAID => 'Payment received for order ' . $reference,
        default => 'An update about order ' . $reference,
    };
}

/**
 * The body of one message: what happened, then what to do about it, then the link.
 *
 * Plain text, because sendMail() sends plain text -- buildMailMessage() writes no MIME headers,
 * so HTML here would arrive as visible tags.
 *
 * Addressed with the contact name frozen on the order at checkout rather than the account's
 * name. They are usually the same person, and when they are not it is because the order is a
 * gift being sent by one to another, in which case the order's own name is the one that belongs
 * on a message about that order.
 *
 * "Delivered" and "collected" are told apart, as paymentMethodLabel() tells cash apart: a
 * message saying a bouquet was delivered to somebody who walked in and picked it up is wrong in
 * the one way that matters, and it is the same mistake in the other direction.
 *
 * @param array<string, mixed> $order As hydrateOrder() returns it.
 */
function orderMailBody(array $order, string $event): string
{
    $reference = (string) $order['reference'];
    $pickup = (string) $order['fulfilment'] === 'pickup';

    $news = match ($event) {
        ORDER_MAIL_PACKED => 'Good news -- we have started making your order.',
        ORDER_MAIL_DELIVERED => $pickup
            ? 'Your order has been collected. Thank you.'
            : 'Your order has been delivered. Thank you.',
        ORDER_MAIL_CANCELLED => 'We have had to cancel your order.',
        ORDER_MAIL_PAID => 'We can see your payment -- thank you. Your order is paid in full.',
        default => 'There has been an update to your order.',
    };

    /*
     * What happens next, which is the half of a notification that stops it generating a reply
     * asking exactly that. Only the cancellation has nothing to promise, so it says so and
     * points at a person instead of at a step.
     */
    $next = match ($event) {
        ORDER_MAIL_PACKED => $pickup
            ? 'We will send word again as soon as it is ready to collect.'
            : 'We will send word again as soon as it is on its way to you.',
        ORDER_MAIL_DELIVERED => 'Nothing more is needed from you. We would love to see a photo.',
        ORDER_MAIL_CANCELLED => 'Nothing is being made and nothing is owed. If a payment has already '
            . 'reached us, reply to this message and we will sort it out with you.',
        ORDER_MAIL_PAID => $pickup
            ? 'We will let you know when it is ready to collect.'
            : 'We will let you know when it is on its way.',
        default => 'You can see where it has got to on the site.',
    };

    return 'Hello ' . (string) $order['contactName'] . ',' . "\n\n"
        . $news . "\n\n"
        . 'Order:  ' . $reference . "\n"
        . 'Total:  ' . formatPrice((string) $order['total']) . "\n\n"
        . $next . "\n\n"
        // siteUrl() and not url(): a root-relative path in an inbox is not a link, because there
        // is no page for the mail client to resolve it against.
        . 'Everything about it, including where it has got to:' . "\n"
        . '    ' . siteUrl('php/order.php') . '?ref=' . rawurlencode($reference) . "\n\n"
        . SITE_NAME . "\n";
}

/**
 * Send one customer one message about one order. Returns false if it could not be sent.
 *
 * It goes to the order's frozen contact_email, not to the account's current address, and that is
 * the same argument the whole order makes about its own contact details: the address on the
 * order is where this order was arranged, and an account that has since moved to a new email has
 * not moved this order's history with it.
 *
 * **Never throws.** Every caller has already committed a change to the database by the time this
 * runs, and an exception here would report a failure for something that actually happened. A
 * false comes back instead, and the admin is told the move worked but the message did not --
 * which is the one thing they need to know, because the alternative is a shop believing a
 * customer was told when they were not.
 *
 * An address that is somehow empty is a false rather than a call into the mailer, so the log
 * records a refusal rather than a message addressed to nobody.
 *
 * @param array<string, mixed> $order As hydrateOrder() returns it.
 */
function notifyOrderEvent(array $order, string $event): bool
{
    $address = trim((string) ($order['contactEmail'] ?? ''));
    $reference = (string) ($order['reference'] ?? '');

    if ($address === '' || $reference === '') {
        error_log('notifyOrderEvent: order ' . $reference . ' has no contact address to write to');

        return false;
    }

    try {
        return sendMail($address, orderMailSubject($event, $reference), orderMailBody($order, $event));
    } catch (Throwable $exception) {
        // sendMail() already swallows an SMTP failure and logs it; this is for anything further
        // out -- a transport misconfigured badly enough to throw, or a log file that cannot be
        // written. Either way the order has already moved and the page must not die here.
        error_log('notifyOrderEvent: ' . $exception->getMessage());

        return false;
    }
}
