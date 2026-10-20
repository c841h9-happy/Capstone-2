<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to
// run before the redirects and the 302 below, which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/events-admin.php';
require_once __DIR__ . '/../config/products.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Events and promos.
 *
 * An event is a dated occasion -- Valentine's, Mother's Day, a fiesta week -- carrying one featured
 * flower for the home page and a whole-percent discount over a hand-picked set of products.
 *
 * This is php/admin/components.php's screen with the same filter/sort/search/paging shape, so all
 * eight admin lists behave identically. Two differences worth naming.
 *
 * The status filter is four states, not two, because "is it switched on" and "is it running" are
 * different questions and the shop needs both: an event can be drafted ahead (Upcoming), be live
 * (On now), have passed (Finished), or be pulled without losing its dates (Switched off). See
 * eventStateLabel() in config/events-admin.php.
 *
 * Switching off is the reversible action and the one to reach for. A real DELETE is offered only on
 * an event that is already switched off, so no single mis-click and no single forged cross-site
 * request can remove a promo the shop is advertising. Editing is on php/admin/event-edit.php.
 */

const EVENTS_PER_PAGE = 12;

$state = in_array($_GET['state'] ?? '', ['on', 'upcoming', 'finished', 'off'], true)
    ? (string) $_GET['state']
    : '';

$sort = array_key_exists((string) ($_GET['sort'] ?? ''), EVENT_SORTS) ? (string) $_GET['sort'] : 'soonest';

// is_string first: ?q[]=x makes $_GET['q'] an array.
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$page = is_numeric($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;

/**
 * A link to this page with the filters on it.
 *
 * Empty values, the default sort and page 1 are left out, so the plain list stays at
 * /Capstone/php/admin/events.php with nothing after it.
 */
function adminEventsUrl(array $params = []): string
{
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 1
    );

    return url('php/admin/events.php') . ($query === [] ? '' : '?' . http_build_query($query));
}

/*
 * Everything on the page carries the filters that are already on, so switching off the third row of
 * a filtered page 2 leaves the admin looking at that same page rather than back at the top of an
 * unfiltered list hunting for where they were.
 */
$filterParams = [
    'state' => $state,
    'sort' => $sort === 'soonest' ? '' : $sort,
    'q' => $search,
];

$listUrl = adminEventsUrl($filterParams + ['page' => $page]);

/*
 * Switch on, switch off, delete.
 *
 * Answered with a redirect and a flashed message rather than by rendering the list inline -- a POST
 * left as the current request means a refresh offers to send it again, and "delete" is not a thing
 * to offer twice. That includes the failures: a refused delete is not a form to correct, so there is
 * nothing to keep on screen.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = productInputText($_POST, 'action');
    $targetId = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;

    try {
        // Read first, for the name. A message naming the event is worth one extra query, and it is
        // the only way to tell "switched off" from "never there".
        $target = adminFetchEvent($targetId);

        if ($target === null) {
            flashToast('error', 'That event is no longer there. The list below is up to date.');
        } elseif ($action === 'off') {
            setEventActive($targetId, false);
            flashToast('success', '"' . $target['name'] . '" is switched off. Nothing on the shop mentions it now.');
        } elseif ($action === 'on') {
            setEventActive($targetId, true);

            /*
             * Switching one on says what it will actually do, which is not always what the button
             * implies: an event whose dates have passed is on in the sense of being published and
             * will still never appear, and that is confusing enough to be worth a sentence.
             */
            flashToast(
                'success',
                eventIsOnNow(['is_active' => 1] + $target)
                    ? '"' . $target['name'] . '" is on. It is live on the shop now.'
                    : '"' . $target['name'] . '" is switched on, but its dates are ' . eventDateLabel($target)
                        . ' so it is not showing yet.'
            );
        } elseif ($action === 'delete') {
            /*
             * Only an event that is already switched off, enforced in deleteEvent() as well as by
             * the button not being drawn -- so getting here with a live event means a stale page or
             * a request made by hand, and it is refused rather than warned about.
             *
             * event_products goes with it by CASCADE, and that costs nothing: what a customer
             * actually paid during the promo is frozen in their basket and order lines, not here.
             */
            if (deleteEvent($targetId)) {
                flashToast('success', '"' . $target['name'] . '" is deleted.');
            } else {
                flashToast('error', 'Switch "' . $target['name'] . '" off first, then it can be deleted.');
            }
        } else {
            flashToast('error', 'That is not something this page can do.');
        }
    } catch (PDOException $exception) {
        error_log('admin/events.php: ' . $exception->getMessage());
        flashToast('error', 'That did not go through. Nothing has changed.');
    }

    redirectTo($listUrl);
}

/*
 * The load, guarded like every other admin list: a missing migration or a lost connection leaves an
 * empty state and the rest of the screen standing rather than a stack trace.
 */
$events = [];
$total = 0;
$grandTotal = 0;
$loadFailed = false;

try {
    $total = adminCountEvents($filterParams);
    $grandTotal = adminCountEvents();

    // ?page=99 clamps to the last page rather than showing an empty table, the same as every other
    // list here.
    $lastPage = max(1, (int) ceil($total / EVENTS_PER_PAGE));
    $page = min($page, $lastPage);

    $events = adminFetchEvents($filterParams + ['sort' => $sort], EVENTS_PER_PAGE, ($page - 1) * EVENTS_PER_PAGE);
} catch (PDOException $exception) {
    error_log('admin/events.php load: ' . $exception->getMessage());
    $loadFailed = true;
}

$toasts = takeFlashToasts();

renderHeader('Events');
renderToasts($toasts);
?>

    <main class="page">
        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>Events</h1>
            <p class="page-intro">
                An occasion with a featured flower and a promo. One event at a time shows on the shop
                &mdash; if two overlap, the one that started most recently wins.
                <a href="<?php echo escape(url('php/admin/dashboard.php')); ?>">Back to the Dashboard</a>.
            </p>
        </header>

        <!--
            The same filter row the catalogue and the other admin lists use. A real GET form with a
            real Apply button; script.js makes the dropdowns submit on change. No hidden page field,
            so changing a filter starts again at page 1 rather than landing on a page that no longer
            exists.
        -->
        <form class="catalogue-filters" method="get" action="<?php echo escape(url('php/admin/events.php')); ?>" data-catalogue-filters>
            <div class="filter-field">
                <label for="filter-state">Showing</label>
                <select id="filter-state" name="state" data-filter-select>
                    <option value="" <?php echo $state === '' ? 'selected' : ''; ?>>All</option>
                    <option value="on" <?php echo $state === 'on' ? 'selected' : ''; ?>>On now</option>
                    <option value="upcoming" <?php echo $state === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                    <option value="finished" <?php echo $state === 'finished' ? 'selected' : ''; ?>>Finished</option>
                    <option value="off" <?php echo $state === 'off' ? 'selected' : ''; ?>>Switched off</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-sort">Sort by</label>
                <select id="filter-sort" name="sort" data-filter-select>
                    <!-- Built from the constant, so a sort added to EVENT_SORTS turns up here on
                         its own and cannot be offered without existing. -->
                    <option value="soonest" <?php echo $sort === 'soonest' ? 'selected' : ''; ?>>Starts: newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Starts: oldest first</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name: A to Z</option>
                    <option value="discount" <?php echo $sort === 'discount' ? 'selected' : ''; ?>>Biggest discount</option>
                </select>
            </div>

            <div class="filter-field filter-field-grow">
                <label for="filter-q">Search</label>
                <input id="filter-q" name="q" type="search" value="<?php echo escape($search); ?>"
                       maxlength="100" placeholder="valentine, mother&rsquo;s day&hellip;">
            </div>

            <button type="submit" class="btn btn-primary filter-submit">Apply</button>
        </form>

        <div class="admin-toolbar">
            <?php if (!$loadFailed): ?>
                <p class="catalogue-count">
                    <?php echo $total; ?> event<?php echo $total === 1 ? '' : 's'; ?>
                    <?php if ($total !== $grandTotal): ?>
                        of <?php echo $grandTotal; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <a class="btn btn-primary" href="<?php echo escape(url('php/admin/event-edit.php')); ?>">Add an event</a>
        </div>

        <?php if ($loadFailed): ?>
            <div class="empty-state">
                <h2>We could not load the events</h2>
                <p>
                    Nothing has changed. If this is a fresh database, run
                    <code>sql\events-upgrade.sql</code> and try again.
                </p>
            </div>
        <?php elseif ($events === []): ?>
            <div class="empty-state">
                <?php if ($grandTotal === 0): ?>
                    <h2>There are no events yet</h2>
                    <p>Make one and the shop can run a promo for an occasion.</p>
                    <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/event-edit.php')); ?>">Add an event</a></p>
                <?php else: ?>
                    <h2>Nothing matches that</h2>
                    <p>Try a different word, or a wider filter.</p>
                    <p><a class="btn btn-quiet" href="<?php echo escape(url('php/admin/events.php')); ?>">Show everything</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!--
                The scroll box is on the wrapper, not the page. Seven columns do not fit a phone at
                any readable size, and a table that widens the document makes the whole page slide
                sideways -- including the navbar. Inside its own box, only the table moves.
            -->
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">When</th>
                            <th scope="col">State</th>
                            <th scope="col">Discount</th>
                            <th scope="col">On</th>
                            <th scope="col">Featured</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventId = (int) $event['id'];
                            $isOn = eventIsOnNow($event);
                            $stateLabel = eventStateLabel($event);
                            $percent = (int) $event['discount_percent'];
                            $promoCount = (int) $event['promo_count'];
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo escape(url('php/admin/event-edit.php?id=' . $eventId)); ?>">
                                        <?php echo escape((string) $event['name']); ?>
                                    </a>
                                    <?php if (trim((string) $event['tagline']) !== ''): ?>
                                        <span class="admin-cell-blank"><?php echo escape((string) $event['tagline']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo escape(eventDateLabel($event)); ?></td>
                                <td>
                                    <span class="pill <?php echo $isOn ? 'pill-active' : 'pill-hidden'; ?>">
                                        <?php echo escape($stateLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($percent > 0): ?>
                                        <?php echo escape((string) $percent); ?>% off
                                    <?php else: ?>
                                        <span class="admin-cell-blank">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($percent === 0): ?>
                                        <span class="admin-cell-blank">&mdash;</span>
                                    <?php elseif ($promoCount === 0): ?>
                                        <!--
                                            A discount with nothing ticked is the one state that
                                            looks configured and does nothing at all, so it is said
                                            out loud rather than printed as a quiet 0.
                                        -->
                                        <span class="pill pill-warn">No flowers picked</span>
                                    <?php else: ?>
                                        <?php echo escape((string) $promoCount); ?> flower<?php echo $promoCount === 1 ? '' : 's'; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($event['featured_name'] !== null): ?>
                                        <?php echo escape((string) $event['featured_name']); ?>
                                    <?php else: ?>
                                        <span class="admin-cell-blank">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form class="admin-row-actions" method="post" action="<?php echo escape($listUrl); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $eventId; ?>">

                                        <a class="btn btn-quiet btn-small" href="<?php echo escape(url('php/admin/event-edit.php?id=' . $eventId)); ?>">Edit</a>

                                        <?php if ((int) $event['is_active'] === 1): ?>
                                            <button class="btn btn-quiet btn-small" type="submit" name="action" value="off">Switch off</button>
                                        <?php else: ?>
                                            <button class="btn btn-quiet btn-small" type="submit" name="action" value="on">Switch on</button>
                                            <!--
                                                Delete is offered only on an event that is already
                                                switched off, and data-confirm puts a real
                                                window.confirm in front of it. Both halves matter:
                                                the first means no single mis-click can remove a
                                                live promo, the second means no accidental one.
                                            -->
                                            <button class="btn btn-danger btn-small" type="submit" name="action" value="delete"
                                                    data-confirm="Delete &quot;<?php echo escape((string) $event['name']); ?>&quot;? This cannot be undone.">
                                                Delete
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $lastPage = max(1, (int) ceil($total / EVENTS_PER_PAGE));
            ?>
            <?php if ($lastPage > 1): ?>
                <nav class="pager" aria-label="Pages">
                    <?php if ($page > 1): ?>
                        <a class="pager-arrow" href="<?php echo escape(adminEventsUrl($filterParams + ['page' => $page - 1])); ?>">&larr; Newer</a>
                    <?php endif; ?>

                    <p class="pager-position">Page <?php echo $page; ?> of <?php echo $lastPage; ?></p>

                    <?php if ($page < $lastPage): ?>
                        <a class="pager-arrow" href="<?php echo escape(adminEventsUrl($filterParams + ['page' => $page + 1])); ?>">Older &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
<?php renderFooter(); ?>
