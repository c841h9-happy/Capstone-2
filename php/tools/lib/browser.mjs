/*
 * The browser half of this project's test tooling: one CDP client, shared.
 *
 * Nothing in php/tools/ drove a browser until 2026-10-02 except screenshot.mjs, which shoots a
 * single page. The passes that actually found faults -- the happy-path walk, the cross-site attack
 * page, the responsive sweeps -- lived in %TEMP% as four separate scripts, each carrying its own
 * copy of this client, its own check(), its own mail-log reader and its own signIn(). Four copies
 * is four places for a fix to be applied three times, and it is why the TABLE_FIT border bug below
 * survived in one of them for a week. This is that code, once.
 *
 * Imported by layout-test.mjs, journey-test.mjs and csrf-browser-test.mjs.
 *
 * screenshot.mjs is deliberately NOT rewritten to use this. It works, it is documented, and it is
 * the one tool here a person reaches for by hand against an arbitrary URL -- "do not rebuild
 * working features" applies to tooling too. Its header carries the single most important fact in
 * this folder and is worth reading before changing anything here:
 *
 *     chrome --headless --screenshot --window-size=390,844 does NOT give you a phone viewport on
 *     Windows. The OS will not make a window that narrow, so the page lays out at desktop width and
 *     Chrome crops the PNG. Every media query reports desktop and you get an image that looks like
 *     evidence and is a lie.
 *
 * Emulation.setDeviceMetricsOverride sets the viewport the layout is computed against, which is the
 * only honest answer. Node 24 ships a global WebSocket and a global fetch, so the whole client is
 * the few hundred lines below and there is nothing to install.
 */

import { spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
export const SHOP = 'http://localhost/Capstone/php';
export const MAIL_LOG = path.join(os.tmpdir(), 'capstone-mail.log');
export const SHOTS = path.join(os.tmpdir(), 'capstone-shots');

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* ------------------------------------------------------------------ *
 * Reporting.
 * ------------------------------------------------------------------ */

let failures = 0;
let checks = 0;
let skips = 0;

export function check(label, actual, expected) {
    checks++;

    if (actual === expected) {
        console.log(`  ok    ${label}`);
        return true;
    }

    failures++;
    console.log(`  FAIL  ${label}\n          expected: ${expected}\n          actual:   ${actual}`);
    return false;
}

export const checkTrue = (label, actual) => check(label, actual === true, true);

export function note(label, value) {
    console.log(`  ..    ${String(label).padEnd(44)} ${value}`);
}

/*
 * A screen that could not be reached, said out loud and counted.
 *
 * Not a silent pass. The old 'no table' branch in inventory-shots.js returned early without
 * asserting anything, so a screen whose list was empty reported success for a check that had never
 * run -- the same shape of lie as a row-count assertion answered by a crashed query. A skip is a
 * result; it has to be visible in the summary.
 */
export function skip(label, why) {
    skips++;
    console.log(`  SKIP  ${label} -- ${why}`);
}

export function heading(text) {
    console.log(`\n--- ${text} ---`);
}

/** Exit code and one line, for a suite's last act. */
export function report(name) {
    const verdict = failures === 0 ? 'all green' : `${failures} FAILED`;
    console.log(`\n${name}: ${checks} checks, ${skips} skipped, ${verdict}`);
    return failures === 0 ? 0 : 1;
}

export const counts = () => ({ checks, failures, skips });

/* ------------------------------------------------------------------ *
 * The mail log.
 * ------------------------------------------------------------------ */

/*
 * The newest sign-in code for one address.
 *
 * MAIL_TRANSPORT is 'log' on this machine, so php/config/mailer.php appends what it would have sent
 * to capstone-mail.log. The file is a run of blocks separated by a rule of '=' characters.
 *
 * Read the LAST matching block, not the first: these suites sign the same account in more than once,
 * and an old code fails the verify form in a way that looks like the form is broken.
 *
 * The code is RETURNED, never logged. That file holds live codes for real accounts, and a six-digit
 * number in a transcript is a credential in a transcript.
 */
export function readCodeFor(address) {
    const blocks = readFileSync(MAIL_LOG, 'utf8').split(/^={10,}$/m);

    for (let index = blocks.length - 1; index >= 0; index--) {
        const block = blocks[index];
        if (!block.includes(address) || !block.includes('Your sign-in code')) continue;

        const code = block.match(/Your verification code is:\s*\n\s*(\d{6})/);
        if (code) return code[1];
    }

    return null;
}

/* ------------------------------------------------------------------ *
 * Driving Chrome.
 * ------------------------------------------------------------------ */

function connect(url) {
    return new Promise((resolve, reject) => {
        const socket = new WebSocket(url);
        let nextId = 1;
        const pending = new Map();

        socket.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);

            if (message.id && pending.has(message.id)) {
                const { resolve: done, reject: fail } = pending.get(message.id);
                pending.delete(message.id);
                message.error ? fail(new Error(JSON.stringify(message.error))) : done(message.result);
            }
        });

        socket.addEventListener('error', reject);
        socket.addEventListener('open', () => resolve({
            send(method, params = {}) {
                const id = nextId++;
                socket.send(JSON.stringify({ id, method, params }));
                return new Promise((done, fail) => pending.set(id, { resolve: done, reject: fail }));
            },
            close: () => socket.close(),
        }));
    });
}

async function waitForDebugger(port) {
    for (let attempt = 0; attempt < 80; attempt++) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}/json/list`);
            const targets = await response.json();
            const page = targets.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
            if (page) return page.webSocketDebuggerUrl;
        } catch {
            // The port is not open the moment the process starts, and nothing announces when it is.
        }
        await sleep(250);
    }

    throw new Error('Chrome never opened its debugging port');
}

/*
 * Start a headless Chrome nobody else is using, and hand back a connected client.
 *
 * Its own throwaway profile directory, always. Without --user-data-dir Chrome hands the command to
 * whatever Chrome the user already has open, which ignores every flag here and reports success --
 * so the suite would quietly measure the user's own browser window.
 *
 * Each suite takes its own port so two can run back to back without racing for one.
 */
export async function launch({ port, name = 'capstone' }) {
    const profile = mkdtempSync(path.join(os.tmpdir(), `${name}-`));

    const chrome = spawn(CHROME, [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-features=Translate',
        '--hide-scrollbars',
        'about:blank',
    ], { stdio: 'ignore' });

    const cdp = await connect(await waitForDebugger(port));
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');

    return {
        cdp,
        async close() {
            try { cdp.close(); } catch { /* already gone */ }
            chrome.kill();
            await sleep(400);
            // Chrome can still hold the profile for a moment after kill; losing a temp dir is not
            // worth failing a run over.
            try { rmSync(profile, { recursive: true, force: true }); } catch { /* it will age out */ }
        },
    };
}

export async function evaluate(cdp, expression) {
    const result = await cdp.send('Runtime.evaluate', {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });

    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.exception?.description ?? 'evaluate threw');
    }

    return result.result?.value;
}

export async function settle(cdp) {
    for (let attempt = 0; attempt < 80; attempt++) {
        await sleep(150);
        if (await evaluate(cdp, 'document.readyState') === 'complete') return;
    }
}

export async function goto(cdp, url) {
    await cdp.send('Page.navigate', { url });
    await settle(cdp);
}

export const where = (cdp) => evaluate(cdp, 'document.location.href');
export const text = (cdp) => evaluate(cdp, 'document.body.innerText');

export function setViewport(cdp, width, height, mobile = false) {
    return cdp.send('Emulation.setDeviceMetricsOverride', {
        width, height, deviceScaleFactor: 1, mobile,
    });
}

/* ------------------------------------------------------------------ *
 * Pressing things.
 * ------------------------------------------------------------------ */

/*
 * Fill the form holding `anchor` and submit it.
 *
 * Found through the anchor field's own .form rather than by action, because some of these pages
 * post to themselves and some post elsewhere -- and login.php carries two forms at once, which is
 * why the anchor for signing in is `identifier` and not `email`.
 *
 * Every form is checked for a token first, so a failure says which form lost it rather than landing
 * as a mystery 403 three steps later. Radios and checkboxes are matched by value; everything else
 * is assigned. Returns 'submitted', or the first thing that was missing.
 */
export async function fillAndSubmit(cdp, anchor, values) {
    const result = await evaluate(cdp, `
        (() => {
            const anchor = document.querySelector('[name="${anchor}"]');
            if (!anchor) return 'no field named ${anchor} on the page';

            const form = anchor.form;
            if (!form) return 'the ${anchor} field is not in a form';
            if (!form.querySelector('[name="csrf_token"]')) return 'that form carries no token';

            for (const [name, value] of Object.entries(${JSON.stringify(values)})) {
                const nodes = [...form.querySelectorAll('[name="' + name + '"]')];
                if (nodes.length === 0) return 'no field named ' + name;

                if (nodes[0].type === 'radio' || nodes[0].type === 'checkbox') {
                    const hit = nodes.find((node) => node.value === value);
                    if (!hit) return 'no ' + name + ' option worth ' + value;
                    hit.checked = true;
                } else {
                    nodes[0].value = value;
                }
            }

            form.submit();
            return 'submitted';
        })()
    `);

    if (result === 'submitted') await settle(cdp);
    return result;
}

/*
 * Press one of the admin action buttons.
 *
 * .click() and not form.submit(), and this is the trap worth remembering: form.submit() does not
 * include the submit button's own name/value pair, so a screen whose actions are
 * <button name="action" value="pack"> posts an action of nothing at all and the handler does
 * nothing, successfully. A hidden input is handled too, in case a screen is ever built the other
 * way round.
 *
 * window.confirm is answered yes first. The destructive buttons carry data-confirm and
 * setUpConfirmButtons() in js/script.js turns that into a real confirm() -- a dialog nobody closes in
 * a headless browser, so the script simply waits forever. An operator pressing OK is what is being
 * simulated, so yes is the honest answer.
 */
export async function pressAction(cdp, wanted) {
    const result = await evaluate(cdp, `
        (() => {
            window.confirm = () => true;

            const wanted = ${JSON.stringify(wanted)};

            const button = [...document.querySelectorAll('button[name="action"], input[name="action"][type="submit"]')]
                .find((node) => node.value === wanted);

            if (button) {
                if (!button.form?.querySelector('[name="csrf_token"]')) return 'the ' + wanted + ' form carries no token';
                button.click();
                return 'pressed';
            }

            const form = [...document.querySelectorAll('form[method="post"]')]
                .find((f) => [...f.querySelectorAll('input[type="hidden"][name="action"]')]
                    .some((node) => node.value === wanted));

            if (form) {
                if (!form.querySelector('[name="csrf_token"]')) return 'the ' + wanted + ' form carries no token';
                form.submit();
                return 'pressed';
            }

            return 'no ' + wanted + ' button on the page';
        })()
    `);

    if (result === 'pressed') await settle(cdp);
    return result;
}

/*
 * Sign in for real, through the real form, including the 2FA step.
 *
 * Not a forged session cookie. The whole value of a browser pass is that it presses what a person
 * presses, and a hand-made PHPSESSID skips every guard on the way in.
 */
export async function signIn(cdp, { email, password, label = 'sign-in' }) {
    await goto(cdp, `${SHOP}/login.php`);

    const submitted = await fillAndSubmit(cdp, 'identifier', {
        form_type: 'login',
        identifier: email,
        password,
    });

    if (!check(`${label}: the sign-in form carries a token and posts`, submitted, 'submitted')) {
        return false;
    }

    if ((await where(cdp)).includes('verify.php')) {
        // Written to the log by the request that just finished.
        const code = readCodeFor(email);

        if (!checkTrue(`${label}: a sign-in code was sent`, typeof code === 'string')) {
            return false;
        }

        check(`${label}: the verify form carries a token and posts`,
            await fillAndSubmit(cdp, 'code', { code }), 'submitted');
    }

    const landed = await where(cdp);

    return checkTrue(`${label}: signed in and off the sign-in pages`,
        !landed.includes('login.php') && !landed.includes('verify.php'));
}

/*
 * Sign out, through the form in the page rather than by dropping cookies.
 *
 * php/logout.php is a POST behind requireCsrfTokenOrRedirect(), so there is no link to follow -- and
 * clearing the cookie jar instead would leave the session alive on the server, which is the half of
 * signing out that matters.
 *
 * Suites that change account mid-run need this. login.php has no "you are already signed in"
 * redirect, so posting the sign-in form over a live session appears to work; it just means the
 * second account inherits whatever the first one left on the session, which is not a state any
 * visitor is ever in.
 *
 * Returns 'submitted', or what was missing.
 */
export async function signOut(cdp) {
    const result = await evaluate(cdp, `
        (() => {
            const form = document.querySelector('form[action*="logout"]');
            if (!form) return 'no logout form on the page';
            if (!form.querySelector('[name="csrf_token"]')) return 'the logout form carries no token';
            form.submit();
            return 'submitted';
        })()
    `);

    if (result === 'submitted') await settle(cdp);
    return result;
}

/* ------------------------------------------------------------------ *
 * The three measurements, and the reasons they are shaped as they are.
 * ------------------------------------------------------------------ */

/*
 * Everything sticking out past the right edge, ignoring what a scroll box legitimately clips.
 *
 * `el.parentElement?.closest(...)`, NEVER `el.closest(...)`. closest() matches the element it is
 * called on, so the plain form exempts the very box whose entire job is to clip -- and the check
 * then reports "nothing hangs off the right edge" on a page that visibly scrolls sideways. That
 * hole hid a real fault on three admin screens for days. Asking the PARENT keeps the box itself in
 * scope while still excusing its contents.
 *
 * Two class names, not one. .admin-table-wrap is the seven admin lists; .table-wrap is the
 * dashboard's recent-orders table. A selector naming only the first reports the second box's
 * contents as overflow the moment it starts clipping honestly.
 */
export const OVERFLOW = `
    (() => {
        const limit = window.innerWidth + 1;
        const bad = [];

        for (const el of document.querySelectorAll('body *')) {
            if (el.parentElement?.closest('.admin-table-wrap, .table-wrap')) continue;

            const r = el.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) continue;

            if (r.right > limit) {
                const cls = String(el.className?.baseVal ?? el.className ?? '').split(' ')[0];
                bad.push(el.tagName.toLowerCase() + (cls ? '.' + cls : '') + ' @' + Math.round(r.right));
            }
        }

        return bad.slice(0, 5).join(', ');
    })()
`;

/*
 * Does the page actually scroll, rather than merely measure as though it might.
 *
 * documentElement.scrollWidth is a hint; this is the fact. Push it right, read how far it went, put
 * it back. A page pinned at 0 cannot slide its own header off the screen whatever it measures --
 * and documentElement.scrollWidth and body.scrollWidth genuinely diverge in the escaping-abspos
 * case (body said 390 while the document said 599), so asserting on body alone misses it entirely.
 */
export const SCROLLS_SIDEWAYS = `
    (() => {
        const before = window.scrollX;
        window.scrollTo(500, window.scrollY);
        const moved = window.scrollX;
        window.scrollTo(before, window.scrollY);
        return moved;
    })()
`;

/*
 * The contents of every scroll box, against that box's own scrollable width.
 *
 * Exempting a box from OVERFLOW only moves the question; this is where it gets answered.
 *
 * The limit is built from clientLeft and scrollLeft, not from the bounding rect alone.
 * getBoundingClientRect().left is the BORDER box's left edge while scrollWidth measures the CONTENT
 * box, so `left + scrollWidth` is short by the left border -- and .admin-table-wrap has one. That
 * off-by-one only surfaces once the box is genuinely scrolling; before the 2026-10-02 min-width fix
 * the order page spilled instead, so the formula was never under load and the error sat unnoticed.
 * It reported a 456px table inside a 456px box as overflow by half a pixel.
 *
 * Every box, not querySelector's first: the dashboard has a .table-wrap and no .admin-table-wrap at
 * all, so the single-box form quietly checked nothing there.
 */
export const TABLE_FIT = `
    (() => {
        const boxes = [...document.querySelectorAll('.admin-table-wrap, .table-wrap')];
        if (!boxes.length) return 'no table';

        const bad = [];

        for (const wrap of boxes) {
            const r = wrap.getBoundingClientRect();
            const limit = r.left + wrap.clientLeft + wrap.scrollWidth - wrap.scrollLeft + 1;

            for (const el of wrap.querySelectorAll('*')) {
                const b = el.getBoundingClientRect();
                if (b.width === 0 && b.height === 0) continue;
                if (b.right > limit) {
                    bad.push(el.tagName.toLowerCase() + ' @' + Math.round(b.right) + ' past ' + Math.round(limit));
                }
            }
        }

        return bad.slice(0, 5).join(', ');
    })()
`;

/*
 * Overflow the page absorbs without scrolling, which is a whole second class of fault.
 *
 * On 2026-09-29 the dashboard's bar chart spilled past its panel's right border and across the
 * panel beside it without moving the document one pixel -- window.scrollX stayed 0 at every width,
 * so a scroll-only assertion called the fix finished while the screen was still visibly broken.
 * Ask each container whether its own contents fit inside it.
 */
export const INNER_SPILL = `
    (() => {
        const bad = [];

        for (const el of document.querySelectorAll('.panel, .chart-panel, .card, section')) {
            if (el.scrollWidth > el.getBoundingClientRect().width + 1) {
                const cls = String(el.className?.baseVal ?? el.className ?? '').split(' ')[0];
                bad.push(el.tagName.toLowerCase() + (cls ? '.' + cls : '')
                    + ' ' + el.scrollWidth + '>' + Math.round(el.getBoundingClientRect().width));
            }
        }

        return bad.slice(0, 5).join(', ');
    })()
`;

/* ------------------------------------------------------------------ *
 * Pictures.
 * ------------------------------------------------------------------ */

/*
 * A plain viewport capture, with the subject scrolled into view first.
 *
 * Never captureBeyondViewport. It grows the viewport to the document height before shooting, which
 * fires the page's own resize handling -- so a 3D stage that re-frames its camera on resize gets
 * photographed in a state no visitor is ever in. It hid a clipped bouquet from the entire
 * customizer suite: the pixel buffer said clipped, every picture said fine.
 *
 * And scroll to the subject, because a plain capture photographs the top of the page. A panel added
 * near the bottom of a long admin screen is simply absent from the picture, which looks exactly
 * like a passing screenshot.
 */
export async function shoot(cdp, file, { into = null } = {}) {
    mkdirSync(SHOTS, { recursive: true });

    if (into) {
        await evaluate(cdp, `
            (() => {
                const el = document.querySelector(${JSON.stringify(into)});
                if (el) { el.scrollIntoView({ block: 'start' }); window.scrollBy(0, -24); }
                return window.scrollY;
            })()
        `);
        await sleep(200);
    }

    const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const target = path.join(SHOTS, file);
    writeFileSync(target, Buffer.from(shot.data, 'base64'));

    return target;
}
