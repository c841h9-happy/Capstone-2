/*
 * Screenshot a page of this site at the two widths every UI pass here uses, and print what the
 * browser actually measured. Run it with Node: there are no packages, and there is nothing to
 * install.
 *
 *   node php/tools/screenshot.mjs <url> <outputPrefix> [probeExpression]
 *
 *   node php/tools/screenshot.mjs http://localhost/Capstone/php/index.php C:\...\Temp\home
 *     -> C:\...\Temp\home-desktop.png (1280x800) and C:\...\Temp\home-phone.png (390x844)
 *
 * WHY THIS EXISTS, because the obvious thing looks like it works and does not:
 *
 *   chrome.exe --headless=new --screenshot=out.png --window-size=390,844 <url>
 *
 * does not give you a phone viewport on Windows. The OS will not make a window that narrow, so
 * the page lays out at desktop width and Chrome merely crops the PNG to the size you asked for.
 * Every media query reports desktop, the hamburger never appears, and you get a 390-wide image
 * that looks like evidence and is a lie. This was found on 2026-09-21 during the logo pass: the
 * "phone" shot showed a desktop navbar and hero text sliced off mid-word.
 *
 * CDP's Emulation.setDeviceMetricsOverride sets the viewport the layout is computed against,
 * which is the only way to get an honest answer. Node 24 ships a global WebSocket, so the whole
 * CDP client is the few dozen lines below.
 *
 * The third argument is a JavaScript expression evaluated in the page after each shot and printed
 * beside the size. Measuring beats squinting -- a screenshot tells you something rendered, not
 * that it rendered at the size the CSS asked for. Pass an expression returning a string.
 *
 * SHOT_COOKIES in the environment is set on localhost before the first navigation, as
 * `name=value; name=value`. Most of this site is behind a sign-in, and a fresh profile is a
 * stranger: without this, every shot of an order, a basket or the admin folder is a shot of the
 * login page. A PHPSESSID minted by signing in over HTTP is all it takes -- the session is not
 * bound to a user agent -- so the browser joins a session that was established through the real
 * form rather than having one forged for it.
 *
 * It is an environment variable and not a fourth argument because a session id is a capability.
 * Arguments show up in the process list and in whatever scrollback the caller keeps; this way the
 * value is not printed by the tool and not sitting in a command line.
 */
import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { setTimeout as sleep } from 'node:timers/promises';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9222;
const PROFILE = 'C:\\Users\\User\\AppData\\Local\\Temp\\screenshot-mjs-profile';

const pageUrl = process.argv[2];
const prefix = process.argv[3];
const probe = process.argv[4] ?? 'innerWidth + "x" + innerHeight';

if (pageUrl === undefined || prefix === undefined) {
    console.error('usage: node php/tools/screenshot.mjs <url> <outputPrefix> [probeExpression]');
    process.exit(1);
}

/*
 * 1280x800 is the desktop this project has always checked at; 390x844 is an iPhone 14, and it is
 * below the 720px breakpoint the navbar collapses at, which is the width most worth seeing.
 * mobile: true so the meta viewport is honoured the way a phone honours it.
 */
const SHOTS = [
    { name: 'desktop', width: 1280, height: 800, mobile: false },
    { name: 'phone', width: 390, height: 844, mobile: true },
];

rmSync(PROFILE, { recursive: true, force: true });

/*
 * Its own profile directory, always. Without one Chrome hands the command to whatever Chrome the
 * user already has open, which ignores these flags entirely and reports success.
 */
const chrome = spawn(CHROME, [
    '--headless=new',
    '--disable-gpu',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${PROFILE}`,
    'about:blank',
], { stdio: 'ignore' });

// The port is not open the moment the process starts and nothing announces when it is.
let target = null;
for (let attempt = 0; attempt < 80 && target === null; attempt++) {
    try {
        const response = await fetch(`http://127.0.0.1:${PORT}/json/new?url=about:blank`, { method: 'PUT' });
        target = await response.json();
    } catch {
        await sleep(250);
    }
}

if (target === null) {
    chrome.kill();
    throw new Error('Chrome never opened its debugging port');
}

const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    socket.onopen = resolve;
    socket.onerror = reject;
});

let nextId = 1;
const pending = new Map();
const listeners = [];

socket.onmessage = (event) => {
    const message = JSON.parse(event.data);

    if (message.id !== undefined && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result);
        return;
    }

    for (const listener of [...listeners]) {
        listener(message);
    }
};

const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});

const once = (method) => new Promise((resolve) => {
    const listener = (message) => {
        if (message.method === method) {
            listeners.splice(listeners.indexOf(listener), 1);
            resolve(message.params);
        }
    };
    listeners.push(listener);
});

await send('Page.enable');
await send('Runtime.enable');

/*
 * Cookies before the first navigation, not after: a page that is only shown to a signed-in
 * visitor redirects to the login form on the way in, and a cookie set afterwards would be one
 * navigation too late. They live on the profile, so setting them once covers every shot.
 */
for (const pair of (process.env.SHOT_COOKIES ?? '').split(';')) {
    const [name, ...rest] = pair.trim().split('=');

    if (name !== '') {
        await send('Network.setCookie', {
            name,
            value: rest.join('='),
            domain: 'localhost',
            path: '/',
        });
    }
}

for (const shot of SHOTS) {
    await send('Emulation.setDeviceMetricsOverride', {
        width: shot.width,
        height: shot.height,
        deviceScaleFactor: 1,
        mobile: shot.mobile,
    });

    /*
     * Subscribe before navigating. Page.navigate can resolve after the load event on a page this
     * small, and then the wait never ends.
     */
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: pageUrl });
    await loaded;
    await sleep(500);

    const { data } = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(`${prefix}-${shot.name}.png`, Buffer.from(data, 'base64'));

    const { result } = await send('Runtime.evaluate', { expression: probe, returnByValue: true });
    console.log(shot.name.padEnd(8), result.value);
}

socket.close();
chrome.kill();
await sleep(500);
rmSync(PROFILE, { recursive: true, force: true });
