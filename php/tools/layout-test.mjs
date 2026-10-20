/*
 * Every screen this site has, measured in a real browser at two widths.
 *
 *     node php\tools\layout-test.mjs <adminEmail> <adminPassword>
 *     node php\tools\layout-test.mjs <adminEmail> <adminPassword> --locate <url>
 *
 * WHY THIS EXISTS. Three phone-width layout faults have shipped on this project -- 2026-09-27,
 * 2026-09-29 and 2026-10-02 -- and every one of them was found by a throwaway script in %TEMP%
 * rather than by anything in the repo. The seven PHP suites could never have seen any of them: they
 * assert markup and database state, and a page that scrolls sideways has perfectly good markup.
 * The 2026-10-01 stylesheet revert that lost 37 selectors across four finished modules went
 * unnoticed by all seven for the same reason.
 *
 * So this is the assertion half of "look at the screenshots". It still writes the pictures, and
 * they are still meant to be opened -- measurement alone has missed real faults here too -- but the
 * things that can be stated are now stated.
 *
 * FIVE QUESTIONS AT EVERY SCREEN, AT EVERY WIDTH:
 *
 *   1. Does the document measure wider than the window?
 *   2. Will it actually move when pushed? (scrollWidth is a hint; sliding is the fact)
 *   3. Does anything stick out past the right edge, scroll boxes excepted?
 *   4. Does everything inside a scroll box fit that box's own scrollable width?
 *   5. Does any panel's content spill past the panel without moving the document at all?
 *
 * Question 5 is the one that has no symptom. On 2026-09-29 the dashboard's bar chart spilled across
 * the panel beside it while window.scrollX stayed 0 at every width, so questions 1-3 all said the
 * screen was fine while it was visibly broken.
 *
 * Then a 320->1440 sweep in 20px steps over the three widest screens, because hand-picked widths
 * miss faults that live between round numbers: a fix verified at 320/360/390/979/1440 still hung
 * 12px off the right edge between roughly 981px and 1050px.
 *
 * --locate <url> runs a different pass entirely: it hides one candidate at a time and re-measures.
 * That is what actually found the 2026-10-02 cause, after the overflow report had blamed three
 * innocent elements -- see the comment on locate() below.
 *
 * No npm packages. The CDP client, the sign-in and the three measurements are in lib/browser.mjs.
 */

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

import {
    SHOP, INNER_SPILL, OVERFLOW, SCROLLS_SIDEWAYS, TABLE_FIT,
    check, checkTrue, evaluate, goto, heading, launch, note, report,
    pressAction, setViewport, shoot, signIn, signOut, sleep, text, where,
} from './lib/browser.mjs';

const PHP = 'C:\\xampp\\php\\php.exe';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(HERE, 'layout-fixtures.php');

const PORT = 9231;

const argv = process.argv.slice(2);
const locateUrl = argv.includes('--locate') ? argv[argv.indexOf('--locate') + 1] : null;
const [adminEmail, adminPassword] = argv;

if (!adminPassword) {
    console.error('usage: node php\\tools\\layout-test.mjs <adminEmail> <adminPassword> [--locate <url>]');
    process.exit(2);
}

/*
 * 1280x800 is the desktop this project has always checked at. 390x844 is an iPhone 14 and is below
 * the 720px breakpoint the navbar collapses at, which is the width most worth seeing.
 *
 * mobile: true on the narrow one so the meta viewport is honoured the way a phone honours it.
 */
const WIDTHS = [
    { label: 'desktop', width: 1280, height: 800, mobile: false },
    { label: 'phone', width: 390, height: 844, mobile: true },
];

/*
 * The headings a page shows when it has given up. Asserted absent everywhere, because the real
 * failure of a browser pass is not a broken layout -- it is measuring the Not found page and
 * reporting that it fits beautifully.
 */
const FAILURE_HEADINGS = [
    'We could not load that',
    'Product not found',
    'Order not found',
    'Part not found',
    'Not allowed',
    'That form had expired',
    'The designer is unavailable',
];

const fixturesRun = (mode) => execFileSync(PHP, [FIXTURES, mode], { encoding: 'utf8' }).trim();

/* ------------------------------------------------------------------ *
 * One screen, at one width.
 * ------------------------------------------------------------------ */

async function measure(cdp, screen, size) {
    await setViewport(cdp, size.width, size.height, size.mobile);
    await goto(cdp, screen.url);
    await sleep(250);

    heading(`${screen.name} @ ${size.width}`);

    const body = await text(cdp);
    const landed = await where(cdp);

    // Did we even get the page we asked for? Every assertion below is worthless otherwise.
    const failed = FAILURE_HEADINGS.find((h) => body.includes(h));
    if (!checkTrue('it is the screen we asked for', failed === undefined)) {
        note('page says', failed);
        note('landed on', landed);
        return;
    }

    checkTrue('PHP printed nothing into the page',
        !body.includes('Warning:') && !body.includes('Fatal error') && !body.includes('Notice:')
        && !body.includes('Deprecated:'));

    if (screen.expect) {
        if (!checkTrue(`it drew its own content (${screen.expect})`, body.includes(screen.expect))) {
            note('landed on', landed);
        }
    }

    const scrollWidth = await evaluate(cdp, 'document.documentElement.scrollWidth');
    if (!check('the document is no wider than the window', scrollWidth <= size.width + 1, true)) {
        note('document scrollWidth', `${scrollWidth} against a ${size.width} viewport`);
    }

    check('and will not move when pushed', await evaluate(cdp, SCROLLS_SIDEWAYS), 0);
    check('nothing hangs off the right edge', await evaluate(cdp, OVERFLOW), '');
    check('nothing spills out of its own panel', await evaluate(cdp, INNER_SPILL), '');

    const fit = await evaluate(cdp, TABLE_FIT);
    if (fit === 'no table') note('tables', 'this screen has none at this width');
    else check('every table fits inside its own scroll box', fit, '');

    note('shot', await shoot(cdp, `${screen.name}-${size.width}.png`, { into: screen.into }));
}

async function walk(cdp, screens) {
    for (const screen of screens) {
        for (const size of WIDTHS) {
            await measure(cdp, screen, size);
        }
    }
}

/* ------------------------------------------------------------------ *
 * The sweep: every width, not the round ones.
 * ------------------------------------------------------------------ */

async function sweepWidths(cdp, screens) {
    heading('every width from 320 to 1440, in 20px steps');

    for (const screen of screens) {
        await goto(cdp, screen.url);

        const slides = [];
        const spills = [];

        for (let width = 320; width <= 1440; width += 20) {
            await setViewport(cdp, width, 900, false);
            await sleep(60);

            if (await evaluate(cdp, SCROLLS_SIDEWAYS) !== 0) slides.push(width);
            if (await evaluate(cdp, INNER_SPILL) !== '') spills.push(width);
        }

        const band = (list) => (list.length === 0 ? '' : `${list[0]}-${list[list.length - 1]}`);

        if (!check(`${screen.name}: never slides, at any width`, slides.length, 0)) {
            note('slides between', band(slides));
        }
        if (!check(`${screen.name}: nothing spills, at any width`, spills.length, 0)) {
            note('spills between', band(spills));
        }
    }
}

/* ------------------------------------------------------------------ *
 * --locate: which element is actually forcing the page wide.
 * ------------------------------------------------------------------ */

/*
 * Hide one candidate at a time and re-measure, rather than measuring elements one by one.
 *
 * This exists because the obvious approach lies. On 2026-10-02 the overflow report named
 * ol.order-timeline, li.order-timeline-step and span.order-timeline-at -- all sitting at the same
 * right edge, all perfectly innocent. Identical right edges are what "stretched to fill an
 * over-wide parent" looks like; the culprit was .order-main's grid children having no min-width: 0,
 * two levels up and not in the list at all.
 *
 * Forcing `width: min-content` on a probe does not help either: it overrides the very min-width: 0
 * and scroll-container behaviour that decides the answer, so a scroll box reports its content's
 * width and reads as a cause. Removing a thing and seeing whether the page recovers is the only
 * question with an honest answer.
 */
async function locate(cdp, url) {
    heading(`what is forcing ${url} wide at 390`);

    await setViewport(cdp, 390, 844, true);
    await goto(cdp, url);
    await sleep(250);

    const doc = 'document.documentElement.scrollWidth';
    note('as it stands', `${await evaluate(cdp, doc)}px against a 390 viewport`);

    const steps = [
        ['every table hidden', `document.querySelectorAll('.admin-table-wrap, .table-wrap, table').forEach(el => el.style.display = 'none')`],
        ['...and every panel hidden', `document.querySelectorAll('.panel, .chart-panel').forEach(el => el.style.display = 'none')`],
        ['...and the whole main column hidden', `document.querySelectorAll('.order-main, main').forEach(el => el.style.display = 'none')`],
    ];

    for (const [label, script] of steps) {
        await evaluate(cdp, script);
        await sleep(120);
        note(label, `${await evaluate(cdp, doc)}px`);
    }

    /*
     * Then one panel at a time, from a clean page each time -- the compounding pass above says
     * which LAYER the fault is in, this says which panel.
     */
    await goto(cdp, url);
    await sleep(200);

    const panels = await evaluate(cdp, `
        [...document.querySelectorAll('.panel, .chart-panel')]
            .map((p) => p.querySelector('h1, h2, h3')?.textContent.trim() ?? '(unnamed)')
    `);

    for (let index = 0; index < panels.length; index++) {
        await goto(cdp, url);
        await sleep(150);
        await evaluate(cdp, `
            (() => {
                const panel = [...document.querySelectorAll('.panel, .chart-panel')][${index}];
                if (panel) panel.style.display = 'none';
                return true;
            })()
        `);
        await sleep(120);

        const width = await evaluate(cdp, doc);
        note(`without "${panels[index]}"`, `${width}px${width <= 391 ? '   <-- this one' : ''}`);
    }
}

/* ------------------------------------------------------------------ *
 * The screens.
 * ------------------------------------------------------------------ */

function publicScreens(fixtures) {
    return [
        { name: 'home', url: `${SHOP}/index.php`, expect: 'wire' },
        { name: 'catalogue', url: `${SHOP}/products.php`, expect: 'Our flowers' },
        { name: 'product', url: `${SHOP}/product.php?id=${fixtures.productId}`, expect: 'ZZTest Layout Bouquet' },
        { name: 'customizer', url: `${SHOP}/customize.php`, expect: 'Build your own bouquet' },
        { name: 'signin', url: `${SHOP}/login.php`, expect: 'Sign in to your account' },
        /*
         * login.php?form=signup, not signup.php. That file is four lines and a redirect here --
         * sign-up lives on the same page as sign-in and the old URL is kept so existing links work.
         * Naming the redirect would measure the same panel while reporting a screen that does not
         * render anything, and the ?form= parameter is what opens the panel being measured.
         */
        { name: 'signup', url: `${SHOP}/login.php?form=signup`, expect: 'Create your account' },
        { name: 'basket-empty', url: `${SHOP}/cart.php`, expect: 'Your basket' },
    ];
}

function customerScreens(fixtures) {
    return [
        { name: 'my-dashboard', url: `${SHOP}/dashboard.php`, expect: 'Hello' },
        { name: 'my-profile', url: `${SHOP}/profile.php`, expect: 'Your details' },
        { name: 'my-orders', url: `${SHOP}/orders.php`, expect: 'Your orders' },
        {
            name: 'my-order',
            url: `${SHOP}/order.php?ref=${encodeURIComponent(fixtures.orderRef)}`,
            expect: fixtures.orderRef,
            // The panel that carried the latent 2026-10-02 fault on this very screen.
            into: '.order-main',
        },
        { name: 'my-designs', url: `${SHOP}/designs.php`, expect: 'Saved bouquets' },
        { name: 'basket-full', url: `${SHOP}/cart.php`, expect: 'ZZTest Layout Bouquet' },
        { name: 'checkout', url: `${SHOP}/checkout.php`, expect: 'Checkout' },
    ];
}

/*
 * Admin screens, with the ids of the per-row ones discovered rather than hardcoded.
 *
 * Scraped from the list page that links to them, the way inventory-shots.js already finds a recipe
 * link. A hardcoded id is a test that breaks the next time anyone re-seeds, and it would be testing
 * a row this file does not own.
 */
async function adminScreens(cdp) {
    const found = async (listUrl, pattern, param) => {
        await goto(cdp, listUrl);
        return evaluate(cdp, `
            (() => {
                const link = document.querySelector('a[href*="${pattern}"]');
                return link ? new URL(link.href).searchParams.get('${param}') : null;
            })()
        `);
    };

    const productId = await found(`${SHOP}/admin/products.php`, 'product-edit.php?id=', 'id');
    const componentId = await found(`${SHOP}/admin/components.php`, 'component-edit.php?id=', 'id');
    const orderRef = await found(`${SHOP}/admin/orders.php`, 'admin/order.php?ref=', 'ref');

    const screens = [
        { name: 'admin-dashboard', url: `${SHOP}/admin/dashboard.php`, expect: 'Dashboard' },
        { name: 'admin-orders', url: `${SHOP}/admin/orders.php`, expect: 'Orders' },
        { name: 'admin-products', url: `${SHOP}/admin/products.php`, expect: 'Products' },
        { name: 'admin-materials', url: `${SHOP}/admin/materials.php`, expect: 'Raw supplies' },
        { name: 'admin-material-ledger', url: `${SHOP}/admin/material-moves.php`, expect: 'Every receipt' },
        { name: 'admin-components', url: `${SHOP}/admin/components.php`, expect: 'Components' },
        { name: 'admin-stock', url: `${SHOP}/admin/inventory.php`, expect: 'Stock' },
        { name: 'admin-ledger', url: `${SHOP}/admin/stock-moves.php`, expect: 'Transactions' },
        { name: 'admin-customers', url: `${SHOP}/admin/customers.php`, expect: 'Customers' },
        { name: 'admin-reports', url: `${SHOP}/admin/reports.php`, expect: 'Reports' },
        { name: 'admin-settings', url: `${SHOP}/profile.php`, expect: 'Admin settings' },
    ];

    const add = (id, screen, label) => {
        if (id === null) {
            // Said out loud and counted, not passed over. A screen that could not be reached is a
            // result, and the old silent-return branch is how a check that never ran read as a pass.
            note('SKIP', `${label} -- nothing on its list page to open`);
            return;
        }
        screens.push(screen);
    };

    add(productId, { name: 'admin-product-edit', url: `${SHOP}/admin/product-edit.php?id=${productId}`, expect: 'Edit product' }, 'admin-product-edit');
    add(productId, { name: 'admin-recipe', url: `${SHOP}/admin/product-parts.php?id=${productId}`, expect: 'made of' }, 'admin-recipe');
    add(productId, { name: 'admin-product-materials', url: `${SHOP}/admin/product-materials.php?id=${productId}`, expect: 'Material recipe' }, 'admin-product-materials');
    add(componentId, { name: 'admin-component-edit', url: `${SHOP}/admin/component-edit.php?id=${componentId}`, expect: 'Edit part' }, 'admin-component-edit');
    add(orderRef, {
        name: 'admin-order',
        url: `${SHOP}/admin/order.php?ref=${encodeURIComponent(orderRef ?? '')}`,
        expect: 'What it takes out of stock',
        into: '.order-main',
    }, 'admin-order');

    return screens;
}

/* ------------------------------------------------------------------ *
 * The run.
 * ------------------------------------------------------------------ */

async function main() {
    let fixtures = null;
    let browser = null;

    try {
        fixtures = JSON.parse(fixturesRun('setup'));
        note('fixtures', `product ${fixtures.productId}, order ${fixtures.orderRef}`);

        browser = await launch({ port: PORT, name: 'capstone-layout' });
        const { cdp } = browser;

        if (locateUrl !== null) {
            await signIn(cdp, { email: adminEmail, password: adminPassword, label: 'admin' });
            await locate(cdp, locateUrl);
            return;
        }

        heading('signed out: what a stranger sees');
        await walk(cdp, publicScreens(fixtures));

        heading('signing in as the shopper');
        const shopperIn = await signIn(cdp, {
            email: fixtures.email,
            password: fixtures.password,
            label: 'shopper',
        });

        if (shopperIn) {
            // Through the real button, so cart.php and checkout.php have something to draw. The
            // form posts a hidden action=add-product, which pressAction() handles.
            await setViewport(cdp, 1280, 800, false);
            await goto(cdp, `${SHOP}/product.php?id=${fixtures.productId}`);
            check('the bouquet went into the basket', await pressAction(cdp, 'add-product'), 'pressed');

            heading("signed in: the shopper's own screens");
            await walk(cdp, customerScreens(fixtures));
        }

        heading('signing in as the shop');

        // Signed out first. login.php has no "already signed in" redirect, so posting the sign-in
        // form over the shopper's live session appears to work -- it just means the admin inherits
        // whatever the shopper left on it, which is not a state any visitor is ever in.
        await goto(cdp, `${SHOP}/dashboard.php`);
        note('signed the shopper out', await signOut(cdp));

        const adminIn = await signIn(cdp, { email: adminEmail, password: adminPassword, label: 'admin' });

        if (adminIn) {
            await setViewport(cdp, 1280, 800, false);
            const screens = await adminScreens(cdp);

            heading('signed in: the shop');
            await walk(cdp, screens);

            /*
             * The three with the most to go wrong: the widest table, the only chart, and the grid
             * whose children had no floor. One sweep each, 57 widths apiece.
             */
            await sweepWidths(cdp, [
                screens.find((s) => s.name === 'admin-orders'),
                screens.find((s) => s.name === 'admin-dashboard'),
                screens.find((s) => s.name === 'admin-order'),
            ].filter(Boolean));
        }
    } finally {
        if (browser) await browser.close();

        // Always, even if the walk threw: a fixture left behind is a ZZTest order in the shop's
        // own queue and an active ZZTest bouquet in the public catalogue.
        try {
            fixturesRun('sweep');
            note('fixtures', 'swept');
        } catch (error) {
            // Counted as a failure rather than only printed. process.exit() below would discard a
            // bare process.exitCode, and a sweep that did not run is the one outcome that leaves
            // the shop worse than it was found.
            check('the fixtures were swept', `failed: ${error.message}`, 'swept');
        }
    }

    process.exit(report('layout'));
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
