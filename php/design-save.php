<?php
// Nothing but redirects come out of this file, so nothing may be printed before them.
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/designs.php';
require_once __DIR__ . '/config/flash.php';
require_once __DIR__ . '/config/csrf.php';
ensureSession();
requireLogin();

/*
 * Keeping a bouquet. The only write in the 3D Customizer module.
 *
 * A separate file from php/customize.php rather than a POST branch at the top of it, for the
 * reason the admin's own actions are separate files: customize.php is a page that renders, and
 * this is an action that writes and then sends you somewhere. Mixing the two means every
 * render of the builder runs past a block of write logic it will never take.
 *
 * Never renders. Every path ends in redirectTo(), which is what makes a refresh after saving
 * harmless: the browser's last request is the GET it was sent to, not the POST that wrote.
 *
 * requireLogin() and not requireAdmin(): a saved design belongs to whoever built it, and an
 * admin is also a person with an account.
 *
 * The design arrives as a query string in a hidden field rather than being re-read from
 * $_GET, so what is saved is the bouquet whose price was printed next to the button --
 * including on the enhanced path, where the script has been rewriting that field while
 * somebody clicked. It is parsed and then put through readDesign() exactly as a URL is, so a
 * hand-forged field cannot save a design the builder would refuse to draw.
 */
$userId = (int) $_SESSION['user_id'];
$back = url('php/customize.php');

/*
 * A GET here is somebody typing the address in, or a bookmark of a POST. Neither is an error
 * worth a page: the builder is where they were going.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo($back);
}

/*
 * Signed in is not the same as meant it. requireLogin() above proves there is an account; this
 * proves the account's own browser was looking at one of our pages when the form went off, rather
 * than at another site's page holding a form aimed here. Delete is the one that matters: a
 * bouquet somebody built is gone and nothing puts it back.
 */
requireCsrfTokenOrRedirect($back);

$action = (string) ($_POST['action'] ?? 'save');

/*
 * Rename and delete post here too, from php/designs.php. One file for the three writes,
 * because they are the same write in three shapes -- find this account's design, change it,
 * flash, go back -- and three files would be three copies of the ownership check.
 */
$designId = filter_var($_POST['design_id'] ?? null, FILTER_VALIDATE_INT);
$designId = $designId === false ? 0 : (int) $designId;

$check = validateDesignName($_POST['name'] ?? null);

try {
    if ($action === 'delete') {
        /*
         * No name to validate: a delete names a row, not a bouquet. Answered the same whether
         * the design was somebody else's or never existed -- deleteSavedDesign() scopes by
         * user_id, so both come back false.
         */
        if ($designId > 0 && deleteSavedDesign($userId, $designId)) {
            flashToast('success', 'That bouquet has been deleted.');
        } else {
            flashToast('error', 'We could not find that bouquet to delete.');
        }

        redirectTo(url('php/designs.php'));
    }

    if ($action === 'rename') {
        if ($check['errors'] !== []) {
            flashToast('error', $check['errors'][0]);
            redirectTo(url('php/designs.php'));
        }

        if ($designId > 0 && renameSavedDesign($userId, $designId, $check['name'])) {
            flashToast('success', 'Renamed to "' . $check['name'] . '".');
        } else {
            flashToast('error', 'We could not find that bouquet to rename.');
        }

        redirectTo(url('php/designs.php'));
    }

    /*
     * Saving. The parts list is read here rather than trusted from the form, because a price
     * and a colour are the shop's to state and a form is the customer's to fill in.
     */
    $parts = fetchComponentsByKind();

    /*
     * parse_str rather than splitting on '&' by hand: it is what un-does http_build_query,
     * including the mix[1]=4 brackets designQuery() wrote, and it is the same parser $_GET
     * came out of. So the hidden field and a pasted URL are read identically.
     */
    $query = [];
    parse_str((string) ($_POST['design'] ?? ''), $query);

    $design = readDesign($query, $parts);

    /*
     * A bouquet with no flowers in it is not a bouquet. Reachable only by saving from a page
     * with every bloom withdrawn, or by forging the field -- but a saved design with an empty
     * mix would be a name with nothing under it, and it would come back from
     * readSavedDesign() looking like a design whose parts had all been withdrawn.
     */
    if ($design['blooms'] === []) {
        flashToast('error', 'Add at least one flower before saving.');
        redirectTo($back);
    }

    if ($check['errors'] !== []) {
        flashToast('error', $check['errors'][0]);
        redirectTo($back . '?' . designQuery($design));
    }

    $savedId = saveDesign($userId, $check['name'], $design);

    flashToast('success', 'Saved "' . $check['name'] . '" to your account.');

    /*
     * Back to the builder with the design open, rather than to the list. Saving is not
     * finishing: the most likely next thing is another adjustment, and ?design= means the page
     * comes back with the name already in the box, so a second save updates the same bouquet
     * rather than making a near-identical one.
     */
    redirectTo($back . '?design=' . $savedId);
} catch (RuntimeException $exception) {
    /*
     * The two refusals saveDesign() and renameSavedDesign() raise deliberately: the per-account
     * cap, and a name taken between the check and the write. Both carry a sentence written for
     * a customer, so it is shown as it is.
     */
    flashToast('error', $exception->getMessage());
    redirectTo($action === 'save' ? $back : url('php/designs.php'));
} catch (PDOException $exception) {
    error_log('design-save.php: ' . $exception->getMessage());

    flashToast('error', 'We could not save that just now. Nothing has been changed -- try again in a moment.');
    redirectTo($action === 'save' ? $back : url('php/designs.php'));
}
