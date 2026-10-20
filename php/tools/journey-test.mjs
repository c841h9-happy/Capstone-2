/*
 * The visitor's whole journey, walked once in a real browser.
 *
 *     node php\tools\journey-test.mjs <adminEmail> <adminPassword>
 *
 *   sign in (with the real 2FA step) -> add to basket -> check out
 *   -> sign out -> sign in as the shop -> mark paid -> start making -> mark delivered
 *
 * WHY A BROWSER. Twenty-nine forms on this site carry a hidden CSRF token and fourteen handlers
 * check one. A token that is present but wrong, or a form that posts somewhere the guard does not
 * expect, is invisible until somebody presses the button -- and the seven PHP suites cannot see it,
 * because they build their own request bodies rather than submitting the page's markup. This
 * presses the real buttons on the real pages, and every form it touches is checked for a token
 * first, so a failure names the form that lost it.
 *
 * It is also the only test of the whole thing joined up. Each suite proves its own module; nothing
 * else proves that a basket filled on one screen becomes an order on another and parts off a shelf
 * on a third.
 *
 * WHAT THE BROWSER CANNOT SEE is checked straight after, from the database, by
 * php\tools\journey-fixtures.php check -- including the stock half, which did not exist when this
 * walk was first written: Start making must take the recipe out once, and Mark delivered must take
 * nothing more.
 *
 * The 2FA code is read out of capstone-mail.log, where MAIL_TRANSPORT 'log' puts it. It is held in
 * a variable and never printed: that file holds live codes for real accounts.
 */

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

import {
    SHOP,
    check, checkTrue, fillAndSubmit, goto, heading, launch, note,
    pressAction, report, setViewport, signIn, signOut, text, where,
} from './lib/browser.mjs';

const PHP = 'C:\\xampp\\php\\php.exe';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(HERE, 'journey-fixtures.php');

const PORT = 9232;

const [adminEmail, adminPassword] = process.argv.slice(2);

if (!adminPassword) {
    console.error('usage: node php\\tools\\journey-test.mjs <adminEmail> <adminPassword>');
    process.exit(2);
}

const fixturesRun = (...args) => execFileSync(PHP, [FIXTURES, ...args], { encoding: 'utf8' }).trim();

async function main() {
    let browser = null;
    let reference = null;

    try {
        const fixtures = JSON.parse(fixturesRun('setup'));
        note('fixtures', `product ${fixtures.productId}, bloom ${fixtures.bloomId} opening at 20`);

        browser = await launch({ port: PORT, name: 'capstone-journey' });
        const { cdp } = browser;
        await setViewport(cdp, 1280, 900, false);

        /* -- the customer ------------------------------------------------ */

        heading('the customer');

        if (!await signIn(cdp, { email: fixtures.email, password: fixtures.password, label: 'customer' })) {
            return;
        }

        await goto(cdp, `${SHOP}/product.php?id=${fixtures.productId}`);
        checkTrue('product: the page is the fixture bouquet',
            (await text(cdp)).includes('ZZTest Journey Bouquet'));

        // The add form posts a hidden action=add-product, which pressAction() finds. Its token is
        // checked on the way past.
        check('basket: the add-to-basket form carries a token and posts',
            await pressAction(cdp, 'add-product'), 'pressed');

        await goto(cdp, `${SHOP}/cart.php`);
        checkTrue('basket: the bouquet is in it', (await text(cdp)).includes('ZZTest Journey Bouquet'));

        await goto(cdp, `${SHOP}/checkout.php`);
        const placed = await fillAndSubmit(cdp, 'contact_name', {
            fulfilment: 'delivery',
            payment_method: 'cod',
            payment_reference: '',
            contact_name: fixtures.buyer,
            contact_phone: '0917 555 0199',
            address_line: '9 ZZTest Lane',
            barangay: 'Poblacion',
            city: 'Cebu City',
            province: 'Cebu',
            postcode: '6000',
            notes: 'Walked by a browser.',
        });
        check('checkout: the form carries a token and posts', placed, 'submitted');

        const landed = await where(cdp);
        reference = decodeURIComponent((landed.match(/ref=([A-Za-z0-9%-]+)/) || [])[1] ?? '');

        if (!checkTrue('checkout: it landed on an order with a reference', reference !== '')) {
            note('landed on', landed);
            return;
        }

        note('the order the browser placed', reference);
        checkTrue('checkout: and the order page names the bouquet',
            (await text(cdp)).includes('ZZTest Journey Bouquet'));

        /* -- signing out is a form too ----------------------------------- */

        check('logout: the form carries a token and posts', await signOut(cdp), 'submitted');

        await goto(cdp, `${SHOP}/orders.php`);
        checkTrue('logout: the shop no longer knows this browser',
            (await where(cdp)).includes('login.php'));

        /* -- the shop ----------------------------------------------------- */

        heading('the shop');

        if (!await signIn(cdp, { email: adminEmail, password: adminPassword, label: 'shop' })) {
            return;
        }

        const orderPage = `${SHOP}/admin/order.php?ref=${encodeURIComponent(reference)}`;

        /*
         * 'paid' is not asserted against the page text: the order page says "Not paid" before the
         * button is pressed and a substring search is happy with either. What the browser proves is
         * that the button posts and is not refused; what the press actually did is read from the
         * database below.
         */
        for (const action of ['pay', 'pack', 'deliver']) {
            await goto(cdp, orderPage);
            check(`shop: the ${action} button carries a token and posts`,
                await pressAction(cdp, action), 'pressed');
            check(`shop: and ${action} was not refused`,
                (await text(cdp)).includes('That form had expired'), false);
        }

        await goto(cdp, orderPage);
        checkTrue('shop: the order now reads delivered',
            (await text(cdp)).toLowerCase().includes('delivered'));

        /* -- what the database says -------------------------------------- */

        heading('what the database says');

        const db = JSON.parse(fixturesRun('check', reference));

        if (!checkTrue('the order is really there', db.found === true)) return;

        check('it belongs to the shopper who placed it', db.email, fixtures.email);
        check('its status is delivered', db.status, 'delivered');
        check('its payment is paid', db.payment, 'paid');
        check('it holds the one bouquet', db.lines, 1);
        check('and the line names it', db.lineName, 'ZZTest Journey Bouquet');
        check('checkout emptied the basket rather than copying it', db.basketLeft, 0);
        check('the shop is recorded as delivering it once', db.deliveredByShop, 1);
        note('the history the customer sees', db.history);

        // Start making took the recipe out: 3 blooms and 3 stems, two rows, both 'used'.
        check('making it took its parts out', db.used, 2);
        check('six parts in total', db.unitsOut, 6);
        check('the bloom went from 20 to 17', db.bloomLeft, 17);
        // Four rows would mean delivering consumed a second time, which is the double-count the
        // ledger-derived design exists to make impossible.
        check('and delivering moved no stock at all', db.moves, 2);
        check('the books balance', db.audit, 0);
    } finally {
        if (browser) await browser.close();

        try {
            fixturesRun('sweep');
            note('fixtures', 'swept');
        } catch (error) {
            check('the fixtures were swept', `failed: ${error.message}`, 'swept');
        }
    }

    process.exit(report('journey'));
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
