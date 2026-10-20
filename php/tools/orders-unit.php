<?php

declare(strict_types=1);

/**
 * Unit checks for the parts of config/orders.php that are pure arithmetic or pure string work.
 *
 * CLI only, touches no database and no session. The queries -- placeOrder() above all -- are tested
 * over Apache in orders-http-test.php, where a real session and a real basket exist.
 *
 *     C:\xampp\php\php.exe php\tools\orders-unit.php
 */

// Same guard as the tools beside this one. This file is under the webroot, so without it Apache
// would serve it -- and the first thing it does is overwrite DOCUMENT_ROOT.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Apache's conditions, set before the first basePath() call caches its answer. Same reasoning as
// cart-unit.php: under CLI the base path would otherwise resolve to "/" and test a different site.
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/orders.php';

$passed = 0;
$failed = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        $passed++;

        return;
    }

    $failed++;
    printf(
        "FAIL  %s\n        expected: %s\n        actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

/** For the assertions whose expected value is just "yes" -- a substring being present, mostly. */
function checkTrue(string $label, bool $actual): void
{
    check($label, $actual, true);
}

/** A complete, valid delivery form. Each block below starts from this and breaks one thing. */
function deliveryForm(array $overrides = []): array
{
    return array_merge([
        'fulfilment' => 'delivery',
        'payment_method' => 'cod',
        'payment_reference' => '',
        'contact_name' => 'Maria Santos',
        'contact_phone' => '0917 555 0134',
        'address_line' => '14 Mabini Street',
        'barangay' => 'San Roque',
        'city' => 'Antipolo',
        'province' => 'Rizal',
        'postcode' => '1870',
        'notes' => '',
    ], $overrides);
}

/** The signed-in account validateCheckout() takes the email from. */
const TEST_ACCOUNT = ['id' => 1, 'name' => 'Maria Santos', 'email' => 'maria@example.test'];

function errorsFor(array $overrides = []): array
{
    return validateCheckout(deliveryForm($overrides), TEST_ACCOUNT)['errors'];
}

function valuesFor(array $overrides = []): array
{
    return validateCheckout(deliveryForm($overrides), TEST_ACCOUNT)['values'];
}


/*
 * The vocabulary. Six constants and four label maps have to agree with the ENUMs in
 * sql/orders-upgrade.sql, and nothing at runtime would notice if they stopped -- a status the map
 * has no entry for prints as "Placed" and looks fine while being wrong.
 */
check('vocabulary: the fulfilments', ORDER_FULFILMENTS, ['delivery', 'pickup']);
check('vocabulary: the payment methods', ORDER_PAYMENT_METHODS, ['cod', 'gcash', 'bank']);
check('vocabulary: the statuses', ORDER_STATUSES, ['placed', 'packed', 'delivered', 'cancelled']);
check('vocabulary: the payment statuses', ORDER_PAYMENT_STATUSES, ['unpaid', 'submitted', 'paid']);

check(
    'vocabulary: every status has a label',
    array_keys(ORDER_STATUS_LABELS),
    ORDER_STATUSES
);
check(
    'vocabulary: every payment status has a label',
    array_keys(ORDER_PAYMENT_STATUS_LABELS),
    ORDER_PAYMENT_STATUSES
);
check(
    'vocabulary: no label is empty',
    array_filter(array_merge(ORDER_STATUS_LABELS, ORDER_PAYMENT_STATUS_LABELS), static fn (string $l): bool => trim($l) === ''),
    []
);
check(
    'vocabulary: the transfer methods are payment methods',
    array_diff(ORDER_TRANSFER_METHODS, ORDER_PAYMENT_METHODS),
    []
);
check('vocabulary: cash is not a transfer', in_array('cod', ORDER_TRANSFER_METHODS, true), false);


/*
 * orderFulfilment() and orderPaymentMethod(): null rather than a default.
 *
 * The point of these returning null is that a form with an unreadable choice is sent back to be
 * asked again, instead of an order quietly being delivered to somebody expecting to collect it.
 */
check('fulfilment: delivery is kept', orderFulfilment('delivery'), 'delivery');
check('fulfilment: pickup is kept', orderFulfilment('pickup'), 'pickup');
check('fulfilment: junk is null', orderFulfilment('courier'), null);
check('fulfilment: empty is null', orderFulfilment(''), null);
check('fulfilment: null is null', orderFulfilment(null), null);
check('fulfilment: an array is null', orderFulfilment(['delivery']), null);
check('fulfilment: a number is null', orderFulfilment(1), null);
check('fulfilment: case matters', orderFulfilment('Delivery'), null);
check('fulfilment: whitespace is not trimmed into validity', orderFulfilment(' delivery'), null);

check('method: cod is kept', orderPaymentMethod('cod'), 'cod');
check('method: gcash is kept', orderPaymentMethod('gcash'), 'gcash');
check('method: bank is kept', orderPaymentMethod('bank'), 'bank');
check('method: junk is null', orderPaymentMethod('card'), null);
check('method: empty is null', orderPaymentMethod(''), null);
check('method: an array is null', orderPaymentMethod(['cod']), null);
check('method: true is null', orderPaymentMethod(true), null);

check('needs reference: gcash does', paymentNeedsReference('gcash'), true);
check('needs reference: bank does', paymentNeedsReference('bank'), true);
check('needs reference: cash does not', paymentNeedsReference('cod'), false);
check('needs reference: junk does not', paymentNeedsReference('card'), false);


/*
 * orderTextLine(): tidying without changing what was said.
 *
 * The newline case is the one that matters. An address pasted out of a messaging app arrives with
 * line breaks in it, and an address line holding a break prints wrong on every label afterwards --
 * VARCHAR(160) will store it happily.
 */
check('text: trimmed', orderTextLine('  Antipolo  '), 'Antipolo');
check('text: runs of spaces collapse', orderTextLine('14   Mabini    Street'), '14 Mabini Street');
check('text: a newline collapses', orderTextLine("14 Mabini\nStreet"), '14 Mabini Street');
check('text: a tab collapses', orderTextLine("14\tMabini"), '14 Mabini');
check('text: a CRLF collapses', orderTextLine("San Roque\r\nAntipolo"), 'San Roque Antipolo');
check('text: an all-whitespace value empties', orderTextLine("  \n\t "), '');
check('text: empty stays empty', orderTextLine(''), '');
check('text: null is empty', orderTextLine(null), '');
check('text: an array is empty', orderTextLine(['x']), '');
check('text: a number becomes its digits', orderTextLine(1870), '1870');
check('text: accents survive', orderTextLine('  Parañaque  '), 'Parañaque');
check('text: an apostrophe survives', orderTextLine("O'Brien"), "O'Brien");


/*
 * validateCheckout(), the happy paths first.
 */
check('delivery: a complete form has no errors', errorsFor(), []);
check('delivery: the email comes from the account', valuesFor()['contact_email'], 'maria@example.test');
check(
    'delivery: the email cannot be set from the form',
    valuesFor(['contact_email' => 'someone@else.test'])['contact_email'],
    'maria@example.test'
);
check('delivery: a missing postcode is fine', errorsFor(['postcode' => '']), []);
check('delivery: the address is kept', valuesFor()['address_line'], '14 Mabini Street');
check('delivery: the phone keeps its formatting', valuesFor()['contact_phone'], '0917 555 0134');

$pickup = validateCheckout(deliveryForm(['fulfilment' => 'pickup']), TEST_ACCOUNT);
check('pickup: a form with an address left in it is still valid', $pickup['errors'], []);

/*
 * The five address fields are wiped on a pickup, and this is the check that stands for
 * chk_orders_address in the schema. The boxes are in the markup and a visitor can fill them in,
 * change their mind and switch to collection with the boxes still full -- without the wipe the
 * database would refuse the INSERT with a message written for a database, not a person.
 */
check('pickup: the street is wiped', $pickup['values']['address_line'], '');
check('pickup: the barangay is wiped', $pickup['values']['barangay'], '');
check('pickup: the city is wiped', $pickup['values']['city'], '');
check('pickup: the province is wiped', $pickup['values']['province'], '');
check('pickup: the postcode is wiped', $pickup['values']['postcode'], '');
check('pickup: the name is kept', $pickup['values']['contact_name'], 'Maria Santos');
check('pickup: the phone is kept', $pickup['values']['contact_phone'], '0917 555 0134');
check(
    'pickup: an empty address form is also valid',
    errorsFor([
        'fulfilment' => 'pickup',
        'address_line' => '', 'barangay' => '', 'city' => '', 'province' => '', 'postcode' => '',
    ]),
    []
);


/*
 * The missing-address cases. Four required fields on a delivery, one error each, and the count is
 * asserted as well as the emptiness -- a validator that reports one error for four empty boxes
 * sends the customer back four times.
 */
check('delivery: no street is refused', count(errorsFor(['address_line' => ''])), 1);
check('delivery: no barangay is refused', count(errorsFor(['barangay' => ''])), 1);
check('delivery: no city is refused', count(errorsFor(['city' => ''])), 1);
check('delivery: no province is refused', count(errorsFor(['province' => ''])), 1);
check(
    'delivery: four empty boxes give four errors',
    count(errorsFor(['address_line' => '', 'barangay' => '', 'city' => '', 'province' => ''])),
    4
);
check(
    'delivery: a whitespace-only street is empty',
    count(errorsFor(['address_line' => '   '])),
    1
);
check(
    'delivery: an over-long street is refused',
    count(errorsFor(['address_line' => str_repeat('a', 161)])),
    1
);
check(
    'delivery: a street at the column limit is accepted',
    errorsFor(['address_line' => str_repeat('a', 160)]),
    []
);
check(
    'delivery: an over-long barangay is refused',
    count(errorsFor(['barangay' => str_repeat('b', 81)])),
    1
);
check(
    'delivery: an over-long postcode is refused',
    count(errorsFor(['postcode' => str_repeat('9', 11)])),
    1
);
check('delivery: a postcode at the limit is accepted', errorsFor(['postcode' => '9999999999']), []);


/*
 * The contact. A name and a number the shop can ring, on both fulfilments -- a pickup still needs
 * somebody to ask for at the counter.
 */
check('contact: no name is refused', count(errorsFor(['contact_name' => ''])), 1);
check('contact: a whitespace name is refused', count(errorsFor(['contact_name' => '  '])), 1);
check('contact: a missing name key is refused', count(errorsFor(['contact_name' => null])), 1);
check(
    'contact: an over-long name is refused',
    count(errorsFor(['contact_name' => str_repeat('n', 101)])),
    1
);
check('contact: a name at the limit is accepted', errorsFor(['contact_name' => str_repeat('n', 100)]), []);
check('contact: a one-character name is accepted', errorsFor(['contact_name' => 'X']), []);
check('contact: a pickup still needs a name', count(errorsFor(['fulfilment' => 'pickup', 'contact_name' => ''])), 1);

/*
 * The phone, deliberately lenient. Every one of these is a number a real customer writes down, and
 * a regex insisting on 09XXXXXXXXX turns most of them into a checkout that cannot be completed.
 */
check('phone: a plain mobile', errorsFor(['contact_phone' => '09175550134']), []);
check('phone: spaced', errorsFor(['contact_phone' => '0917 555 0134']), []);
check('phone: dashed', errorsFor(['contact_phone' => '0917-555-0134']), []);
check('phone: international', errorsFor(['contact_phone' => '+63 917 555 0134']), []);
check('phone: bracketed area code', errorsFor(['contact_phone' => '(02) 8123 4567']), []);
check('phone: a landline', errorsFor(['contact_phone' => '8123 4567']), []);
check('phone: dotted', errorsFor(['contact_phone' => '0917.555.0134']), []);
check('phone: seven digits is the floor', errorsFor(['contact_phone' => '8123456']), []);
check('phone: six digits is too few', count(errorsFor(['contact_phone' => '812345'])), 1);
check('phone: fifteen digits is the ceiling', errorsFor(['contact_phone' => '639175550134123']), []);
check('phone: sixteen digits is too many', count(errorsFor(['contact_phone' => '6391755501341234'])), 1);
check('phone: no digits at all is refused', count(errorsFor(['contact_phone' => 'call me'])), 1);
check('phone: empty is refused', count(errorsFor(['contact_phone' => ''])), 1);
check('phone: null is refused', count(errorsFor(['contact_phone' => null])), 1);
check(
    'phone: over the column length is refused',
    count(errorsFor(['contact_phone' => str_repeat('9', 31)])),
    1
);
check(
    'phone: a long value reports one problem, not two',
    count(errorsFor(['contact_phone' => str_repeat('9', 40)])),
    1
);


/*
 * The payment. A transfer needs its reference; cash has nothing to reference, which is the other
 * CHECK in the schema and is enforced here first.
 */
check('payment: cash needs no reference', errorsFor(['payment_method' => 'cod']), []);
check(
    'payment: gcash without a reference is refused',
    count(errorsFor(['payment_method' => 'gcash', 'payment_reference' => ''])),
    1
);
check(
    'payment: bank without a reference is refused',
    count(errorsFor(['payment_method' => 'bank', 'payment_reference' => ''])),
    1
);
check(
    'payment: gcash with a reference is accepted',
    errorsFor(['payment_method' => 'gcash', 'payment_reference' => '0021 4419 8832']),
    []
);
check(
    'payment: the reference is kept as typed',
    valuesFor(['payment_method' => 'gcash', 'payment_reference' => '0021 4419 8832'])['payment_reference'],
    '0021 4419 8832'
);
check(
    'payment: any shape of reference is accepted',
    errorsFor(['payment_method' => 'bank', 'payment_reference' => 'REF/2026-09-17/ABC']),
    []
);
check(
    'payment: an over-long reference is refused',
    count(errorsFor(['payment_method' => 'gcash', 'payment_reference' => str_repeat('9', 61)])),
    1
);
check(
    'payment: a reference at the column limit is accepted',
    errorsFor(['payment_method' => 'gcash', 'payment_reference' => str_repeat('9', 60)]),
    []
);
check(
    'payment: a reference left on a cash order is wiped',
    valuesFor(['payment_method' => 'cod', 'payment_reference' => '0021 4419 8832'])['payment_reference'],
    ''
);
check(
    'payment: the gcash error names gcash',
    str_contains(errorsFor(['payment_method' => 'gcash', 'payment_reference' => ''])[0], 'GCash'),
    true
);
check(
    'payment: the bank error names the bank',
    str_contains(errorsFor(['payment_method' => 'bank', 'payment_reference' => ''])[0], 'Bank transfer'),
    true
);


/*
 * The choices themselves missing -- a hand-built POST, or a form submitted before anything was
 * picked. No radio pre-selected means no key in $_POST at all.
 */
check('choice: no fulfilment is refused', count(errorsFor(['fulfilment' => null])), 1);
check('choice: no method is refused', count(errorsFor(['payment_method' => null])), 1);
check('choice: a junk fulfilment is refused', count(errorsFor(['fulfilment' => 'courier'])), 1);
check('choice: an array fulfilment is refused', count(errorsFor(['fulfilment' => ['delivery']])), 1);
check('choice: an entirely empty POST still returns values', array_keys(validateCheckout([], TEST_ACCOUNT)['values']), [
    'fulfilment', 'payment_method', 'payment_reference', 'contact_name', 'contact_phone',
    'contact_email', 'address_line', 'barangay', 'city', 'province', 'postcode', 'notes',
]);
check(
    'choice: an unreadable fulfilment does not also demand an address',
    // 'fulfilment' is null, so neither branch runs -- one error about the choice, not five.
    count(errorsFor(['fulfilment' => 'courier', 'address_line' => '', 'barangay' => '', 'city' => '', 'province' => ''])),
    1
);
check('choice: values survive an invalid choice', valuesFor(['fulfilment' => 'courier'])['contact_name'], 'Maria Santos');
check('choice: an invalid choice is not echoed back', valuesFor(['fulfilment' => 'courier'])['fulfilment'], '');


/*
 * The note. Optional, allowed its line breaks, capped at the column.
 */
check('note: empty is fine', errorsFor(['notes' => '']), []);
check('note: a sentence is kept', valuesFor(['notes' => 'Leave it with the guard.'])['notes'], 'Leave it with the guard.');
check(
    'note: line breaks survive',
    valuesFor(['notes' => "For a birthday.\nRing when outside."])['notes'],
    "For a birthday.\nRing when outside."
);
check('note: the ends are trimmed', valuesFor(['notes' => "  hello  "])['notes'], 'hello');
check('note: at the limit is accepted', errorsFor(['notes' => str_repeat('x', ORDER_NOTES_MAX)]), []);
check('note: over the limit is refused', count(errorsFor(['notes' => str_repeat('x', ORDER_NOTES_MAX + 1)])), 1);
check('note: an array note is empty', valuesFor(['notes' => ['x']])['notes'], '');


/*
 * orderReference(): the string a customer reads down a phone.
 *
 * The alphabet check is the one with teeth. 0/O and 1/I are the pairs people mistype, and a
 * reference containing one is a support call that ends in "we cannot find your order".
 */
$reference = orderReference();
check(
    'reference: the shape',
    (bool) preg_match('/^SLJ-\d{8}-[' . ORDER_REFERENCE_ALPHABET . ']{4}$/', $reference),
    true
);
check('reference: fits CHAR(17)', strlen($reference), 17);
check('reference: carries today\'s date', substr($reference, 4, 8), date('Ymd'));
check('reference: the alphabet has no confusable characters', preg_match('/[01OI]/', ORDER_REFERENCE_ALPHABET), 0);
check('reference: the alphabet has no duplicates', strlen(count_chars(ORDER_REFERENCE_ALPHABET, 3)), strlen(ORDER_REFERENCE_ALPHABET));

$tails = [];

for ($i = 0; $i < 500; $i++) {
    $drawn = orderReference();
    check('reference: draw ' . $i . ' uses only the alphabet', strspn(substr($drawn, -4), ORDER_REFERENCE_ALPHABET), 4);
    $tails[substr($drawn, -4)] = true;
}

/*
 * 500 draws from 32^4 = 1,048,576 tails. A handful of collisions is arithmetic (the birthday
 * bound puts the expectation near 0.12), but a generator stuck on one value, or drawing from a
 * tenth of its alphabet, shows up here immediately -- and that is the failure that would look
 * fine in production until two orders collided.
 */
check('reference: 500 draws are almost all distinct', count($tails) >= 495, true);


/*
 * orderReferenceFromInput(): a reference as it comes back off a URL or a form.
 */
check('input: a real reference passes', orderReferenceFromInput('SLJ-20260917-K4M9'), 'SLJ-20260917-K4M9');
check('input: lower case is raised', orderReferenceFromInput('slj-20260917-k4m9'), 'SLJ-20260917-K4M9');
check('input: mixed case is raised', orderReferenceFromInput('Slj-20260917-K4m9'), 'SLJ-20260917-K4M9');
check('input: surrounding space is trimmed', orderReferenceFromInput('  SLJ-20260917-K4M9 '), 'SLJ-20260917-K4M9');
check('input: a confusable character is refused', orderReferenceFromInput('SLJ-20260917-K0M9'), '');
check('input: a letter I is refused', orderReferenceFromInput('SLJ-20260917-KIM9'), '');
check('input: the wrong prefix is refused', orderReferenceFromInput('ABC-20260917-K4M9'), '');
check('input: a short tail is refused', orderReferenceFromInput('SLJ-20260917-K4M'), '');
check('input: a long tail is refused', orderReferenceFromInput('SLJ-20260917-K4M99'), '');
check('input: a short date is refused', orderReferenceFromInput('SLJ-2026917-K4M9'), '');
check('input: a bare number is refused', orderReferenceFromInput('17'), '');
check('input: empty is refused', orderReferenceFromInput(''), '');
check('input: null is refused', orderReferenceFromInput(null), '');
check('input: an array is refused', orderReferenceFromInput(['SLJ-20260917-K4M9']), '');
check('input: a 500-character probe is refused', orderReferenceFromInput(str_repeat('A', 500)), '');
check('input: SQL in the box is refused', orderReferenceFromInput("' OR 1=1 --"), '');
check('input: a trailing wildcard is refused', orderReferenceFromInput('SLJ-20260917-K4M9%'), '');

/*
 * The pair have to agree: every reference the generator can produce must be one the reader
 * accepts. If the alphabet were ever widened on one side only, this is what would notice --
 * and the symptom otherwise is a customer being told their own reference does not exist.
 */
$roundTrips = 0;

for ($i = 0; $i < 200; $i++) {
    $drawn = orderReference();

    if (orderReferenceFromInput($drawn) === $drawn) {
        $roundTrips++;
    }
}

check('input: 200 generated references all round-trip', $roundTrips, 200);


/*
 * The labels. paymentMethodLabel() is the one that reads differently depending on fulfilment --
 * "Cash on delivery" printed on something the customer is collecting tells them to wait at home.
 */
check('label: cash on a delivery', paymentMethodLabel('cod', 'delivery'), 'Cash on delivery');
check('label: cash on a pickup', paymentMethodLabel('cod', 'pickup'), 'Cash on collection');
check('label: gcash reads the same either way', paymentMethodLabel('gcash', 'pickup'), 'GCash');
check('label: bank reads the same either way', paymentMethodLabel('bank', 'pickup'), 'Bank transfer');
check('label: an unknown method falls back to cash', paymentMethodLabel('card', 'delivery'), 'Cash on delivery');

check('label: placed', orderStatusLabel('placed'), 'Placed');
check('label: packed', orderStatusLabel('packed'), 'Being made');
check('label: delivered', orderStatusLabel('delivered'), 'Delivered');
check('label: cancelled', orderStatusLabel('cancelled'), 'Cancelled');
check('label: an unknown status falls back', orderStatusLabel('exploded'), 'Placed');

check('label: unpaid', orderPaymentStatusLabel('unpaid'), 'Not paid yet');
check('label: submitted', orderPaymentStatusLabel('submitted'), 'Waiting for us to check');
check('label: paid', orderPaymentStatusLabel('paid'), 'Paid');
check('label: an unknown payment status falls back', orderPaymentStatusLabel('refunded'), 'Not paid yet');

check('label: delivery', orderFulfilmentLabel('delivery'), 'Delivery');
check('label: pickup', orderFulfilmentLabel('pickup'), 'Collection');


/*
 * orderCanBeCancelled(): while it is only placed, and while the money has not been agreed.
 *
 * The paid case is the important one. A button that quietly cancels an order somebody has already
 * transferred money for is a refund the shop never hears about.
 */
check(
    'cancel: a new unpaid order can be',
    orderCanBeCancelled(['status' => 'placed', 'paymentStatus' => 'unpaid']),
    true
);
check(
    'cancel: a submitted-but-unconfirmed payment can be',
    orderCanBeCancelled(['status' => 'placed', 'paymentStatus' => 'submitted']),
    true
);
check(
    'cancel: a paid order cannot be',
    orderCanBeCancelled(['status' => 'placed', 'paymentStatus' => 'paid']),
    false
);
check(
    'cancel: something already being made cannot be',
    orderCanBeCancelled(['status' => 'packed', 'paymentStatus' => 'unpaid']),
    false
);
check(
    'cancel: something delivered cannot be',
    orderCanBeCancelled(['status' => 'delivered', 'paymentStatus' => 'paid']),
    false
);
check(
    'cancel: something already cancelled cannot be again',
    orderCanBeCancelled(['status' => 'cancelled', 'paymentStatus' => 'unpaid']),
    false
);


/*
 * orderDate(): a stored timestamp as a person reads it.
 */
check('date: a stored timestamp', orderDate('2026-09-17 13:09:00'), '17 September 2026, 1:09pm');
check('date: midnight reads as 12am', orderDate('2026-09-17 00:05:00'), '17 September 2026, 12:05am');
check('date: noon reads as 12pm', orderDate('2026-09-17 12:00:00'), '17 September 2026, 12:00pm');
check('date: a single-digit day has no leading zero', orderDate('2026-09-07 09:00:00'), '7 September 2026, 9:00am');
// Unparseable comes back as it went in, rather than as today -- a broken date should look broken.
check('date: junk is returned unchanged', orderDate('not a date'), 'not a date');
check('date: empty is returned unchanged', orderDate(''), '');


/*
 * orderDateShort(): the same timestamp with the month abbreviated, for the admin queue.
 *
 * Worth its own block rather than trusting it to look like the one above, because the two are
 * separate functions precisely so the long form can stay long -- and a copy-paste that left
 * 'j F Y' in both would pass every test that only checked the short one parsed.
 */
check('short date: the month is abbreviated', orderDateShort('2026-09-17 13:09:00'), '17 Sep 2026, 1:09pm');
check('short date: a single-digit day has no leading zero', orderDateShort('2026-09-07 09:00:00'), '7 Sep 2026, 9:00am');
check('short date: a four-letter month keeps its full name', orderDateShort('2026-06-02 15:30:00'), '2 Jun 2026, 3:30pm');
check('short date: midnight reads as 12am', orderDateShort('2026-09-17 00:05:00'), '17 Sep 2026, 12:05am');
check('short date: junk is returned unchanged', orderDateShort('not a date'), 'not a date');
check('short date: empty is returned unchanged', orderDateShort(''), '');
// The two really are different, which is the whole reason the second function exists.
check(
    'short date: it is shorter than the long form',
    strlen(orderDateShort('2026-09-17 13:09:00')) < strlen(orderDate('2026-09-17 13:09:00')),
    true
);


/*
 * The arithmetic placeOrder() borrows. Not re-implemented here -- the point of these two is that
 * the order and the basket it came from cannot disagree, so what is worth pinning is that the
 * order module is using the basket's helpers and getting the basket's answers.
 */
check('money: a line of one', cartLineTotal('249.00', 1), '249.00');
check('money: a line of three', cartLineTotal('249.00', 3), '747.00');
check('money: centavos do not drift over a large quantity', cartLineTotal('0.07', 99), '6.93');
check('money: a zero quantity is free', cartLineTotal('249.00', 0), '0.00');

$lines = [
    ['unitPrice' => '249.00', 'quantity' => 2, 'orderable' => true],
    ['unitPrice' => '75.50', 'quantity' => 3, 'orderable' => true],
];
check('money: a basket totals', cartTotal($lines), '724.50');

$linesWithGone = array_merge($lines, [['unitPrice' => '999.00', 'quantity' => 1, 'orderable' => false]]);
check('money: a withdrawn line is not in the total', cartTotal($linesWithGone), '724.50');
check('money: an empty basket is zero', cartTotal([]), '0.00');


/*
 * ===================================================================
 *   Tracking: the timeline, and the messages that go with it
 * ===================================================================
 *
 * orderTimeline() is pure -- it is handed an order and its events and touches no database -- which
 * is the whole reason it was written that way: every shape of history can be checked here, including
 * the ones that are a nuisance to produce over HTTP. The writes that build those histories are
 * tested in orders-http-test.php, where there is a real row to move.
 *
 * config/orders-mail.php is required late, on purpose. It pulls in the mailer, which pulls in
 * config/mail.php -- so requiring it at the top of this file would mean every unrelated assertion
 * above ran with a transport loaded.
 */
require_once __DIR__ . '/../config/orders-mail.php';

/** An order as hydrateOrder() hands it back, with only the parts a timeline or a message reads. */
function trackedOrder(array $overrides = []): array
{
    return array_merge([
        'reference' => 'SLJ-20260922-AB12',
        'status' => 'placed',
        'placedAt' => '2026-09-21 08:35:00',
        'fulfilment' => 'delivery',
        'contactName' => 'Maria Santos',
        'contactEmail' => 'maria@example.test',
        'total' => '348.00',
    ], $overrides);
}

/** One row of order_events, in the shape fetchOrderEvents() returns. */
function trackedEvent(string $status, string $at, string $actor = 'shop'): array
{
    return ['status' => $status, 'actor' => $actor, 'actor_user_id' => 7, 'happened_at' => $at];
}

/** Just the labels, for asserting the shape of a timeline in one line. */
function timelineLabels(array $dots): array
{
    return array_map(static fn (array $dot): string => $dot['label'], $dots);
}

/** Just the states: done, now or todo. */
function timelineStates(array $dots): array
{
    return array_map(static fn (array $dot): string => $dot['state'], $dots);
}

/** Just who moved each step, which only php/admin/order.php draws. */
function timelineActors(array $dots): array
{
    return array_map(static fn (array $dot): string => $dot['actor'], $dots);
}

// A brand new order: one dot that has happened and two that have not. The empty dots are half of
// what a customer opens the page for -- they say what is still coming.
$fresh = orderTimeline(trackedOrder(), []);
check('timeline: a new order has three dots', count($fresh), 3);
check('timeline: a new order reads placed, being made, delivered', timelineLabels($fresh), ['Placed', 'Being made', 'Delivered']);
check('timeline: a new order is at the first of them', timelineStates($fresh), ['now', 'todo', 'todo']);
check('timeline: the first dot is the order placed_at', $fresh[0]['at'], '2026-09-21 08:35:00');
check('timeline: a dot that has not happened has no time', $fresh[1]['at'], '');

// Being made. The dot the shop just made is "now", the one behind it is done, delivery is still to
// come. This is the state most orders will be read in.
$started = orderTimeline(
    trackedOrder(['status' => 'packed']),
    [trackedEvent('packed', '2026-09-22 09:10:00')]
);
check('timeline: a started order still has three dots', count($started), 3);
check('timeline: a started order is at the second', timelineStates($started), ['done', 'now', 'todo']);
check('timeline: the second dot carries the event time', $started[1]['at'], '2026-09-22 09:10:00');

// Delivered. Nothing further is coming, so nothing further is drawn.
$done = orderTimeline(
    trackedOrder(['status' => 'delivered']),
    [trackedEvent('packed', '2026-09-22 09:10:00'), trackedEvent('delivered', '2026-09-23 14:05:00')]
);
check('timeline: a delivered order draws no empty dots', timelineStates($done), ['done', 'done', 'now']);
check('timeline: a delivered order ends at delivered', timelineLabels($done), ['Placed', 'Being made', 'Delivered']);

// Cancelled from placed. Also no empty dots: a hollow "Delivered" under a cancellation would read
// as a promise.
$cancelled = orderTimeline(
    trackedOrder(['status' => 'cancelled']),
    [trackedEvent('cancelled', '2026-09-22 10:00:00', 'customer')]
);
check('timeline: a cancellation is the last dot', timelineLabels($cancelled), ['Placed', 'Cancelled']);
check('timeline: a cancellation promises nothing further', timelineStates($cancelled), ['done', 'now']);

// Cancelled halfway through being made, which is the shape that would tempt a fixed four-step
// ladder into drawing the cancellation before the work.
$cancelledLate = orderTimeline(
    trackedOrder(['status' => 'cancelled']),
    [trackedEvent('packed', '2026-09-22 09:10:00'), trackedEvent('cancelled', '2026-09-22 11:30:00')]
);
check('timeline: a late cancellation keeps the work before it', timelineLabels($cancelledLate), ['Placed', 'Being made', 'Cancelled']);

// The undo. A second "Placed" with no explanation would look like a bug in the page rather than a
// correction in the shop, and the superseded dot is not deleted -- that would be a history table
// that edits history.
$undone = orderTimeline(
    trackedOrder(['status' => 'placed']),
    [trackedEvent('packed', '2026-09-22 09:10:00'), trackedEvent('placed', '2026-09-22 09:11:00')]
);
check('timeline: an undo says it went back', timelineLabels($undone), ['Placed', 'Being made', 'Moved back to Placed', 'Being made', 'Delivered']);
check('timeline: an undo keeps the dot it undid', timelineStates($undone), ['done', 'done', 'now', 'todo', 'todo']);

// Straight to delivered with no packed event -- which the buttons refuse, but a row edited in the
// database does not. The steps ahead are measured from the order's own status, so it still reads
// correctly rather than offering to deliver something already delivered.
$jumped = orderTimeline(
    trackedOrder(['status' => 'delivered']),
    [trackedEvent('delivered', '2026-09-22 16:00:00')]
);
check('timeline: a jump to delivered draws no empty dots', timelineStates($jumped), ['done', 'now']);

// A status the ENUM cannot hold. Not reachable through the site; worth pinning because the answer
// has to be a page that renders rather than a crash.
$junk = orderTimeline(trackedOrder(['status' => 'zzz']), []);
check('timeline: an unknown status still draws placement', timelineLabels($junk), ['Placed']);
check('timeline: an unknown status offers no steps ahead', count($junk), 1);

// orderEventLabel() on its own, including the pair the sequence cannot rank.
check('timeline: forward is just the label', orderEventLabel('delivered', 'packed'), 'Delivered');
check('timeline: back two steps is still back', orderEventLabel('placed', 'delivered'), 'Moved back to Placed');
check('timeline: cancelled is never "moved back"', orderEventLabel('cancelled', 'packed'), 'Cancelled');
check('timeline: the same state twice is not a move back', orderEventLabel('packed', 'packed'), 'Being made');

/*
 * Who moved each step. Only php/admin/order.php draws this, and it is the whole reason it is
 * carried: a cancellation the customer made and one the shop made are the same word in the status
 * column and are not the same conversation to have.
 *
 * Placement is the customer's by definition -- an order exists because somebody checked out -- and
 * a step that has not happened has nobody to name, so it says nothing rather than guessing.
 */
$mixed = orderTimeline(
    trackedOrder(['status' => 'cancelled']),
    [trackedEvent('packed', '2026-09-22 09:10:00', 'shop'), trackedEvent('cancelled', '2026-09-22 11:30:00', 'customer')]
);
check('timeline: placement is always the customer', $fresh[0]['actor'], 'customer');
check('timeline: a step not yet taken names nobody', $fresh[1]['actor'], '');
check('timeline: each event keeps its own actor', timelineActors($mixed), ['customer', 'shop', 'customer']);
check('timeline: a shop cancellation says shop', timelineActors(orderTimeline(
    trackedOrder(['status' => 'cancelled']),
    [trackedEvent('cancelled', '2026-09-22 11:30:00', 'shop')]
)), ['customer', 'shop']);


/*
 * The four messages. A subject titled for the wrong event is the failure worth guarding against --
 * config/twofactor.php records why -- so what is pinned is that all four differ and all four carry
 * the reference somebody has written down.
 */
$reference = 'SLJ-20260922-AB12';
$subjects = array_map(
    static fn (string $event): string => orderMailSubject($event, $reference),
    ORDER_MAIL_EVENTS
);

check('mail: there are four events', count(ORDER_MAIL_EVENTS), 4);
check('mail: all four subjects differ', count(array_unique($subjects)), 4);
check('mail: every subject carries the reference', count(array_filter($subjects, static fn (string $s): bool => str_contains($s, $reference))), 4);
check('mail: started', orderMailSubject(ORDER_MAIL_PACKED, $reference), 'We have started on order ' . $reference);
check('mail: paid', orderMailSubject(ORDER_MAIL_PAID, $reference), 'Payment received for order ' . $reference);
check('mail: an event nobody named is still titled honestly', orderMailSubject('zzz', $reference), 'An update about order ' . $reference);

// Delivery and collection are told apart, for the reason paymentMethodLabel() tells cash apart: a
// message saying a bouquet was delivered to somebody who walked in and collected it is wrong in the
// one way that matters.
$deliveredBody = orderMailBody(trackedOrder(['status' => 'delivered']), ORDER_MAIL_DELIVERED);
$collectedBody = orderMailBody(trackedOrder(['status' => 'delivered', 'fulfilment' => 'pickup']), ORDER_MAIL_DELIVERED);
checkTrue('mail: a delivery says delivered', str_contains($deliveredBody, 'has been delivered'));
checkTrue('mail: a collection says collected', str_contains($collectedBody, 'has been collected'));
checkTrue('mail: a collection never says delivered', !str_contains($collectedBody, 'delivered'));

// Every body has to carry the three things that make it actionable: who it is to, which order, and
// a link that works from inside an inbox.
$paidBody = orderMailBody(trackedOrder(), ORDER_MAIL_PAID);
checkTrue('mail: addressed by the name frozen on the order', str_contains($paidBody, 'Hello Maria Santos,'));
checkTrue('mail: carries the reference', str_contains($paidBody, $reference));
checkTrue('mail: carries the total as pesos', str_contains($paidBody, formatPrice('348.00')));
checkTrue('mail: links with a host a mail client can resolve', str_contains($paidBody, 'http://localhost/Capstone/php/order.php?ref=' . $reference));
checkTrue('mail: signed with the shop name', str_contains($paidBody, SITE_NAME));

// siteUrl() itself. url() is root-relative and right for every link on a page; this is the one
// place that needs a host, and it is the reason SITE_HOST exists.
check('mail: siteUrl spells out the host', siteUrl('php/order.php'), 'http://localhost/Capstone/php/order.php');
check('mail: siteUrl with no path is the site root', siteUrl(), 'http://localhost/Capstone/');
check('mail: siteUrl does not double a leading slash', siteUrl('/php/orders.php'), 'http://localhost/Capstone/php/orders.php');

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
