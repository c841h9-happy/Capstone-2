<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
ensureSession();

// POST only: a plain link could be fired by another site's <img> tag or by a
// browser prefetch, logging people out without them asking.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo(url('php/index.php'));
}

// POST was never the whole answer, only the cheap half of it: another site can post a form here
// as easily as it can load an image. The token is what makes the request provably ours. A refused
// logout leaves them signed in, which is what the toast says.
requireCsrfTokenOrRedirect(url('php/index.php'));

// Drop the data, expire the cookie, then destroy the session itself.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
redirectTo(url('php/index.php'));
