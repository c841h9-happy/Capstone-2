<?php

declare(strict_types=1);

/**
 * Close a page opened by renderHeader(), and draw the site footer.
 *
 * It was two closing tags until 2026-09-21, with a note saying a real footer
 * belonged here when one existed. One does now: the user's logo carries the
 * line "BRINGING BLOOMS TO OUR COMMUNITY" and it appeared nowhere in the site's
 * own words, so the shop had a tagline that only existed as a picture.
 *
 * Deliberately small. It names the shop, says the line, and dates the copyright
 * -- no link columns, no contact block, no newsletter box. Every link a visitor
 * needs is in the navbar at the top of the same page, and a second copy of the
 * navigation is a second thing to keep in step with the first.
 *
 * It appears on the admin pages too, because this is the one function every page
 * in the project closes with and a special case would have to be maintained by
 * everyone who ever adds a page. It is the same shop either way.
 */
function renderFooter(): void
{
    if (isAdminPage()) {
        echo '</div>'; // .admin-main-content
        echo '</div>'; // .admin-layout
    }
    ?>
    <footer class="site-footer">
        <div class="site-footer-inner">
            <div>
                <p class="site-footer-name"><?php echo escape(SITE_NAME); ?></p>
                <?php
                /*
                 * Written in sentence case and uppercased by CSS, rather than typed in capitals.
                 * Some screen readers spell out a fully capitalised phrase letter by letter, and
                 * this one would be read as an acronym rather than a sentence.
                 */
                ?>
                <p class="site-footer-tagline">Bringing blooms to our community</p>
            </div>
            <?php
            /*
             * date('Y') rather than a typed year, because a hardcoded one is wrong every January
             * and nobody notices until a customer does.
             */
            ?>
            <p class="site-footer-legal">
                &copy; <?php echo date('Y'); ?> <?php echo escape(SITE_NAME); ?>.
                Handmade to order.
            </p>
        </div>
    </footer>
</body>
</html>
<?php
}
