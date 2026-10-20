/*
 * The cross-site attack, watched once in a real browser.
 *
 *     node php\tools\csrf-browser-test.mjs
 *
 * A page that is not the shop autosubmits a form at php\cart-update.php asking it to empty the
 * visitor's basket. Before php\config\csrf.php that worked. What should happen now is that the
 * request arrives, is refused, and the basket still holds what the visitor put in it.
 *
 * THE ATTACKER PAGE IS SERVED OVER HTTP ON PORT 8099, AND THAT IS THE WHOLE POINT.
 *
 * SameSite is about *site*, not origin, and **a port is not part of a site** -- so localhost:8099
 * and localhost:80 are same-site, the browser sends the session cookie, and the request reaches the
 * handler with the visitor's basket attached. A file:// page would have been stopped by cookie
 * rules before the handler ever ran and would have proved nothing at all about the token. This is
 * the harder case deliberately, and it is also why php\config\session's empty cookie_samesite is
 * recorded under OPEN ISSUES as less of a mitigation than it sounds.
 *
 * php\tools\csrf-http-test.php already proves the token is checked, by building request bodies.
 * This proves the other half: that a REAL browser, carrying REAL cookies, driven from somewhere
 * else, cannot get through. Only one of those two is the thing an attacker actually does.
 *
 * Fixtures come from php\tools\layout-fixtures.php rather than a third copy of the same thing -- it
 * already makes an active ZZTest bouquet and a shopper to own the basket, and already sweeps both.
 */

import { createServer } from 'node:http';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

import {
    SHOP,
    check, checkTrue, goto, heading, launch, note, pressAction, report,
    settle, setViewport, signIn, sleep, text, where,
} from './lib/browser.mjs';

const PHP = 'C:\\xampp\\php\\php.exe';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(HERE, 'layout-fixtures.php');

const PORT = 9233;
const ATTACKER_PORT = 8099;

const fixturesRun = (mode) => execFileSync(PHP, [FIXTURES, mode], { encoding: 'utf8' }).trim();

const ATTACK_PAGE = `<!doctype html>
<html>
  <head><meta charset="utf-8"><title>Free stems, honestly</title></head>
  <body>
    <p>Nothing to see here.</p>
    <form id="f" method="post" action="${SHOP}/cart-update.php">
      <input type="hidden" name="action" value="empty">
      <input type="hidden" name="back" value="/Capstone/php/cart.php">
    </form>
    <script>document.getElementById('f').submit();</script>
  </body>
</html>`;

async function main() {
    let browser = null;
    let attacker = null;

    try {
        const fixtures = JSON.parse(fixturesRun('setup'));

        attacker = createServer((request, response) => {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            response.end(ATTACK_PAGE);
        });
        await new Promise((resolve) => attacker.listen(ATTACKER_PORT, resolve));
        note('attacker page serving on', `http://localhost:${ATTACKER_PORT}/`);

        browser = await launch({ port: PORT, name: 'capstone-csrf' });
        const { cdp } = browser;
        await setViewport(cdp, 1280, 900, false);

        /* -- the visitor uses the shop normally -------------------------- */

        heading('a visitor fills a basket');

        if (!await signIn(cdp, { email: fixtures.email, password: fixtures.password, label: 'shopper' })) {
            return;
        }

        await goto(cdp, `${SHOP}/product.php?id=${fixtures.productId}`);
        check("the shop's own add-to-basket form carries a token",
            await pressAction(cdp, 'add-product'), 'pressed');

        await goto(cdp, `${SHOP}/cart.php`);
        checkTrue('the basket has the bouquet in it',
            (await text(cdp)).includes('ZZTest Layout Bouquet'));

        /* -- and then visits somewhere else ------------------------------ */

        heading('and then opens a page that is not the shop');

        await goto(cdp, `http://localhost:${ATTACKER_PORT}/`);
        // The form autosubmits on load and the shop answers with its own page; give the navigation
        // it starts time to finish before reading where we ended up.
        await sleep(1200);
        await settle(cdp);

        const landedOn = await where(cdp);
        const after = await text(cdp);

        note('the attack landed on', landedOn);
        note('the shop answered', (after.match(/^.*expired.*$/mi) || ['(no expiry message found)'])[0].trim());

        checkTrue('the shop said the form had expired', after.includes('That form had expired'));

        /*
         * Then look at the basket itself, rather than trusting that the refusal page happened to
         * render it. "The attack was refused" and "the basket survived" are two claims, and only
         * the second one is what the visitor cares about.
         */
        await goto(cdp, `${SHOP}/cart.php`);
        checkTrue('and the basket still has the bouquet',
            (await text(cdp)).includes('ZZTest Layout Bouquet'));
    } finally {
        if (browser) await browser.close();
        if (attacker) attacker.close();

        try {
            fixturesRun('sweep');
            note('fixtures', 'swept');
        } catch (error) {
            check('the fixtures were swept', `failed: ${error.message}`, 'swept');
        }
    }

    process.exit(report('csrf-browser'));
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
