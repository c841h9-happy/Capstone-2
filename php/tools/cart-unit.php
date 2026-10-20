<?php

declare(strict_types=1);

/**
 * Unit checks for the parts of config/cart.php that are pure arithmetic or pure string work.
 *
 * CLI only, touches no database and no session. Everything here is a function that takes values
 * and returns a value, which is exactly the set worth testing this way -- the queries are tested
 * over Apache, where a real cookie and a real session exist.
 *
 *     C:\xampp\php\php.exe php\tools\cart-unit.php
 */

// Same guard as the two tools beside this one. This file is under the webroot, so without it
// Apache would happily serve it -- and the first thing it does is overwrite DOCUMENT_ROOT.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Apache's conditions, reproduced before the first basePath() call caches its answer.
 *
 * Without this the redirect-guard checks below silently test a different install. Under CLI there
 * is no DOCUMENT_ROOT, and paths.php's realpath('') resolves to the working directory -- which,
 * run from the project folder, equals the project root, so basePath() concludes the site is served
 * from "/" and every absolute path is legitimately same-site. That is correct behaviour for a
 * root-served install and useless for testing this one, where the base path is "/Capstone/".
 */
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/cart.php';

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

/*
 * cartQuantity(): the only guard on how many of a thing goes in the basket.
 *
 * This matters more here than it looks. This server's sql_mode has no STRICT_TRANS_TABLES, so a
 * SMALLINT UNSIGNED column silently clamps a negative to 0 rather than refusing it -- the column
 * cannot be the guard, so these clamps are.
 */
check('quantity: one stays one', cartQuantity(1), 1);
check('quantity: a middling number is kept', cartQuantity(7), 7);
check('quantity: the ceiling is kept', cartQuantity(99), 99);
check('quantity: zero becomes the minimum', cartQuantity(0), 1);
check('quantity: negative becomes the minimum', cartQuantity(-5), 1);
check('quantity: over the ceiling is clamped', cartQuantity(100), 99);
check('quantity: far over the ceiling is clamped', cartQuantity(999999), 99);
check('quantity: a numeric string works', cartQuantity('3'), 3);
check('quantity: a string with spaces works', cartQuantity(' 4 '), 4);
check('quantity: junk becomes the minimum', cartQuantity('abc'), 1);
check('quantity: empty becomes the minimum', cartQuantity(''), 1);
check('quantity: null becomes the minimum', cartQuantity(null), 1);
check('quantity: an array becomes the minimum', cartQuantity(['9']), 1);
check('quantity: true becomes the minimum', cartQuantity(true), 1);
check('quantity: hex notation is not a number', cartQuantity('0x10'), 1);
check('quantity: a negative string is clamped up', cartQuantity('-99'), 1);

/*
 * A fraction is not a quantity, so it lands on the minimum rather than being rounded or truncated.
 *
 * Checked because it is the one clamp that could reasonably have gone the other way, and because it
 * has to agree with customBloomCount() -- which does the same thing, landing on its own default.
 * Half a bouquet is not a thing to guess the meaning of.
 */
check('quantity: a decimal string is not an integer', cartQuantity('6.7'), 1);
check('quantity: a float is not an integer', cartQuantity(6.7), 1);
// A float that happens to be whole is a whole quantity, and is taken as one. Only the fractional
// part is the problem; 6.0 of something is unambiguously six.
check('quantity: a whole-numbered float is accepted', cartQuantity(6.0), 6);
check('quantity: scientific notation is not an integer', cartQuantity('1e9'), 1);

/*
 * cartLineTotal(): the frozen unit price times how many, in integer centavos.
 *
 * The .1/.2/.3 cases are the reason it is centavos and not floats: 0.1 + 0.2 is not 0.3 in binary
 * floating point, and a basket that disagrees with the sum of its own lines by one centavo is a
 * bug somebody has to chase.
 */
check('line total: one of something', cartLineTotal('120.00', 1), '120.00');
check('line total: three of something', cartLineTotal('120.00', 3), '360.00');
check('line total: centavos multiply cleanly', cartLineTotal('0.50', 3), '1.50');
check('line total: the classic float trap', cartLineTotal('0.10', 3), '0.30');
check('line total: an odd price times seven', cartLineTotal('1212.35', 7), '8486.45');
check('line total: zero of something is nothing', cartLineTotal('120.00', 0), '0.00');
check('line total: a negative count cannot refund', cartLineTotal('120.00', -3), '0.00');
check('line total: the ceiling still formats', cartLineTotal('9999.99', 99), '989999.01');

/*
 * cartTotal(): the basket's money, leaving out anything that can no longer be bought.
 */
$lines = [
    ['unitPrice' => '250.00', 'quantity' => 2, 'orderable' => true],
    ['unitPrice' => '120.50', 'quantity' => 1, 'orderable' => true],
];
check('basket total: two orderable lines', cartTotal($lines), '620.50');

$lines[] = ['unitPrice' => '999.00', 'quantity' => 3, 'orderable' => false];
check('basket total: a withdrawn line is not charged for', cartTotal($lines), '620.50');

check('basket total: an empty basket is zero', cartTotal([]), '0.00');
check(
    'basket total: every line withdrawn is zero',
    cartTotal([['unitPrice' => '500.00', 'quantity' => 2, 'orderable' => false]]),
    '0.00'
);

/*
 * cartSafeDestination(): the open-redirect guard on the `back` field.
 *
 * The first check is not decoration. Every case below is a prefix test against basePath(), so if
 * that were to come back "/" the whole block would pass while testing nothing -- see the
 * DOCUMENT_ROOT note at the top of this file.
 */
check('back: the base path is the one Apache serves', basePath(), '/Capstone/');

$fallback = '/Capstone/php/cart.php';

check(
    'back: a page inside the site is kept',
    cartSafeDestination('/Capstone/php/product.php?id=3', $fallback),
    '/Capstone/php/product.php?id=3'
);
check(
    'back: the basket itself is kept',
    cartSafeDestination('/Capstone/php/cart.php', $fallback),
    '/Capstone/php/cart.php'
);
check('back: empty falls back', cartSafeDestination('', $fallback), $fallback);
check('back: another host falls back', cartSafeDestination('https://evil.test/login', $fallback), $fallback);
check('back: http on another host falls back', cartSafeDestination('http://evil.test', $fallback), $fallback);
check('back: protocol-relative falls back', cartSafeDestination('//evil.test/login', $fallback), $fallback);
check('back: a backslash trick falls back', cartSafeDestination('/\\evil.test', $fallback), $fallback);
check('back: a backslash anywhere falls back', cartSafeDestination('/Capstone/php\\..\\x', $fallback), $fallback);
check('back: javascript: falls back', cartSafeDestination('javascript:alert(1)', $fallback), $fallback);
check('back: data: falls back', cartSafeDestination('data:text/html,<b>x', $fallback), $fallback);
check('back: mailto: falls back', cartSafeDestination('mailto:a@b.test', $fallback), $fallback);
check('back: a bare hostname falls back', cartSafeDestination('evil.test', $fallback), $fallback);
check('back: a path outside the install falls back', cartSafeDestination('/other/page.php', $fallback), $fallback);
check('back: a newline falls back', cartSafeDestination("/Capstone/x\r\nLocation: http://evil.test", $fallback), $fallback);
check('back: a tab falls back', cartSafeDestination("/Capstone/\tx", $fallback), $fallback);
check('back: a null byte falls back', cartSafeDestination("/Capstone/x\0.php", $fallback), $fallback);
check('back: an overlong value falls back', cartSafeDestination('/Capstone/' . str_repeat('a', 600), $fallback), $fallback);
check(
    'back: a near-miss prefix falls back',
    cartSafeDestination('/CapstoneEvil/php/cart.php', $fallback),
    $fallback
);

/*
 * cartDesignName(): what a designed bouquet is called in the basket.
 *
 * Count plus arrangement, not a list of parts -- enough to tell two lines apart at a glance, which
 * is all the name has to do. The parts are listed underneath the line on the page anyway, so
 * repeating them in the name would be the same information twice at twice the length, and the
 * column is VARCHAR(120).
 */
check(
    'design name: six domed flowers',
    cartDesignName(['count' => 6, 'arrangement' => 'dome']),
    'Custom bouquet: 6 flowers, domed'
);
check(
    'design name: a single flower is not pluralised',
    cartDesignName(['count' => 1, 'arrangement' => 'dome']),
    'Custom bouquet: 1 flower, domed'
);
check(
    'design name: a round posy',
    cartDesignName(['count' => 12, 'arrangement' => 'round']),
    'Custom bouquet: 12 flowers, round posy'
);
check(
    'design name: loose and airy',
    cartDesignName(['count' => 24, 'arrangement' => 'loose']),
    'Custom bouquet: 24 flowers, loose and airy'
);

/*
 * An unrecognised arrangement is named as the default rather than printed back. A snapshot is
 * supposed to come from designSnapshot(), so this only happens to a row that was tampered with or
 * frozen before the column existed -- and echoing whatever it holds would put an unvalidated string
 * in the basket under the shop's own voice.
 */
check(
    'design name: junk arrangement reads as the default',
    cartDesignName(['count' => 3, 'arrangement' => 'wheelbarrow']),
    'Custom bouquet: 3 flowers, domed'
);
check(
    'design name: an empty arrangement reads as the default',
    cartDesignName(['count' => 3, 'arrangement' => '']),
    'Custom bouquet: 3 flowers, domed'
);
check(
    'design name: the longest name fits the column',
    strlen(cartDesignName(['count' => 24, 'arrangement' => 'loose'])) <= 120,
    true
);

/*
 * cartPartColour(): the swatch colour for a frozen part.
 *
 * Only the no-component paths are checked here, because they are the ones that answer before any
 * query runs. A real component id means a database read, which belongs in the Apache suite -- and
 * the fallback is the branch worth pinning anyway: it is what a part withdrawn between the snapshot
 * and the freeze lands on, and it must be the neutral grey rather than black, since an unexplained
 * black swatch reads as a colour somebody chose.
 */
check('colour: a zero component id falls back', cartPartColour(['componentId' => 0]), COMPONENT_FALLBACK_HEX);
check('colour: null falls back', cartPartColour(['componentId' => null]), COMPONENT_FALLBACK_HEX);
check('colour: a negative id falls back', cartPartColour(['componentId' => -1]), COMPONENT_FALLBACK_HEX);
check('colour: junk falls back', cartPartColour(['componentId' => 'abc']), COMPONENT_FALLBACK_HEX);
check('colour: the fallback is a real hex colour', (bool) preg_match('/^#[0-9a-f]{6}$/i', COMPONENT_FALLBACK_HEX), true);

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
