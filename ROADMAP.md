# PROJECT ROADMAP

Status: `[x]` complete (tested) · `[~]` in progress · `[ ]` pending · `[!]` issue

Only major modules are tracked here. Statuses below were set by reading the code,
not by assuming — the evidence note on each one says what exists today.

* [x] **Authentication** — login, signup, 2FA, password rules
  Login and signup panels share `php/login.php`; `php/signup.php` is a redirect shim.
  Email-code 2FA (`php/verify.php`, `config/twofactor.php`, `config/mailer.php`,
  `config/devices.php`), trusted-device cookie, bcrypt hashes, strength rules in
  `config/auth.php`, session id regenerated on sign-in. Tested end-to-end over
  Apache with `MAIL_TRANSPORT = 'log'`.
* [x] **Foundation** — DB, sessions, roles, shared components
  `config/database.php` (PDO, prepared statements), `config/paths.php` (`url()`,
  `asset()` with cache-busting), `config/auth.php` (sessions, password rules,
  `signInUser()` as the single place a session gains privileges, `currentUserRole()`,
  `isAdmin()`, `requireLogin()`, `requireAdmin()`), `users.role
  ENUM('customer','admin')`, shared `header.php` / `footer.php` / `navbar.php` /
  `toast.php` / `password-field.php`, and `php/admin/guard.php`. Tested: 54 HTTP and
  database assertions plus a Chrome render check at desktop and mobile widths.
* [x] **Customer** — dashboard, profile, catalog
  The shop sells fuzzy wire handicraft flowers, as ready-made bouquets and as
  individual stems, so `products` holds both in one table split by
  `kind ENUM('bouquet','flower')`. Public catalogue (`php/products.php` — kind
  filter, search, four sorts, 12 per page) and product page (`php/product.php`, a
  real 404 page for a missing or hidden id); customer hub (`php/dashboard.php`);
  `php/profile.php` changes name, password and email, the new address proven by an
  emailed code and both dangerous forms requiring the current password. Data layer in
  `config/products.php`, redirect-surviving toasts in `config/flash.php`. Tested:
  438 assertions over Apache, the database and real Chrome. Done 2026-09-02.
* [x] **Products** — admin CRUD + customization components
  All three parts are in and tested. **Admin CRUD** (2026-09-03) —
  `php/config/products-admin.php`, `php/admin/products.php`,
  `php/admin/product-edit.php`: list, filter, search, page, add, edit, hide, show, and a
  delete that only a hidden product will accept. **Image upload** (2026-09-04) —
  `php/config/uploads.php`, `uploads/products/` and an `uploads/.htaccess` that stops PHP
  running out of either folder; the sent MIME type is never trusted. **The customizer's
  component table** (2026-09-04) — `sql/components-upgrade.sql`,
  `sql/components-seed.sql`, `php/config/components.php`,
  `php/config/components-admin.php`, `php/admin/components.php`,
  `php/admin/component-edit.php`: `product_components` is its own table with its own
  admin screens, seeded with 17 parts. See "Products — second half" below.
* [x] **3D Customizer** — Three.js bouquet builder + saved designs
  All four quarters are in and tested (2026-09-05 → 2026-09-14). `php/customize.php` is a
  working builder — pick up to four kinds of flower head, a stem wire, a wrap and a tie,
  choose 1–24 flowers and one of three arrangements, and the bouquet renders in WebGL while
  the price adds up. Three.js 0.185.1 is vendored in `js/vendor/`; `js/customizer.js` is the
  project's first ES module; the design lives in the query string, so the picker is a real
  GET form that works with JavaScript off and a bouquet can be shared as a link.
  `product_components` gained a `shape` column so a sunflower is not drawn as a daisy.
  Signed-in customers save designs to `saved_designs` + `saved_design_blooms` and manage
  them at `php/designs.php`. **The basket handoff is Commerce's, not this module's** — the
  button is present, disabled and says why, and `designSnapshot()` is the documented freeze
  point it will read. See the four sections below.
* [x] **Commerce** — cart, checkout, pricing, payments
  **Both halves are in and tested (2026-09-16, 2026-09-17, 2026-09-20).** The basket:
  `sql/cart-upgrade.sql`, `php/config/cart.php`, `php/cart.php`, `php/cart-update.php` — a
  visitor can fill a basket without an account and it follows them into one when they sign in,
  every line **freezes its name and price** when it is added, and the navbar carries a cart icon
  with a red count badge whose hover panel shows what is in it. Checkout, orders and payments:
  `sql/orders-upgrade.sql`, `php/config/orders.php`, `php/config/orders-admin.php`,
  `php/checkout.php`, `php/orders.php`, `php/order.php`, `php/order-cancel.php`,
  `php/admin/orders.php`, `php/admin/order.php` — a customer chooses delivery or pickup, pays by
  GCash, bank transfer or cash, and the shop confirms the money by hand from an order queue.
  **No dead control is left in the customer-facing site.** The Checkout button was the last one,
  and the only button that still disables itself is that same one refusing a basket holding
  something no longer on sale. **Where Commerce stops is payment**: `packed`, `delivered`,
  tracking and notifications belong to the Orders module, and both
  `php/config/orders-admin.php` and `php/admin/order.php` say so on the screen. See the three
  sections below.
* [x] **Orders** — management, tracking, notifications
  **Done 2026-09-22.** All three things Commerce named are in and tested, and the note on
  `php/admin/order.php` that said moving an order was not switched on yet has been replaced by the
  buttons it was apologising for. **Management** — `markOrderPacked()`, `markOrderUnpacked()` and
  `markOrderDelivered()` in `php/config/orders-admin.php`, reachable from both admin screens, so an
  order walks `placed → packed → delivered` and can step back once. Delivered is final.
  **Tracking** — a dated status history in a new `order_events` table (`sql/orders-tracking-upgrade.sql`),
  drawn as a timeline on `php/order.php` and `php/admin/order.php`: the customer's copy says when,
  the admin's also says who. **Notifications** — `php/config/orders-mail.php`, one message on each
  of four moves: being made, delivered, cancelled by the shop, and payment marked paid.
  `MAIL_TRANSPORT` is still `'log'`, so those messages land in `capstone-mail.log` rather than in an
  inbox — the same standing the 2FA mail has had since Authentication, and the same reason.
  Tested: 725 unit assertions, 310 over Apache, and six Chrome shots reviewed at two widths.
* [x] **Inventory** — materials, stock, usage, transactions
  **Stage 1 done 2026-09-27: stock, recipes and the movement ledger. Stage 2 done 2026-10-02: an
  order consuming its parts when making starts.** `sql/inventory-upgrade.sql` adds `stock_on_hand`
  and `low_stock_threshold` to
  `product_components` and creates two tables: **`product_parts`**, the recipe, which finally links
  `products` to `product_components` and closes the one structural gap that had passed through
  Commerce, Orders and Admin untouched; and **`stock_moves`**, the ledger. `php/config/inventory.php`
  is the data layer, with `recordStockMove()` as the **only writer of `stock_on_hand` anywhere** —
  one transaction, `SELECT ... FOR UPDATE` then `UPDATE` then the ledger row. Three screens:
  `php/admin/inventory.php` (stock, with Receive / Set to / Waste per row), `php/admin/stock-moves.php`
  (the ledger, with an audit banner and an `?order=N` filter), `php/admin/product-parts.php?id=N` (the
  recipe, one POST for the whole thing), plus a fourth panel on `php/admin/order.php`. **Stage 2 needed
  no migration** — `stock_moves` was built in Stage 1 already carrying `order_id` and the `used` /
  `returned` reasons. Tested: `php/tools/inventory-http-test.php`, **345 checks**, run green with the
  other six (**1,840 checks, 0 failures**). See the two sections below.
  **Raw-material inventory added 2026-10-16 and tested.** `sql/inventory-fractional-upgrade.sql`
  adds decimal material stock, `product_materials`, and `material_moves`, separate from the 3D
  customizer's component catalog. Admins manage stock at `php/admin/materials.php` and set each
  product's material amount and output yield at `php/admin/product-materials.php`. The example seed
  models one Red Fuzzy Wire strand yielding two Single Fuzzy Roses; making one consumes 0.5 strand.
  Existing component recipes remain the fallback until a product gets a materials recipe. The
  `php/tools/inventory-http-test.php` now covers fractional receipts, yields, order consumption and
  returns, the audit, and the order-filtered ledger: **377 checks, 0 failures**. The full quick PHP
  suite passed with **0 failed suites**, unchanged before/after table counts, and clean fixture
  cleanup. Run the migration and `sql/materials-seed.sql` on a fresh install before testing.
* [x] **Admin** — dashboard, customers, reports, analytics
  **Built by the user with Copilot on 2026-09-24**, reviewed the same day, and the review's findings
  fixed on 2026-09-26. The screens: `php/config/admin.php` is the data layer (customer listing with
  role filter, search and pagination; `adminReportsSummary()`; `adminTopProducts()`;
  `adminRecentOrders()`; `adminDashboardStats()`; `adminInventorySummary()`, written here and given
  its two dashboard panels on 2026-09-29 once Inventory existed to fill it), `php/admin/customers.php`
  and `php/admin/reports.php` are the two new screens,
  `dashboard.php` is a KPI row with a seven-day chart, a donut and two panels, and
  `php/components/header.php` renders an admin sidebar linking all six.
  The search escaping is sound — `%`, `_`, `!` and `' OR 1=1 -- ` are all matched literally, so the
  `ESCAPE "!"` clause works and nothing is interpolated. Junk dates fall back to unfiltered, a
  reversed range is swapped, and `?page=99` clamps to the last page.
  **`[x]` as of 2026-09-26 because the five defects are fixed and there is now a suite that would
  have caught them**: `php/tools/admin-http-test.php`, 78 checks, run green alongside the other five
  (1,495 checks, 0 failures). It asserts the dashboard KPI against the customer list's own number,
  that money excludes cancelled orders while counts include them, that the chart's three series are
  three measurements, that a custom bouquet and a withdrawn product both appear in Top products, that
  the search form carries its role tab, and — by renaming `orders` out from under a live request —
  that a partially-migrated database still renders the cards that work.
  **What this `[x]` does not mean.** The customer list is **read-only**: there is no way to edit,
  suspend or delete an account from the admin side, and no per-customer drill-down to their orders.
  Reports has no CSV export and no period-over-period comparison — it answers "what
  happened in this range", not "is that better than last month". *(**Print export landed
  2026-10-11** and is no longer on this list; CSV still is.)* The dashboard's seven-day window is
  fixed and not selectable. "Analytics" in the module title is the seven-day chart, the donut and the
  reports page, and nothing more sophisticated than `GROUP BY` — no cohorts, no forecasting. None of
  that is a defect; it is the line between this module and the two that are left. *(The one item that
  did close: `adminInventorySummary()`'s numbers had no panel to sit in when this module shipped. They
  have two as of 2026-09-29 — "What to buy" and "In stock" — which was Inventory's job to make
  meaningful, and it did.)*
* [x] **Security** — validation, authorization, CSRF, uploads
  **CSRF landed 2026-09-24**, which was the one thing this module had been waiting on since
  2026-09-16. `php/config/csrf.php` mints one token per session and offers two shapes of refusal:
  a plain 403 page for a screen that renders its own reply, and a toast plus a redirect for the
  four endpoints that only ever redirect. **29 forms across 16 files carry the hidden field and
  14 POST handlers check it** — *recounted 2026-10-03 and now **33 rendered fields across 19 files**
  and **16 guard call sites in 16 files**, the growth being Inventory's three screens
  (`admin/inventory.php` 2 fields + 1 guard, `admin/product-parts.php` 1 + 1) and the admin sidebar
  in `components/header.php` (1 field). Nothing lost coverage; the headline numbers here were simply
  never updated after 2026-09-24. `admin/stock-moves.php` has neither, correctly — it filters over
  GET and posts nothing* — and `signInUser()` drops the token beside
  `session_regenerate_id(true)` — a token captured before the session had privileges is worth
  nothing after it has them. Already covered before today: prepared statements and output
  escaping throughout, the mailer's header-injection guard, `php/admin/guard.php`, and
  **uploads** as of 2026-09-04 (`php/config/uploads.php`) — `getimagesize()` and `finfo_file()`
  must agree on the type, the extension is generated from what was found rather than from the
  filename, a 2 MB cap is enforced in PHP as well as in `php.ini`, and `uploads/.htaccess`
  removes the handler so nothing in there executes.
  **What `[x]` here does not mean, named rather than implied.** **One thing is left: there is no
  HTTPS**, so every password, every 2FA code and every session cookie crosses the wire in clear. On
  `localhost` that is nothing; off it, it is everything. It needs a certificate and a server
  configuration rather than code.
  *(**Was four.** `session.cookie_samesite` was set on 2026-10-11. **Login rate limiting and session
  binding landed 2026-10-14** — `php/config/login-guard.php`, five wrong tries per identifier and
  twenty per address inside a sliding fifteen-minute window, nothing ever locked; and a signed-in
  session bound to a hash of its browser's User-Agent, destroyed on mismatch. 45 checks in
  `php/tools/security-http-test.php`. See the section below for why the address is deliberately not
  part of the fingerprint, and why a missing attempts table fails open.)*
  The CSRF token is still what stands between another site's page and a signed-in visitor's
  account — SameSite is about site and not origin, and a port is not part of a site — which is why
  it was tested against a page that really is on another origin rather than assumed. Details in the
  Security section below.
* [x] **Events** — occasions, a featured flower, percentage promos
  **Done 2026-10-08.** The shop runs different offers depending on the occasion, and until now the
  site had no way to say so: there was no discount, promo or featured concept anywhere in it.
  `sql/events-upgrade.sql` adds `events` (name, tagline, two inclusive DATEs, a featured product, a
  whole-percent discount, `is_active`) and `event_products`, the hand-picked promo membership.
  `php/config/events.php` is the read half and `php/config/events-admin.php` the write half, split
  the way `products.php` / `products-admin.php` are. Two admin screens — `php/admin/events.php` and
  `php/admin/event-edit.php`, the second posting the whole promo list in one form like
  `product-parts.php` does a recipe. Customers get a banner on `php/index.php` and a badge with a
  struck-through old price on the catalogue card and the product page.
  **The discount is applied in ONE place** — `decorateProductPricing()`, called by `fetchProduct()`
  and `fetchProducts()`, which are the only customer-facing readers of `products.price`. So the
  catalogue, the product page *and* `addProductToCart()`'s freeze all inherit it, and **not one line
  of `cart.php`, `checkout.php`, `orders.php`, `orders-admin.php` or `admin.php` changed.** There is
  no discount column on `orders`: the total stays exactly `SUM(order_items.line_total)`, which is
  what every report assumes. Tested: `php/tools/events-http-test.php`, **132 checks, 0 failures**,
  the ninth PHP suite, plus six screenshots at 1440 and 390 that were looked at. See the section
  below.
* [x] **Testing** — integration, responsive, security, bug fixes
  **Written 2026-10-02, run 2026-10-13.** `[x]` because the rule's condition is finally met: every
  suite has executed and its numbers are evidence rather than intent. **Thirteen suites, 2,525
  checks, 0 failures** — ten PHP (2,088) and three driving a real browser (layout 395, journey 34,
  csrf-browser 8) — green together in one `run-all.ps1` call, table counts identical before and
  after, every `ZZ` counter 0, and both servers stopped as found. The layout suite was then proved
  able to fail, and the screenshots were opened. See the `### Testing` section below for what that
  found.
  **Written 2026-10-02 and not yet run.** `[~]` and not `[x]` for exactly the reason the rules give:
  nothing here is marked complete until it has been tested, and the shell was unavailable for the
  whole of the session that wrote it. Ten suites now live in `php/tools/` — the seven PHP ones that
  were already there, plus three that drive a real browser:
  `cart-unit.php` (63), `cart-http-test.php` (133), `orders-unit.php` (725),
  `orders-http-test.php` (334), `csrf-http-test.php` (162), `admin-http-test.php` (78) and
  `inventory-http-test.php` (345): **1,840 checks**, each suite its own harness, each run green
  together as of 2026-10-02. *(**Eleven suites and 1,895 checks as of 2026-10-03**, when
  `shapes-unit.php` (58) was added with the `bud` shape and registered in `run-all.ps1`, and **`events-http-test.php` (132) on 2026-10-08** with the Events module. It has been
  run on its own and is green; it has not yet been run inside `run-all.ps1` with the others, which
  is still step 2 below.)* What this task adds:
  * **`php/tools/run-all.ps1`** — the single command that did not exist. Adopt-or-start, runs all
    ten, prints one summary with real table counts before and after, and stops only what it
    started. `-Quick` skips the browser half.
  * **`php/tools/layout-test.mjs`** — the responsive pass, as assertions rather than as screenshots
    reviewed by eye. ~26 screens at 1280 and 390, five questions at each, plus a 320→1440 sweep in
    20px steps over the three worst. `--locate <url>` reproduces the hide-one-panel-at-a-time pass
    that found the 2026-10-02 cause.
  * **`php/tools/journey-test.mjs`** — the visitor's whole journey, which until now was only ever
    proved by hand. Sign in with the real 2FA step, basket, checkout, sign out, then the shop marks
    it paid, made and delivered — and the stock half asserted from the ledger afterwards.
  * **`php/tools/csrf-browser-test.mjs`** — the cross-site attack page, served same-site on port
    8099 because a port is not part of a site.
  * **`php/tools/lib/browser.mjs`** — one CDP client where there were four copies, each carrying its
    own `check()`, mail-log reader and `signIn()`. The `TABLE_FIT` border bug survived in one of
    those copies for a week, which is the argument for there being one.
  * **`php/tools/.htaccess`** — `Require all denied`. That folder is under the webroot and the
    `PHP_SAPI !== 'cli'` guard in each suite does nothing for a `.mjs` or a `.ps1`, so all of this
    tooling's source was readable by anyone who guessed a filename.
  * **`.gitignore` and a git repository**, which the project had never had. See OPEN ISSUES.
  **What was left on 2026-10-02: running it.** None of the four new suites had been executed once,
  so every count above for them was zero and nothing in this entry was evidence yet.
  *(**Step 1 of the four is done as of 2026-10-03, and it passed.** A full static sweep came back
  clean: `php -l` over **all 70 PHP files**, `node --check` over **all five `.mjs`** — the three new
  suites, `lib/browser.mjs`, and the older `screenshot.mjs` — both plain `.js` files, and a real
  parse of `run-all.ps1`: **0 failures anywhere.** *(**Corrected 2026-10-03**: the two plain `.js`
  files were checked in the WRONG MODE and that sweep was worth less than it claimed — see
  "The bud" section below. `node --check x.js` parses as a CommonJS script; `js/customizer.js` is
  an ES module. Re-checked as modules, both still pass — but the sweep as originally run could not
  have failed on a duplicate top-level `const`, and hours later one was shipped into that very
  file and caught by a browser rather than by this.)* `run-all.ps1` was read against the adopt-or-start
  rules it claims to follow rather than taken at its word, and it implements all three: it refuses to
  start beside an existing `mysqld`, its readiness probe is a real `SELECT 1`, and it polls until the
  process is gone. It names all seven PHP suites by their exact inconsistent filenames and every one
  resolves. `layout-fixtures.php` was checked against the code it calls: `recordStockMove()` and
  `saveProductParts()` match their signatures, `'opening'` is genuinely a key of `STOCK_REASONS`,
  `order_events.actor` genuinely accepts `'customer'` and `'shop'`, and
  `NOW() - INTERVAL :ago HOUR` genuinely binds under `ATTR_EMULATE_PREPARES => false` — that last
  one being the construct in the file most likely to fail on a first run, and it does not.
  **Steps 2, 3 and 4 are still untouched**: not one assertion has executed, so every check count
  quoted for the four new suites is still zero and nothing here about* behaviour *is evidence.
  Syntax is not a test.)*
  **ALL OF THAT IS DONE AS OF 2026-10-13 — see the `### Testing` section below.** The three browser
  suites have run: **layout 395, journey 34, csrf-browser 8**, all green, alongside the ten PHP
  suites at **2,088**. The layout suite was then proved able to *fail*, and the screenshots were
  opened, which found one fault — in a fixture, not in the site.

## OPEN ISSUES

* **Resolved 2026-10-11 — two of the easy gaps closed, and one that looked easy left alone.**
  Asked to fix whatever was cheap after the roadmap was scanned for unbuilt features.
  **`session.cookie_samesite` is set.** `ensureSession()` now calls `session_set_cookie_params()`
  before `session_start()`, so `PHPSESSID` arrives as `path=/; HttpOnly; SameSite=Lax` — verified by
  reading the real `Set-Cookie` header, not by reading the code. This is the two-line change the
  Security item below has carried since 2026-09-24. `Lax` and not `Strict`, because Strict withholds
  the cookie on any cross-site navigation **including the link in the order email this shop sends**:
  a customer would land signed out while reading their own order. `secure` is deliberately not
  forced — there is no HTTPS here, and a secure cookie over plain HTTP is a cookie the browser never
  sends, which would lock everybody out. That belongs with the HTTPS job. `HttpOnly` came with it
  because nothing in this project reads `PHPSESSID` from JavaScript.
  **It buys less than it sounds, and the file says so.** SameSite is about *site*, not origin, and a
  port is not part of a site — so `localhost:8099` is same-site to `localhost:80` and `Lax` cookies
  are sent to it anyway, which is exactly why `csrf-browser-test.mjs` serves its attacker page on
  another port. The token is still what refuses the request; this closes an inconsistency with
  `cart.php` and `devices.php`, which both set `'samesite' => 'Lax'` explicitly, rather than adding a
  second line of defence.
  **`php/config/mail.php.example` exists.** Written from scratch with empty credentials rather than
  copied and edited, so the live app password could not end up in it — and then *proved* clean by
  extracting each secret from the real file and asserting none appears in the example, without
  printing any of them. Every one of the live file's eight constants is present, it parses, and it
  ships `MAIL_TRANSPORT = 'log'` and an empty `ADMIN_LOGIN_2FA_BYPASS_EMAIL` so a fresh copy starts
  safe. `.gitignore` already excluded the real file and named this one.
  **A per-customer order drill-down was NOT done, though it looked like a one-line link.**
  `adminOrderFilterSql()` searches `reference`, `contact_name`, `contact_phone` and
  `payment_reference` — **not the email and not `user_id`**. So the link would have had to match on
  the order's frozen `contact_name`, which is not unique and can differ from the account's current
  name: it would quietly show one customer another customer's orders, or miss their own. That needs
  a `user_id` filter in the data layer, which is a change rather than a link, so it stays on the
  unbuilt list.
  **Verified:** `run-all.ps1 -Quick`, ten PHP suites plus the module-parse gate, **0 failed
  suites** — which is the check that mattered, because four of those suites sign in over HTTP and a
  wrong cookie attribute would have broken every one of them.

* [!] **The admin account has no two-step verification at all. Found 2026-10-11 and NOT changed,
  because switching it back on is the user's call.** `php/config/mail.php` sets
  `ADMIN_LOGIN_2FA_BYPASS_EMAIL = 'admin@capstone.test'` and `php/login.php:148` honours it, so that
  one account signs in on a password alone — no emailed code, no trusted-device check. Its own
  comment says *"Temporarily bypass login 2FA only for this admin account; empty this to restore
  it"*, so it was deliberate and is very likely forgotten. **It is the account that can edit every
  product, settle every payment, read every customer and now change where the shop's mail comes
  from**. *(**Softened, not closed, on 2026-10-14**: sign-ins are now rate limited, so a password
  can no longer be guessed at machine speed on this account or any other. A throttle slows guessing;
  it is not a second factor, and the password is still the only thing in the way.)* It also explains
  something that looked like luck: the test suites sign in as the shop without ever reading a code,
  which is why `events-http-test.php` passed on the `'smtp'` transport where no code is written
  anywhere readable. **Emptying that constant restores 2FA**; the suites would then need the mail
  log, so they would need `MAIL_TRANSPORT = 'log'` to sign in — which is the same decision as the
  item below it.
* **Resolved 2026-10-11 — `.card-error` had no rule, so the one state on Reports that should look
  alarming looked ordinary.** `php/admin/reports.php` has used `class="card card-error"` for its
  "Unable to load reports" box since it was written on 2026-09-24 and nothing in `css/main.css`
  matched the modifier, so a failure rendered as a plain white card with a heading. Found by
  sweeping every class the admin views use against the stylesheet, which also turned up
  `.report-breakdown-card` — **left alone deliberately**: a modifier with no rule is harmless, the
  cards look right from `.card` on its own, and inventing a style for it would be a design decision
  nobody asked for. Styled off `.notice-alarm` rather than a new palette, for the reason that rule's
  own comment gives: a failure box on Reports and a `Short 8` pill on the ledger should read as the
  same alarm and not as two.
  **The selector is `.card.card-error`, and that is the whole reason it works.** Both are
  single-class selectors, so they tie on specificity and source order decides — and `.card` sits 760
  lines further down the file, so it won. The first version set the text colour successfully (`.card`
  does not set one) while the background stayed white and the border stayed grey: a box that had
  taken half its styling and still looked like an ordinary card. **Caught by reading
  `getComputedStyle()`, not by looking at the screenshot**, where "slightly off" and "not applied at
  all" are the same picture — `looksOrdinary: true` was the assertion that said so.
  Verified against the real failure rather than a mocked one: `orders` renamed out from under a live
  request, the box photographed, then the table renamed back with its row count and
  `order_items`' foreign key both re-checked. Background `#fdf1f1`, a 4px `#9c3535` bar, heading
  `#7d2b2b`. **The Print button is correctly absent on that screen**, because the toolbar is inside
  `if (!$loadFailed)` — a report that could not load is not a report to print.
* **The Gmail app password now has two homes, as of 2026-10-11.** It was only in
  `php/config/mail.php`, which `.gitignore` excludes; the mail settings screen can now store it in
  the `settings` table, which a database dump contains. Unavoidable if the sending address is to be
  changeable at all — SMTP authenticates with the address *and* its password, so one without the
  other is a setting nobody can use — and it is stored as given because AUTH has to present the real
  value. Never rendered to a page, never printed by any tool, and `sql/settings-upgrade.sql` carries
  the warning beside the schema. **Treat a dump of this database the way that file's own header says
  to treat the file.**

* **Resolved 2026-10-08/10 — the ten failing assertions are fixed, and neither bug class had
  spread.** The user asked for these to be dealt with on the grounds that they might affect other
  operations, so the first thing done was to find out whether they could. **Two project-wide sweeps,
  both clean.** A scan of every `prepare('...')` string outside `php/tools/` for a named placeholder
  used twice — the `SQLSTATE[HY093]` class — returns **zero**: `setEventActive()` was the only
  instance and it is fixed. A scan of every `escape()` call in every file that declares
  `strict_types` returns twenty hits, **all of them genuine strings** (`SITE_NAME`, concatenations,
  `$options[...]`, docblock mentions, `csrfToken()`); no other integer reaches a function typed
  `escape(string $value)`. Neither fault is systemic.
  **The seven mail assertions now ask the transport instead of dictating it.**
  `orders-http-test.php` asserted `MAIL_TRANSPORT === 'log'` and then read the log; it now checks
  the transport is one of the two this project knows, runs the seven body assertions when it is
  `'log'`, and **prints a named SKIP** when it is not. A suite that fails because the environment
  moved is a suite that gets ignored, and pinning the setting would mean a test file dictating how
  the shop sends mail. **Proved both ways rather than assumed**: flipped to `'log'` it reports
  **334 passed, 0 failed, 0 skipped** — every mail assertion runs and passes — and back on `'smtp'`
  **327 passed, 0 failed, 7 skipped**. `php/config/mail.php` was restored with an identical SHA-256
  and a copy kept at `%TEMP%\mail.php.yours-20261010.bak`.
  **That round-trip also caught an off-by-one in the skip count**, which is the part worth keeping:
  the first version said "8 skipped", and 327 + 8 does not equal the 334 the other run reported. The
  transport check itself runs either way and is not skipped. A skip count that cannot be reconciled
  against the other branch is a summary telling a small lie, and the only reason it surfaced is that
  both branches were run and the numbers compared.
  **The two absolute assertions in `admin-http-test.php` are deltas now.** `$quietDay = 5` was
  hard-coded — day six of the window had no sales on the day it was written, and stopped being quiet
  the moment the shop traded on that weekday (₱677). The window is now **scanned** for a day that
  has an account and no revenue, which is the condition the assertion actually needs; if the shop
  traded on all seven, that is a week with no quiet day and it skips rather than fails. And "a
  custom bouquet is in the ranking" compares against a **baseline taken before the fixtures exist**,
  so it asserts *this run's one bouquet turned up* however many the shop has sold. Both suites also
  gained a `$skipped` counter printed beside the pass/fail line, because an un-made assertion must
  never read as a made one.
  **`php/admin/inventory.php` was missing from the admin sidebar**, despite Inventory's own section
  claiming an entry in `$adminLinks`. It was only reachable from the Dashboard and from a Components
  row. Added as "Stock", beside Components and Events.
  **Verified: `run-all.ps1 -Quick`, nine PHP suites plus the module-parse gate, 0 failed suites** —
  2,023 passed, 0 failed, 7 skipped, table counts identical before and after, every `ZZ` counter 0,
  and the runner started and cleanly stopped both servers.

* **`MAIL_TRANSPORT` is now `'smtp'`. The seven assertions this broke were fixed on 2026-10-10 (see above); kept for the record of why.** Seven assertions in `orders-http-test.php` failed
  because of it. Found 2026-10-08.** `php/config/mail.php:41` reads `const MAIL_TRANSPORT = 'smtp';`
  where every claim elsewhere in this file says it is `'log'`. That change was not made by any task
  recorded here, and it is **not a defect** — it is presumably somebody wiring up real delivery. But
  it has two consequences worth naming. **First, the suite.** `orders-http-test.php` asserts
  `MAIL_TRANSPORT === 'log'` and then that the run appended to `%TEMP%\capstone-mail.log`; with smtp
  it writes nothing there, so seven checks fail — "the transport is the log one", "this run wrote to
  it", and the five that read the appended bytes. Nothing about orders is broken; the suite is
  asserting an environment that no longer holds. **Second, every mail result quoted anywhere in this
  file is a log-mode result**, and the entry further down saying no mail has left this machine and
  none will is now out of date as a statement about the present. The log's last write was
  2026-10-06 23:50 and it still holds live sign-in codes for real accounts, so it is still a secret.
  **The fix is a decision, not a patch**: either the suite learns to accept both transports (read
  the log when `'log'`, skip the body checks and say so when `'smtp'`), or mail goes back to `'log'`
  for testing. That is the user's call, and nothing here guesses at it.
* **Two assertions in `admin-http-test.php` were absolutes that the shop's own trading broke. Fixed 2026-10-10 (see above); kept for the reasoning.** They were absolutes that trading now
  breaks. Found 2026-10-08.** "the fixture day has no revenue" expected ₱0 and got **₱677**, and "a
  custom bouquet is in the ranking" expected 1 and got **2**. Comparing the database against the
  2026-10-03 scan recorded below: `orders` 3 → **4**, `saved_designs` 0 → **1**, `products` 13 →
  **14**. A real order containing a custom bouquet was placed in between. **The suite is wrong, not
  the screens** — and it is wrong in exactly the way this file already warns about: *assert on the
  delta, not the absolute, whenever the shop's own data is in range.* Those three checks pin a
  weekday's revenue at zero and a ranking row at one, both of which the shop invalidates simply by
  trading. The fixture that proves the Accounts series is not derived from Revenue needs a day with
  no sales, and there is no longer a guaranteed one; it should create its account on a date far
  enough back to be quiet, or assert the delta.
* [!] **Three source files were corrupted by a bulk find-and-replace on 2026-10-08, and two were
  recovered from the editor's local history. Recorded because the cause is a trap, not a typo.**
  A PowerShell loop meant to wrap `escape($percent)` in a `(string)` cast instead ran
  `.Replace('e', 's')` over `php/index.php`, `php/product.php` and
  `php/components/product-card.php`, turning every `e` into an `s` — `require_once` became
  `rsquirs_oncs`. **The cause: `@(@('a','b'))` FLATTENS in PowerShell**, so `$pair[0]` and
  `$pair[1]` were the single characters `'e'` and `'s'` rather than the two strings. The asymmetry
  is what makes it dangerous — a list of *two* pairs does not flatten, so the same loop corrupted
  three files and left `php/admin/events.php` perfectly intact. **It is not reversible**: an `s` in
  the damaged file may have been an `s` or an `e`. `index.php` and `product.php` were restored from
  `%APPDATA%\Code\User\History\` (sizes matched the pre-edit originals byte for byte, 4,574 and
  7,678); `product-card.php` had **no history entry at all**, because it had only ever been written
  by tooling, and was rewritten from scratch. All three lint clean and the suites are green. The
  damaged copies are kept at `%TEMP%\*.damaged-20261008.bak`. **This is the version-control item
  below demonstrating itself a third time**, and the sharpest demonstration yet: a `git checkout --`
  would have been one command, and for one of the three files the only copy that existed was in a
  conversation.
* **Resolved 2026-10-08 — `css/main.css` lost 1,549 bytes between 2026-10-03 and 2026-10-06, and
  nothing was actually lost.** Worth recording because the size drop looks exactly like the
  2026-10-01 revert and was investigated as though it were one. The local history reads: 136,065 at
  2026-09-30 18:04 (last good), 109,899 at 2026-10-01 15:21 (the revert), **136,065 again at
  2026-10-04 15:49** — byte-identical to the last-good backup in `%TEMP%`, so the 2026-10-02 session's
  additions were dropped — then 136,368 at 2026-10-06 23:20. A line-by-line diff of the current file
  against `%TEMP%\main.css.last-good-20260930.css` returns **sixteen lines present in the current
  file and none missing from it**: `.admin-stat-grid--reports` and `.order-main > .panel
  { min-width: 0 }`. So the fix the 2026-10-02 entry describes is back and nothing was lost.
  **Corrected 2026-10-08:** an earlier version of this entry said that rule had been re-written
  *inside* `@media (max-width: 900px)`. It has not. Checking brace depth line by line puts
  `.order-main > .panel` at `css/main.css:4670` at **depth 0 — top level and unconditional**, which
  is what the original fix was. The `@media` lines in the diff belonged to the
  `.admin-stat-grid--reports` rule beside it; `Compare-Object` lists differing lines without
  context, and reading an ownership relationship out of that listing was a guess dressed as a
  finding. There is nothing to fix in the CSS.

* [!] **`css/main.css` was reverted on 2026-10-01 and lost roughly a week of styling. Recovered
  2026-10-02 — but nothing stops it happening again.** The file on disk was byte-identical to its
  2026-09-24 6:33pm version (109,899 bytes, same hash), while the last good version was
  2026-09-30 6:04pm at 136,065. **37 top-level selectors had gone**, across four finished modules:
  `.pill-danger` and `.pill-warn` (used by Inventory, the dashboard and the new order panel), the
  whole recipe editor (`.recipe-list`, `.recipe-item*`), the ledger's `.ledger-in` / `.ledger-out`,
  the dashboard's `.dashboard-buy-panel` and `.dashboard-stock-*`, the home page's `.home-hero-band`
  and `.hero-*`, and this task's `.order-stock-*`. The site was rendering unstyled pills and an
  unstyled recipe screen, and no PHP suite could see any of it — all seven stayed green throughout,
  because they assert markup and not appearance. Recovered from VS Code's local history
  (`%APPDATA%\Code\User\History\-71d743a2\0q8f.css`). **Nothing was lost in the restore**: the hash
  check proved no edit had been made after the revert. Both states are kept at
  `%TEMP%\main.css.as-found-20261002.bak` and `%TEMP%\main.css.last-good-20260930.css`
  — *both still on disk and verified at 109,899 and 136,065 bytes on 2026-10-03.* **The real
  issue is that this project has no version control** — `git` is not initialised here, so a stray
  restore or a bad undo is unrecoverable except by an editor's local history, which expires. That is
  the thing to fix, and it is bigger than a module.
  **Scanned again 2026-10-03, and the revert window was wider than this entry knew.** The local
  history shows the restore landed on **three** project files, not one: `css/main.css` and
  `php/components/header.php` at 15:21:39, and `php/profile.php` at 15:26:21. **Only `main.css` lost
  anything.** The other two had not been edited since 2026-09-24 18:27, so their pre-revert and
  post-revert bytes are the same bytes — 5,926 and 29,766, matching what is on disk today. The
  revert overwrote them with themselves. That is a worry closed rather than opened, and it is only
  provable because the history happens to still hold the 2026-09-24 entries. **It also took two
  whole files that this entry never mentioned — see the next item.**
* **Decided 2026-10-03, not restored — two documentation files were written on 2026-10-01 and are
  not in the project any more.** Found by scanning the editor's local history rather than the repo.
  `DATABASE_ERD.md`
  (6,489 bytes, saved twice — 15:27:55 and 15:28:07) and `DATABASE_ERD_OVERVIEW.md` (3,383 bytes,
  15:37:13) both sat at the project root. **Neither is on disk now**, and no entry in this file ever
  mentioned them, so their loss went unnoticed for two days while the `main.css` revert beside them
  got a whole investigation. `DATABASE_ERD.md` is a Mermaid `erDiagram` of the live schema with the
  note *"based on the live `capstone` database metadata queried on 2026-10-01 … schema only; no
  account, order, or other row data is included"*; the overview is its companion read-me.
  **Both have been copied out of local history to `%TEMP%` as a safety net and nothing in the
  project was touched**: `%TEMP%\DATABASE_ERD.recovered-20261001.md` and
  `%TEMP%\DATABASE_ERD_OVERVIEW.recovered-20261001.md`. **The user's call was to leave them
  deleted**, so they are not coming back and this is a record rather than a task. The schema they
  described is still readable the way it was derived in the first place — `information_schema` —
  and the live ERD was read out and reviewed on 2026-10-03: 16 tables, 25 foreign keys, 6 CHECK
  constraints, now 18 tables and 28 keys with `events` and `event_products`.
  **This is the version-control item above stopping being hypothetical.** That entry argued a stray
  restore would be "unrecoverable except by an editor's local history"; this is the case where it
  cost two files outright rather than 37 selectors, and where nothing but an unrelated file-by-file
  scan would ever have reported it. A `git status` would have shown both deletions in one line.
* **Also found 2026-10-03, and fixed: a stray keystroke from that same 2026-10-01 edit.**
  `php/config/designs.php` was the one file genuinely *changed* rather than reverted that afternoon
  (15:38:38), and the change was two characters — a module-docblock line reading *"returned by the
  oneww caller that forgets to compare"*. Harmless to behaviour, invisible to `php -l`, and the only
  reason it is recorded at all is that it is the single content difference between the file on disk
  and its last good version, so a future diff of that file should not treat it as meaningful. Fixed
  to `the one`. **And one stale reference in the new test tooling**: `php/tools/lib/browser.mjs`
  cited `setUpConfirmButtons()` as living in `js/app.js`. There is no `js/app.js` in this project —
  the file is `js/script.js`, where that function is at line 367. A comment, not code, so nothing
  behaved wrongly; corrected because the one thing this project's own notes say repeatedly is that
  guessing a filename here costs a whole run.
* **Resolved 2026-10-02 — the order page scrolled sideways on a phone, and the customer's copy had
  the same latent fault.** Found by the Stage 2 browser pass. `.order-main` is `display: grid` and
  carries `min-width: 0` for itself; its children did not, and a grid item's automatic minimum size
  is its min-content size — so the single column's track was sized to the widest panel's min-content
  and every panel overflowed the container that had been so carefully told it could shrink. At 390px
  the document measured 916 against a 390 viewport and genuinely scrolled, carrying the header off
  the screen. **The overflow report named the wrong elements**: `.order-timeline`, its steps and its
  timestamps, all sitting at the same right edge — which is what "stretched to fill an over-wide
  parent" looks like, not what a cause looks like. Hiding one panel at a time found it in a single
  pass, where measuring never would have. Fixed with `.order-main > .panel { min-width: 0 }`, on the
  item rather than the track because `.order-main`'s columns are declared in two places. The scroll
  box then does its job: 318px of window against a 456px forecast table and a 553px record table,
  scrolled rather than spilled. **`php/order.php` shares the wrapper** and had the same floor waiting
  in "What to make", so the one rule fixes a screen the pass was not even looking at.
* **Resolved 2026-10-02 — an assertion in `inventory-http-test.php` had never been true.** The
  dashboard section looked for the string `3 owed` in the "What to buy" panel. The panel has never
  said that and is right not to: a shortfall is a `Short` pill beside an `On hand` column reading
  `-3`, and a column headed "On hand" printing `3` for a drawer holding minus three would be a lie in
  the one place it matters most. **The check was wrong, not the screen.** It had been sitting in the
  suite unrun since the dashboard panel landed on 2026-09-28 — the runs in between were the three
  order-related suites, not this one. Replaced with an assertion on `number_format(-3)` and on the
  `Short` pill, which is what the wording was reaching for.

* [!] **Four things Security's `[x]` did not cover — ONE still open as of 2026-10-14, and it is the
  one that cannot be fixed from PHP.** Recorded here on
  2026-09-24 as their own item rather than left as a caveat inside a closed module, because a
  caveat inside a `[x]` is a thing that gets skimmed. **Login rate limiting** — ***done
  2026-10-14***: `php/login.php` would check a password as fast as it was asked to, so an account
  with a guessable password had no protection but the 2FA step that follows it.
  `php/config/login-guard.php` now refuses after five wrong tries at one identifier or twenty from
  one address inside a sliding fifteen-minute window, checked *before* the password is read, with
  nothing ever locked and the refusal saying nothing about whether the account exists. *(**Corrected
  2026-10-11:** this item used to add
  "and the sign-in code is six digits with no attempt cap of its own either", which is **not true
  and never was**. `php/config/twofactor.php:26` sets `CODE_MAX_ATTEMPTS = 5` and `:184` enforces
  it — five wrong guesses and the code is deleted, not merely refused — and `CODE_RESEND_SECONDS`
  throttles resends to one a minute so the form cannot be used to flood an inbox. The password
  check genuinely had no throttle; the code it leads to always did. An overstated gap misleads as
  surely as a hidden one, and this one made the second factor sound worthless.)*
  **NO HTTPS — this is the one that is still open** and the only one of the four that needs
  something other than code:
  every password, every 2FA code and every session cookie
  crosses the wire in clear; on `localhost` that is nothing, off it that is everything. It wants a
  certificate and an Apache virtual host, and until there is one `session.cookie_secure` must stay
  off — a secure cookie over plain HTTP is a cookie the browser never sends, which would lock
  everybody out. **A session was not bound to its user agent or its address** — ***done
  2026-10-14***: a signed-in session now carries a sha256 of its browser's User-Agent, written in
  `signInUser()` and checked by `ensureSession()` on every request, and a mismatch destroys it. The
  address is deliberately not part of the fingerprint; the section below says why.
  And **`session.cookie_samesite` was empty** — ***fixed 2026-10-11***: `PHPSESSID` carried no
  SameSite attribute at all,
  while `php/config/cart.php:74` and `php/config/devices.php:42` both set `'samesite' => 'Lax'` on
  their own cookies explicitly. That inconsistency was found while testing the CSRF fix and left
  alone deliberately — changing session cookie behaviour was outside that task and would change how
  a demo machine behaves for no gain that day. `ensureSession()` now calls
  `session_set_cookie_params(['samesite' => 'Lax', 'httponly' => true])` before `session_start()`,
  and the real `Set-Cookie` header was read back to prove it: `path=/; HttpOnly; SameSite=Lax`.
  **It did not change the conclusion below, which is the point of having written it down.** The
  token is still the only thing standing between another site's page and a signed-in visitor's
  account, not the second of two. **SameSite does not help as much as it sounds.**
  SameSite is about *site*, not origin, and **a port is not part of a site** — so a page on
  `localhost:8099` is same-site to `localhost:80` and `Lax` cookies are sent to it regardless. The
  attacker-page test is served on another port for exactly that reason: a `file://` page would have
  been stopped by cookie rules before the handler ever ran and would have proved nothing.
* **Resolved 2026-09-27 — every admin table page scrolled sideways on a phone, and two of them had
  been doing it since they shipped.** Found by the Inventory browser pass, on a screen built that
  day, and then found to be older than the screen: at 390px `php/admin/inventory.php` measured a
  599px document, and `php/admin/components.php` (807) and `php/admin/products.php` (768) were
  worse. Not cosmetic — the page itself slid, so the heading, the filters and the paging all
  travelled off the left edge while the visitor was trying to read a table. **Table width was not
  the cause**, which is what made it confusing: `php/admin/orders.php` has the widest table in the
  project at 1070px and did not scroll at all. The cause is that **`.visually-hidden` is
  `position: absolute` and `.admin-table-wrap` was `position: static`.** A scroll box only clips
  descendants whose containing block is inside it, and a static box is not a containing block for an
  absolutely positioned one — so every hidden label inside the table was laid out against an
  ancestor *outside* the box, escaped the `overflow-x: auto` entirely, and counted toward the
  document's scroll area. `inventory.php` has 54 of them, one per input on every row form, the
  widest sitting at exactly x=599. `orders.php` has one, at x=227, which is why it was fine and why
  the obvious suspect was the wrong one. **The fix is `position: relative` on `.admin-table-wrap`**
  in `css/main.css` — one line, with the reasoning above it, and it fixes all four screens at once
  because they share the rule. Nothing moves on screen: the labels are 1px and clipped either way.
  Verified in headless Chrome at 390px on all five table screens, by scrolling rather than by
  measuring — `window.scrollTo(500, 0)` now leaves `scrollX` at 0 on every one of them, where
  `inventory` was 209, `components` 417 and `products` 378.
  **The measurement that missed it is worth more than the bug.** The check excluded the table's
  scroll box with `el.closest('.admin-table-wrap')` — and `closest()` matches the element it is
  called on, so the one box whose entire job is to clip was the one box exempt from the question
  "does anything hang off the edge". It reported *nothing hangs off the right edge* on a page that
  visibly scrolled. It asks the parent now, and there is a second assertion that pushes the page
  sideways and reads back how far it went, because `scrollWidth` is a hint and a scroll is a fact.
* **Resolved 2026-09-29 — the admin dashboard scrolled sideways on a phone, and it had three
  separate causes, none of them the thing that was reported.** Found by the browser pass over the
  new "What to buy" panel, so the new panel was the obvious suspect; the check named the **KPI
  cards** as the elements hanging off the edge. Both were wrong. A removal experiment settled it by
  measurement rather than argument — at a 390px viewport the document measured 515px **as shipped**,
  515px **with the new panel deleted**, and 515px **with the KPI grid deleted** (`.dashboard-panels`
  simply took over as the reported offender). Nothing that was reported was causing anything.
  **The three real causes, all pre-existing, all the same family of mistake.**
  (1) **`.admin-dashboard-shell` had no `grid-template-columns` at all.** A `display: grid` with none
  gets one implicit `auto` column, and an auto column is at least the min-content width of its widest
  item — so the widest of the four rows set the column, and the column set every other row. That is
  why innocent cards measured 499px wide in a 390px window and looked guilty. Now `minmax(0, 1fr)`.
  (2) **The `@media (max-width: 980px)` override said `1fr` where the base rules say
  `minmax(0, 1fr)`.** They are not the same thing: `1fr` means `minmax(auto, 1fr)`, and that `auto`
  floor keeps a grid item's default `min-width: auto`, so a card cannot shrink below its own content.
  The base rules spell out the long form for exactly this reason; the phone override silently dropped
  it, which is why the desktop view always looked right.
  (3) **`.bar { width: 12px }` gave the seven-day chart a rigid 452px floor.** A *definite* width
  makes an element's min-content contribution equal to that width, so three bars plus gaps plus
  padding floored each column at 56px whatever room there was. Now `flex: 1 1 0` with
  `max-width: 12px` — contribution 0, same 12px rendering wherever there is room.
  **Widening the test then found two more pre-existing faults on the same screen**, which is the part
  worth keeping: `.table-wrap` and `.data-table` — the recent-orders table — **had no CSS rule
  anywhere in the project**, so a four-column table whose first column is a 17-character order
  reference pushed the whole page sideways at 320px; and the base
  `.dashboard-panels, .dashboard-lower-grid { grid-template-columns: 2fr 1fr }` had the same bare-`fr`
  bug, spilling the chart panel 12px past the right edge **between roughly 981px and 1050px** — a band
  no round-number test width lands in, which is how it survived the first fix round. `.table-wrap` now
  matches `.admin-table-wrap` exactly, `position: relative` included, for the reason recorded in the
  entry above; `.data-table` gained the cell padding that `border-collapse: collapse` takes away.
  **Two things the measurements could not have told us.** A scroll-only assertion called round two
  fixed while the bars were still spilling over the panel's right border — they overflowed *within*
  the layout without moving the document one pixel, so the sweep now asks a second question (does the
  chart fit its own panel?) at every width. And the cell-padding fault — `delivered₱110.00`, two
  columns touching, which I introduced — was invisible to every number and obvious in a screenshot.
  Verified at 320/360/390/979/981/1010/1440 plus **every width from 320 to 1440 in 20px steps**, no
  slide and no spill at any of them, and the three changed panels photographed at phone and desktop
  width and looked at. Only `css/main.css` changed, so nothing here could reach the PHP suites — but
  all seven were re-run anyway to say so from evidence rather than from reasoning: **1,731 checks,
  0 failed**, identical table counts before and after, zero `ZZ` rows left.
* **Resolved 2026-09-29 — one of the two "orphaned" `carts` rows was not an orphan, and finding
  that out was the point of not deleting them.** Recorded 2026-09-27 as *rows 37 and 39 name
  `user_id` 112 and 113, and `users` holds two rows*, with the note that working out what they were
  is cheaper than a `DELETE` that destroys the trace. It was. Joined against `users`, **cart 39's
  owner (113) is genuinely gone, and cart 37's owner (112) is `kennethbargayo213@gmail.com` — a real
  customer account that still exists.** The 2026-09-27 reading was wrong about which rows were
  orphans because it counted `users` rows instead of joining to them: two of the three carts named a
  live account, and only one did not. **Cart 39 deleted, cart 37 left exactly where it is**, along
  with cart 12 (the admin's). A sweep of all 25 foreign keys in the schema afterwards returns zero
  orphaned rows anywhere, and the seven suites are green with identical table counts before and
  after. The general lesson is the one the original entry guessed at: *an inference about a row is
  not the same as a query about it*, and a delete would have taken a real customer's basket on the
  strength of an inference.
* **Decided 2026-09-24, not changed — the customer's timeline still says "Moved back to Placed",
  and a re-pack still sends a second "being made" email.** Both were reported as issues when Orders
  closed and both were looked at properly; the answer in each case is that the current behaviour is
  the correct one and the alternative is worse. **The dot stays** because hiding the shop's undo from
  the customer would put the page in contradiction with their inbox — they already have the "being
  made" email that the first move sent, and the dot is the only thing that makes the second email
  legible. A history table that hides part of the history is not a history table. **The duplicate
  email stays** because two presses of a button that says *tell the customer we have started* are
  two instructions to tell the customer, and a customer who deleted the first message would
  otherwise get silence. Suppressing it would also mean coupling the mailer to `order_events` to ask
  whether this has been said before, which is a dependency for a cosmetic gain. Recorded so that
  neither is mistaken for an oversight later. Both are the user's to overrule.
* **Not fixable here, and permanent as a constraint rather than a bug: `MAIL_TRANSPORT` is
  `'log'`.** No mail has left this machine and none will. Every message the project sends —
  verification, sign-in codes, and the four order notifications — is appended to
  `%TEMP%\capstone-mail.log` with its headers and body intact, which is what makes the 2FA flow
  testable end to end. Real delivery needs the user's own mail credentials, which this project will
  not ask for or handle, so every result reported anywhere in this file is a log-mode result and
  says so. **The log holds live sign-in codes for real accounts** and is treated as a secret: it is
  read for appended bytes only, digit runs are masked before anything is printed, and no code has
  ever been shown.
* **Resolved 2026-09-26 — all five Admin defects fixed, and the two "not a defect" items with
  them.** Proved by a new suite of 78 checks, run alongside the existing five (1,495 checks total,
  0 failures). What each one was, for the record, because three of them were a screen disagreeing
  with another screen rather than a crash: **(a) the dashboard's "Customers" KPI counted admins as
  customers** — `adminCountCustomers()` unfiltered, reading every row in `users`, while
  `customers.php` broke the same data down as "All accounts / Customers / Admins". The admin count
  was already computed on the next line and thrown away; it is the card's subtitle now ("Plus 2 staff
  accounts"), so both numbers are shown and the two screens agree. Asserted over HTTP by scraping the
  KPI and comparing it against `adminCountCustomers(['role' => 'customer'])` — 3, not the 5 it used
  to print. **(b) the "Revenue trend" chart drew three series from one number** — bar one at the
  revenue percentage, bar two at `revenue - 10`, bar three at `revenue - 20`, behind a legend
  promising Revenue / Orders / Accounts. Now three real queries. Orders-per-day comes from `orders`,
  accounts-per-day from `users.created_at`, and each series is normalised against **its own** peak,
  which is printed in the legend and repeated in every bar's `title` and a `.visually-hidden` span —
  without a scale a ₱1,360 week and a ₱136,000 week drew the same picture. The panel is titled "Last
  7 days" rather than "Revenue trend", since it is three measurements and not one trend. The test
  pins this with a fixture account created on a day with **no** sales: its Accounts bar is 50% where
  the old drawing would have computed `max(0, 0 - 20)` = 0. **(c) `adminDashboardStats()` was the one
  data call on the page with no `try`/`catch`** while five siblings each had their own, on a file that
  spent two comment blocks explaining that a partially-migrated database must still render its
  working cards. Now guarded, and the fallback is `adminEmptyDashboardStats()` — a fully-formed zero
  shape, because the template indexes `donut_stats[0]` and `graph_series[n]['bars'][3]` directly and a
  bare `[]` would only trade the fatal for an undefined index. Tested both ways: the shape supplies
  every key and index the template dereferences, **and** end-to-end by renaming `orders` out from
  under a live request — 200, no fatal, the toast naming `sql\orders-upgrade.sql`, and the customer
  cards still on the page. The rename is reversed inline and again in a `register_shutdown_function`,
  and the suite then re-asserts that `order_items.order_id` still points at `orders`. **(d) two
  definitions of revenue, agreeing only by luck** — the dashboard summed `total` with no status
  filter, `reports.php` used `status <> 'cancelled'` and said so on the card, `customers.php` had no
  filter either. They agreed only because there were **zero cancelled orders** on the books. One rule
  now runs through `config/admin.php` and is stated in its module docblock: **a count of orders
  includes cancelled ones, a sum of money excludes them** — the work still came in, the money was
  never collected. Both screens that could mislead now say so in the markup ("excludes cancelled").
  The test places a cancelled order of ₱9,999 and asserts weekly revenue rose by ₱1,850 while weekly
  orders rose by 4. **(e) the customers search form dropped the role filter** — one field, `q`, no
  hidden `role`, so searching from the Admins tab silently returned you to All accounts. One hidden
  input, emitted only when a tab is active; asserted by scraping the form (`role, q`) and by
  searching inside the Admins tab. There is deliberately no `page` field: a new search belongs on
  page one. **And the "arguably by design" note turned out to matter more than the defects.**
  `reports.php`'s "Top products" filtered `oi.kind = 'product'` and joined `products`, which dropped
  two whole classes of sale: every **custom bouquet** — the shop's headline feature — had no
  `product_id` to join on, and because `order_items.product_id` is `ON DELETE SET NULL`, withdrawing
  a product erased its entire sales history from the report. Now grouped on the line's own frozen
  snapshot name, with `CASE WHEN oi.kind = 'design' THEN 'Custom bouquet' ELSE oi.name END` repeated
  in SELECT and GROUP BY (repeated, not aliased — the alias `name` collides with a real column and
  MariaDB calls it ambiguous). The cost is that two different products sharing a name merge into one
  row, which is a fair trade for a by-name ranking and much cheaper than silently under-reporting.
  The test deletes a product mid-run and asserts its 3 units survive.
* **Resolved 2026-09-26 — the admin dashboard went from ~34 queries with ~7 rendered to 9, all
  rendered.** Eight variables were computed and never shown (`$productCount`, `$hiddenCount`,
  `$componentCount`, `$withdrawnCount`, `$openOrders`, `$waitingPayments`, `$ordersTotal`,
  `$adminCount`), each appearing exactly twice in the file — once to initialise, once to assign. Three
  of the six `try` blocks existed only to guard them, and one still raised "Unable to count the
  customizer parts" about a count nobody could see. `adminReportsSummary()` was called unfiltered —
  eleven queries — to borrow two of its eleven results, and `SELECT COUNT(*) FROM users` ran three
  times per load. The dead variables and their three toasts are gone; the two borrowed lists are now
  `adminTopProducts()` and `adminRecentOrders()`, which both screens share, so the dashboard no longer
  loads the reports page to read two of its answers. What remains is two data calls behind two `try`
  blocks: `adminDashboardStats()` (7 queries — one weekly summary, three series, one payment
  breakdown, and the two lists) plus `adminCountCustomers()` twice. Measured in the suite off
  `Com_select`, calibrated against the cost of reading the counter itself: **7**. `config/products.php`
  is now required explicitly for `formatPrice()` alone, since the three admin config files the page
  used to pull in have no call sites left on it. **`adminInventorySummary()` kept, call deleted** — its
  nine queries fed nothing, but it is written and it works, and Inventory is the module that wants it;
  deleting it would only mean writing it again. Its docblock now says so, with the date. Adding a panel
  for it here would have been scope creep into a module that has not started. The two stale docblocks
  describing screens the redesign removed are rewritten.
  *(Updated 2026-09-29: the panel exists now, so the page's own appetite is those 7 queries plus
  `adminInventorySummary()`'s nine and the two customer counts. The **7** above is unchanged and still
  asserted, because the suite measures `adminDashboardStats()` — the function — and not the page.)*

* **Resolved 2026-09-24 — every POST in the project now carries and checks a token.** For six days
  this was the project's largest piece of debt: every POST form — sign in, sign up, verify, resend,
  log out, the four on the profile page, the six admin product and part forms, the three on saved
  designs, the six basket actions posting to `php/cart-update.php`, and the eight that place, settle
  and fulfil an order — accepted a cross-site submission. What that exposed, for the record, because
  it is the reason the fix is shaped the way it is: **the basket forms were reachable by a visitor
  with no account at all** (the cookie is the only identity, so a forged `empty` threw away a
  stranger's basket); the saved-design forms were the first destructive POSTs a plain *customer*
  could reach; a forged POST to `php/checkout.php` placed a real order in a signed-in customer's
  name, to an address the attacker chose, and emptied their basket doing it; a forged admin `pay`
  recorded money that never arrived, which only a person reconciling a GCash app by hand would
  notice; and a forged `deliver` was **the one forgeable request in the project with no undo of any
  kind** — refused from every state but `packed`, permanent once it lands by design, and it emails
  the customer in the shop's name. Orders made this entry worse on 2026-09-22 rather than better,
  and knowingly, which is why fixing it was the whole of the next task. Now: one token per session
  from `php/config/csrf.php`, dropped on sign-in, checked as the first statement of all 14 handlers —
  placed *after* `product-edit.php`'s and `component-edit.php`'s oversize-upload discard branch, so a
  60 MB photo still gets "that file was too large" rather than "your session expired". Proved by 162
  checks in `php/tools/csrf-http-test.php` (each handler refused with no token and with a wrong
  token, accepted with a scraped one, **and the thing it would have changed confirmed unchanged**),
  by a rendered-form sweep that catches a field placed outside its `<form>`, by two static checks
  that no handler file lacks a guard call and no admin file lacks its `guard.php` require, by a real
  browser walking sign-in → basket → checkout → pay → pack → deliver pressing the actual buttons, and
  by an attacker page **served over HTTP on another port** — same-site to `localhost:80`, so the
  cookies really were sent and the token really was the only thing that refused it. The basket
  survived and the shop answered "That form had expired, so nothing was changed. Try again."
* **Resolved 2026-09-24 — the CHECK constraint that two tables could be left violating.**
  `fk_cart_items_product` is `ON DELETE SET NULL` while `chk_cart_items_kind` demanded
  `kind = 'product' AND product_id IS NOT NULL OR kind = 'design'` — so deleting a product nulled
  the column on any basket line pointing at it and left a `'product'` row with no product id.
  MariaDB 10.4 enforces CHECK constraints **but not for changes made by a foreign-key referential
  action**, so nothing refused the delete and nothing complained afterwards; the table simply ended
  up holding rows its own constraint forbade, and a later `ALTER TABLE ... CHECK` validation or a
  dump reload would have rejected rows the running server produced. `order_items` was asked to
  answer this on 2026-09-20 and copied it instead. The fix was neither of the two options this entry
  had proposed. Inverting the predicate to `kind = 'product' OR product_id IS NULL`
  (`sql/kind-check-upgrade.sql`, and in the three `CREATE TABLE` bodies so a fresh install matches a
  migrated one) is **strictly stronger where it matters and unbreakable by a referential action**:
  nulling the column always satisfies it, and unlike the old one it also forbids a *design* line
  carrying a stale `product_id` — the row the old comment warned would be "totalled twice over by a
  careless join", which the old predicate's `OR (kind = 'design')` had let straight through. A
  pre-flight count found zero existing violations in either table, the migration is re-runnable
  (a second immediate run also exits 0), and `information_schema.CHECK_CONSTRAINTS` now reports the
  new predicate for both. The half of this that was deliberate stays deliberate: the order line keeps
  its own `name`, `unit_price` and `line_total` so a receipt survives its product being deleted. That
  path is now exercised live for an **order** line rather than only a basket line, which it never was.
* **The admin account's basket did not match the 2026-09-16 record — and on 2026-09-21 the
  evidence came back pointing at the user rather than at a test.** That record says the admin
  holds one Single Calla Lily at ₱110.00. What cart 16 held when this was found on 2026-09-20
  was one line instead — id 450, `kind = design`, "Custom bouquet: 6 flowers, domed",
  ₱348.00 × 1, `created_at 2026-09-20 10:53:43` — and it was reported as a probable breach of
  this project's rule that a test never writes to a seeded row or a real account. It was left
  in place rather than quietly deleted, because deleting it would have destroyed the only
  evidence of what had happened. That turned out to be the right call. The user has since
  checked that basket out themselves: `orders.id 41`, `SLJ-20260921-6YP2`, ₱348.00, a single
  line reading "Custom bouquet: 6 flowers, domed", placed 2026-09-21 08:35 from
  `admin@capstone.test`. Cart 16 is now empty. Two further orders (42 and 43) and a hand-made
  "Test2" product were added the same morning, with no session of mine running. The simplest
  reading is that the user built that bouquet in the customizer and put it in their own
  basket, which is not a violation of anything. Not provable either way, but the line is gone
  and there is nothing left to do. Note that the 2026-09-16 record below is now historical on
  this point — the Calla Lily it describes is not there.
* **MariaDB would not start on 2026-09-16, and was recovered rather than reinstalled.**
  `[FATAL] InnoDB: Trying to read page number 16385 in space 0 ... outside the tablespace
  bounds` during redo roll-forward: `ibdata1` is 4,864 pages and the redo log was asking for
  one about 268 MB in. **Nothing was deleted.** The whole data folder was copied to
  `C:\xampp\mysql\data-crashbackup-2026-09-16` first; `innodb_force_recovery=6` then started
  the server on port 3307 long enough to dump the project database to
  `data-crashbackup-2026-09-16\capstone-2026-09-16.sql` (12 products, 1 user, 1 cart); and
  after a clean shutdown the two redo logs were **moved**, not removed, into
  `data-crashbackup-2026-09-16\redo-logs-moved-aside\`. MariaDB then started normally with
  all 10 tables and all 12 products intact, and the dump was never needed. Both folders are
  still on disk and are the user's to delete. The cause was a dirty kill: a server started
  from a tool call dies with that call's process tree. Every server-touching command in this
  project now starts, works and runs `mysqladmin shutdown` inside **one** call.
* `php/admin/` is served by Apache like any other folder, so `php/admin/guard.php`
  is the only thing protecting it. Every file in there must require it on its first
  line. **A test now catches the forgetting, as of 2026-09-24**: `php/tools/csrf-http-test.php`
  ends with two static sweeps over the source — every file in `php/admin/` contains the
  `guard.php` require, and every file with a POST handler contains a token guard call. That is
  not the second layer this entry asks for, and it is not pretending to be: a convention that
  is checked is still a convention. But the failure mode it was written for — a new admin page
  added months from now without the require — is no longer silent. `php/admin/reports.php`,
  added by the user the same day, was checked by it on its first run.
* **`C:\xampp\mysql\bin\my.ini` was edited on 2026-09-04** — outside this project, but
  the project could not be tested without it. MariaDB 10.4 on Windows was aborting
  mid-test with `Failing assertion: slot` at `os0file.cc:6132` (native asynchronous I/O,
  exception `0x80000003`). `innodb_use_native_aio=0` was added under `[mysqld]`, with a
  dated comment above it saying why. The original is kept as
  `my.ini.before-aio-fix`; deleting the added lines undoes it. This was a decision made
  while working, not something the user asked for. The setting costs a little InnoDB
  throughput on Windows and nothing else.
* **Resolved 2026-09-22 — the shop is "Sky and LJ's Craft", singular.** The logo artwork read
  "SKY AND LJ'S CRAFT" while `SITE_NAME` rendered "Sky and LJ's Crafts"; the user's call was
  that the logo is right. `php/config/paths.php` is the one place the name lived, so it was one
  edit and the navbar, the footer and every page title followed. The tagline the artwork
  carries, "BRINGING BLOOMS TO OUR COMMUNITY", is now in the site's own words too — the user
  chose a footer for it, and `php/components/footer.php` is no longer a stub. Details under
  the Branding section.
* **Stale as of 2026-10-11 — `products.id 109` is not in the table any more.** It was: `products.id 109` is a hidden row named "Angelo" (₱20, a single flower), typed into the
  admin form by hand on 2026-09-03. Not a test row — no cleanup script matches it and
  none should. It is hidden, so the shop shows twelve products. Left alone deliberately;
  deleting someone's own row is their call.
* Outside this project, but it breaks MariaDB for everything on this XAMPP:
  `citizenlogindb.citizen_users` is corrupt and **opening it aborts the whole
  server**; `barangay_t_3_users_db.inquiry_history` has a corrupt InnoDB page 0.
  Both are covered by dumps in `C:\xampp\mysql\recovered-dumps`. Restoring them is
  the user's call.

## CURRENT TASK

**None. Every module is `[x]` as of 2026-10-13.**

Testing was the last one, and it closed when the three browser suites finally ran: **thirteen
suites, 2,525 checks, 0 failures** in one `run-all.ps1` call — ten PHP at 2,088 plus layout 395,
journey 34 and csrf-browser 8. The layout suite was then proved able to *fail*, and the screenshots
were opened, which found one fault in a fixture. The `### Testing` section below has the detail,
including why the first run died at sign-in and why that was a setting rather than a bug.

**So what is left is not a module.** Three kinds of thing, and they are different kinds:

**1. Open issues, which are real and are the user's to decide.** All four are under `## OPEN ISSUES`
with the evidence:

* **The admin account has no two-step verification.** `ADMIN_LOGIN_2FA_BYPASS_EMAIL` is set to
  `admin@capstone.test` and `php/login.php` honours it, so the one account that can do
  everything signs in on a password alone. It is now at least *throttled* — the 2026-10-14 rate
  limiting applies to every identifier including this one — but a throttle slows guessing, it does
  not add a second factor. Emptying that constant restores 2FA, and the constant is now also
  required to be non-empty for the bypass to fire at all. Doing so means the suites need
  `MAIL_TRANSPORT = 'log'` to read a code, which is the same decision as the next item.
* **No HTTPS** — the last of Security's original four, and the only one needing something other
  than code. `session.cookie_samesite` was set on 2026-10-11; **login rate limiting and session
  binding landed 2026-10-14**.
* **No version control.** `git` is not installed on this machine, so `git init` is blocked rather
  than pending, and `.gitignore` describes a repository that cannot be created. This is the thing
  that made a bulk-replace accident on 2026-10-08 recoverable only from an editor's cache, and for
  one of the three files not recoverable at all.
* **The Gmail app password now has two homes** — the file and the `settings` table.

**2. Features named as out of scope, not as unfinished.** These are lines the modules drew on
purpose, and each is recorded against the `[x]` that drew it: no CSV export, no period-over-period
comparison, a fixed seven-day dashboard window, a read-only customer list with no per-customer
drill-down, no customer-facing stock, no checkout shortfall warning, no catalogue-wide "what can we
build" list, and for Events no stacking, no per-customer codes and no custom-bouquet discounts.
Picking any of them up is new work, not finishing old work. The 2026-10-12 scan that confirmed each
one against the code is below.

**3. One thing that cannot be undone.** An anonymous guest basket was deleted on 2026-10-11 by a
cleanup scoped to "any ownerless cart" rather than to the cart it had just created. No signed-in
customer's basket was affected. It is on the record because the lesson is the one the 2026-09-29
entry already drew: *an inference about a row is not the same as a query about it.*

### Project-wide scan — every file, 2026-10-03

The user asked for a scan of every file against what this roadmap claims. No module was being
worked on, nothing was built, and the point was to find the places where the file and the disk had
drifted apart. **The stack was already running** — `mysqld` pid 20712 and two `httpd` from 11:39
that morning — so it was adopted read-only under the adopt-or-start rule and **nothing was started
and nothing was shut down**. The database was only ever read.

**What held up, checked rather than assumed.**

* **Syntax, everywhere.** `php -l` over all **70** PHP files, `node --check` over all **5** `.mjs`,
  both plain `.js` files, and a real `Parser::ParseFile` of `run-all.ps1`: **0 failures**. That is
  step 1 of the Testing module, done. **Corrected later the same day: the two plain `.js` files
  were checked in the wrong mode.** `node --check x.js` parses as a CommonJS script, and
  `js/customizer.js` is an ES module — so that part of this sweep could not have failed on a
  duplicate top-level `const`, which is precisely the error shipped into that file hours later.
  Re-run with the bytes copied to a `.mjs`, both files still pass. Detail in "The bud" below.
* **The live database is exactly what this file says it is.** 16 tables, and every count printed in
  Inventory's section matches to the row: **18** parts, **34** recipe rows, **16** moves, **13**
  products, **3** orders, **2** users, **3** carts, **1** cart item. **Zero `ZZ` rows** in any of the
  five tables the suites write to — no previous run stranded anything.
* **The stock books still balance.** `stock_on_hand = SUM(delta)` re-derived for every component:
  no discrepancies, which is `stockAuditDiscrepancies()`'s assertion made from outside the code that
  is supposed to maintain it.
* **The single-writer discipline is intact.** Exactly one statement in the entire project assigns
  `stock_on_hand`, at `php/config/inventory.php:359` inside `recordStockMove()`. Not one admin
  screen writes it directly.
* **Every file in `php/admin/` requires `guard.php`.** All ten, checked by reading them, not by
  trusting the static sweep inside `csrf-http-test.php` that has not run since the Testing files
  landed.
* **The 2026-10-01 CSS loss is fully recovered.** All 37 reported selectors are present, including
  the ones most easily missed on a partial restore — `.pill-danger`, `.pill-warn`, `.recipe-list`,
  `.recipe-item*`, `.ledger-in` / `.ledger-out`, `.dashboard-buy-panel`, `.dashboard-stock-*`,
  `.home-hero-band`, `.hero-*` and `.order-stock-*`. `main.css` is 137,917 bytes, **above** the
  136,065 of the last good version, which is the 2026-10-02 fix on top of it.
* **Both layout fixes are still in place**: `.admin-table-wrap { position: relative }` and
  `.order-main > .panel { min-width: 0 }`. `minmax(0, 1fr)` appears 31 times and bare-`1fr` grid
  columns have not crept back. **`.sr-only` appears zero times** and `.visually-hidden` four, which
  is the right way round.
* **`php/tools/.htaccess` really does say `Require all denied`**, so the tooling under the webroot
  is covered.
* **`layout-fixtures.php` was validated against the code it calls**, since it has never executed:
  signatures match for `recordStockMove()` and `saveProductParts()`, `'opening'` is a real key of
  `STOCK_REASONS` (the constant is a key⇒label map, so a membership test against its *values*
  wrongly says no — worth knowing before writing an assertion on it), `order_events.actor` is
  `ENUM('customer','shop')` as the fixture assumes, and `NOW() - INTERVAL :ago HOUR` binds fine with
  emulated prepares off. The riskiest-looking line in the file is not a bug.

**What had drifted, and is now corrected above.**

* **The CSRF headline numbers were two modules stale** — 29 fields / 16 files / 14 handlers, written
  2026-09-24 and true then; **33 / 19 / 16** today. Inventory's screens carry their tokens exactly as
  its own section promised, so this was a bookkeeping gap and not a coverage gap.
* **`git init` is blocked rather than pending**: there is no `git` on this machine.
* **Two root-level documentation files are missing** and no entry had ever named them. Both are now
  preserved in `%TEMP%` and recorded under OPEN ISSUES.
* **Two stale strings fixed** — the `oneww` typo in `php/config/designs.php` and the `js/app.js`
  citation in `php/tools/lib/browser.mjs`, which should be `js/script.js`.
* **Two files on disk are named nowhere in this roadmap**: `php/tools/mail-test.php` (the mailer
  harness from Authentication) and `sql/2fa-upgrade.sql` (the migration the 2FA work ran). Both are
  real and both work; they predate the habit of recording every file here, and they are noted now so
  a future sweep does not read them as orphans.
* **`php/tools/layout-fixtures.php` carries an mtime of 2026-10-03 11:51** — after the servers came
  up that morning, and with no local-history entry, so it was not an editor save. Its contents lint
  clean and read as complete. Flagged rather than explained, because an undated change to a file
  that has never run is worth one line in the record.
* **`run-all.ps1`'s own usage comment says `pwsh -File …`**, and `pwsh.exe` is not at the documented
  path on this machine. Invoke it as `& 'C:\xampp\htdocs\Capstone\php\tools\run-all.ps1'`.

**What this scan deliberately did not do: run anything.** Steps 2, 3 and 4 of the Testing module are
untouched. The suites write to the live database and `admin-http-test.php` renames `orders` out from
under a live request, and the user's own Apache and MariaDB had been up for twenty minutes when the
scan started — so firing ten suites at a stack that might be in front of someone is not a decision a
scan gets to make. **Testing stays `[~]`.** Nothing in this section is evidence about behaviour; it
is evidence about files.

### Login rate limiting and session binding — done 2026-10-14

Two of the three gaps Security's `[x]` named. The third, HTTPS, needs a certificate and a server
configuration rather than code and is still open.

**RATE LIMITING.** `php/login.php` would check a password as fast as it was asked to. Now
`php/config/login-guard.php` counts, and `sql/login-attempts-upgrade.sql` records.

* **Two limits, because either alone is the wrong one.** Five wrong tries at one identifier in
  fifteen minutes stops a dictionary run at a known address; twenty from one IP across any accounts
  stops **one password sprayed over every account**, which a per-identifier counter never sees.
* **An identifier matching nobody is throttled identically**, and that is the point rather than a
  side effect: if a real account throttled and an invented one did not, the form would answer "is
  this address registered here" differently for the two and the throttle itself would become an
  enumeration oracle. Everything keys on the typed string.
* **Nothing is ever locked.** There is no `locked_until` column anywhere and a wrong password never
  disables an account — that would hand anybody who knows an email address the power to lock its
  owner out, and the defence would become the attack. The window slides, so waiting is always
  enough and no administrator ever has to unlock anybody.
* **The check runs before the password is looked at**, not after. Checked afterwards it would still
  count, but it would also have done a row read and a bcrypt verify for every refused attempt — so
  the form would stay a usable timing oracle and a usable load generator.
* **A success clears that identifier's failures**, and it is cleared in the one place all three
  sign-in continuations pass through, not inside one of them — which is how the trusted-device path
  and the code path would otherwise come to disagree.
* **The refusal never says which limit was hit, how many tries remain, or whether the account
  exists.** The reason goes to the log.

**SESSION BINDING.** A signed-in session now carries a sha256 of the browser's `User-Agent`, written
beside `session_regenerate_id(true)` in `signInUser()` and checked by `ensureSession()` on every
request. A mismatch destroys the session outright — `$_SESSION` emptied, the cookie expired,
`session_destroy()` — rather than refusing one request, because a half-emptied session would leave a
basket and a flash queue belonging to an identity that has just been rejected.

* **The user agent and deliberately NOT the address.** Binding to `REMOTE_ADDR` is the stronger
  check and the wrong one here: a phone changes address moving between towers and Wi-Fi, so a
  customer filling a basket on the way home would be signed out repeatedly for no reason they could
  understand — and an attacker holding a stolen cookie is very often on the same network anyway. The
  security gained is small and the cost is a shop that logs people out.
* **A guest session is left alone** — nothing to protect, and a basket is not worth ending a session
  over. **A session signed in before this existed is adopted, not destroyed**, because signing
  everybody out to defend against a cookie stolen before the feature was written is the larger
  disruption for the smaller threat, and `signInUser()` already rotates the id so they age out.
* **No `X-Forwarded-For`.** There is no proxy in front of this install, so that header is entirely
  attacker-controlled: trusting it would let anybody defeat the per-address limit by varying it, and
  would *look* like a limit while doing nothing.
* A one-word hardening came with it: the admin 2FA bypass now also requires
  `ADMIN_LOGIN_2FA_BYPASS_EMAIL !== ''`, so emptying that constant really does restore 2FA rather
  than leaving an empty-string comparison in the condition.

**Tested: `php/tools/security-http-test.php`, 45 checks, 0 failures**, the eleventh PHP suite.

**The hazard the suite is built around is worth more than its assertions.** The throttle counts by
address, and every suite in this project signs in from 127.0.0.1 — so twenty deliberate failures
would trip the per-address ceiling and lock `admin@capstone.test` out of `admin-http`,
`inventory-http`, `events-http` and `settings-http` for the next quarter of an hour. **A test that
breaks four unrelated suites by passing.** So the per-identifier limit is driven by real HTTP
attempts (six, comfortably under twenty) while the per-address limit and the window expiry are
driven by **crafted rows** fed to `loginThrottleState()` — the only way to express "twenty failures"
or "sixteen minutes ago" without either breaking the neighbours or waiting a quarter of an hour. The
sweep deletes any attempt recorded against the two real accounts unconditionally, and the last thing
the file asserts is that the admin really can still sign in.

Three assertions carry the weight: **the correct password is also refused while throttled** (if the
right one got through, the limit would be worth nothing — the attacker's last guess is the one that
works); **a throttled attempt records nothing new** (otherwise each refusal would push the oldest
failure's expiry further out and the wait would never end); and **the same cookie from a different
browser is not signed in**, with the session destroyed rather than refused once.

**A missing `login_attempts` table FAILS OPEN**, deliberately and uncomfortably: the throttle allows
and logs loudly rather than refusing everybody. Failing closed would mean an un-run migration locks
every customer and every admin out of the shop with no way back in through the web — a self-inflicted
denial of service far more likely than the attack this defends against.

**Two things found while doing it, neither of them this feature's.**

1. **`settings` and `login_attempts` were missing from `sql/schema.sql`**, and both migrations
   *claimed in their own header* to be mirrored into it. Found by listing what `schema.sql` creates
   against what the migrations create — a check worth repeating after any new table, and one nothing
   had ever run. Both are in now.
2. **A fresh install has never been tested, and testing it needed a trick.** `schema.sql` opens with
   `CREATE DATABASE IF NOT EXISTS capstone` and `USE capstone;`, and `inventory-upgrade.sql` has
   `USE capstone;` too — so piping them at a scratch database writes to the **live** one instead and
   reports success. The first attempt did exactly that: six migrations "failed" against an empty
   scratch database while two silently wrote to `capstone`, and the result read as a schema fault
   when it was a fault in the test. Re-run with those lines stripped: **all nine files apply clean
   and the fresh database holds exactly the live twenty tables, identical.** The live row counts were
   checked against their baseline afterwards and were untouched — every migration here is
   re-runnable, which is why an accidental replay cost nothing.

**Verified.** `run-all.ps1`: **fourteen suites, 0 failed** — eleven PHP plus the module-parse gate
and the three browser suites — table counts identical before and after, every `ZZ` counter 0, and
`php/config/mail.php` restored with an identical SHA-256 after being flipped to `'log'` so the mail
assertions and the browser sign-ins could run.

**One gap in the runner found on 2026-10-15 and closed.** Its "anything ZZ left behind" counter did
not cover three tables the suites write to — `events`, `settings` and `login_attempts` — so it would
have reported a clean 0 while a suite stranded rows in any of them. That is this file's own lesson
from the day a leftover total missed `cart_items`: *a total that does not cover every table the
suites touch reports 0 and means nothing.* All three are counted now.
`zz_logins` reads **3** on a green run and that is correct rather than a leak: every successful
sign-in writes a `login_attempts` row by design — it is what clears an identifier's failures — so a
fixture that signs in legitimately leaves one, and the prune takes it once it ages past the window.
Only failures throttle anybody. The comment beside the query says so, because a non-zero counter
that is supposed to be non-zero is exactly the kind of thing a later reader will "fix".

### Testing — the suites finally run, done 2026-10-13

Steps 2, 3 and 4 of the plan under `## CURRENT TASK`. The three browser suites were written on
2026-10-02 and had **never executed once**; the module sat at `[~]` for eleven days under the rule
that nothing is marked complete until it has been tested.

**Thirteen suites, 2,525 checks, 0 failures**, green together in one call:

| | checks |
|---|---|
| ten PHP suites | **2,088** |
| `layout-test.mjs` | **395** |
| `journey-test.mjs` | **34** |
| `csrf-browser-test.mjs` | **8** |

Table counts identical before and after, every `ZZ` counter 0, both servers stopped as found.

**THE FIRST RUN FAILED, AND THE CAUSE WAS NOT A BUG.** All three browser suites died at the same
line — `signed in and off the sign-in pages` — while 295 of the layout suite's assertions passed
around it. The reason is `MAIL_TRANSPORT = 'smtp'`: a fixture customer needs an emailed code, and
on the smtp transport nothing is written to `capstone-mail.log` for `signIn()` to read. The admin
account signs in anyway because `ADMIN_LOGIN_2FA_BYPASS_EMAIL` skips its 2FA entirely, which is why
every admin-side suite had been passing and had hidden this. **Same root cause as the seven skipped
mail assertions in `orders-http-test.php`** — one setting, two symptoms, eleven days apart.

Run with the transport temporarily on `'log'`, everything passes, *and* `orders-http` reports
**334 passed, 0 skipped** instead of 327 and 7. `php/config/mail.php` was restored each time with an
identical SHA-256 and a copy kept in `%TEMP%`.

**Step 3: the layout suite was proved able to fail**, which the plan insisted on and which is the
difference between a suite and a decoration. `.order-main > .panel { min-width: 0 }` was removed
from `css/main.css` and the suite reported **2 failures** — "the document is no wider than the
window" and "admin-order: never slides, at any width" — with exit 1. Put back: 395 green, exit 0.
Both the stylesheet and the mail config were hash-verified on restore, and the search string was
matched **verbatim with a guard that aborts if it is not found**, which it did on the first attempt:
a PowerShell here-string carries LF while the file is CRLF, so nothing was touched and nothing was
lost. Worth noting that the suite names only the **admin** order page, not the customer's, even
though the one rule covers both — so the regression is caught, but by one screen rather than two.

**Step 4: the screenshots were opened, and that is what found the one real fault.** Fifty-two
captures, 26 screens at 1280 and 390. The customer's order page drew **"Placed" twice**.

It is a fault in a fixture I wrote, not in the site, and the distinction took reading
`orderTimeline()` to establish: it **always** synthesises its first dot from `orders.placed_at` and
then appends every `order_events` row after it — and `placeOrder()` never writes a `'placed'` event,
the only one it records being `'cancelled'`. So a real order has exactly one Placed dot.
`layout-fixtures.php` was inserting a `'placed'` row no real order ever has, and the duplicate it
produced read as a site defect in a photograph. **A fixture that does not look like production
reports bugs the shop does not have.** Fixed to one `'packed'` row, which still draws
Placed → Being made → Delivered and exercises the filled, "now" and unfilled states. Re-run: 395
green, and the timeline now reads the way a real packed order does.

That is three times on this project that measurement passed and a picture did not, and the first
time the picture accused something innocent.

**What is still not done, and why.** `git init` remains blocked — git is not installed on this
machine, which is a change to the user's machine and their call. `php/config/mail.php.example` was
written on 2026-10-11 and is the other half of step 5. The seven mail assertions stay skipped while
the transport is `'smtp'`, which is now a reported SKIP rather than a failure and is the right
behaviour: the suite asks the environment instead of dictating it.

### Printable records and editable mail settings — done 2026-10-11

Two things the user asked for in one go: a record on the Reports screen for a date they pick that
can be printed or saved as a PDF, and a way to change the email the shop's verification codes are
sent **from** without editing PHP.

**PRINTING. There was no `@media print` anywhere in `css/main.css`** — a Ctrl+P on any admin screen
put the navbar, the sidebar, the filter form and every button on paper and left the figures wherever
they fell. That is the gap Admin's own section names ("reports has no CSV or print export").

**No PDF library, and that is the design rather than a shortcut.** Nothing is installed here — no
composer, no vendor directory — so a server-side PDF would mean a dependency this project has so far
managed without. The browser already has a PDF writer: the print dialog's **Save as PDF**
destination. So the work is a stylesheet, and the stylesheet is the reason no library is needed.

* `css/main.css` — an `@media print` block: chrome hidden, the admin shell collapsed to one column,
  cards turned into ruled blocks, badges turned into outlined text because colour is exactly what a
  printer may not give you, `thead` set to `table-header-group` so a long table repeats its heading
  on page two, and `@page { margin: 14mm }`.
* **`.admin-table-wrap` becomes `overflow: visible` on paper.** A scroll box on a sheet of paper
  clips whatever does not fit and prints nothing to say it did — on a record of orders that is a
  silent omission, which is the one failure a printed record must not have.
* `php/admin/reports.php` — a `.print-only` heading carrying the shop's name, the period and a
  printed-at stamp. It exists because **everything identifying the report was unprintable**: the
  period lives in two `<input type="date">` boxes and a printed input prints its box, not its
  meaning, and the shop's name lives in the navbar that print styles correctly strip. A page of
  figures with no idea which shop or which dates is one somebody has to throw away — or worse, file.
* `js/script.js` — `setUpPrintButtons()`, matching the file's existing `data-` delegation. The button
  ships `hidden` and the script unhides it, so a browser with JavaScript off is never shown a dead
  control; `Ctrl+P` on the same page produces identical paper, because all the work is in the CSS.

**Verified by emulating print media, not by reasoning about it.**
`Emulation.setEmulatedMedia({ media: 'print' })` makes Chrome apply the rules on screen, so the paper
can be both asserted and photographed — without it a screenshot of that page is the screen styles
and proves nothing. On screen: heading hidden, chrome visible, button visible. As print: heading
**visible** reading "1 September 2026 – 11 October 2026", navbar, sidebar, filter form, page head and
the button itself all **hidden**, figures and table **visible**, `overflow-x: visible` with the table
no wider than its wrapper. Then the picture was looked at, which caught two things no measurement
reported: a grey wash behind every card (the shell wrappers carry the app's background and resetting
`html, body` alone does not reach them) and an "Open order queue" link printing inside a section
heading, where a link means nothing to somebody holding paper.

**MAIL SENDER. The three values that say who mail is from now come from a settings store**, so the
shop can change the sending Gmail without opening a PHP file.

* `sql/settings-upgrade.sql` — a `settings` key/value table, re-runnable, mirrored into
  `sql/schema.sql`. Key/value rather than named columns because the next setting the shop wants to
  own will not be about mail. `updated_by` is `ON DELETE SET NULL`, the receipt pattern: the record
  of a change outlives the account that made it.
* `php/config/settings.php` — `settingValue()`, `saveSetting()`, and `mailSenderAddress()`,
  `mailSenderPassword()`, `mailFromName()`. **Every setting has a default in PHP and the store is
  consulted first**, so an empty table — or a missing one — means the project behaves exactly as it
  did before the file existed. That is what makes the migration safe to apply to a running shop, and
  why a `PDOException` is swallowed: these are read on the sign-in path and a missing migration must
  cost the feature, not the ability to log in.
* `php/config/mailer.php` — seven direct `const` reads swapped for those accessors. **`MAIL_TRANSPORT`
  deliberately stays a plain const**: it decides whether verification codes reach anybody, and it is
  the one mail setting where a wrong value locks every customer out of their own account.
* `php/admin/settings.php` — the screen, reached from a new **Mail** entry in the sidebar. It reports
  which transport is live so the shop is never guessing, and offers a "forget the saved password"
  path back to the file, because the alternative is an admin who wants to undo a change having to
  know what the file says.

**THE PASSWORD IS NEVER RENDERED BACK**, and that is the assertion the suite exists for. A password
in a `value` attribute is a password in the page source, in the browser's autofill and in any
screenshot of the screen — and it looks identical to an empty field, so the failure is silent. The
field is always blank, leaving it blank keeps what is stored, and what is shown is *whether* one is
saved and when.

**The cost, stated rather than buried.** Until now that credential lived only in
`php/config/mail.php`, which `.gitignore` excludes — so it could not leave the machine in a commit.
Storing it in the database is what makes the feature work at all (a new Gmail needs its own app
password, so an address field without a password field is a setting nobody can use), and it means a
database dump now contains a live credential where before it did not. It is stored as given, not
hashed, because SMTP AUTH has to present the actual password — a hash would make the feature not
work rather than make it safer. `sql/settings-upgrade.sql` says all of this beside the schema, and
its own proof query deliberately selects `CHAR_LENGTH(value)` rather than `value`.

**Tested: `php/tools/settings-http-test.php`, 58 checks, 0 failures**, the tenth PHP suite.
Beyond the store's behaviour it pins: the accessors falling back to the file consts with nothing
stored and preferring the store with something; **the app password absent from the rendered page**
when one is saved; an **empty password field keeping the stored one** — the behaviour the whole
never-render-it-back design depends on, since a blank submission that wiped the password would make
every address edit silently break sending; a blank, malformed or newline-carrying address refused
with nothing changed; and a **missing `settings` table costing the feature and not the site**, proved
by renaming it out from under a live read and reversing that inline *and* in a
`register_shutdown_function`. **The sweep deletes rather than restoring a remembered value**, because
deleting is what makes the accessors fall back to the file — so a run that dies half way leaves the
shop on its own settings rather than on a test address, and there is no state to restore wrongly.

**A third invented API in one day, and the pattern is worth naming.** The screen's POST path called
`currentUserId()`, which **this project does not have** — `auth.php` offers `isUserLoggedIn()` and
`currentUserRole()` and nothing between them. The GET path never touched it, so the page rendered
perfectly and only the save fataled: nine assertions failed in a way that read like a broken form.
That is the same mistake as `.customizer-count` (a class matching nothing) and `escape($int)` (a
TypeError mid-render) earlier the same day — **inventing an API rather than grepping for it** — and
all three were caught by a test rather than by reading. The fix is the project's own one-line idiom
from `php/config/inventory.php:315`, not a new accessor.

**Verified.** `run-all.ps1 -Quick`: ten PHP suites plus the module-parse gate, **0 failed suites**,
table counts identical before and after, every `ZZ` counter 0.

### Phone ergonomics and the shop's front door — done 2026-10-11

The user asked whether the UI could be made better for the visitor. Rather than guess at polish, the
customer-facing pages were photographed at 390 and 1440 and **looked at**, with page heights and
tap-target sizes measured beside the pictures. That found one problem much bigger than any styling
question.

**TEST DATA WAS THE SHOP'S FRONT DOOR.** Not a UI fault at all, and the most damaging thing on the
site:

* `php/products.php` defaults to **Newest first**, so "Our flowers" opened with *"this is another
  test"* at ₱50 and *"change name test"* at ₱110 — one of them carrying an uploaded stock avatar.
* `php/customize.php` defaults to the **first bloom by `sort_order`**, and `product_components.id 18`
  *"test flower"* sat at `sort_order 0` — so the flagship feature's first impression was six
  olive-green blobs at ₱110 each.
* `php/index.php` escaped it only by luck: it sorts **price-high**, which skips the cheap test rows.

The user's call was to **withdraw** rather than delete, which is the reversible half and the right
one. Done through `setProductActive()` and `setComponentActive()` rather than raw SQL, so the write
went the way the rest of the project writes: products **21** and **257**, parts **18** and **138**.
Part 192 was already withdrawn. **Nothing was deleted and every row keeps its history** — part 138
is in a saved design and carries a ledger row, and both survive a withdrawal untouched.

**Both defaults then fixed themselves with no code change**: 12 products on sale, all seeded, and the
customizer now opens on *Blush Rose Head*. Worth keeping as a finding in its own right — the
instinct was to change the sort order and give the customizer a designated default bloom, and
neither was needed. The data was the bug.

*(Two consequences the user should know. Part 138 was the only component with `shape = 'bud'`, so the
sixth flower head added on 2026-10-03 is no longer offered in the picker; a part named properly —
"Rose Bud" — would bring it back. And `products.id 109` "Angelo", which an older OPEN ISSUES entry
says is a hidden row to leave alone, **is not in the table any more**; that entry is stale.)*

**The sticky total bar.** On a phone `customize.php` runs to about 3.5 screens and the order is
stage, total, pickers — so the price scrolls out of sight exactly when somebody starts changing it.
A bar now pins the running total and Add to basket to the bottom of the screen below 900px.

* **A real form, not a scripted click on another button**, so it works with JavaScript off like the
  two add forms already on the page. This page is a plain GET form by design and every POST on it is
  a plain submit.
* **`position: fixed`, not `sticky`.** Sticky only holds an element while its own containing block is
  in view, and the bar sits above the pickers in document order — so sticky would let it scroll away
  precisely when it is wanted.
* **`js/customizer.js` now writes every `[data-customizer-amount]`, not the first.** That is the
  change that makes the bar honest: a `querySelector()` would have updated the panel and left the bar
  showing the price the page opened with, and **a stale figure beside a live Add to basket button is
  worse than no figure at all.** The file already used `querySelectorAll` for
  `[data-customizer-add-design]`, so the precedent for two add controls was there.
* `.customizer { padding-bottom: 86px }` below 900px, so the last control is not left underneath the
  bar — which would be a worse problem than the one the bar solves. Asserted, not assumed.

**Touch targets, measured before and after.** The review found sixteen controls under 32px on the
customizer at 390px, worst of them a 13px count box. Now `.customizer-mix-qty` and
`.customizer-option` are 44px, and the bar's button 48px, with desktop untouched at 32px and 50px.
`font-size: 16px` on the count box as well, which is what stops iOS zooming the page when the field
takes focus.

**Two mistakes caught by insisting on verification rather than measurement alone.**

1. **The first draft of the touch rules targeted `.customizer-count input`, which matches nothing** —
   the real class is `.customizer-mix-qty`. Checked against the markup, not guessed, after the same
   class of error as writing `.sr-only` in a project whose class is `.visually-hidden`.
2. **The rules were gated on `@media (hover: none) and (max-width: 900px)` and silently did not
   apply.** That is the more precise question — *is this a touch device* rather than *is this window
   narrow* — but headless Chrome driven through `Emulation.setDeviceMetricsOverride` reports a 390px
   viewport while still answering `hover: hover`, so the count box measured **32px in the very test
   written to prove it measured 44**. The gate was removed. **A rule this project cannot test is a
   rule it does not know works**, and the cost of dropping it is that a narrow desktop window also
   gets finger-sized controls, which is harmless and arguably kinder.

**Verified.** The sticky bar was proved to *track* rather than merely exist: a count bumped from 6 to
9 moves **both** totals from ₱348.00 to ₱492.00 together, at 390px and at 1440px. The bar is visible
and pinned to `bottom: 0` at 390 and `display: none` at 1440, and the bottom Add to basket button is
not covered at either width. Four screenshots looked at. `run-all.ps1 -Quick`: nine PHP suites plus
the module-parse gate, **0 failed suites**, table counts identical, every `ZZ` counter 0.

**One thing to own: a pre-existing anonymous basket was deleted during this pass.** The review needed
a basket with something in it, and the cleanup afterwards was scoped to *any ownerless cart* rather
than to the cart it had just created — so it took one pre-existing guest line with it. Both remaining
carts have owners (the admin's, and `kennethbargayo213@gmail.com`'s cart 201), so no signed-in
customer's basket was touched, and nothing recoverable was lost beyond one anonymous line. It is
recorded because it is the same mistake the 2026-09-29 entry below draws its lesson from: *an
inference about a row is not the same as a query about it.* Scope a sweep to the id it created.

### Events and promos — done 2026-10-08

The shop runs different offers depending on the occasion, and the site had no way to express that:
a grep for discount, promo, voucher, coupon or sale across every `.php` and `.sql` file returned
**nothing**, and the only hit for "featured" was the home page's hard-coded
`fetchProducts(['sort' => 'price-high'], 4)`. Every price shown was `products.price`, flat.

Four decisions were the user's, asked before anything was written: **percentage off** (not a fixed
peso amount), **hand-picked products** (not the whole catalogue and not by kind), **a catalogue
product** as the featured flower (not a customizer bloom), and **a banner plus a price badge** (no
savings panel in the basket).

**WHERE THE DISCOUNT IS APPLIED IS THE WHOLE DESIGN, and it was chosen by reading Commerce rather
than by preference.** `php/config/cart.php:416` binds `(string) $product['price']` straight into
`cart_items.unit_price`, and `placeOrder()` copies that frozen figure into `order_items` without
re-reading anything. So:

* discounting at **checkout** would make the basket and the charge disagree — the exact silent
  substitution this project refuses everywhere else;
* discounting at **order level** would break `orders.total == SUM(order_items.line_total)`, which
  every report in `php/config/admin.php` assumes;
* discounting in the **products data layer** costs nothing, because `fetchProduct()` and
  `fetchProducts()` are the *only* customer-facing readers of `products.price` — and
  `addProductToCart()` calls the first of them.

So `decorateProductPricing()` is called in those two functions and nowhere else, and **not one line
of `cart.php`, `checkout.php`, `orders.php`, `orders-admin.php` or `admin.php` changed.** The
catalogue, the product page and the basket freeze all inherit the promo through code nobody touched.

**The consequence, stated rather than discovered: a price frozen during a promo stays frozen.** Add
a bouquet on 14 February at 20% off, check out in March, pay the February price. That is not a hole
to plug — it is the freeze rule working, and the alternative is re-pricing somebody's basket behind
their back. The suite proves it by ending the event and re-reading the column.

* `sql/events-upgrade.sql` — re-runnable, applied twice, both exits 0, mirrored into
  `sql/schema.sql`. **`events.featured_product_id` is nullable `ON DELETE SET NULL`** (the receipt
  pattern: withdrawing a bouquet must not delete the record of an event that featured it, nor be
  refused), while **`event_products` is `CASCADE` on both keys with no snapshot columns** (the
  recipe pattern: it answers "is this product in the promo *right now*", and a row naming a deleted
  product can only make a join hand back a discount for nothing). Nothing is lost by that cascade —
  what a customer actually paid is frozen in their basket and order lines. Two CHECKs, both proved
  by trying to violate them: `ends_on >= starts_on` and `discount_percent <= 90`.
* **`discount_percent` 0 is meaningful** and is not the same as unconfigured: an event that features
  a flower and discounts nothing is a real thing a shop does. 90 rather than 100 is the ceiling,
  because nobody types into a percentage field meaning to give stock away.
* **Money is integer centavos, never floats.** `eventPromoPrice()` goes to centavos, multiplies,
  rounds, and formats back — so the figure the catalogue prints, the figure the admin's preview
  arrow shows and the figure frozen into `cart_items.unit_price` are bit-identical rather than
  merely close. The preview on the edit form calls the same function the shop front does.
* **Overlap resolves to the event that started most recently**, ties by higher id, stated in
  `activeEvent()`'s docblock and asserted. Overlap is not worth refusing — a fiesta offer may well
  run across the end of a Valentine's one — but two live promos silently fighting over one price is.
* **`activeEvent()` swallows a missing table.** It is on the read path of the public catalogue and
  the home page, so an unguarded query would turn an un-run migration into a blank shop rather than
  a missing feature. `PDOException` only; a bug in the file is still loud.
* **The admin always sees list price.** Nothing in `events-admin.php` calls `fetchProduct()`; the
  picker and the promo checkboxes read `adminFetchProducts()`. Asserted on one product at once:
  `fetchProduct()` returns ₱30.40 while `adminFetchProduct()` returns ₱38.00.
* `php/admin/event-edit.php` saves the event row and its promo list in **one transaction**, because
  a 20% discount stored with nothing ticked would read as configured here and do nothing on the
  shop. That combination is refused outright on save, and the list screen draws a "No flowers
  picked" pill for any that already exist.

**What this does not do.** No discount on a **custom bouquet** — those price from
`product_components` through `designSnapshot()`, which this never touches. No savings panel in the
basket (the user's call). **No stacking**: one event applies at a time, by construction. No
per-customer codes, no free shipping, no buy-one-get-one. And **sorting stays on list price** —
`fetchProducts()` orders in SQL on `products.price`, so a discounted bouquet sorts by what it
normally costs. Re-sorting after decoration would be worse: this function returns one page, so a
PHP sort would reorder *within* the page and "cheapest first" would be a lie told twelve rows at a
time.

**Tested: `php/tools/events-http-test.php`, 132 checks, 0 failures**, registered in `run-all.ps1` as
the ninth PHP suite. Beyond the arithmetic and the freeze it pins: a product outside the promo
unaffected while one inside it is discounted; a finished event, a switched-off event and an
overlapping newer one all resolving correctly; a 0% event featuring without discounting; a withdrawn
featured flower disappearing from the banner rather than being advertised; `'2026-02-30'` and `'20%'`
both refused; `saveEventProducts()` idempotent and dropping a stale product id without a fatal; and
the `event_products` row going with a deleted product while the event stands.

**Three defects were written and caught during this task, and all three are worth keeping.**

1. **`setEventActive()` used `:active` twice in one statement** — `SET is_active = :active WHERE id
   = :id AND is_active <> :active`. With `ATTR_EMULATE_PREPARES` off PDO binds positionally, so a
   name used twice is one parameter short: `SQLSTATE[HY093]`, every single call. **This is the exact
   bug `php/config/inventory.php`'s ledger search shipped with**, and I wrote it in a function whose
   own docblock now cites that precedent. Two names bound to one value.
2. **`escape()` is `escape(string $value)` and `product-card.php` declares `strict_types`**, so
   passing the integer `promo_percent` is a **TypeError raised mid-render** — the response has
   already begun, so it arrives as a **200 with the body truncated** at the first discounted tile. A
   status-code check reports a healthy page. Three assertions failed and the status check passed,
   which is what pointed at it. `product.php` has no `strict_types` and coerced silently, so only
   the card broke — every `escape()` argument is cast now.
3. **The suite's own `signIn()` reported success for a jar that was never signed in.** It asked
   whether `php/dashboard.php`'s body contained "Sign in" — but a signed-out visitor is
   *redirected*, a 302 has an empty body, and `!str_contains('', 'Sign in')` is true. Eighteen later
   checks failed in ways that looked like broken admin pages. It now requires a **200 from a
   login-only page**. The field names were wrong too: `php/login.php` reads `form_type` and
   `identifier`, not `action` and `email`, and a wrong-named POST re-renders the form with a 200 —
   indistinguishable from a bad password unless the `Location` header is read.

**And the browser pass, because measurement has missed real faults here three times.** Six captures
at 1440 and 390 over the banner, the catalogue and the product page, all **looked at**: no sideways
slide (`window.scrollTo(500, 0)` leaves `scrollX` at 0 at both widths) and no element spilling its
own container. The banner collapses to one column under 900px, the date range prints as
"8 - 9 October" with the month said once, the "Ends tomorrow" pill fires only on the last three
days, and both prices fit one line on a 150px-wide phone card. `.product-card-media` gained
`position: relative` so the corner badge is clipped by its tile — without it an absolutely
positioned child of a static box is laid out against an ancestor *outside* it, which is precisely
how four admin screens scrolled sideways for days in September.

### The bud — a sixth flower head, done 2026-10-03

The user asked whether a 58 MB AI-generated `.glb` of a bouquet, dropped in a scratch folder, was
usable for the 3D customizer. It was not, and the reasons are worth keeping because they are about
this project's shape rather than about that file: it was **one fused mesh, one material, 986,734
vertices** from Tripo, so there was nothing to take apart — no way to render seven flowers instead
of twenty-four, swap a rose for a tulip, recolour a single bloom, or price any of it against a
`product_components` row. `GLTFLoader` is not vendored either. The customizer is compositional and
that model is a photograph. The folder was deleted at the user's instruction.

What the user wanted from it — a flower that reads as a real bud — was then built procedurally
instead, from the primitives already in use, and chosen from a rendered comparison rather than
from a description. **`bud` is the sixth value of `product_components.shape`.**

**The geometry, and the two wrong turns that found it.** A petal is a wedge cut from the **pole**
of a sphere, not a band around its equator. A band is a rectangle with hard straight ends, and a
few side by side tile into a smooth dome — the first render was a mushroom cap. A wedge taken from
`thetaStart = 0` converges to a *point* at the pole, so it is narrow at one end and broad and
cupped at the other, which is a petal. The second wrong turn was leaning the petals from their own
centres, which splayed them into a water lily; each petal now hangs from a **hinge placed at the
bud's apex**, so leaning swings the skirt outward while the point stays where the petals converge.
Twenty-four of them on a golden-angle spiral, each on a slightly larger sphere than the last.

**One detail carried more weight than the rest.** A wedge's hem is an arc at constant `theta`, so a
single shared `thetaLength` puts every petal's edge at exactly the same height and the head renders
with a flat, sliced-off bottom — a bell, not a flower. A per-petal `Math.sin(i * 1.7)` wobble fixes
it, and it is deterministic rather than `Math.random()` because the design lives in the query
string and a shared link has to redraw identically.

`BUD_OPENNESS` is `0.5`, picked by rendering 0, 0.5 and 1 side by side at two camera angles and
looking at them: shut reads as a bean, fully open reads as a parasol from below, and only the
half-open one reads as a rose from both. It is a constant and not a column — the shop picks a shape
per part, not a stage of flowering.

**The crown is built upside down and then turned over, and the first version shipped without the
turn.** Written the natural way — wedge point up, body sweeping down and out — the petals converge
at the TOP and the widest part of the head is its bottom hem. That is an inverted teardrop, and on
a stem it reads as a hanging bell; the user spotted it immediately in the bouquet. A real bud is
the other way round: narrow where it leaves the calyx, widening through the shoulder, tapering to
a point going up. The fix is one group and two lines — the whole crown is assembled point-up, then
`crown.rotation.x = Math.PI` with `crown.position.y = BUD_APEX + BUD_BASE`, which lands the
convergence just above the hip and sweeps every petal upward from it. Flipping one group is far
easier to reason about than negating a position, a lean and a stretch separately and hoping the
three agree; `BUD_APEX` is how far the convergence sits from the crown's own origin, so adding it
back is exactly what cancels the flip's offset. The hip and the sepals never moved — they were
always at the base and stayed there.

* `sql/component-shape-bud-upgrade.sql` — **appends** `'bud'` to the ENUM, and the comment explains
  why appending is not a style choice: MySQL stores an ENUM value as the *ordinal* of its position,
  so slipping `'bud'` in beside `'rose'` because they read as related would renumber four other
  shapes and every row holding one would come back as the shape before it. Nothing is backfilled —
  which parts are buds is the shop's decision. Applied twice, both runs exit 0, distribution
  unchanged. Mirrored into `sql/schema.sql`.
* `php/config/components.php` — one entry in `COMPONENT_SHAPES` and one label. That is the whole
  PHP change: `component-edit.php` renders its dropdown by iterating the constant and
  `validateComponentInput()` validates against it, so both followed without being touched.
* `js/customizer.js` — `petalWedge()`, `sheet()` (a double-sided `matte()`, because from inside a
  cupped petal you are looking at its back and a single-sided material draws that as a hole),
  `budHead()`, and one `case` in `bloomHead()`.
* **Geometry is per head and deliberately not cached across heads.** `disposeGroup()` hands every
  geometry and material in the bouquet back to the GPU on each rebuild, so a module-level cache
  would be serving destroyed buffers the second time the flower count changed. One material per
  head and one geometry per petal is the same bargain `petalled()` already strikes.

**One real bug was written and caught, and how it was caught is the point.** `GOLDEN_ANGLE` was
declared at the top of the new block — and it already existed further down, used by the stem
placement for exactly the same reason. A duplicate top-level `const` is a `SyntaxError` for the
**whole module**, so `js/customizer.js` never evaluated and **every** shape fell back to the
written summary, not just the new one. The page is built to survive that silently — two fallbacks,
one for no JavaScript and one for no WebGL — so it looked like a missing GPU on the test machine.

Three things failed to catch it, which is the part to keep:

1. **`node --check js/customizer.js` exits 0.** It parses as a CommonJS script. Copy the same bytes
   to `.mjs` and it fails correctly. Every JS syntax claim in this file made before 2026-10-03 was
   made in the wrong mode.
2. **`php/tools/shapes-unit.php` passed**, because its builder checks are `str_contains()` over the
   source. A missing `case` is visible that way; a file that does not parse is not.
3. **The standalone preview page rendered perfectly**, because it carried its own copy of the
   geometry and never loaded `customizer.js` at all.

What found it was asking the browser: an error collector installed via
`Page.addScriptToEvaluateOnNewDocument` **before** navigation, since by the time a page has loaded
it is far too late to attach `window.onerror` from outside. A control render with a *seeded rose*
pointed the wrong way first — it failed too, which read as "environmental, not mine" when it
actually meant "the whole module is down". **When a control also fails, that is evidence the fault
is shared and upstream, not evidence of innocence.**

**The detection gap is closed, and the fix is in two places on purpose.**

* **`php/tools/run-all.ps1` gained a real parse gate**, ahead of the PHP suites: each browser
  `.js` is copied to a `.mjs` in `%TEMP%` and handed to `node --check`, because **the extension is
  what picks the parser**. It reports as its own `js-parse` row in the summary and its failure
  count goes into the run's exit code, so a module that does not parse stops the run instead of
  being discovered by a browser. Measured both ways on the real fault: duplicate `const` as
  `.mjs` exits **1**, the identical bytes as `.js` exit **0**.
* **`php/tools/shapes-unit.php` gained the cheap half**, so running that suite alone still catches
  the one failure mode that actually happened: a scan for top-level `const`/`let` declarations
  starting in column 0, asserting no name appears twice, plus a named assertion on `GOLDEN_ANGLE`
  itself — because a general check that silently stopped matching anything would pass. It is a
  scan and not a parser and says so; a duplicate nested inside a function is legal and none of its
  business.

**Both were proved by putting the bug back.** With the duplicate `const` re-appended the suite
reports **2 failures** naming exactly those two checks; with it removed, **58 checks, 0 failures**,
and the file is back to one declaration. A check that has never failed is not evidence.

**Tested.** `php/tools/shapes-unit.php` is the eighth suite — **58 checks, 0 failures** — and it is
the first automated coverage `shape` has ever had; a grep for the vocabulary across all seven
existing suites returned nothing. Its best assertion is that the live ENUM and `COMPONENT_SHAPES`
are **the same list in the same order**, read out of `information_schema` rather than typed here, so
the drift `componentShape()`'s docblock already warns about is now noticed rather than merely
survived. It also pins that a wrap cannot be a bud however its row reads, that an unknown shape
draws nothing rather than quietly becoming a rose, and that a bud round-trips through
`createComponent()` and `updateComponent()`. Registered in `run-all.ps1`, which now runs eight PHP
suites. Then the real page: `php/customize.php` with six buds, in headless Chrome at 1440 — canvas
unhidden, WebGL up, breakdown and total correct at ₱270.00 — photographed beside a seeded-rose
control and **looked at**. The fixture was a `ZZTest` part created through `validateComponentInput()`
and `createComponent()`, swept afterwards; `product_components` back to 18, zero `ZZTest` rows.

**What this does not do.** The bud is a *shape*, so it costs 30 meshes a head where the rose costs
one — 24 of them is roughly 164,000 triangles and ~720 draw calls, against ~360 for a bouquet of
sunflowers, which is the existing worst case and the same family of number rather than a new one.
It has not been profiled on a weak machine. At bouquet scale the sepals read paler than in the
close-up, because the hemisphere light's ground colour lights the petals' back faces; that is
correct behaviour and may still not be the wanted look. And no seeded part is a bud — the shop has
to pick it on `php/admin/component-edit.php`, which is deliberate.

**One pre-existing fault was seen while photographing this and is NOT the bud's.** At a flower
count of **one**, the stage clips the head against the top of the canvas — and it does it to a
seeded `Blush Rose Head` exactly as much as to a bud, photographed side by side to be sure. The
3D Customizer section describes this being fixed ("a camera parked at one distance … slicing the
single rose flat across the top of the canvas"), and the camera does measure the bouquet now, so
whatever remains is narrower than the original fault — but at one stem the head is still against
the top edge. Recorded, not fixed: it is the customizer's framing, it predates this work, and
chasing it was not what this task was for.

**Mid-task, Apache and MariaDB both stopped** — zero processes, nothing on 3306, and no shutdown or
crash line in `mysql_error.log`, which reads as the XAMPP panel being stopped by hand rather than a
fault. Nothing was running, so starting was safe under the first of the three adopt-or-start rules;
the render was done and both were shut down again inside **one** call, leaving the machine as found.

### Inventory — Stage 2: an order consuming its parts, done 2026-10-02

The half Stage 1 deliberately left. Pressing **Start making** on `php/admin/order.php` now takes the
order's parts out of the drawer; **Not started after all** and an admin cancel put them back. Three
decisions taken before any of it was written, all of them the user's: stock comes out **when making
starts**, a shortfall **warns the shop and never blocks the customer**, and `placeOrder()` is not
touched.

**No migration.** Stage 1 built `stock_moves` already carrying `order_id`, the index
`idx_stock_moves_order`, and the `used` / `returned` reasons, and gave `recordStockMove()` an
`orderId` option and the ability to join a transaction somebody else had opened. Stage 2 is wiring, a
panel and a great deal of testing — not one line of DDL. The only `.sql` changes in this task are
comments that had become false.

**The customer path needed nothing, and that is a finding rather than a convenience.**
`cancelOrderForUser()` is pinned to `status = 'placed'`, so a customer can only ever cancel an order
that has consumed nothing. Checkout, `php/order.php` and `order-cancel.php` are untouched.

| Move | Stock |
|---|---|
| `placed → packed` (Start making) | **consume** |
| `packed → placed` (Not started after all) | **return** |
| `packed → delivered` | nothing — already out |
| admin cancel of a **packed** order | **return** |
| admin cancel of a **placed** order | nothing — nothing was taken |
| customer cancel | **nothing, ever** |

**Idempotency comes from the ledger, not from a flag.** "What is currently out" for one order is
`-SUM(delta)` over its `used` and `returned` rows, grouped by component — exactly what
`idx_stock_moves_order` was created for. Consuming moves `-(required - alreadyOut)` and only when
that is positive; returning moves `+alreadyOut` and reads no recipe at all. So pack → unpack → pack
nets to **one** consumption with no `stock_consumed` column, no guard and no second opinion about a
fact `stock_moves` already holds. A re-run is a no-op by construction rather than by a check.

**Returning reads the receipt, never the recipe**, which is the same distinction `product_parts` was
shaped around and the fourth time this module has landed on the right side of it. Change a recipe
from 3 blooms to 9 while the order is packed, then undo: **6 come back, not 18**. Reading the recipe
there would invent stock when a part had been added to it and destroy stock when one had been
removed. The suite proves this with the recipe edited in between, and 9 rather than 4 on purpose — a
wrong answer has to overshoot, because an undershoot could be mistaken for a rounding argument and
an overshoot puts the bloom at 13 against an opening of 10, a number no arithmetic of the drawer can
produce.

* `php/config/inventory.php` — `orderStockRequirement()` (the forecast *and* the consume input, with
  a `blind` list of lines that cannot be costed), `orderStockOut()`, `orderStockLostUnits()`,
  `orderStockLedger()`, `consumeOrderStock()`, `returnOrderStock()`, `stockReportSentence()`.
  **Neither write opens a transaction** — the caller owns it, which is the contract
  `recordStockMove()`'s docblock already stated. A refusal from one line is collected into `errors`
  and the rest continue, because *warn, never block* means the other eleven parts really are off the
  bench.
* `php/config/orders-admin.php` — `packOrderAndConsume()`, `unpackOrderAndReturn()`,
  `cancelOrderAndReturn()`, each owning one transaction so the status change and the stock commit or
  fail together. `markOrderPacked()`, `markOrderUnpacked()` and `cancelOrderAsAdmin()` keep their
  signatures and their bodies; the rule stays in the `WHERE`. `moveOrderStatus()` and
  `cancelOrderAsAdmin()` gained the three-line join-don't-nest pattern
  (`$ours = !$database->inTransaction()`), which was **landed and run on its own** against 1,221
  existing assertions before a line of stock wiring was written.
* `cancelOrderAndReturn()` takes two parameters where the plan gave it three. The `bool $wasPacked`
  it was to be handed is a status column's opinion about something the ledger already knows, and the
  ledger is right in the pack → unpack → pack → cancel case where the status read is not.
* `php/admin/order.php` — **"What it takes out of stock"**, one panel with two modes chosen by
  whether anything is out yet. Before: each material, what the order needs, what is on hand, and a
  **Short by 2** pill — the warning arriving *before* the button is pressed, which is strictly more
  use than after. After: the `used` / `returned` rows with the balance frozen beside each and a link
  to the ledger filtered to this order. Underneath, in both modes, a line per order line that
  consumes nothing and why — *a pack that quietly took nothing out is the one outcome worse than a
  shortfall.*
* `php/admin/stock-moves.php` — an `?order=N` filter, the chip that clears it, and a hidden input so
  pressing **Apply** does not silently widen the view back to every order. The order's reference is
  derived from rows already on the page rather than from a new query into `orders`, which keeps the
  module edge pointing one way.

**Tested: `php/tools/inventory-http-test.php`, 345 checks (236 before), green with the other six —
1,840 checks, 0 failures**, every table count identical before and after. The new section proves the
two `used` rows carry the order, the admin who pressed it and the frozen part names in recipe order;
that pressing twice writes nothing the second time; that cancelling a *placed* order and delivering a
*packed* one move no stock at all; that a product with no recipe is named out loud rather than passed
over; that a material deleted after it was consumed leaves its movement standing and reports the
units as unrecoverable; and that **two admins pressing Start making at the same instant** — two
accounts, two jars, `curl_multi` — pack the order once and take the parts out once.
`stockAuditDiscrepancies()` is asserted empty after every phase, because a Stage 2 that can leave the
balances disagreeing with the ledger has broken the thing Stage 1 was written to protect.

Then a browser pass over the panel at 1440 and 390, pictures looked at and not only measured — which
is what found the layout fault below.

**One layout fault, found and fixed here (OPEN ISSUES, 2026-10-02).** `.order-main` is a grid that
carries `min-width: 0` for itself; its children did not, and a grid item's automatic minimum size is
its min-content. The track was therefore sized to the widest panel's min-content and every panel
overflowed the container that had been told it could shrink — at 390px the page measured 916 and
genuinely scrolled sideways. The overflow report named the timeline and its timestamps, all at the
same right edge, which is what *victims* look like; hiding one panel at a time found the cause in a
single pass. `.order-main > .panel { min-width: 0 }` fixes it for the admin page and for the
customer's `php/order.php`, which shares the wrapper and had the same latent floor in "What to make".

**What Stage 2 still does not do.** Checkout says nothing about a shortfall — warning there was
considered and the decision was that it must never block, so the warning lives where the shop can act
on it. The customer-facing site still shows no stock. There is still no catalogue-wide "what can we
build right now" list. And **a design line consumes its frozen `order_item_parts`, not a recipe** —
correct, but it means a custom bouquet whose materials were all deleted reports as blind rather than
as costed.

### Inventory — Stage 1: stock, recipes and the ledger, done 2026-09-27

The one structural gap left in the project. Three tables already recorded what a **custom bouquet**
is made of — `saved_design_blooms`, `cart_item_parts`, `order_item_parts` — so the shop knew exactly
what the customizer consumed and nothing at all about what a **catalogue product** consumed: a
product had a `stem_count` and no parts list. And there was no stock count anywhere, on purpose,
because `sql/schema.sql` had said since Products that answering "is this in stock" with a boolean on
`products` would be "a lie the moment real stock exists". This is the half that answers both.

Three decisions were the user's, and each one shows up in the schema rather than only in the code:

* **Stock lives on the parts, and comes out when making starts.** Not on finished bouquets — the shop
  makes to order, so a "12 in stock" on a bouquet would be a guess about parts it has not counted.
  The count therefore always means one thing: **what is physically in the drawer.**
* **A shortfall warns the shop and never blocks the customer.** `placeOrder()` is not touched.
* **Stage 1 only.** An order consuming its parts automatically is Stage 2, and the module ended this
  task at `[~]`. **Stage 2 landed on 2026-10-02 — see the section above it.**

**The shortfall decision is why `stock_on_hand` is `INT` and not `INT UNSIGNED`.** Stock has to be
able to go negative — "we used four more than we had" is a true statement a shop needs to be able to
make — and an UNSIGNED column would answer it with SQLSTATE 22003 instead of with the number. The
schema is also built so **Stage 2 needs no migration**: `stock_moves` already carries `order_id` and
the `used` / `returned` reasons, written by nothing yet. *(It needed none. Stage 2 wired both up on
2026-10-02 and touched no DDL at all.)*

* `sql/inventory-upgrade.sql` — re-runnable, like every migration here, and mirrored into
  `sql/schema.sql` so a fresh install matches a migrated one. Two columns on `product_components`
  (`stock_on_hand`, `low_stock_threshold`, where 0 means never warn) rather than a 1:1 side table,
  because a part *is* a material and the join would buy nothing.
* **`product_parts` is a recipe, and it deliberately breaks the pattern of its three siblings.**
  They are **receipts** — nullable `component_id`, `ON DELETE SET NULL`, frozen `name` and
  `each_price` — so an order stays readable after a part is deleted. This one is a **live instruction
  read fresh every time**, and a recipe still naming a part the shop no longer has is a recipe nobody
  can follow. So: both keys `NOT NULL`, both FKs `ON DELETE CASCADE`, no snapshots, and
  `UNIQUE (product_id, component_id)` — which is what makes "set the whole recipe in one POST"
  idempotent. The table comment says all of this, because the next person to read it will have just
  read one of the receipts.
* **`stock_moves` is history, so the snapshot pattern is right again** for exactly the reason it was
  wrong above: `component_id` is nullable `ON DELETE SET NULL` beside a frozen `component_name`, so
  deleting a part leaves its movements legible instead of erasing the evidence of what was done with
  it. `delta` is signed and is the whole point of the table; `balance_after` is stored so the ledger
  page needs no window function and reads the same forever.
* **A stored balance *and* a full ledger, both rather than either.** `SUM(delta)` on every catalogue
  page is a cost nobody should pay, and a balance with no trail is a number nobody can argue with.
  `recordStockMove()` writes both in one transaction and `stockAuditDiscrepancies()` re-derives the
  sums to prove they still agree — which is a feature and a test at the same time, and the audit
  banner on the ledger screen is that function with a colour.
* `sql/inventory-seed.sql` — the twelve seeded products' real recipes and an opening stock-take,
  idempotent through the same `LEFT JOIN ... WHERE c.id IS NULL` pattern the other two seeds use.
  The opening balances and their matching `opening` ledger rows come from **one** `VALUES` list, so
  they cannot disagree with each other, and the audit check proves they do not.
* `php/config/inventory.php` — one file, not the `inventory.php` / `inventory-admin.php` pair the
  other modules use, because that split exists to stop a customer-facing read seeing a withdrawn row
  and **nothing here is customer-facing at all**. Same reason `orders-admin.php` stands alone.
  `recordStockMove()` is the **only writer of `stock_on_hand` anywhere**, the single-writer
  discipline `setComponentImage()` already had for `image_path`: one transaction,
  `SELECT ... FOR UPDATE` on the component row, compute, `UPDATE`, then the ledger row carrying
  `balance_after`. The `FOR UPDATE` is what makes two admins receiving stock at the same instant add
  up instead of one overwriting the other — asserted with `curl_multi`, not assumed.
* `buildableCount()` is `MIN(FLOOR(stock_on_hand / quantity))` across the recipe, and **an empty
  recipe returns `null`, meaning unknown, never 0.** A product nobody has written a recipe for is not
  a product the shop cannot build; it is a product the shop has not been asked about yet, and
  printing "0 can be built" would be the screen inventing bad news.
* **Ledger ordering is by `id`, not by `created_at`** — the same call `order_events` made for the
  same reason. A stock-take writes several rows inside one transaction sharing a `TIMESTAMP` to the
  second, so sorting by time would shuffle them.
* **The data-loss path this task created is closed.** `product_parts` cascades from
  `product_components`, so `deleteComponent()` would now silently gut every recipe using that part.
  `php/admin/component-edit.php`'s delete path gained a `productsUsingComponent()` warning that names
  them. That is not polish — without it, deleting one part quietly changes what several bouquets are
  made of.

**The three screens.** All three copy `php/admin/components.php`'s filter/sort/search/paging shape, so
all six admin lists behave identically, and all three redirect after a POST so a refresh cannot
re-submit.

* `php/admin/inventory.php` — **stock.** One row per part: name, kind, on hand, the low-stock
  threshold as an editable box, what it is used in, and a row form with three actions — Receive *n*,
  Set to *n*, Waste *n* — plus an optional note. "Set to" is a stock-take and still leaves a movement
  behind, through `setComponentStock()` computing the delta and calling `recordStockMove()` with
  `adjusted`: the ledger has no gaps in it even when somebody overrides a count by hand.
* `php/admin/stock-moves.php` — **transactions.** The ledger, newest first, with `balance_after` in
  its own column, and filters for part, reason and order. Its real job is the banner: if
  `stockAuditDiscrepancies()` returns anything, the screen says so loudly and names the parts.
* `php/admin/product-parts.php?id=N` — **the recipe.** One number box per component, grouped by kind,
  empty meaning *not in this bouquet*; one form, one POST, the whole recipe at once. **No
  add-a-row JavaScript**, which keeps the project's rule that every POST in it is a plain form
  submit. It shows `buildableCount()` and which part is the binding constraint, and it flags a
  disagreement between `products.stem_count` and the number of blooms in the recipe — the recipe is
  the truth, but silently disagreeing with the catalogue copy is how a shop ships the wrong bouquet.
* **Only three of the six reasons are buttons.** `received`, `adjusted` and `wasted` are things a
  person does; `opening`, `used` and `returned` are machine-written, and offering a human a "used"
  button would let the ledger say an order consumed parts when no order did.
* Links: one entry in `$adminLinks`, a "What's it made of" link on the product list rows and the
  edit page, and a stock link on the components rows. Every new form carries `csrfField()`; every new
  handler opens with `requireCsrfToken()`.

**Verified.** `php -l` clean on every touched file. `php/tools/inventory-http-test.php` is the seventh
suite — **214 checks, 0 failures** on the day, **236 since 2026-09-29**, when the dashboard panel that
finally reads `adminInventorySummary()` brought 22 assertions of its own — and the other six were
re-run to prove nothing broke:
`orders-unit` (725), `orders-http` (334), `csrf-http` (162), `cart-http` (133), `admin-http` (78),
`cart-unit` (63). **1,709 checks green together on 2026-09-27, 1,731 on 2026-09-29**, with the counts
of eleven
tables printed before and after and **identical**: 18 parts, 16 moves, 34 recipe rows, 804 units,
13 products, 3 orders, 2 users. Zero `ZZ` rows left anywhere. What the suite proves, beyond the
per-screen guard and CSRF checks: **the books balance** — `stock_on_hand == SUM(delta)` for every
touched part, each row's `balance_after` equal to the running total, and `stockAuditDiscrepancies()`
empty; **two admins receiving at once add up**, over `curl_multi` with two separately signed-in jars,
because one jar cannot race itself (PHP holds the session file lock for a whole request); negative
stock is allowed, recorded and shown as a shortfall rather than refused; the recipe POST is
idempotent and emptying a box removes the row; `buildableCount()` against hand-computed values for a
short part, an empty recipe and an exact multiple; and deleting a `ZZTest` product cascades its recipe
away while leaving the ledger untouched.

**One production defect was found and fixed, and the test that found it was the problem too.** The
ledger's search box threw `SQLSTATE[HY093]: Invalid parameter number` on **every** search:
`adminMoveFilterSql()` wrote `:search` twice, once for the frozen part name and once for the note.
With `ATTR_EMULATE_PREPARES => false` PDO hands placeholders to the server positionally, so a name
used twice is one parameter short every time. Fixed with `:search_name` / `:search_note` bound to the
same value, which is exactly what `adminCustomerFilterSql()` at `php/config/admin.php:56` already
does — the precedent was in the project and had not been followed. **The part worth keeping is why
the suite very nearly missed it.** `stock-moves.php` wraps its whole load in one `try`/`catch
(PDOException)` and renders an empty state, so a query that throws comes back as a **rendered page
with no rows in it** — which is indistinguishable from "nothing matched" if all you count is rows.
The first version of the search section counted rows and reported a bland `expected: 1, actual: 0`.
Every filter assertion in that suite now also asserts the failure banner is **absent**, a search with
no matches is asserted to have *run* as well as found nothing, and a lone `%` is checked to match
literally rather than to match everything. A row-count assertion cannot tell a crashed query from an
empty result set, and on this project every admin screen is built to make those two look the same.

**A second defect was in a suite rather than in the shop, and it stranded rows.** The full run left
`carts` at 4 and `cart_items` at 1. `csrf-http-test.php`'s first refusals are aimed at a **signed-out
visitor** — that is who fills a basket — so the cart it creates has `user_id NULL` and no owner to
find it by, and its item could not be found by product either, because `cart_items.product_id` is
`ON DELETE SET NULL` and deleting the test product had already blanked it. The frozen name was the
only handle left, which is the receipt pattern working exactly as designed. The scrub now deletes the
guest carts **first** so the cascade takes their items, then mops up by name, scoped to
`user_id IS NULL` so a real visitor's basket is never in range — and the suite's leftover total gained
a `cart_items` term, because a total that does not cover every table the suite writes to reports 0 and
means nothing. Verified: `carts` 4 → 3, `cart_items` 1 → 0.

**The browser pass found the layout fault recorded under OPEN ISSUES**, which turned out not to belong
to this module at all: the same one-line CSS cause had been live on `components.php` and `products.php`
since they shipped. Five admin screens now measure and *scroll* correctly at 390px. The three new
screens were also photographed at 1440×900 and 390×844 and **looked at** — plain viewport captures,
never `captureBeyondViewport`, which grows the viewport to the document height before shooting and so
photographs a state no visitor sees.

**What Stage 1 does not do**, named rather than implied. **An order does not consume stock** — placing,
packing and delivering an order change no count, so the numbers on these screens only move when a
person moves them. That is Stage 2 and it is the reason for the `[~]`. Nothing warns the shop at
checkout about a shortfall, because warning is Stage 2's job too and the decision was that it must
never block. The customer-facing site shows no stock at all: `products.is_active` is still publication
and not availability, and a bouquet whose parts have run out is still on sale. And `buildableCount()`
is read per product on demand rather than stored, so there is no "what can we build right now" list
across the whole catalogue.

*(**Stage 2 closed the first of those on 2026-10-02**: packing an order now consumes it and the
shortfall is shown on the order page before the button is pressed. The other three stand as written —
checkout still says nothing, the customer-facing site still shows no stock, and there is still no
catalogue-wide buildable list.)*

*(`adminInventorySummary()` had no call site when Stage 1 closed either. It has one as of 2026-09-29 —
the dashboard's "What to buy" and "In stock" panels — which was the smallest of the five issues this
section's report listed and the only one that needed code.)*

### Admin — dashboard, customers, reports, analytics, done 2026-09-26

The user built this module with Copilot on 2026-09-24 and asked for it to be reviewed. The review
found five defects and two things that were not defects but wasted work; the user then asked for the
defects fixed, **with the not-a-defect items done first**, which is the order below.

**Not defects, done first.** The dashboard was running about thirty-four queries per load and putting
roughly seven of them on the page. Eight variables were computed and never rendered — each appearing
exactly twice in the file, once to initialise and once to assign — three of the six `try` blocks
existed only to guard them, and one of those still raised "Unable to count the customizer parts" about
a number nobody could see. Worse, `adminReportsSummary()` was being called unfiltered, eleven queries,
to borrow two of its eleven results, and `SELECT COUNT(*) FROM users` ran three times per load from
three different functions. The two borrowed lists are now `adminTopProducts()` and
`adminRecentOrders()`, which both screens call, so there is one definition of each query and the
dashboard no longer loads the reports page to read two of its answers. The result is **9 queries, all
rendered**: `adminDashboardStats()` (7) plus `adminCountCustomers()` twice, behind two `try` blocks
instead of six. `config/products.php` is now required explicitly for `formatPrice()` alone — the three
admin config files the page used to pull in have no call sites left on it, and dropping them without
checking would have taken `formatPrice()` with them.

`adminInventorySummary()` is the interesting half. Nine queries, a complete working return value, and
no call site that read it. **The call is deleted and the function kept**, with a docblock that says so
and dates it: it works, Inventory is the module that wants it, and deleting it would only mean writing
it again. Building a panel for it here would have been scope creep into a module that has not started.
*(It got its panels on 2026-09-29, three days later, once Inventory had given the numbers something to
be about. The bet paid: the function was read as written, and no part of it had to be rewritten.)*

**The second not-a-defect turned out to matter more than the five defects.** "Top products" filtered
`oi.kind = 'product'` and joined `products`, which quietly dropped two whole classes of sale. A custom
bouquet has no `product_id` to join on, so **every sale the customizer made — the shop's headline
feature — was missing from a ranking titled "Top products"**. And `order_items.product_id` is
`ON DELETE SET NULL`, so withdrawing a product erased its entire sales history from the report too. It
now groups on the line's own frozen snapshot name, which is the thing that survives both:

```sql
CASE WHEN oi.kind = 'design' THEN 'Custom bouquet' ELSE oi.name END
```

Repeated in `SELECT` and `GROUP BY` rather than aliased — MariaDB allows grouping on an alias, but the
alias `name` collides with a real column on the table and it calls that ambiguous. The cost is that
two different products sharing a name merge into one row, which is a fair trade for a by-name ranking
and much cheaper than silently under-reporting. Grouping on the raw `oi.name` would not have worked
either: `cartDesignName()` builds names like "Custom bouquet: 6 flowers, domed", so every combination
of flower count and arrangement would have been its own row.

**One rule now governs every number in `php/config/admin.php`**, stated in its module docblock,
because two screens quietly disagreeing about what "revenue" means is worse than either answer alone:
**a count of orders includes cancelled ones, and a sum of money excludes them.** The order was still
taken, so it belongs in "how much work came in"; the money was never collected, so it does not belong
in "how much the shop made". That was defect (d) — the dashboard summed `total` with no status filter
while `reports.php` used `status <> 'cancelled'` and said so on its card, and `customers.php`'s
"Lifetime spent" had no filter either. They agreed only because there were **zero cancelled orders on
the books**; the first cancellation would have made two screens disagree in public. Every `SUM()` in
the file now carries the filter and no `COUNT()` does, and the two screens that could still mislead
say "excludes cancelled" in the markup.

**The chart was the largest single fix.** Its legend promised Revenue / Orders / Accounts and the code
drew one number three times: bar one at the revenue percentage, bar two at `revenue - 10`, bar three at
`revenue - 20`. On any day with sales that is a 100/90/80 staircase standing behind a legend making
three different claims — it invited the reader to conclude orders track revenue exactly, because it was
drawn that way. The judgment call was whether to delete the two fabricated series or compute them;
computing won, because orders-per-day and accounts-per-day are one query each and keeping three series
preserves the design the user built while making it true. Each series is normalised against **its own**
peak, since three orders and three thousand pesos cannot share an axis — which makes a bar's height
meaningless on its own, so the peak travels with the series, the legend prints it, and every bar carries
its real value in a `title` and a `.visually-hidden` span. Without that, a ₱1,360 week and a ₱136,000
week draw exactly the same picture. The panel is titled "Last 7 days", not "Revenue trend": it is three
measurements, not one trend. The seven dates are computed in PHP rather than with `CURDATE()`, so the
cards, the bars and the labels cannot disagree about where the week starts if PHP and MariaDB are set
to different timezones.

The other three defects were small. **(a)** The "Customers" KPI called `adminCountCustomers()` with no
role filter — every row in `users` — on a card whose icon links to the customer list, which broke the
same total down as "All accounts / Customers / Admins". The admin count was already being fetched on
the next line and thrown away; it is the card's subtitle now, so both numbers are shown and the two
screens agree. **(c)** `adminDashboardStats()` was the one data call on the page with no `try`/`catch`
while five siblings each had their own, on a file that spent two comment blocks explaining that a
partially-migrated database must still render its working cards. With `ERRMODE_EXCEPTION` that made a
missing `orders` table a fatal — a blank page instead of the cards that worked. The fallback is
`adminEmptyDashboardStats()`, a fully-formed zero shape, not `[]`: the template indexes
`donut_stats[0]` and `graph_series[n]['bars'][3]` directly, so a bare `[]` would only trade the fatal
for an undefined index. **(e)** The customers search form had one field, `q`, and no hidden `role`, so
searching from the Admins tab silently returned you to All accounts. One hidden input, emitted only
when a tab is active. Deliberately no `page` field: a new search belongs on page one.

**Verified.** `php -l` clean on every touched file. `php/tools/admin-http-test.php` is new —
**78 checks**, 0 failures — and the five existing suites were re-run to prove nothing broke:
`orders-unit` (725), `orders-http` (334), `csrf-http` (162), `cart-unit` (63), `cart-http` (133).
**1,495 checks, 0 failures**, all six green together. The new suite creates a ZZTest admin, buyer and
newbie, two ZZTest products and four orders spread across different days at different values, and
asserts: weekly revenue rises by ₱1,850 while weekly orders rise by 4, because one of the four is a
cancelled order of ₱9,999 deliberately large enough that a leak into any sum could not be missed; the
three series are not a staircase; "Custom bouquet" appears in the ranking; a product deleted mid-run
keeps its 3 units and its order line's `product_id` goes null; the dashboard KPI equals
`adminCountCustomers(['role' => 'customer'])` and not the unfiltered total; the search form carries
`role, q`; and eight admin URLs answer 200 with nothing PHP is unhappy about, `display_errors` on.
Every money and order assertion is on the **delta** rather than the absolute, so the suite does not
care what else is in the database.

Two of the checks needed arranging rather than observing. The Accounts series is proved by an account
created on a day with **no sales at all**: its bar is 50% where the old drawing would have computed
`max(0, 0 - 20)` = 0, which is the one fixture that cannot pass by coincidence. And defect (c) is
proved by **renaming `orders` out from under a live request** — 200, no fatal, the toast naming
`sql\orders-upgrade.sql`, and the customer cards still on the page. That was the piece expected to be
untestable: `DB_NAME` is a hard `const` and `getDatabaseConnection()` memoises in a function-local
static, so there is no seam to point the suite at a throwaway database, and editing either would have
meant Apache serving a broken config to the user. The rename works because MariaDB rewrites a child
table's FK definition to follow its parent; it is reversed inline **and** in a
`register_shutdown_function`, because a shutdown function runs even on a fatal and a half-renamed
schema is not something to leave behind, and the suite then re-asserts that `order_items.order_id`
still references `orders`. The fallback's shape is asserted separately against every key and index the
template dereferences, so if a future MariaDB refuses the rename the suite degrades to a shape check
and says so rather than going quiet.

Two mistakes worth recording, both caught by the run rather than by reading. The first version wrote
`<span class="sr-only">` in the chart markup; this project's class is `.visually-hidden`
(`css/main.css:1823`) and `sr-only` does not exist, so the reading would have rendered as visible text
under every bar. The second stranded fixtures: the cleanup block guessed `cart_item_parts.cart_item_id`
where the column is `item_id`, and because `ERRMODE_EXCEPTION` throws, the fifth of eleven deletes
took the rest of the block down with it and left three users and two products in the database.
**Cleanup now runs each statement independently and reports failures instead of throwing**, and it runs
at both ends — the suite clears anything a previous run stranded *before* it reads its baseline, not
after. The two `cart_item_parts` and `order_item_parts` deletes turned out to be unnecessary anyway:
both cascade from their item tables.

**What this module's `[x]` does not mean** is listed against the marker itself: the customer list is
read-only, reports has no export and no period-over-period comparison, the seven-day window is fixed,
and the inventory numbers still have no panel. None of that is a defect — it is the line between this
module and the two that are left.

### Security — validation, authorization, CSRF, uploads, done 2026-09-24

Uploads landed with Products on 2026-09-04 and the rest of this module was one item that had been
the recommendation since 2026-09-16 and had been deferred twice, the second time knowingly. This is
that item, plus the two pieces of debt that were deferrable until they were the only things left.

**No decision was asked of the user, and one was made anyway: the token is minted once per session,
not once per form.** A per-render token is the stricter-sounding option and it is the wrong one
here. It breaks the back button, it breaks two tabs, and it breaks the customizer page specifically
— that page stays open while a bouquet is built and posts at the end of it. One token per session
is what the threat actually needs, because the whole basis of the attack is that an attacking page
**cannot read the response** to a cross-site request, so it cannot learn the token no matter how
long the token lives.

* `php/config/csrf.php` — five functions. `csrfToken()` mints `bin2hex(random_bytes(32))` on first
  use and keeps it in the session; `csrfField()` returns the escaped hidden input ready to echo;
  `csrfTokenIsValid()` compares with `hash_equals()` rather than `===`; and there are **two**
  refusals, because this project already had two kinds of endpoint. `requireCsrfToken()` prints a
  plain 403 modelled exactly on `requireAdmin()` — no header, no navbar, no stylesheet, no database
  touched, but a viewport meta and the favicon — then exits. `requireCsrfTokenOrRedirect()` is for
  the four endpoints that never render anything (`cart-update.php`, `design-save.php`,
  `order-cancel.php`, `logout.php`): they answer every other refusal with `flashToast()` plus
  `redirectTo()`, so they answer this one the same way and the visitor lands back where they were
  reading "That form had expired, so nothing was changed. Try again."
* **`csrf.php` requires `auth.php` and `flash.php`, and nothing requires `csrf.php` back.** Putting
  the require into `auth.php` would have saved 20 lines and made the load order
  auth → csrf → flash → auth, with functions undefined mid-load — the exact cycle `signInUser()`'s
  own docblock already warns about. Each page requires it explicitly instead, and one line in
  `php/admin/guard.php` covers all eight admin files.
* **`signInUser()` drops the token**, one line beside `session_regenerate_id(true)`, for the reason
  the file already gives for the id: a token captured before the session had privileges is worth
  nothing once it has them. The cost is honest and small — a guest with the basket open in a second
  tab gets one "that form had expired" after signing in elsewhere, and the retry works.
* **29 forms across 16 files** gained `<?= csrfField() ?>` as the first child, beside the hidden
  fields already there — `cart.php` (3), `profile.php` (6), `verify.php` (2), `designs.php` (3),
  `login.php` (2), the four admin screens, `checkout.php`, `product.php`, `customize.php`,
  `order.php`, and `components/navbar.php`, whose logout form is on every page. **14 handlers**
  gained one guard line as their first statement.
* **The one place the guard is deliberately not first.** `php/admin/product-edit.php:132` and
  `component-edit.php`'s twin branch catch a POST larger than `post_max_size`, which arrives with
  `$_POST` and `$_FILES` both empty — so a token check ahead of that branch would answer a 60 MB
  photo with "your session expired" instead of "that file was too large". The check goes **inside
  the `elseif`, after the discard branch**. This was found by reading the handlers before editing
  them, not by a test afterwards.
* `php/tools/csrf-http-test.php` — **162 checks**, its own harness like the other two suites. Per
  handler: a POST with no token, a POST with a wrong token, then a POST with a token scraped from
  the form's own page — and for each refusal, **the thing it would have changed is checked to be
  unchanged**, because a 403 that still writes is the failure worth catching. Then a sweep that
  fetches every form-bearing page as the right kind of visitor and checks the token is present
  *inside* a form, which the static count cannot see, and the two source sweeps described under
  OPEN ISSUES. This suite is also the first HTTP coverage `login`, `verify`, `profile`, admin
  `products` and admin `components` have ever had — threading a token through them blind would have
  been a change nobody watched run.
* **The two existing suites got one edit each, not sixty.** `request()` now adds the token itself
  when a POST does not already carry one, memoised per jar by `PHPSESSID` and scraped from a cheap
  GET. Every existing call site and every existing assertion is untouched; the deliberate omissions
  live in the new suite, which does not share that harness.
* `sql/kind-check-upgrade.sql` — the CHECK constraint both item tables could be left violating,
  inverted to `kind = 'product' OR product_id IS NULL` in the migration and in all three
  `CREATE TABLE` bodies. Details under OPEN ISSUES; the short version is that the new predicate is
  strictly stronger where it matters and cannot be broken by a referential action.
* **The race branch that could not be tested is now tested, by stopping being sequential.** Two
  `pack` POSTs for one order fired at the same instant with `curl_multi`. **The trap that makes the
  obvious version silently useless:** PHP holds an exclusive lock on the session file for the life
  of a request, so two requests sharing one `PHPSESSID` cannot overlap — the second just waits. It
  needs **two different signed-in admin browsers**, each with its own jar. Asserted: the status is
  `packed`, there is **exactly one** `packed` row in `order_events`, and exactly one success toast.
  The suite prints which of the two known refusals the loser got rather than demanding a particular
  one, because a race that has to interleave to pass is a test that fails on a fast morning. It did
  interleave, twice, and the losing press reported "is already being made".

**Verified before anything was marked.** All five suites green in one run — `csrf-http` 162,
`orders-http` 334, `orders-unit` 725, `cart-http` 133, `cart-unit` 63: **1,417 checks, 0 failures**,
every one exit code 0. `php -l` clean on every touched file. Then the two passes no suite can make,
because 29 forms changed and a missing token is invisible until somebody presses the button:

* **A real browser walked the whole happy path** — headless Chrome over CDP, no npm packages. Signed
  in through the **real 2FA step** with the code read out of the mail log, added to the basket,
  checked out, signed out, signed back in as the shop, then pay → pack → deliver. Every form was
  checked for a token before it was pressed, so a failure would name the form that lost it. 23
  browser checks and 7 database checks, 0 failures. Order `SLJ-20260924-CLD2`: delivered, paid, one
  line, basket emptied by checkout, timeline `packed shop` then `delivered shop`, exactly one
  `delivered` event.
* **An attacker page was actually pointed at the shop**, and made harder than planned. It is served
  over HTTP on port 8099 rather than opened from `file://`, so it is same-site to `localhost:80`,
  the cookies really were sent, and the token really was the only thing that refused it. The victim
  added to their basket legitimately first; the attacker page then autosubmitted a tokenless
  `action=empty` to `cart-update.php`. Refused, redirected to `cart.php`, "That form had expired, so
  nothing was changed. Try again.", and the bouquet was still in the basket.

Every row any of this created was a row it created, prefixed `ZZTest`, cleaned up by id above a
high-water mark taken first, with the counts printed before and after: 0 leftover test products, 0
leftover test users, 0 constraint violations in either item table, and 13 real products and 2 real
users still there. The user's own XAMPP was found already running and was left running.

### Orders — management, tracking, notifications, done 2026-09-22

Commerce stopped at payment on purpose and said so in three places. This module is those three
places answered. Three decisions were the user's: **tracking means a dated status history, not a
courier number** — a shop that delivers its own work has no courier to link to, and the field says
nothing at all for a pickup order; **four moves email the customer** — being made, delivered,
cancelled by the shop, payment marked paid; and **the undo goes back one step and never from
delivered.**

* `sql/orders-tracking-upgrade.sql` — one table, `CREATE TABLE IF NOT EXISTS` like every other
  migration here. `order_events` is one row per move: the `status` reached, the `actor`
  (`'customer'` or `'shop'`), the account that did it, and when. **A history table rather than two
  more columns on `orders`**, because "when did this go to Being made" is a one-to-many question
  and a `packed_at` column would answer it once and then be overwritten by an undo.
  `actor_user_id` is `ON DELETE SET NULL` — the history has to survive a deleted account, which is
  the whole point of keeping one — while the order's own `user_id` stays `ON DELETE RESTRICT`.
  `ON DELETE CASCADE` from `orders`, because an event without its order is unreadable.
* **Ordered by `id`, not by `happened_at`.** Two moves in the same second are ordinary — the
  screenshot pass produced four inside one — and `TIMESTAMP` has no sub-second precision here,
  so sorting by time would put an undo before the step it undid.
* **The "Placed" dot is derived from `orders.placed_at` and is never a row.** No backfill, no
  migration for the orders that already existed, and nothing added to `placeOrder()` — the
  riskiest transaction in the project was not reopened for a dot that cannot disagree with the
  column it is read from. Only moves *after* placement are written.
* **Payment draws no dot, deliberately.** `php/order.php` already argues that the order state and
  the payment state are two clocks that do not run together, and draws them as two pills for that
  reason; folding payment into one ladder would contradict that on the same page. Marking a
  payment paid still sends a message, because a notification is not a state.
* `php/config/orders.php` — `recordOrderEvent()` is a single
  `INSERT ... SELECT id FROM orders WHERE reference = :reference`: no read-then-write, no id
  juggling at the call site, and each named placeholder appears exactly once, which is not
  optional with `ATTR_EMULATE_PREPARES => false`. `orderTimeline()` builds a list rather than
  filling a fixed ladder — the derived first dot, then every event in order, then the canonical
  steps not yet reached as hollow ones, and **no hollow dots at all once an order is delivered or
  cancelled**, because nothing further is coming. An undo appears as its own dot reading "Moved
  back to Placed", decided by comparing the event's place in `ORDER_STATUS_SEQUENCE` against the
  one before it. Deleting the superseded row would be a history table that edits history.
* `php/config/orders-admin.php` — `markOrderPacked()`, `markOrderUnpacked()` and
  `markOrderDelivered()`, each built exactly like `markOrderPaid()`: **the rule lives in the
  `UPDATE`'s `WHERE` and never in a prior read**, and success is `rowCount() === 1`. PDO's MySQL
  driver reports rows *changed* rather than matched, so a refusal and a no-op are the same answer
  unless the condition is in the statement that writes. Each wraps its `UPDATE` and its event in
  one transaction, with the `inTransaction()` guard `placeOrder()` established — an order that
  moved but has no dot in its history is worse than one that did not move.
* **`deliver` is reachable from `packed` only**, not straight from `placed`. Both presses match
  real moments in making something by hand, and it puts the one irreversible step behind a
  reversible one. **Nothing moves a delivered order** — not `unpack`, not `cancel` — which is the
  rule `cancelOrderAsAdmin()` already set before this module existed.
* **All notifying happens in `applyAdminOrderAction()`**, never inside the `markOrder*()`
  functions. Both admin screens funnel through it, so the queue and the order page cannot
  disagree about who was told, and each write keeps doing exactly one thing. `pay` and `cancel`
  are Commerce's writes and Orders' messages, and that is where the two meet.
* **A failed message is reported, not swallowed.** The move committed, so the toast stays a
  success — but it says the customer could not be emailed. Silently swallowing it leaves the shop
  believing a customer knows something they do not.
* `php/config/orders-mail.php` — subject and body per event through a `match`, copied from
  `php/config/twofactor.php:116` and for the reason recorded there: a message titled for the wrong
  event is both wrong and the kind of wrong that makes a real message look like a phishing
  attempt. `php/config/paths.php` gained `SITE_URL` and `siteUrl()`, because mail is the one thing
  here with no request to derive a host from — `url()` returns `/Capstone/php/order.php`, which is
  not clickable in an inbox.
* **The undo is on the single-order page only.** The queue row already carries up to four
  controls; the undo should be a deliberate act taken while looking at the order. Both admin
  screens name no action of their own — `applyAdminOrderAction()` decides which exist — so a
  hand-written `unpack` posted to the queue is still answered correctly rather than falling
  through to "that action is not something this page can do".
* `php/admin/orders.php` — `Start` and `Delivered` join the existing row-action form as an
  `elseif`, never both, which is what keeps the cell at the same three lines it already had at
  1280px. Measured after the change: the row is 133px and the four controls sit on three lines,
  the same as before, and the nine-column table still needs no scrollbar at 1280.
* `php/admin/order.php` — the paragraph that told the shop "moving this to Being made or Delivered
  is not switched on yet" is gone, replaced by the buttons in the order somebody works in: Start
  making, Delivered, the payment pair, the undo, then Cancel last. `Delivered` and the undo carry
  `data-confirm`. At most four can ever appear at once (packed and paid), which was recounted case
  by case after a first comment here claimed five.
* **Only the admin's timeline names the actor.** "Cancelled by the customer" and "cancelled by us"
  are the same word in the status column and are not the same conversation, and the shop is the
  side that needs to tell them apart. This was found by writing a comment that claimed the dots
  named who moved them before they did; the claim was made true rather than deleted, and four unit
  assertions now hold it.
* `css/main.css` — one flat `.order-timeline` block in the established palette. The spine is
  `::before` and the dot is `::after` and that order matters: `::after` paints over `::before`, so
  the other way round draws a line straight through every hollow dot. The spine is full-height and
  hidden on the last step, so it joins dots and never dangles.

**Verified.** `php -l` clean on every touched file. `php/tools/orders-unit.php` **725 assertions**,
0 failures — the timeline against hand-built event lists, including a cancellation mid-run, an undo
and a delivered order, plus the four subjects and `siteUrl()`. `php/tools/orders-http-test.php`
**310 assertions**, 0 failures, over Apache with real cookies: an order walked
`placed → packed → delivered` from both screens, one history row per move with the right actor,
every refusal by SQL rather than by markup (deliver from `placed`, pack twice, pack a cancelled
order, unpack a delivered one, cancel a delivered one, any action against a reference that does not
exist), a customer refused all three actions, and the customer's own cancel writing
`actor = 'customer'`. **Mail is asserted from the bytes appended to `capstone-mail.log` after the
run started and nothing from it is ever printed** — that file also holds live two-factor codes from
other runs. The tracked order sent exactly packed, packed and delivered; the shop's cancellation
sent one; the customer's own cancellation sent none; a refused action sent none; and the body
carries a link a mail client can resolve. Regression: cart unit 63, cart HTTP 133, both unchanged.
**That 310 is the number as of 2026-09-22 and it has since moved to 334** — Security added the
concurrent double-submit described in its own section, and replaced three assertions that were
reading labels the user's admin redesign had removed. The ones listed above are all still there.

**Six Chrome shots over CDP at 1280×800 and 390×844, reviewed rather than only measured**, against
a `ZZShot` order walked pay → pack → unpack → pack so the timeline had a done dot, an undo dot, a
current dot and a still-to-come dot at once. The timeline reads as a timeline and not a bullet list
at both widths; the five dots measure 12px with a 2px spine and no list marker; the admin aside is
19rem and holds all four buttons stacked one per line at 266px each; the queue row is unchanged in
height. `php/tools/screenshot.mjs` gained a `SHOT_COOKIES` environment variable to do this at all —
every page in this module is behind a sign-in, and a fresh Chrome profile is a stranger, so the
shots were of the login page until the browser could join a session established through the real
form. It is an environment variable and not an argument because a session id is a capability.

**What was not exercised live.** The stale-page race the plan asked for is **not reachable from a
sequential suite**, and the suite says so where it would have been rather than claiming otherwise:
`applyAdminOrderAction()` re-reads the order at the start of every request, so moving the row in SQL
and then posting produces the *named* refusal, not the "changed while you were looking at it" one.
Reaching that branch needs a write landing between the read and the `UPDATE` inside one request.
What is asserted instead is the observable behaviour — the page offered the button, the row moved
underneath it, the second press changed nothing and wrote no second row — which is safe to leave
there because the condition is in the `WHERE` either way. The `PDOException` → 503 paths are covered
by `php -l` and by eye, the same gap accepted for the same reason in Commerce and the customizer.
And **no message has ever left this machine**: `MAIL_TRANSPORT` is `'log'`, so "the customer was
emailed" means a message was written to a file, and every result above is a log-mode result.

**One thing the shop may want to revisit, recorded rather than decided.** The customer's timeline
shows "Moved back to Placed". That follows from a history that never edits itself, and it is honest,
but it does show a customer that the shop started their order and then un-started it. Hiding it
would mean the customer's page and the admin's page telling different stories about the same order,
which is worse; dropping the undo entirely is the other option. Both are the user's call.

Cleanup: the screenshot fixture's product, two accounts, order, lines, parts, cart, trusted devices
and both session files are deleted, along with the throwaway script and the six PNGs. Afterwards
`orders` is back at 2 rows and `order_events` at 0 — the history went with the order on its own,
through `fk_order_events_order`, which is the cascade doing what it was written for. No `ZZTest` or
`ZZShot` row is left anywhere. The two seeded orders now show a `Start` button, which is the only
visible change to anything that existed before today.

### Commerce — the basket, done 2026-09-16

The first half of Commerce: a basket a visitor can fill without an account, which follows them
into one when they sign in. Before this, three buttons in three finished modules all said
ordering was not switched on.

* `sql/cart-upgrade.sql` — three tables. `carts` is one row per basket, owned **either** by a
  `user_id` **or** by a `token_hash`, both UNIQUE: an account cannot hold two baskets and a
  cookie cannot be shared between two. The cookie is stored as a **sha256 of the token, never
  the token**, for the same reason `trusted_devices` does it — a leaked database should not
  hand over live baskets. `cart_items` holds the line, `cart_item_parts` holds what a designed
  bouquet is made of, one row per part, `ON DELETE CASCADE` from the line.
* **Every line freezes its own name and price.** `unit_price DECIMAL(10,2) UNSIGNED` on the
  line and `each_price` on each part, written at the moment of adding. A basket that re-read
  `products` would re-price itself whenever an admin edited a bouquet — the customer would see
  one number, agree to it, and be charged another. The product id is still stored, so the line
  can still link to the page, but nothing about the money depends on that row surviving.
* `php/config/cart.php` — the whole data layer, read and write in one file because a basket
  has no admin side to separate from. `CART_QUANTITY_MIN`/`MAX` are 1 and 99,
  `CART_LINES_MAX` is 50, `CART_COOKIE_DAYS` is 30. The caps are enforced in PHP, not left to
  the column: MariaDB's `@@sql_mode` on this XAMPP has **no `STRICT_TRANS_TABLES`**, so a
  `SMALLINT UNSIGNED` handed 70000 is silently clamped rather than refused, and a silent clamp
  is how a basket ends up holding a quantity nobody typed.
* **`findCartId()` only ever reads.** Looking at a basket, or loading any page at all — the
  navbar counts on every one of them — never creates a row and never sets a cookie. Only
  adding something calls `openCartId()`. A visitor who browses and leaves is not given a
  database row and not given a cookie, which is both the polite behaviour and the one that
  keeps the table free of empty baskets.
* **`mergeGuestCart()` is called from `signInUser()`**, the single place in the project where
  a session gains privileges, so it fires on all three sign-in paths — `login.php:143`,
  `verify.php:101` and `profile.php:365` — including the trusted-device shortcut that skips
  the emailed code. With no basket of their own, the guest row is simply re-parented
  (`user_id` set, `token_hash` nulled). With one, the lines are moved with a single `UPDATE
  cart_items SET cart_id` and the empty guest row is dropped — the lines carry their own
  prices and the parts hang off them by `item_id`, so moving the parent moves everything and
  there is nothing to copy. Either way the cookie is cleared, so the basket has exactly one
  owner afterwards. A `PDOException` in there is logged, not raised: signing in matters more
  than the basket, and the cookie surviving means the lines are still findable next time.
* `php/cart.php` — the page, read-only, like `php/designs.php`. Every button on it posts to
  `php/cart-update.php`, which owns all six writes (`add-product`, `add-design`, `add-saved`,
  `update`, `remove`, `empty`) and answers each one with a redirect, so a refresh cannot
  re-add anything. `cartSafeDestination()` decides where that redirect lands and refuses
  anything off-site, so `?back=` cannot be turned into an open redirect.
* **The three disabled buttons are live.** `php/product.php` gained a quantity box and a real
  Add to basket, `php/customize.php` posts the design in the query string it already had, and
  `php/designs.php` can order a saved bouquet — which `cart-update.php` reads back with
  `readSavedDesign()`, so a design that has lost a part is refused rather than sold as a
  different bouquet.
* **A withdrawn or deleted product leaves the line standing.** `cartLineIsOrderable()` returns
  false for it, the name stops being a link, the page says "No longer on sale. It is not
  included in the total." on the line and once at the top, and `cartTotal()` skips it. The
  customer can still see what they had picked and remove it themselves. Nothing is deleted
  out from under anybody.
* `php/components/navbar.php` and `css/components.css` — **a cart icon with a red count
  badge**, replacing the word "Basket". The anchor sits **outside `.nav-con2`** and is ordered
  back into place with CSS, because inside that container it was collapsed into the hamburger
  below 720px — and a badge you have to open a menu to see is not a badge, it is a second
  page. The icon is an inline SVG so it takes `currentColor` and needs no image file; the word
  "Basket" is still in the markup as `.visually-hidden`, because a link whose only content is
  an `aria-hidden` drawing has no accessible name at all. The badge is printed only when there
  is something to count — a red zero is a warning about nothing — and anything past 99 shows
  as `99+`, since `cartCount()` sums quantities and 50 lines of 99 would stretch the circle
  into a lozenge.

**How it was tested** — 172 assertions, all passing: 63 unit (`php/tools/cart-unit.php`) on
the arithmetic and the string work, and 109 over Apache and the database
(`php/tools/cart-http-test.php`) on everything a cookie and a session are needed for. Both
were re-run after the navbar and CSS changes. Notable coverage: a guest basket survives across
requests on the cookie alone; signing in merges it both ways (own basket absent, own basket
present), leaves exactly one row afterwards, and the browser **drops the guest cookie** —
without that the next request would look up a `token_hash` that is now NULL, be handed a
second empty basket, and the account's basket would appear to empty itself at sign-in;
quantity
`0`, `-1`, `100`, `abc`, an array and 500 digits all clamp or refuse; the 50-line cap holds;
`update` and `remove` on another basket's `item_id` change nothing and say so; `?back=` at
`https://evil.test`, `//evil.test/login` and `/\evil.test` all redirect in-site; a product
**created by the test** was withdrawn
(`is_active = 0`) and then deleted outright, and the line survived both with the total
correct; and `empty` clears the lines without deleting the basket. No expected total is
hardcoded — each is computed in the test from the prices it wrote.

Real Chrome over the DevTools Protocol at 1280×800 and 390×844, **screenshots reviewed rather
than only measured**, and that is what found the one real defect in this half: the badge was
invisible on a phone, because the basket link was still inside the collapsing menu. Fixed by
moving the anchor out of it. The re-shot images confirm the icon and its red count sit between
the site name and the hamburger at 390px, and to the right of Home / Products / Create / Sign
in at 1280px, with the basket page itself correct at both widths — 4 items in 3 lines, the
amber withdrawn-line warning, four colour swatches on the designed line, and a total of
₱1,505.00 that correctly excludes the ₱520.00 line that is no longer on sale.

`php/tools/cart-unit.php` and `php/tools/cart-http-test.php` are **kept**, unlike every earlier
suite, because the second half of this module extends these exact tables and the roadmap has
already recorded once that deleting a suite made the next change more expensive. Both were
given the `PHP_SAPI !== 'cli'` guard the two tools beside them already had — they sit under the
webroot, and the HTTP suite signs up an account and deletes rows. Verified over HTTP: both
answer 404 with an empty body.

Cleanup: the screenshot seed, the CDP driver, the probe, `php/tools/shots/` and six temp
scratch files are deleted; no `ZZTest%` or `ZZShot%` row survives in `products`, `users`,
`cart_items`, `saved_designs` or `trusted_devices`; `products` is back to 13 rows (12 offered
plus the hand-typed hidden "Angelo") and `product_components` to 17. One basket is left on
purpose: **the admin account holds a Single Calla Lily at ₱110.00.** It is a seeded product at
its seeded price on the user's own account, which is the same reason "Angelo" is left alone —
one press of Empty basket clears it if it was not deliberate.

### Commerce — the basket's hover panel and two-column page, done 2026-09-17

Three things the user asked for on top of the finished basket: hovering the cart icon shows
what is in it, clicking it still goes to the basket, and the basket page puts the lines on
the left with the total and the buttons on the right.

* **One read now answers the badge, the panel and the total.** `cartSummary()` in
  `php/config/cart.php:769` returns `count`, `lines`, `total` and `gone` from a single query;
  `cartCount()` is one line on top of it. The alternative — a count query for the badge and a
  lines query for the panel — is two reads on every page of the site that could disagree with
  each other about the same basket. They cannot now, because there is only one answer.
* **`fetchCartLines()` lost its N+1 on the way.** It joins `LEFT JOIN products p ON p.id =
  i.product_id AND p.is_active = 1` and selects `(p.id IS NOT NULL) AS product_live`. The join
  reads no column off `products` — that would re-price the line, which is exactly what this
  module froze prices to prevent. It asks one question only: is that product still on sale.
  A fifty-line basket was fifty `fetchProduct()` calls before this.
* **The panel is a child of the anchor, and that is the whole design.** No JavaScript: hovering
  any part of the panel is still hovering the link, so it holds itself open, and clicking
  anywhere in it follows the link to `php/cart.php` — which is the behaviour the user asked to
  keep, so it is asserted end to end rather than assumed. Nothing inside it is a button or a
  link, because an anchor may not contain interactive content; the "View basket" strip at the
  bottom is a styled `<span>` saying out loud what clicking already does.
* **A gap the pointer cannot fall through.** The panel hangs 18px below the navbar's edge so it
  reads as hanging from the bar rather than from the icon, and that offset is a strip where the
  pointer is over neither. `.nav-cart-panel::before` fills it with a transparent part of the
  panel, height matched to the offset. Without it the panel closes on the way down.
* **`@media (hover: hover)` guards the whole interaction**, with `:focus-visible` deliberately
  outside the query — a keyboard gets the panel on every device, and it is the only way to see
  it without a pointer. On a touch screen `:hover` latches after a tap, and that tap has
  already followed the link, so the panel would flash over the page the visitor just asked to
  leave.
* **Four lines, then "and N more".** `CART_PREVIEW_LINES` is 4. The panel hangs off a corner of
  a fixed navbar and a basket may hold fifty lines; it is a glance, not the basket. A withdrawn
  line is shown struck through and greyed with the same wording the page uses, and the panel's
  total excludes it exactly as `cartTotal()` does.
* **`php/cart.php` is a two-column grid.** `.cart-layout` is `minmax(0, 1fr) 19rem`, the list
  left and `.cart-foot` right, and the foot is `position: sticky; top: 86px` so the total and
  Checkout stay in view while a long basket scrolls. The item count and the withdrawn-lines
  warning stay full width above both columns: they are about the basket rather than about
  either column, and the warning has to be read before the total it explains. A new
  `max-width: 900px` breakpoint drops it back to one column and the foot back to `static`.

**How it was tested** — 191 assertions, all passing: 63 unit (`php/tools/cart-unit.php`,
unchanged) and 128 over Apache and the database (`php/tools/cart-http-test.php`, up from 109).
The 19 new ones read the panel out of the rendered navbar with three helpers and check: an
empty basket says "Nothing in it yet.", lists nothing, prints no total, appears on
`php/products.php` too, and **still mints no cookie** — the navbar reads the basket on every
page, so a panel that created a row would undo `findCartId()`'s only-ever-reads guarantee;
the panel's names and its total match the page's, line for line and peso for peso; a withdrawn
line is marked `nav-cart-panel-gone` exactly once and the panel total still equals the page
total; at 50 lines the panel lists `CART_PREVIEW_LINES` and says "and 46 more lines"; the
anchor still reads `<a href="/Capstone/php/cart.php" … class="…nav-cart">`; and the page has
one two-column wrapper with the list before the foot and Checkout inside the foot.

Real Chrome over the DevTools Protocol, screenshots reviewed rather than only measured. At
1280×800: `listRight 856 ≤ footLeft 876`, both columns starting at the same y, the foot 304px
wide and computed `sticky`, no sideways scroll. The panel is `display: none` at rest; on hover
it opens at left 972 / right 1260 / top 72 / bottom 329 — on screen, clear of the navbar —
lists all four names in full, says "and 1 more line", and shows ₱2,474.00 against a badge of
6. It stays open when the pointer crosses the gap, **clicking it lands on
`/Capstone/php/cart.php`**, and it opens on keyboard focus. At 390×844 the page stacks
(`listBottom 1453 ≤ footTop 1473`), both columns are 350px, the foot is `static`.

The screenshots caught what the measurements did not: at `top: 100%` the panel sat under the
navbar's lower edge rather than below it, and at `width: 16rem` "Custom bouquet: 6 fl…"
ellipsed two words in. Hence the 18px offset and 18rem.

**The touch case took two attempts and the first one proved nothing.**
`Emulation.setEmulatedMedia` with `hover: none` set *before* navigating was ignored —
`matchMedia('(hover: hover)')` still matched, so the `@media (hover: hover)` guard was never
exercised and the panel opened as it would on a desktop. Re-run with
`Emulation.setTouchEmulationEnabled` and the override applied *after* load, and with
`matchMedia` re-asserted before the result was believed: `(hover: hover)` false,
`(hover: none)` true, panel shut at rest, **panel still shut on hover**, and a real
`Input.dispatchTouchEvent` tap on the icon lands on `/Capstone/php/cart.php` with the badge
still drawn. A verification harness that silently does nothing is worse than one that fails,
because it reports a pass.

Cleanup: the CDP driver, the touch probe, the screenshot folder and three Chrome profile
directories are deleted; the five anonymous baskets the browser runs created are gone with
their lines and parts and no orphans behind them; `products` is back to 13 and
`product_components` to 17; "leftover ZZTest rows: 0". The admin's own Single Calla Lily
basket is left alone, as before.

### Commerce — checkout, orders and payments, done 2026-09-20

Everything after the Checkout button. Two decisions were the user's: **pay by GCash or bank
transfer with a reference the customer types in themselves, or cash when it arrives** — no
gateway, no API keys, the shop confirms by hand — and **both delivery and pickup, the customer
chooses**. Everything else below follows from those two.

* `sql/orders-upgrade.sql` — three tables, `CREATE TABLE IF NOT EXISTS` like every other
  migration here. `orders` holds the reference, the owner, the two states, the payment method
  and the customer's own reference, the contact details, five address columns, a note and the
  total. `order_items` is `cart_items` minus what stops meaning anything once the order exists,
  plus a **stored `line_total`**: the basket multiplies on every page load so there is nothing
  to disagree with, but an order is read years later by whatever code exists then, and the
  figure that was agreed should not depend on that code multiplying the same way.
  `order_item_parts` is what a designed bouquet is made of, and it is the build instruction as
  well as the receipt — which is why `colour_hex` is copied across too. Somebody at the table
  needs to know which pink.
* **`user_id` is `ON DELETE RESTRICT`, alone among this project's foreign keys.** Everything
  else cascades or nulls. An account with orders against it cannot be deleted, because the
  alternative is a shop that has lost who an order was for while still being expected to make
  it. Deleting a *product*, by contrast, is `SET NULL` and the line stands: it kept its own
  name and price when it was ordered.
* **The reference is what the customer has, so it is what everything is written against.**
  `SLJ-YYYYMMDD-XXXX`, four characters from an alphabet with no `0`, `O`, `1` or `I` in it,
  because this gets read down a phone. UNIQUE, generated with up to five tries, and the
  collision is caught from the index rather than by looking first — two checkouts a millisecond
  apart is exactly when a read-then-write is wrong. Every page finds an order by reference, and
  `fetchOrderForUser()` puts `user_id` in the WHERE, so another account's reference is **404
  and not 403**: a 403 would confirm the order exists.
* **The two things the basket half deliberately left open are answered.** A placed order
  **empties the basket in the same transaction** — the `carts` row is kept, only the lines go,
  so the cookie and the merge behaviour are untouched. And a withdrawn line **refuses the whole
  order** rather than dropping itself: `placeOrder()` refuses it, and `php/checkout.php` bounces
  to the basket *before* asking for an address, next to the Remove buttons that fix it. Being
  asked to type out an address and then turned down at the end is the worst version of that
  rule.
* **The form carries no money.** `placeOrder()` re-reads the basket inside the transaction and
  totals it there; the only figures in the POST are a quantity nobody can edit at that point and
  a payment reference. Asserted rather than asserted-in-comment: a tamper test posts a `total`
  field and the order is written at the price the basket says.
* `php/checkout.php` — one page, POSTing to itself and redisplaying with the boxes still full
  when something is wrong, which is this project's convention for any form with more than one
  field. `requireLogin()` is on this page and nowhere earlier, because that was the user's rule
  for the basket: an account is needed to order, not to shop. Delivery and pickup are radio
  tiles reusing the customizer's, and the five address fields are asked for only by the first.
  Phone validation is deliberately lenient — digits, spaces, `+`, dashes and brackets, 7 to 20
  characters — because a form that refuses a real phone number written a slightly unusual way
  is worse than one that accepts a typo the shop will ring anyway. The postcode is optional
  even on a delivery.
* `php/orders.php` and `php/order.php` — the customer's list and one order. The list is the
  50 most recent with a count above it; the order page is the receipt, read-only, with a single
  action on it. Nothing on either links to a product page, and that is deliberate: the name and
  price on an order are frozen copies, and a link to a page that now says something different
  would quietly contradict the receipt it sits on.
* `php/order-cancel.php` — a customer can cancel **while it is placed and unpaid**, and the
  condition is in the `UPDATE` rather than in a read followed by a write: between drawing the
  button and pressing it the shop may have confirmed the payment. PDO reports rows *changed*,
  so the statement names every condition it depends on and a `rowCount()` of 0 means something.
  Write-only and redirect-only, like `php/cart-update.php`.
* `php/admin/orders.php` — the queue. Nine columns, filters for state, payment and a search
  across reference, name, phone and the customer's payment reference, 20 to a page, and three
  actions that need no form of their own: **mark paid, take that back, cancel**. It defaults to
  `open` — placed plus packed — because an order list is a work queue, and the default view of
  a work queue should be the work. The toolbar counts payments waiting to be checked whatever
  filter is on, and links to them, because that is the one number this page exists for.
* `php/admin/order.php` — one order in full, including what to make: every part with its
  colour, its quantity and its kind. The same three buttons, and `applyAdminOrderAction()` in
  `php/config/orders-admin.php` is the single implementation both screens call, because two
  copies of a branch that long is how two screens end up disagreeing about what "already paid"
  means.
* **Mark paid has an undo, and that is not symmetry for its own sake.** It is the one action
  here that records money, a human is matching a typed reference against a banking app, and the
  answer to a mis-click cannot be an SQL prompt. `markOrderUnpaid()`'s `CASE` puts a transfer
  back to `submitted` and cash back to `unpaid`, because those are the two different things
  "not paid" meant before somebody pressed the button.
* **Two status columns, because payment and fulfilment are two clocks.** A transfer can land
  before anything is wrapped and a cash order is delivered before it is paid for; a single
  "status" would have to invent a word for every pairing. Green is agreed, amber is waiting on
  us, grey is over or not started. **Nothing is red** — a cancelled order is a state, not a
  fault, exactly as a hidden product is.
* `php/dashboard.php` and `php/admin/dashboard.php` — a count and the three most recent orders
  on the customer's hub, and a real Orders card with live counts on the admin's, replacing the
  honest empty one that named this module as its owner.
* `css/main.css` — `.order-*` states and pills, the checkout layout riding on `.cart-layout`
  and `.cart-foot`, and `.admin-table-orders` for the queue, which is the widest table in the
  project and the only one that needed rules of its own.

**How it was tested** — 911 assertions on this half, all passing: 682 unit
(`php/tools/orders-unit.php`) on the validation, the arithmetic and the string work, and 229
over Apache and the database (`php/tools/orders-http-test.php`) on everything a session and a
real POST are needed for. Both kept basket suites were re-run against the finished half as a
regression — 63 and 133 — so **1,107 assertions pass together as of 2026-09-20**. Both new
suites carry the `PHP_SAPI !== 'cli'` guard and answer 404 over HTTP, and both are **kept**,
for the reason the basket suites were: the Orders module extends these exact tables.

**Re-running the basket suites was not a formality, and this is the second time keeping a suite
has paid for itself.** `cart-http-test.php` failed on exactly one assertion, and the suite was
what was wrong: it asserted the Checkout button was *disabled*, which was correct while the
basket was the whole of Commerce and false the moment checkout existed. A signed-out visitor
now gets a live "Sign in to check out" link instead. Fixed in the test, and all three states of
that one control are now asserted where each occurs — a guest offered sign-in, a signed-in
customer offered a live link to the till, and the disabled button kept for the single case that
still earns it, a basket holding something no longer on sale.

Notable coverage: an order is placed end to end over HTTP and then read back column by column
against what was posted, with every `line_total` checked against its own `unit_price ×
quantity` and their sum against the order's total; the basket is empty afterwards and the
`carts` row is still there; a posted `total` field is ignored and the order is written at the
price the basket says; a line withdrawn between filling the basket and pressing the button
bounces to the basket and writes no order; another account's reference is 404 with nothing
leaked; cancelling works while placed and unpaid and is refused with the row unchanged once the
payment is confirmed; `unpay` sends a GCash order back to `submitted` and a cash order back to
`unpaid`, which is the one branch a symmetrical undo would have got wrong; a customer POSTing
blind at either admin screen changes nothing; references are unique and correctly formatted and
`orderReferenceFromInput()` refuses everything that is not; the search matches on all four of
reference, name, phone and payment reference; and page 2 of the queue never repeats page 1. No
expected total is hardcoded — each is computed in the test from the prices it wrote.

**The suite caught a real bug that clicking around would not have.** The admin search bound one
named placeholder four times in a single statement, which is fine under emulated prepares and
`SQLSTATE[HY093]` under `ATTR_EMULATE_PREPARES => false` — so the queue worked perfectly until
somebody typed in the search box. Fixed with four distinct placeholder names for the one value,
and the file says so above the query.

Real Chrome over the DevTools Protocol at 1280×800 and 390×844, twelve screenshots **reviewed
by looking rather than only measured**, which found four real defects that every measurement
passed:

* **A radio button floating above its own label** on the checkout tiles. `.panel-form input`
  sets `width: 100%`, the tile's `flex: 0 0 auto` resolves its basis from `width`, and so the
  radio took the full 734px of the row — Chrome drawing the 13px control centred in an empty
  box and pushing the label onto a second line. Fixed with `width: auto`, which is load-bearing
  rather than a default written out. The same collision was already handled the same way for
  `.auth-check input`, which is why the comment now names both.
* **Two "Place order" buttons 277px apart on a phone**, the first of them above the total it
  was asking agreement to. There are two because in two columns each is the foot of its own
  column and they sit a thousand pixels apart; stacked, that argument stops holding. The
  in-form one is hidden below 900px and the summary's is kept, since that is the one sitting
  under the figure. Both are plain submits with no name, so the form posts the same either way
  and Enter in a text field still places the order.
* **The whole actions column off the right-hand edge at 1280**, on a page whose own header
  counts the payments waiting to be checked — and it was my own earlier `min-width: 1180px`
  that put it there. Worth recording as a lesson rather than a fix: **a min-width above a
  table's natural width dumps every surplus pixel into the last column**, which is how a 115px
  strip of buttons became a 217px one. Measured rather than guessed, the nine columns need
  1069px, so the floor is 1070, the cell padding is 9px rather than 14, and `orderDateShort()`
  ("19 Sep 2026, 7:54pm") replaced the spelled-out date that was the widest column on the page
  at 215px — wider than the reference and the customer's name together. The table now measures
  1078 inside the 1078px `.page` and does not scroll at all. The sticky actions column stays
  for the narrower window and the phone.
* **Address lines carrying both a trailing comma and a `<br>`**, so an address read as a
  sentence somebody had pressed Enter in. The line break is already the separator. Fixed on
  both the customer's and the admin's order page.

**What was not exercised live.** The `PDOException` → 503 pages on the four order screens are
covered by `php -l` and by eye only; reaching them means breaking the database mid-suite, which
is the same gap accepted for the same reason in the customizer. And no order in any run reached
`packed` or `delivered`, because nothing in this half writes them — that is the Orders module's
boundary, and `php/admin/order.php` says so where an admin would look for the button.

Cleanup: the teardown runs in foreign-key order (`order_item_parts` → `order_items` → `orders`
→ `cart_items` → `carts` → `trusted_devices` → `users` → `products`), and afterwards `orders`,
`order_items` and `order_item_parts` are empty, `products` is back to 14 rows / 13 active,
`product_components` to 17, and `users` to the one admin account. Twelve temp files and
directories including four Chrome profiles are deleted, and the sixteen Chrome processes still
running were each checked against the screenshot profile paths and the debugging port before
being left alone — they are the user's own browser. Apache and MariaDB had to be restarted
mid-pass for the third time in this project; nothing was lost, and the cause is the one already
recorded under OPEN ISSUES. **One thing is deliberately not cleaned up and is recorded as an
open issue instead:** the admin account's basket now holds a bouquet written during this
session rather than the seeded calla lily the 2026-09-16 record describes. Deleting it would
have deleted the evidence that a run wrote to a real account.

### Branding — the logo in the navbar, done 2026-09-21

The user put `images/Logo.jpg` in place (390×390, 22KB, a circular badge) and said to make
whatever changes it needed. It was already rendering — `php/components/navbar.php` has drawn
it through `asset()` since Foundation — so this is about the four things around it that were
not right.

* **It is a link home now.** The logo and `SITE_NAME` together are one `<a>` to
  `php/index.php`. `.nav-con1` is the anchor itself rather than something wrapped around it,
  so the flex row that was already there is untouched. The Home link in the row below stays
  and is not a duplicate worth deleting: that is the one a keyboard reaches in order and a
  screen reader reads out, this is the one a mouse goes to without having read anything.
* **The shop has a favicon.** `php/components/header.php` had no `<link rel="icon">` at all,
  so every tab — storefront and admin both — showed the browser's blank sheet of paper. The
  JPEG is pointed at directly rather than converted to a `.ico`: GD is off in this XAMPP,
  every browser this runs in accepts `image/jpeg`, and a second copy of the logo is a second
  file to remember on the day the logo changes. It goes through `asset()`, which matters
  more here than anywhere else — a favicon is about the most stubbornly cached thing a
  browser holds, and `?v=<filemtime>` is what makes a replaced logo show up in the tab.
* **`width="50" height="50"` on the `<img>`**, matching the box `css/components.css` gives
  it. Not styling — it is what lets the browser reserve the space before the file arrives.
  Without it the navbar row is 0px tall on a cold load and the whole page jolts downward
  when the logo lands.
* **`alt=""`, not the shop's name.** The name is the text of this same link, an inch to the
  right of it. The old `alt="Sky and LJ's Crafts logo"` had a screen reader announce "Sky
  and LJ's Crafts logo, Sky and LJ's Crafts" for one link.

`css/components.css` gained the two declarations that stop an anchor looking like one —
`color: inherit` and `text-decoration: none`, because the shop's name reads as a heading in
everything but markup — plus a hover tint on the name and an explicit `:focus-visible`
outline, since taking the underline away took the only keyboard hint with it.

**How it was tested** — `php -l` on both changed PHP files; `cart-http-test.php` (133) and
`orders-http-test.php` (229) re-run in full because the navbar renders on every page of the
site, both clean with no leftover rows; the favicon URL serving 200 `image/jpeg`; and Chrome
at 1280×800 and 390×844 with the viewport **measured rather than assumed**.

That last part caught a bad method, and it is the useful thing in this entry.
`chrome.exe --headless=new --screenshot --window-size=390,844` does **not** give a phone
viewport on Windows: the OS refuses a window that narrow, the page lays out at desktop width
and the PNG is merely cropped to the size asked for. Every media query then reports desktop
and the screenshot is a lie that looks like evidence — the first shot came back showing a
desktop navbar with no hamburger and the hero text sliced mid-word. Driven through CDP
instead (`Emulation.setDeviceMetricsOverride`, Node 24's built-in `WebSocket`, so no
packages), the page reports a real `390x844` and the real phone navbar.

That harness is kept as **`php/tools/screenshot.mjs`** rather than thrown away, because a
method recorded with nothing behind it gets rebuilt from scratch or quietly ignored, and
Orders, Inventory and Admin all have UI still to check. It takes a URL, an output prefix and
an optional JavaScript expression evaluated in the page, shoots 1280×800 and 390×844, and
prints what the browser measured beside each shot — a screenshot proves something rendered,
not that it rendered at the size the CSS asked for. It is the seventh file in `php/tools/`
and the first that is not PHP; nothing enumerates that folder, so nothing is affected.

Measured inside the page at both widths: `href` = `/Capstone/php/index.php`, image box
exactly 50×50, the file decoded (`naturalWidth` 390), `alt` empty, the name computing to
`rgb(51, 51, 51)` rather than link blue, `text-decoration-line: none`, and the favicon
linked. Both servers had died before the Chrome pass and were restarted hidden — the
process-lifetime trap again, not anything to do with this change.

**Follow-up, 2026-09-22 — the two branding calls, and a footer.** Asked to fix the issues
the logo pass raised, and with two of them the user's to decide, the user chose: the shop is
**"Sky and LJ's Craft"** (the logo was right, the site was wrong — `SITE_NAME` in
`php/config/paths.php` was the only place the name lived, and no test asserted it), and the
tagline goes in **a small site footer**. So `php/components/footer.php` is no longer two
closing tags: it draws the shop name, "Bringing blooms to our community" in the eyebrow
treatment `.page-eyebrow` uses, and `© <year> <name>. Handmade to order.` — no links, because
the navbar has them. It appears on the admin pages too, since it is the one function every
page closes with. Typed in sentence case and uppercased by CSS, because some screen readers
spell a capitalised phrase out letter by letter.

For the footer to sit on the bottom edge of a short page rather than float halfway up it,
`body` in `css/main.css` is now a flex column of at least `100vh` with `body > main` taking the
slack — the first layout `body` has ever had here. `.auth-page` lost its
`min-height: calc(100vh - 71px)` in the same edit: with the footer in the column that calc
put a scrollbar on the sign-in page, and flex gives the card its centring height for free.
The toast stack is `position: fixed` and unaffected. The 403 page in `php/config/auth.php`
also gained the icon and a viewport meta after all — it already called `url()` and `escape()`
from the same `paths.php`, so "depends on nothing" was never a reason to leave it out.

Tested the same morning, with the user's presentation at 11:00 forty minutes off: `php -l` on
all five touched PHP files; `cart-http-test.php` 133 and `orders-http-test.php` 229, both
clean, no leftovers; every public page 200 with the footer and the new name, every protected
page a clean 302, no 500 anywhere; and `php/tools/screenshot.mjs` on home, sign-in and the
empty basket at both widths — sign-in and basket measure `footerBottom === scrollHeight ===
viewport height` with `scrolls: false`, home scrolls with the footer at the document's end.
The database had been restored by the user beforehand: 12 products, 17 parts, 1 user, 1
order, 2 carts.

**The two leftovers, closed 2026-09-22.** Both were reported as "cosmetic, left alone" and
the user asked whether they were solvable. One was, one half was.

* **The favicon is now `images/favicon.png`, a crop of the logo rather than the logo.** The
  badge carries the shop's name curved around its rim, and a browser tab paints it at 16px.
  Measured rather than guessed — downscaled to 16×16 and read back, enlarged with
  nearest-neighbour — the whole badge is a pale grey disc with a smudge in it: the rim text,
  the ring and the sparkles are all gone, and so is any hint of a flower. Four crops were
  compared the same way. The winner is the bloom with a little air around it (source region
  115,65 160×160, saved at 128×128); a tighter crop on the petals alone was *worse*, because
  filling 16 pixels with petal detail is mush where a shape with margin still reads. It is a
  crop of the user's own artwork with nothing redrawn or recoloured, and deleting the file and
  pointing the two `<link>` tags back at `images/Logo.jpg` undoes the whole idea. **The navbar
  logo is untouched** — at 50px the badge is fine and it is the real mark.
* **What is *not* solved, and cannot be here: the wordmark at 50px.** The crop helps the tab,
  not the navbar. Making "SKY AND LJ'S CRAFT" legible on the rim at 50px means a simplified
  mark, which is new artwork and the user's to commission. Nothing in the site can fix it, and
  it costs nothing to leave: the shop's name is set in text an inch to the right of it.
* **The 403 page's docblock said "it depends on nothing", which had stopped being true.** It
  now says what the rule actually is — no header, no navbar, no stylesheet, no database, so it
  still renders when whatever it is guarding is broken — and notes that this is about
  dependencies that can fail rather than a vow of poverty. `asset()` guards its `filemtime()`
  with `is_file()`, so a deleted icon costs that page a cache-buster and nothing else.

Verified at 10:39 on the morning of the user's presentation: `php -l` on both changed files,
`favicon.png` serving 200 `image/png` at 37,433 bytes, all five public pages linking it with a
cache-buster, and both suites re-run — 133 and 229, clean. The orders suite asserts that 403
page three times, which is what exercised the changed `requireAdmin()` output rather than only
linting it.

**One design change at 10:55, fifteen minutes before the presentation.** Asked whether anything
could be made to look better in the time left, and the honest answer was that the catalogue's
weakness is not its layout: **no product has a photograph**, so twelve identical flat-grey
rectangles *were* the design, and the page read as one whose images had failed to load. So the
placeholder tile in `css/main.css` gained a soft diagonal gradient in the palette's own greens,
a stronger icon colour (`#9fc8b8` was disappearing into its own tile), and a small lift on
hover — a tile with no photograph had nothing to answer a hover with.

Three things kept it safe to ship that close to the clock. It is scoped with
`:has(.product-card-placeholder)`, so a tile showing a real photograph is untouched. It changes
no geometry, which was measured rather than assumed — media heights 191px across all four tiles
and identical row tops, so the grid-alignment trap documented in that file is unaffected. And it
added a `prefers-reduced-motion` block that also covers the card lift that was already there.
Verified with all six public pages at 200 and the catalogue re-shot at both widths.

The real fix is still photographs. `php/config/uploads.php` has worked since 2026-09-04 and
twelve real images would do more for that page than any CSS.

### 3D Customizer — quarters two, three and four done 2026-09-14

Saved designs, the basket handoff's boundary, and arrangement control. Quarter one's builder
was not rebuilt; it was extended.

**Quarter two — saved designs.** `sql/designs-upgrade.sql`, `php/config/designs.php`,
`php/designs.php`, `php/design-save.php`.

* **Two tables, not one.** `saved_designs` holds the wire, wrap, accent and arrangement;
  `saved_design_blooms` holds one row per flower kind with a `quantity` and a `sort_order`.
  A bouquet mixing two blooms has no single `bloom_id`, so flattening it into one row would
  have meant a comma-separated list in a column — and the moment a part is withdrawn, a list
  like that cannot be joined against, checked, or repaired.
* **`ON DELETE SET NULL` on every component column, `ON DELETE CASCADE` on the owner.**
  Deleting a part must not delete somebody's saved design, and deleting an account must take
  its designs with it. `readSavedDesign()` reports a null part as a **gap** rather than
  falling back to the first of its kind — which is the opposite of what `readDesignSelection()`
  does for a hand-edited URL, and deliberately so: a URL somebody typed is a guess worth
  correcting, and a design somebody saved is a record worth keeping honest.
* `UNIQUE KEY (user_id, name)` — one account cannot hold two designs of the same name, and
  the index is per-user, so two customers may both have a "Mum's birthday".
* `SAVED_DESIGN_MAX = 20` per account, checked before the write. A cap that exists in the
  code and not only in the interface, because the save endpoint is reachable directly.
* **Account required**, unlike the builder. Saving is the point at which a visitor becomes a
  customer, which is the argument `php/products.php` already makes in reverse for browsing.

**Quarter three — the basket handoff.** No code, and that is the finding. The button was
already present and disabled from quarter one; what this quarter added was
`designSnapshot()` and the guarantee above it, so Commerce inherits a documented freeze point
rather than a decision. Recorded in `## CURRENT TASK` rather than claimed as delivered.

**Quarter four — arrangement control.** `php/config/customizer.php` and `js/customizer.js`.

* **Up to four kinds of bloom in one bouquet** (`CUSTOM_MIX_MAX`), as `?mix=1x3,5x2` or
  `?mix[1]=3&mix[5]=2` — part id to quantity, in either form. `readBloomMix()` **drops** an
  unknown id rather than substituting for it, which is the opposite of what
  `readDesignSelection()` does for a single part, and the file says why: substituting a rose
  for a withdrawn sunflower puts a flower nobody chose next to the roses they did choose, at
  a price they did not agree to. Dropping makes the bouquet smaller, which is visible;
  substituting makes it wrong, which is not. Past the 24-stem ceiling, entries are trimmed
  from the end of the offered order rather than scaled — `?mix[1]=20&mix[5]=20` is twenty
  roses and four sunflowers, because scaling would answer a request for twenty roses with
  twelve. A mix that empties out falls back to the single `?bloom=` design, so every link
  quarter one shared still works.
* **Three arrangements** — `dome`, `round`, `loose`. `dome` is quarter one's phyllotaxis
  spiral unchanged, so an existing shared link still draws what it drew. `round` lengthens
  every stem by `1/cos(lean)` so the heads sit on a flat disc; `loose` adds a per-stem wobble
  seeded off the stem index, so it is irregular but not random — the same URL draws the same
  bouquet twice.
* **A wrap that scales with the count**, which quarter one recorded as a known fault: a cone
  sized for six flowers was plainly oversized around one. `wrapSize()` grows it with the
  bloom count instead.

**Two product bugs were found by looking at the screenshots, and both were real.**

* **The camera never framed the bouquet.** A fixed `distance = 7.6` was chosen for the
  six-flower dome and clipped everything taller — round posies, loose arrangements, and most
  visibly a single rose sliced flat across the top of the canvas. `fitView()` now measures the
  bouquet and fits a **cylinder** about the upright axis: not a box, because a box fits one
  yaw and clips at the next as the bouquet turns, and not a sphere, because the sphere around
  a tall narrow bouquet is mostly empty air — fitting one put a rose in a field of background
  and dropped stage coverage to 0.053 against a 0.06 floor. The fit is evaluated across the
  **whole pitch range**, not at the steepest tilt: tilting down foreshortens the bouquet, so
  fitting there yields the closest camera of any tilt and every shallower angle then clips.
  Zoom is kept as a fraction of the fitted distance, so somebody who has zoomed in on one rose
  and then asks for a dozen keeps the closer view instead of being snapped back.
* **The saved-design card was 464px tall on a phone**, with about 180px of dead space.
  `.design-card-head { flex: 1 1 14rem }` gives the name a sensible width in a row, but
  flex-basis measures along the container's main axis — so once the card stacked into a column
  it became a 224px minimum **height**, with `space-between` pushing the buttons to the bottom
  of a box four times taller than its two lines of text. Fixed with `flex: 0 0 auto` in the
  560px media query. This is the third time this exact fault has appeared in this project
  (the catalogue search field in Customer, and now this), and the second one that every
  "nothing overflows sideways" measurement passed.

**A test-harness fault was found too, and it is the reason the first bug survived review.**
`Page.captureScreenshot` with `captureBeyondViewport: true` grows the viewport to the document
height before capturing, which fires the page's `ResizeObserver` — and this page reframes its
3D stage on resize. **Every screenshot in the suite was therefore a photograph of a state no
visitor is ever in:** the pixel buffer said the bouquet was clipped and the pictures said it
was fine. The harness now takes a plain viewport capture. An earlier screenshot review in this
module was invalidated by this and re-done.

**How it was tested** — 202 assertions, all passing: 106 over HTTP and the database on saved
designs (`%TEMP%\designs-http.ps1`), and 96 in real Chrome over the DevTools Protocol
(`customizer-chrome.mjs`) at 1280×900, 1280×800, 700px, 560px and 390×844, with `node --check`
clean on `js/customizer.js`. Notable coverage: a design saved by one account is **404, not
403**, to another — a 403 confirms the design exists; the name is validated, trimmed,
length-capped and refused when duplicate within an account; the 21st save is refused; a mix
round-trips through save and reload with quantities and order intact; a withdrawn part leaves
a gap rather than a substitution; and every total is recomputed in the test from
`product_components` rather than hardcoded, because a suite that repeats the page's own
arithmetic agrees with it while both are wrong.

The canvas was proven to hold a bouquet rather than a background by reading pixels back with
`gl.readPixels` on the renderer's own context — `canvas.getContext('2d')` returns null on a
WebGL canvas, so there is no `getImageData`, and a fresh `getContext('webgl2')` is not
guaranteed to return the context Three.js is drawing into. Two read-only handles,
`window.__customizerRenderer` and `window.__customizerCamera`, are **deliberately left in
production code** with a comment saying why: a page that cannot be checked from outside is
worse than two properties on `window`, and they expose objects the page already owns.

One assertion was wrong rather than the code, and it is worth recording: "the sleeve for one
flower is narrower than the sleeve for 24" failed twice while the geometry was correct. Now
that each bouquet is framed individually, the mouth of the cone sits at 64% of the frame for
one flower and 55% for two dozen, so a measurement taken at a **fixed height** crossed the
widest part of the small sleeve and the middle of the big one. Replaced with `paperWidth()`,
which measures the widest the paper gets wherever that falls — which is what the eye compares.

**What was not exercised live.** The `$hasAnything === false` empty state is still only
covered statically. Reaching it means withdrawing all 17 seeded rows, and this project does
not write to a seeded row to make a test pass. The `PDOException` → 503 branch **was** driven
live in an earlier session. Quarter one's 176 HTTP assertions were not re-run here, so that
figure stays historical; the 202 above are what ran today.

One behaviour changed that a reader should not have to discover: `readBloomMix()` returns the
mix in **offered order** (the order the picker shows) rather than in the order the query string
listed them, so `?mix[5]=2&mix[1]=3` and `?mix[1]=3&mix[5]=2` are the same bouquet down to the
URL it rewrites itself to — verified: both print ₱334.00. Two reasons, both in the file. The
picker is a column of quantity boxes in offered order, so that is the order somebody is looking
at while they type; and `js/customizer.js` reads those boxes, so it has no way of knowing what
order a shared URL happened to be written in — a rule that depended on it is a rule the
browser's mirrored total could not follow.

Cleanup: `%TEMP%\designs-http.ps1`, `%TEMP%\customizer-unit.php`, `%TEMP%\customizer-check.mjs`,
`%TEMP%\cdp\` with its suite and eight throwaway probes, `%TEMP%\cdp-shots\`, and three Chrome
profiles are deleted; `zz-card-probe.html` — a standalone copy of the card markup used to
measure the phone layout without an account — was removed from the webroot.
`product_components` is back to 17 rows all offered with the seeded names, kinds and prices
intact, `saved_designs` and `saved_design_blooms` are empty, no `ZZTest%` row survives in any
table, and both upload folders are empty.

### 3D Customizer — first quarter done 2026-09-05

A live bouquet builder. Before this, `php/customize.php` was a ten-line stub that four
places in the site already linked at: the navbar's "Create", the home hero's "Build your
own", "Or build your own bouquet →" on every product page, and "Open the designer" on the
customer dashboard.

* `sql/components-shape-upgrade.sql` — `shape ENUM('none','rose','tulip','sunflower',
  'daisy','sprig') NOT NULL DEFAULT 'none'` on `product_components`, backfilled by name and
  guarded (`... AND shape = 'none'`) so a second run is a no-op, like the other two
  migrations. `'none'` is what every wire, wrap and accent keeps: a stem has one drawn form
  and does not need naming, and defaulting a length of wire to `'rose'` would be a value
  that is never read but still wrong in the table. This column exists for the same reason
  `colour_hex` does — "Sunflower Head" is not something WebGL can draw.
* `php/config/components-admin.php`, `php/admin/component-edit.php`,
  `php/admin/components.php` — the `shape` field end to end. Validated with `in_array`
  against `COMPONENT_SHAPES` and **forced to `'none'` unless the kind is `bloom`**, exactly
  how `validateProductInput()` forces `stem_count` to NULL for a single flower. The select
  never offers `'none'`; asking for it directly is refused rather than quietly saved, so a
  bloom cannot be created that the builder has no shape for. The form select carries
  `data-kind-only="bloom"` and the kind select got `data-product-kind`, so `js/script.js`
  is **unchanged** — the existing `setUpProductKind()` hides the field for the other three
  kinds. Enhancement only; the server is what forces the value.
* `php/config/customizer.php` — the builder's own layer, and the first file in `config/`
  with no write side, because this quarter has no writes. `customBloomCount()` clamps into
  1–24 and falls to 6 on junk; `readDesignSelection()` returns one row per kind or null;
  `designTotal()` returns a `DECIMAL` string built with `number_format(..., 2, '.', '')`
  and never a float; `designSummary()`, `designSentence()`, `designUrl()` and
  `designPayload()`. `DESIGN_PER_STEM_KINDS` is `bloom` and `wire` — a bouquet has one
  wrapper and one ribbon however many flowers are in it.
* **An unknown id falls back rather than erroring**, and the reasoning is in the file:
  there are three ways to arrive at one and none of them is a mistake worth a failure — a
  hand-edited URL, a shared link naming a part the shop has since withdrawn, and an id
  belonging to a real part of a *different* kind. That last one is why the lookup is inside
  the kind's own list and not against the whole table: `?bloom=8`, where 8 is a wire, would
  otherwise be drawn as a flower head.
* `php/customize.php` — server-rendered and complete **before a single line of Three.js
  was written**, which is what made the JavaScript a mirror rather than the feature. A
  `.page-head`, the stage, the total with a per-line breakdown, a disabled "Add to basket"
  carrying the same honest note `php/product.php` uses, and the picker as a real
  `<form method="get">`: one `<fieldset>` per kind with radio tiles rather than a
  `<select>`, because each option shows a colour, a name and a price and a `<select>` can
  only show text. A `PDOException` around the read gives 503 with a real page, as
  `product.php` and `products.php` already do.
* **The server owns the price.** The figure the page ships with is `designTotal()` over the
  rows it just read. `js/customizer.js` mirrors that arithmetic only so the total moves
  while you click, and both sides work in **integer centavos** — `(int) round(((float)
  (string) $price) * 100)` in PHP, `Math.round(Number.parseFloat(part.price) * 100)` in JS.
  The payload carries the server's total and the module checks its own first render against
  it, so a mirror that has drifted shows up in the console immediately rather than at the
  till.
* The parts reach JavaScript as **JSON in a `data-` attribute**, not an inline `<script>`.
  `escape()` is `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, which makes an attribute safe
  with no further thought; a part described as `</script>` cannot end the document from
  inside one.
* `js/vendor/three.module.min.js` and `three.core.min.js` — three@0.185.1 from unpkg, with
  `js/vendor/README.md` recording the version, the URL and the date, because a vendored
  dependency with no provenance is the one nobody dares update. The module build re-exports
  the core with a **relative** specifier, so the two files side by side need no import map
  and no build step.
* `js/customizer.js` — the project's first ES module, and a module is deferred by
  definition, so it needs no `DOMContentLoaded` wait. Every part is drawn from primitives
  in its own `colour_hex` with a matte `MeshStandardMaterial` (`roughness: 0.92`,
  `metalness: 0`) — chenille is fuzzy and unreflective, and GD is not enabled on this
  XAMPP, so no textures and no model files. `LatheGeometry` profiles for the rose and the
  tulip, a ring of petals and a dark centre for the sunflower and the daisy, a spike of
  buds for the sprig, an open `ConeGeometry` with `side: DoubleSide` for the wrap, and a
  `TorusGeometry` band at the tie point for the accent. Stems sit on a phyllotaxis spiral
  so six flowers read as a dome rather than a row. No OrbitControls: drag-to-spin and
  wheel-to-zoom is thirty lines, and hand-rolling it is what lets a touch drag rotate the
  bouquet without also scrolling the page.
* **It degrades twice over.** With no JavaScript nothing unhides the canvas and the
  server-rendered summary — four swatches, four names and the sentence — is what a visitor
  reads, with a working Update button under it. With no WebGL the module leaves the canvas
  hidden and puts the summary back, and it does the same on `webglcontextlost`. A blank
  rectangle is never what anybody is left with.
* `php/components/header.php` — `renderHeader(string $title, array $modules = [])`. Optional
  and defaulted, so **none of the sixteen existing call sites changed**; the re-run admin
  suites are the proof. A module tag in the body would have worked too, but every other
  asset on this site is declared in one place.
* `css/main.css` — one new section, same flat style and palette. `.customizer` is a two
  column grid that stacks under 900px, the canvas has a fixed `aspect-ratio` so it cannot
  collapse to zero height before the module measures it, and `touch-action: none` is what
  stops a drag scrolling the page. One rule is load-bearing rather than decorative:
  `.customizer-canvas[hidden], .customizer-static[hidden] { display: none; }` — the UA
  stylesheet's `[hidden]` loses to a class selector setting `display: block`, so without it
  the hidden canvas and the fallback would both be on screen at once.

**How it was tested** — 542 assertions, all passing, over Apache, the database and real
Chrome: 148 unit tests on the shape column and `customizer.php`'s arithmetic, 65 over HTTP
on the two admin screens as a regression for the schema change, 108 unit tests on
`php/config/customizer.php`, 176 over HTTP on the builder itself, and 45 in Chrome over the
DevTools Protocol at 1280×800 and 390×844. The admin and unit suites were re-run after
`renderHeader()` changed, since that file is on all sixteen pages.

No expected total in the HTTP suite is hardcoded: each one is computed in the test from the
prices read out of `product_components`, because a suite that repeats the page's own
arithmetic would agree with it while both were wrong.

Notable coverage: a 25-case hostile-input matrix, each case checked four ways (the page
answered without a PHP notice, the total, the radios that came back checked, and the count
field) — `?count=` of `0`, `-3`, `25`, `abc`, `6.7`, an array and 500 nines all clamp or
fall to the default; `?bloom=` of `999`, `8` (a wire id), an array, `2.9`, `0`, `-1`, 500
nines and a `<script>` tag all fall back to the first bloom and never error, and the tag is
never echoed as markup. The payload was decoded and checked field by field — the total
agrees with the printed figure, the sunflower arrives as a sunflower and a wire as `none`,
every colour is a real six-digit hex, and `perStem` is true for exactly blooms and wires.
A part **created by the test** was chosen by URL, then withdrawn: the same link answers 200,
falls back to the first bloom, and never prints the withdrawn part's name. The page answers
**200 with no redirect anywhere** to a stranger, to a customer the suite signed up and
verified through the real 2FA flow, and to an admin — the navbar greets the customer and
offers them no Admin link, and all three get the same total.

The canvas was proven to contain a bouquet rather than a background, by reading the pixels
back: over 40 distinct colours and ink covering between 3% and 85% of the stage. A bouquet
that fails to build is a uniform rectangle, and every geometric measurement of one passes.
Also driven for real: a mouse drag changes the picture (checked by a rolling checksum of
the data URL, not its length — two different views can compress to the same size) with
`scrollY` still 0, a touch drag does the same on the phone layout, clicking a different
bloom re-prices in the browser with **no reload** (proved by a marker element surviving),
and the URL, the share link, the breakdown and the canvas `aria-label` all follow. No
console error was logged in any of it, which is the JS total agreeing with the PHP one.
With `Emulation.setScriptExecutionDisabled` the canvas stays hidden at zero height and the
written summary shows four swatches and the exact sentence for the design in the URL.

Reviewing the screenshots rather than the numbers found a real rendering bug the
measurements passed: the cut ends of the stems burst out through the point of the wrap
cone — barely visible at five flowers, a full beard at twenty-four. Every stem pivots at
the tie point, so a head leaning one way swings its end the other, and the cone is
narrowest exactly there. Fixed by raising the stem base and shortening each stem in
proportion to its lean, using a unit-height cylinder scaled per stem so one geometry is
still shared. Six per-shape captures then confirmed all five blooms are visually distinct:
the rose reads as a rounded bud, the tulip as a cup, the sunflower as wide petals around a
dark centre, the daisy as small white petals around a yellow one, and the sprig as a spike
of buds.

Two things were seen in those captures and left alone deliberately. The wrap cone reads a
little like a plant pot, and at a count of one it is plainly oversized for a single flower.
Both are the same fix — a wrap that scales with the bouquet — and that is arrangement
control, which this plan put out of scope for quarter one. Recorded here so the next quarter
finds it rather than rediscovers it.

Two harness defects are worth recording because the fix was in the test, not the page:
an expected total of `₱1212.00` failed against the page's `₱1,212.00` — `formatPrice()`
runs `number_format`, and the separator is the truth; and an assertion for
`value="1" checked` found nothing because the rendered radio tag spans three lines, so it
matches `value="(\d+)"\s+checked>` instead. A third was arithmetic on my side: twelve
sunflowers are ₱840.00, not ₱864.00.

**What was not exercised live, and should be said plainly.** Two branches of
`php/customize.php` were only covered statically — by `php -l` and by the unit run's
`designSummary([]) === []` assertion — not by a real request: the `PDOException` → 503
page, and the "nothing to build with" empty state. Reaching the second one means
withdrawing all 17 seeded rows, and this project does not write to a seeded row to make a
test pass. Both are three lines of markup inside an `if`, which is the reason for accepting
the gap rather than an excuse for it.

The five earlier suites this quarter's schema change was meant to regress against
(`components-guard.ps1` 53, `components-flow.ps1` 209, `upload-guard.ps1` 61,
`upload-sizes.ps1` 12, `upload-flow.ps1` 64 and `uploads-unit.php` 69) **no longer exist on
disk** — the project's own cleanup rule deleted them when Products was finished, so those
counts are historical. What ran in their place is a targeted regression written for this
change: 148 unit and 65 HTTP assertions aimed squarely at the `shape` column, the two admin
screens and the writes that touch them.

Codes were read from the mail log with `MAIL_TRANSPORT` still `'log'` — no mail left the
machine, and the suite reads only the bytes appended by the request it just made rather
than the newest six digits in the file. The throwaway customer and its cascaded
`email_codes` and `trusted_devices` rows, the throwaway part, every temp script, both
Chrome profiles and the screenshots are deleted; `product_components` is back to 17 rows,
all 17 offered, with the seeded shape distribution untouched, and both upload folders are
empty.

### Products — second half done 2026-09-04

Image upload, then the customizer's component table.

**Image upload** — `php/config/uploads.php`, `uploads/products/`, `uploads/components/`
and `uploads/.htaccess`.

* The sent MIME type is never trusted. `getimagesize()`'s `IMAGETYPE_*` and
  `finfo_file()`'s string must **agree** before anything is written, and the stored
  extension comes from what was found, not from the filename. GD is not enabled on this
  XAMPP, so re-encoding the image was not an option; agreement between two independent
  readings is what replaces it.
* Filenames are generated (`bin2hex(random_bytes(16))`), so a name cannot collide,
  overwrite, or carry a path in it. `shell.php.jpg` is refused outright, and would not
  have run even if stored, because `uploads/.htaccess` removes the PHP handler and
  switches off `Options +ExecCGI` for the whole folder and everything under it.
* Replacing a photo deletes the old file, and deleting a row deletes its photo.
  `deleteStoredImage()` refuses any path that resolves outside `uploads/` — the unit
  tests aim `uploads/products/../../php/config/mail.php` at it, both slash directions.
* A refused upload refuses the whole save. The text does not land without the picture,
  because a half-saved product is worse than a rejected one.
* 2 MB is enforced in PHP as well as by `php.ini`, and the message names our cap rather
  than the server's. A body past `post_max_size` arrives with `$_POST` **empty** — which
  looks exactly like a first visit to the form — so `uploadPostWasAttempted()` checks
  `CONTENT_LENGTH` to tell the two apart and says the photo was too big instead of
  silently redrawing an empty form.

**The customizer's component table** — `product_components`, its own table, and its own
two admin screens.

* `sql/components-upgrade.sql` and `sql/schema.sql` — `product_components`: `name`,
  `kind ENUM('bloom','wire','wrap','accent')`, `description`, `price DECIMAL(10,2)
  UNSIGNED`, `colour`, `colour_hex CHAR(7)`, `sort_order`, `image_path`, `is_active`.
  **Not a column on `products`.** A component is what a bouquet is made *of*; a product
  is what a customer buys. Sharing the table would mean every public catalogue query
  growing a clause to exclude parts, and one wrong `WHERE` putting a ribbon on the shop
  front. `colour_hex` is what the 3D builder paints with, separate from `colour` because
  "Sage green" is for people and `#9caf9c` is for WebGL. `sort_order` exists because the
  order parts appear in a picker is a decision, not an accident of insertion.
* `sql/components-seed.sql` — 17 parts (7 blooms, 3 wires, 3 wraps, 4 accents), priced
  ₱10–₱85, `INSERT ... WHERE NOT EXISTS` on the name so re-running adds nothing.
  Re-running both files was checked, not assumed.
* `php/config/components.php` — the public read layer the customizer will use:
  `fetchComponents()`, `fetchComponent()`, `fetchComponentsByKind()`,
  `componentKindLabel()`, `componentColourHex()`, `componentImageUrl()`. `is_active = 1`
  is inside every statement. `fetchComponentsByKind()` always returns all four keys, even
  when a kind has nothing offered, so the builder never has to test for a missing group.
* `php/config/components-admin.php` — the writes and the reads allowed to see withdrawn
  parts, separate from the file above for the same reason `products-admin.php` is
  separate from `products.php`. `validateComponentInput()` is the single answer to "may
  this be saved". `componentStatusLabel()` says **Offered / Withdrawn**, deliberately not
  reusing `productStatusLabel()`'s Visible / Hidden: a part is not visible anywhere, it
  has no page of its own, and calling it "visible" would send an admin looking for it on
  the catalogue. A bare `E8B4B8` is accepted and normalised to `#e8b4b8`; anything else
  is refused rather than quietly becoming black.
* `php/admin/components.php` — the list: status / kind / sort / search on a real
  `<form method="get">`, 20 rows a page, nine columns, and withdraw / put back / delete
  per row. The name is a `<th scope="row">` and **not a link**, because there is no public
  page for a part to link to yet. The colour cell shows the swatch, the name and the hex
  together — the words are what an admin searches for, the hex is what goes wrong.
* `php/admin/component-edit.php` — create and edit in one file, mirroring
  `product-edit.php` step for step. `<input type="color">` for the builder colour, with
  the hex printed beside it: that control has **no empty state** and shows `#000000` for
  anything it cannot parse, so the form is always fed a display-safe value and
  `COMPONENT_FALLBACK_HEX` is `#cccccc` rather than black.
* `php/admin/dashboard.php` — a Components card with live counts. Counted in its own
  `try`, not added to the products one: `product_components` arrived in a later
  migration, so on a database where `sql\components-upgrade.sql` has not been run these
  queries fail and the product ones do not. A shared flag would blank the product counts
  to report a missing components table.
* `css/main.css` — `.colour-cell` / `.colour-swatch` / `.colour-cell-hex`, `input[type="color"]`
  inside `.panel-form`, `.field-hint code`, and `.admin-cell-kind`. The swatch's 1px
  border is not decoration: `#f7f4ec` and `#f4f1e8` are two of the seeded colours, and an
  unbordered swatch of either is an invisible gap on a white row.
* `js/script.js` — **unchanged.** The existing `setUpCatalogueFilters()` and
  `setUpConfirmButtons()` bind by `data-` attribute, so the new pages picked both up by
  using the same hooks. Worth recording as a result rather than an omission.

**How it was tested** — 654 assertions, all passing, over Apache, the database and real
Chrome: 262 on the two component screens (53 on the guard, 209 on the flow), 206 re-run on
the upload half as a regression after this half edited `css/main.css` and
`dashboard.php` (61 on the upload guard, 12 on the size limits, 64 on the upload flow, 69
unit tests on `uploads.php`), and 186 in Chrome over the DevTools Protocol across ten
admin and public views at 1280×800 and 390×844.

Notable coverage on the component screens: both pages 302 to `login.php` signed out and
**403** to a customer, including `?id=N`, and leak no part data in the refusal body; blind
POSTs — withdraw, delete, and a full multipart create — change nothing; `?id=` of `abc`,
`0`, `-4`, `999999` and empty all answer 404 with a real "Part not found" page rather than
a redirect; nine hostile query strings fall back to a full list instead of erroring; page 2
never repeats page 1, and a filtered pager keeps its filter on its links; a create writes
all nine columns exactly as submitted; 22 refusals were each checked to have written
**nothing**, while the boundaries (120, 40, 2000 characters, `0`, `9999`,
`99999999.99`) were checked to be accepted; a failed form comes back with the name,
description, bad price and bad hex still in the boxes; an edit leaves `image_path` and
`created_at` exactly as they were; renaming a part onto another part's name is refused;
photos land in `uploads/components/` under generated names and never in
`uploads/products/`; replacing one deletes the old file; deleting a part takes its photo
with it; and a generated PHP probe confirmed `fetchComponents()` and `fetchComponent()`
never see a withdrawn part while `fetchComponentsByKind()` still returns all four keys.

Reviewing the screenshots rather than the numbers found one thing the measurements
passed: the nine-column table was wrapping "Flower head" and "Pearl white" onto two lines
on a 1280px desktop that still had 100px of unused width, which made the whole list look
squeezed. Fixed with `white-space: nowrap` on those two cells — the table is inside
`.admin-table-wrap`, so refusing to wrap costs a scroll inside that box rather than a
wider page. The phone layout needed no changes: the table scrolls inside its own box with
the page still square, the near-white swatches are legible against a white row, and the
colour picker is 64×44 and pressable.

One test defect is worth recording because the fix was in the test, not the page: an
assertion that the edit form offers no link to a public part page was greping the body for
"See it on the shop", and it failed — on the HTML comment in `component-edit.php` that
explained why that row was absent. The comment became a PHP comment, and the assertion now
checks for an `href` instead of for prose.

Codes were read from `%TEMP%\capstone-mail.log` with `MAIL_TRANSPORT` still `'log'` — no
mail left the machine. Throwaway parts and products, nine throwaway accounts and their
cascaded rows, both upload folders, the mail log, every temp script and the screenshots are
deleted. `product_components` is back to its 17 seeded parts, all offered, with no
`image_path` set, and `products` to its twelve seeded rows. `products.id 11` had been
renamed to "test" during an earlier session while every other column still held the seeded
calla lily values; it is `Single Calla Lily` again.

### Products — first half done 2026-09-03

Admin CRUD. Before this, adding a bouquet meant an `INSERT` by hand in the MySQL shell
and taking one off sale meant an `UPDATE`.

* `php/config/products-admin.php` — every write, plus the reads allowed to see hidden
  rows. **Separate from `config/products.php` rather than a flag on it**: that file puts
  `is_active = 1` into the WHERE clause itself so forgetting it is impossible, and an
  `$includeHidden` parameter would hand every future caller the ability to forget. The
  public read path is untouched, so nothing behind Customer can regress from this half.
  `validateProductInput()` is the single answer to "may this be saved", used by both the
  create and the edit path so the two cannot drift apart. Money is checked in PHP
  (`/^\d{1,8}(\.\d{1,2})?$/` after stripping spaces and separators, then stored via
  `number_format`) rather than left to the column, because `DECIMAL(10,2) UNSIGNED`
  answers a negative with SQLSTATE 22003 and the admin would read that as "unable to
  save". `stem_count` is forced to NULL for a single flower whatever was submitted.
  `updateProduct()` names neither `image_path` nor `created_at`, so a save cannot blank
  a photo or rewrite when a product was added.
* `php/admin/products.php` — the list: status/kind/sort/search on a real
  `<form method="get">` reusing the catalogue's `.catalogue-filters` and its existing
  `setUpCatalogueFilters()` hooks, 20 rows a page, and the three actions that need no
  form of their own. Every POST answers with a redirect back to the same list **carrying
  its query string**, so hiding the third row of a filtered page 2 leaves you on that
  page. `rowCount()` was not used to decide whether a row existed — PDO's MySQL driver
  reports rows *changed*, so "already hidden" and "never existed" both come back 0.
* `php/admin/product-edit.php` — create and edit in one file, differing by a heading, a
  button label and an id. A missing or non-numeric id answers 404 with a real page,
  mirroring `php/product.php`; a database failure there is 503, because 404 would claim
  the product does not exist when the truth is that nobody can tell.
* `php/admin/dashboard.php` — was a stub whose `<main class="admin-page">` matched **no
  CSS rule anywhere**, so it rendered full-bleed and unstyled. Now a `.page` with live
  counts from `adminCountProducts()` and honest empty cards for Customers, Orders and
  Reports, each naming the module that owns it.
* `css/main.css` — one new section for these screens. `.admin-table-wrap { overflow-x:
  auto; }` so eight columns scroll inside their own box on a phone instead of sliding
  the whole page sideways; `.pill` / `.pill-active` / `.pill-hidden` (hidden is grey, not
  red — it is a state, not a fault); `.btn-small`, `.btn-danger`, `.field-hint`, and a
  real `.visually-hidden` rule, which the project did not have. Existing
  `.auth-check` and `.card-note` selectors were extended rather than duplicated, and
  `.panel-form` now styles `select` and `textarea`, which it did not before.
* `js/script.js` — `setUpProductKind()` hides the flower count when the kind is a single
  flower, and `setUpConfirmButtons()` puts a `window.confirm` in front of any
  `[data-confirm]` button. Both enhancements only: the server forces `stem_count` to NULL
  regardless, and with no JavaScript the delete button works on the first press — which
  is why the real guard is that the product must be hidden first.

**Design decisions worth keeping** — hiding is the reversible action and the one to
reach for; a real `DELETE` exists so a mistyped test row is not permanent clutter, but
only from an already-hidden row, and the guard is in the statement
(`DELETE ... WHERE id = :id AND is_active = 0`) rather than a read-then-write pair that a
row going back on sale in between could slip through. A duplicate name is refused by a
check in PHP, not a unique index: adding one is a migration that would also have to
decide what happens to existing duplicates, and one admin cannot race themselves. The
duplicate check is case-insensitive for free, because the column collates
`utf8mb4_unicode_ci`.

**How it was tested** — 462 assertions, all passing, over Apache, the database and real
Chrome: 26 on the guard, 82 on the list, 155 on adding a product, 116 on editing and the
row actions, 16 on the dashboard, and 67 in Chrome over the DevTools Protocol at 1280×800
and 390×844. Notable coverage: a hostile `status`, `kind`, `sort` or `page` falls back
instead of erroring, and `sort=id; DROP TABLE products` leaves the table where it is;
page 2 never repeats page 1; every refusal was checked to have written **nothing** —
blank, 121-character and duplicate names (including a differing-case duplicate), an
unknown kind, ten bad prices, six bad flower counts, an over-long colour and description
— while the boundaries (120, 40, 2000, 1 and 999, `99999999.99`) were checked to be
accepted; a submitted `stem_count` of 12 on a single flower still writes NULL; a draft is
listed for the admin and absent from the shop, and its public page 404s without leaking
its name; an edit leaves a pre-set `image_path` and a back-dated `created_at` exactly as
they were; delete on a visible row is refused and the row survives; and the JavaScript
confirmation, driven for real in Chrome, deletes nothing when it is dismissed.

Reviewing the screenshots rather than the numbers is what confirmed the phone layout:
the table scrolls inside its own box with the page still square, and scrolling it to the
end brings Status, Added and all three row buttons into view. No layout faults were found
this time. Codes were read from `%TEMP%\capstone-mail.log` with `MAIL_TRANSPORT` still
`'log'` — no mail left the machine. Throwaway products, both throwaway accounts and their
cascaded rows, the mail log, every temp script and the screenshots are deleted; `users`,
`email_codes` and `trusted_devices` are empty and `products` is back to exactly twelve
active rows, six of them bouquets, with no `image_path` set. Only
`products.AUTO_INCREMENT` shows the tests happened, which nothing reads.

### Customer — done 2026-09-02

* `sql/products-upgrade.sql` — `products`: one table for both kinds, split by
  `kind ENUM('bouquet','flower')` with `stem_count` NULL for a single stem.
  `DECIMAL(10,2) UNSIGNED` for money, never a float. `is_active` is
  published-or-hidden only; a real stock count belongs to Inventory.
* `sql/products-seed.sql` — twelve rows (six bouquets ₱380–₱1,150, six stems ₱45–₱110),
  `INSERT ... WHERE NOT EXISTS` on the name so re-running adds nothing.
* `sql/email-change-upgrade.sql` — `'email_change'` added to `email_codes.purpose`,
  `users.pending_email` added. Deliberately not UNIQUE: two people may both have a
  pending claim on one address and whoever confirms first wins on `uq_users_email`,
  which the confirm step catches and explains.
* `sql/schema.sql` — the same three changes, for a fresh install.
* `php/config/products.php` — `CURRENCY_SYMBOL` (₱, written as `\u{20B1}` so the
  source stays ASCII), `formatPrice()`, `fetchProducts()`, `countProducts()`,
  `fetchProduct()`. The only place that builds SQL from request input: `kind` and
  `sort` are keys into fixed maps because `ORDER BY` cannot be bound, `%` and `_` in a
  search term are escaped with an explicit `ESCAPE '!'`, and LIMIT/OFFSET are bound
  `PDO::PARAM_INT` because `ATTR_EMULATE_PREPARES => false` would otherwise send them
  as strings. Every sort ends in an `id` tie-break — the seeded rows share a
  `created_at`, so without it page 2 repeats rows from page 1.
* `php/config/flash.php` — toasts that survive a redirect, so a successful profile
  POST can redirect instead of re-saving on refresh. Failures still re-render with the
  values intact.
* `php/config/twofactor.php` — subject and body now `match` all three purposes. The
  signup and login wording is byte-identical to before; Foundation's tests assert it.
* `php/config/devices.php` — `forgetAllDevices()`. A password changed because someone
  else knows it now revokes **every** trusted device on that account, not just this
  browser, so their cookie stops skipping the emailed code.
* `php/components/product-card.php`, `php/products.php`, `php/product.php`,
  `php/dashboard.php`, `php/profile.php`, `php/index.php` (hero, featured row, three
  points). The filter row is a real `<form method="get">` with a visible Apply button;
  the script only makes the two selects auto-submit. **No `images/` folder was
  created** — a card with no photo draws a styled placeholder tile.
* `php/components/navbar.php` — `#products` now points at `php/products.php`, and the
  "Hi, *name*" greeting became a link to the dashboard rather than a fifth nav item in
  a row that already wraps on mobile.
* `js/script.js` — `setUpPasswordRules()` is off its hard-coded ids and binds by
  `data-password-rules="<input id>"`, so the profile page carries its own checklist;
  `setUpCatalogueFilters()` added. `php/login.php` got the matching attribute.
* `css/main.css` — catalogue, card, product, dashboard and profile sections in the
  existing flat style and green palette. `.btn`/`.btn-primary` introduced rather than
  stretching `.auth-submit` over pages it was not written for.

**How it was tested** — 438 assertions, all passing: 59 on the public pages, 67 on
paging and the product page, 115 on the account pages, 141 as a full Foundation
regression (this task edited `twofactor.php`, `navbar.php`, `login.php` and
`script.js`), and 56 in real Chrome over the DevTools Protocol at 1280×800 and
390×844. Codes were read out of `%TEMP%\capstone-mail.log` with `MAIL_TRANSPORT`
still `'log'` — no mail left the machine. Notable coverage: hostile `sort`/`kind`/`q`
fall back instead of erroring; page 2 never repeats page 1 under any sort; a hidden
row 404s without leaking its name; a wrong code counts down and the fifth throws the
row away; the resend throttle holds and starting an email change over while throttled
is refused *before* `pending_email` is rewritten; a completed email change leaves
`email_verified_at` set; a password change revokes that user's devices only, and the
old password and old address both stop working at sign-in.

Two phone-only faults came out of reviewing the screenshots rather than the
measurements, and both were fixed: the catalogue was one card per row at 390px, so
twelve products made a 5,344px scroll (a 150px grid floor under 520px gives two
across — 2,102px), and the search field's `flex: 1 1 200px` becomes a *height* once
the filter row stacks, leaving the box floating below its own label.

Throwaway accounts, their `email_codes` and `trusted_devices` rows, every temp script
and the screenshots are deleted; `users`, `email_codes` and `trusted_devices` are
empty and `products` is back to exactly twelve active rows.

### Foundation — done 2026-09-01

Kept as the record of what exists, since later modules build on all of it.

* `sql/roles-upgrade.sql` and `sql/schema.sql` — `role ENUM('customer','admin') NOT
  NULL DEFAULT 'customer'`. The default is why the sign-up `INSERT` never mentions
  it. Migration applied to the live database and confirmed safe to re-run.
* `php/config/paths.php` — `url()`, `asset()` (filemtime cache-buster), `SITE_NAME`,
  and `basePath()` derived from `DOCUMENT_ROOT`, so pages work from any depth and
  renaming the project folder does not break every link.
* `php/config/auth.php` — `ROLE_CUSTOMER`/`ROLE_ADMIN`, `signInUser()`,
  `normaliseRole()`, `currentUserRole()`, `isAdmin()`, `homeUrlForRole()`,
  `requireLogin()`, `requireAdmin()` (403, not a silent redirect). Unknown or
  missing roles fall to customer — the failure grants nothing.
* `php/components/header.php` (`renderHeader($title, $modules = [])` — the second
  parameter arrived with the 3D Customizer and defaults to `[]`) and `footer.php`
  (`renderFooter()`). `navbar.php` is now just the `<nav>`. `index.php`,
  `customize.php`, `login.php`, `verify.php` and `admin/dashboard.php` all use them.
* `php/admin/guard.php` + `php/admin/dashboard.php` (a stub — content is the Admin
  module). `php/tools/make-admin.php [--demote] <email>` makes the first admin.
* Role is read from the database at sign-in, never carried in
  `$_SESSION['pending_2fa']`, so a promotion applies at the next sign-in and stale
  session state can never confer admin.

**How it was tested** — 54 assertions over Apache and against the database: admin
page 302 signed out / 403 as a customer / 200 as an admin; sign-up creates a
customer; the CLI tool promotes, demotes, is idempotent, and refuses unknown,
unverified and malformed addresses; **both** sign-in paths carry the role, including
the trusted-device shortcut that skips the code; 2FA regression (wrong password,
wrong code, resend throttle, correct code); one doctype and one real title on every
page with no `<link>` in `<body>`; throwaway account and its cascaded rows removed.
Plus a Chrome check at 1280×800 and 390×844 — sticky flex navbar, correct active
link, hamburger hidden on desktop, and opening and closing on mobile.

## RULES

* Inspect existing code before modifying it.
* Don't rebuild working features.
* Don't mark anything complete until tested.
* Keep changes compatible with existing code.
* After finishing a task, report: `Done → Next → Issues`.
