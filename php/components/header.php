<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

/**
 * Open a page: everything from the doctype down to the navbar.
 *
 * Pair it with renderFooter(). Between the two, a page writes only its own
 * content:
 *
 *     renderHeader('Sign in');
 *     ... the page ...
 *     renderFooter();
 *
 * This exists because the shell used to be copied into every page, and
 * components/navbar.php carried a second copy of its own -- doctype, <head>,
 * <body> and all -- which then got included inside the page's. Every page was
 * shipping two nested documents and two <title>s, one of them "Document". One
 * function means there is one of each again, and a change to the <head> is a
 * change in one file.
 *
 * $title is the page's own part of the title; the site name is appended here.
 *
 * ensureSession() still belongs at the top of the page rather than in here.
 * Starting a session sends a Set-Cookie header, and by the time this function
 * runs the page has already begun -- a page that leaves it out would fail
 * confusingly at the point where output starts, and only for logged-in visitors.
 *
 * $modules is for pages that need an ES module of their own, which so far is
 * php/customize.php and its Three.js builder. Optional and defaulted, so none of
 * the sixteen existing calls changes.
 *
 * A module tag rather than another defer script because js/customizer.js has
 * imports; a classic script cannot have those. It goes here rather than at the
 * end of the body because every other asset on this site is declared in one
 * place, and type="module" defers by itself -- so there is nothing to gain by
 * making this the exception.
 *
 * @param array<int, string> $modules Paths relative to the project root, as asset() takes them.
 */
function isAdminPage(): bool
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    return isAdmin() && (str_contains($script, '/php/admin/') || str_ends_with($script, '/php/dashboard.php') || str_ends_with($script, '/php/profile.php'));
}

function renderHeader(string $title, array $modules = []): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
/*
 * The favicon, which this site did not have until 2026-09-21: every tab showed the browser's blank
 * sheet of paper, including the admin's, and a shop with twenty tabs open looks abandoned that way.
 *
 * It points at images/favicon.png, not at the logo itself, and the difference is the whole reason
 * that file exists. images/Logo.jpg is a circular badge with the shop's name curved around the rim;
 * at the 16px a browser tab actually paints, the rim text and the ring vanish and what is left is a
 * pale grey disc with a smudge in it -- checked, not assumed, by downscaling it to 16px and reading
 * the pixels back. favicon.png is that same artwork cropped to the flower, which at 16px is still
 * recognisably a flower. It is a crop of the user's own file and nothing has been redrawn. Delete
 * it and change this line back to 'images/Logo.jpg' to undo the whole idea.
 *
 * PNG rather than .ico: GD is off in this XAMPP so there is nothing to convert with, and every
 * browser this project runs in has taken PNG favicons for a decade.
 *
 * It goes through asset() like every other file, which matters more for this one than for most --
 * a favicon is about the most stubbornly cached thing a browser holds, and ?v=<filemtime> is what
 * makes a replaced logo actually show up in the tab.
 */
?>
    <link rel="icon" href="<?php echo escape(asset('images/favicon.png')); ?>" type="image/png">
    <link rel="stylesheet" href="<?php echo escape(asset('css/main.css')); ?>">
    <link rel="stylesheet" href="<?php echo escape(asset('css/components.css')); ?>">
    <script src="<?php echo escape(asset('js/script.js')); ?>" defer></script>
<?php foreach ($modules as $module): ?>
    <script type="module" src="<?php echo escape(asset((string) $module)); ?>"></script>
<?php endforeach; ?>
    <title><?php echo escape($title . ' | ' . SITE_NAME); ?></title>
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <?php if (isAdminPage()): ?>
        <div class="admin-layout">
            <aside class="admin-sidebar" aria-label="Admin navigation">
                <div class="admin-sidebar-header">
                    <span class="admin-sidebar-kicker">Admin</span>
                    <strong>Control panel</strong>
                </div>

                <nav class="admin-sidebar-nav" aria-label="Admin sections">
                    <?php
                    $adminLinks = [
                        ['php/admin/dashboard.php', 'Dashboard'],
                        ['php/admin/orders.php', 'Orders'],
                        ['php/admin/products.php', 'Products'],
                        ['php/admin/materials.php', 'Materials'],
                        ['php/admin/components.php', 'Components'],
                        ['php/admin/inventory.php', 'Component stock'],
                        ['php/admin/events.php', 'Events'],
                        ['php/admin/customers.php', 'Customers'],
                        ['php/admin/reports.php', 'Reports'],
                        ['php/profile.php', 'Settings'],
                    ];

                    foreach ($adminLinks as [$path, $label]):
                        $active = ($_SERVER['SCRIPT_NAME'] ?? '') === url($path) ? ' active' : '';
                        ?>
                        <a class="admin-sidebar-link<?php echo $active; ?>" href="<?php echo escape(url($path)); ?>"><?php echo escape($label); ?></a>
                    <?php endforeach; ?>
                </nav>

                <form class="admin-sidebar-logout" method="post" action="<?php echo escape(url('php/logout.php')); ?>">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="admin-sidebar-link admin-sidebar-logout-button">Log out</button>
                </form>
            </aside>

            <div class="admin-main-content">
    <?php endif; ?>
    <?php
}
