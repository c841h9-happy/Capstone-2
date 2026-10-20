<?php

declare(strict_types=1);

/**
 * The only thing keeping this folder private.
 *
 * Apache serves php/admin/ like any other directory -- there is no server-side
 * rule stopping a signed-in customer, or a stranger, from typing one of these
 * URLs in. Every file in here must therefore begin with:
 *
 *     require_once __DIR__ . '/guard.php';
 *
 * before it prints anything. As its own named file rather than a line of
 * requireAdmin() buried in each page's preamble, a page that forgot it is
 * obvious at a glance -- and that matters, because forgetting it does not break
 * anything visible. The page simply works, for everybody.
 *
 * config/csrf.php comes with it, so every admin page has csrfField() for its
 * forms and requireCsrfToken() for its handler without repeating the require
 * five times. Being an admin answers "may you do this"; the token answers "did
 * you mean to", and an admin's browser is the one worth tricking.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

ensureSession();
requireAdmin();
