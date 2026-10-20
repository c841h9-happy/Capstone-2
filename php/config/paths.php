<?php

declare(strict_types=1);

/**
 * Building URLs that work from any directory.
 *
 * Every link and stylesheet in this project used to be written relative to
 * php/ -- "../css/main.css", href="index.php". That holds exactly as long as
 * every page lives in php/ and breaks the moment one does not: from
 * php/admin/dashboard.php, "../css/main.css" points at php/css/main.css and
 * href="index.php" points at php/admin/index.php. Neither exists.
 *
 * So paths are written from the project root instead -- url('php/index.php'),
 * asset('css/main.css') -- and these two functions turn them into absolute URLs.
 * A page can then be moved into a subfolder without rewriting its links.
 */

/**
 * The site's name, as it appears in the navbar, the footer and every page title.
 *
 * Here rather than typed into each page so renaming the site is one edit -- which it was, on
 * 2026-09-21. This said "Crafts" until then, while the logo artwork reads "SKY AND LJ'S CRAFT".
 * The user's call was that the logo is right and the site was wrong, so the singular is the
 * shop's actual name and the plural was the slip.
 */
const SITE_NAME = 'Sky and LJ\'s Craft';

/**
 * Where this site answers from, with the scheme and no trailing path: "http://localhost".
 *
 * Only siteUrl() reads it, and only email needs siteUrl() -- see the argument there for why
 * this is a written-down value rather than $_SERVER['HTTP_HOST']. Nothing on a page uses it,
 * so getting it wrong breaks links in messages and nothing else.
 */
const SITE_HOST = 'http://localhost';

/**
 * The URL path this project is served from, with a trailing slash: "/Capstone/".
 *
 * Worked out by asking where the project sits inside Apache's document root
 * rather than being hard-coded, so renaming the folder does not silently break
 * every link on the site.
 */
function basePath(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    // Under CLI there is no document root to compare against, so the tools in
    // php/tools/ fall back to this. They only need it if they print a URL.
    $fallback = '/Capstone/';

    $projectRoot = realpath(__DIR__ . '/../..');
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($projectRoot === false || $documentRoot === false) {
        return $basePath = $fallback;
    }

    // Windows hands back backslashes, and DOCUMENT_ROOT arrives with forward
    // slashes -- C:\xampp\htdocs\Capstone against C:/xampp/htdocs. Without this
    // the two never line up and every URL falls back.
    $projectRoot = str_replace('\\', '/', $projectRoot);
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    // The project served as the site root itself.
    if (strcasecmp($projectRoot, $documentRoot) === 0) {
        return $basePath = '/';
    }

    // stripos, not strpos: Windows paths differ in case for the same directory,
    // so C:\xampp against c:/xampp has to count as a match.
    if ($documentRoot !== '' && stripos($projectRoot, $documentRoot . '/') === 0) {
        return $basePath = substr($projectRoot, strlen($documentRoot)) . '/';
    }

    return $basePath = $fallback;
}

/**
 * An absolute URL for a page, from a path relative to the project root.
 *
 *     url('php/login.php')  ->  /Capstone/php/login.php
 *
 * Also the right thing to pass redirectTo(): a Location header holding a bare
 * "index.php" is resolved against the current directory, which is how a redirect
 * out of php/admin/ ends up somewhere that does not exist.
 */
function url(string $path = ''): string
{
    return basePath() . ltrim($path, '/');
}

/**
 * A fully qualified URL for a page: scheme, host and all.
 *
 *     siteUrl('php/order.php')  ->  http://localhost/Capstone/php/order.php
 *
 * url() above is root-relative, which is the right thing for every link on a page and the
 * wrong thing for the one place this project has that is not a page: an email. "/Capstone/
 * php/order.php" in an inbox is not clickable -- there is no page for a mail client to
 * resolve it against -- so a message that wants to link to an order needs the host spelled
 * out.
 *
 * Read from SITE_HOST rather than from $_SERVER['HTTP_HOST'], and that is the whole reason
 * this is a constant and not a derivation. Mail is sent from two places with no request
 * behind them: php/tools/ under the CLI, where HTTP_HOST does not exist at all, and any
 * later scheduled job. Worse, HTTP_HOST is whatever the client sent -- trusting it would let
 * a forged Host header decide where a real customer's email points, which is a genuine
 * phishing vector in a project whose mail carries order links.
 *
 * One line to change if this site ever moves to a real domain, and it is the only line.
 */
function siteUrl(string $path = ''): string
{
    return rtrim(SITE_HOST, '/') . url($path);
}

/**
 * An absolute URL for a stylesheet, script or image, with a cache-busting suffix.
 *
 *     asset('css/main.css')  ->  /Capstone/css/main.css?v=1756612345
 *
 * The suffix is the file's last-modified time, so a browser fetches the new copy
 * the moment the file changes and keeps using its cached copy the rest of the
 * time. Without it, editing a stylesheet and reloading shows the old one until
 * the cache is cleared by hand -- which reads exactly like a broken change.
 */
function asset(string $path): string
{
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    $version = is_file($absolute) ? (string) filemtime($absolute) : '';

    return url($path) . ($version === '' ? '' : '?v=' . $version);
}
