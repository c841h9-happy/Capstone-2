<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Toast messages that survive a redirect.
 *
 * php/login.php and php/verify.php re-render themselves on POST and keep their
 * messages in local variables, which is right for a form you may still need to
 * correct -- the values you typed are still in the boxes.
 *
 * It is the wrong shape for a form that succeeded. A page that answers POST with
 * HTML leaves that POST as the current request, so a refresh offers to send it
 * again: refreshing after "Password updated" would try to update it a second time,
 * and after an email change would re-request the code. The fix is to answer a
 * successful POST with a redirect and let the browser GET the result -- but a
 * redirect throws away every local variable, which is where these two functions
 * come in.
 *
 * The message is put in the session by the request that did the work, and taken out
 * by the request that renders the page. Nothing else changes: what comes out is the
 * same list of ['type' => ..., 'message' => ...] that renderToasts() in
 * php/components/toast.php already draws.
 */

/** Where the pending messages sit. Named so nothing else collides with it. */
const FLASH_SESSION_KEY = 'flash_toasts';

/**
 * Leave a message for the next page this browser loads.
 *
 * $type is 'success' or 'error', matching renderToasts(). Call it before the
 * redirect, and before any output -- it may have to start the session.
 */
function flashToast(string $type, string $message): void
{
    ensureSession();

    $_SESSION[FLASH_SESSION_KEY][] = [
        'type' => $type === 'success' ? 'success' : 'error',
        'message' => $message,
    ];
}

/**
 * Take every pending message, clearing them as it goes.
 *
 * Taking rather than reading is the whole contract: a message left in the session
 * would reappear on the next page, and then the one after that. Merge the result
 * with whatever the current request has to say of its own before handing it to
 * renderToasts():
 *
 *     $toasts = takeFlashToasts();
 *     foreach ($errors as $error) { $toasts[] = ['type' => 'error', 'message' => $error]; }
 *
 * @return array<int, array{type: string, message: string}>
 */
function takeFlashToasts(): array
{
    ensureSession();

    $toasts = $_SESSION[FLASH_SESSION_KEY] ?? [];
    unset($_SESSION[FLASH_SESSION_KEY]);

    return is_array($toasts) ? $toasts : [];
}
