<?php

declare(strict_types=1);

/**
 * A password input with a show/hide eye button beside it.
 *
 * The button ships with the `hidden` attribute and js/script.js reveals it --
 * with no JavaScript it could not toggle anything, so it should not be on the
 * page at all rather than sit there doing nothing.
 *
 * Options: id, name, autocomplete, and optionally describedBy (an element id
 * for aria-describedby) and attributes (extra markup such as maxlength).
 */
function renderPasswordField(array $options): void
{
    $id = $options['id'];

    // Collected into one string so an absent option leaves no blank line in the
    // page source.
    $extra = [];

    if (isset($options['describedBy'])) {
        $extra[] = 'aria-describedby="' . escape($options['describedBy']) . '"';
    }

    if (isset($options['attributes'])) {
        $extra[] = $options['attributes'];
    }
    ?>
    <div class="password-field">
        <input
            id="<?php echo escape($id); ?>"
            name="<?php echo escape($options['name']); ?>"
            type="password"
            autocomplete="<?php echo escape($options['autocomplete']); ?>"
            <?php echo $extra === [] ? '' : implode(' ', $extra) . "\n            "; ?>required>

        <button
            type="button"
            class="password-toggle"
            data-password-toggle="<?php echo escape($id); ?>"
            aria-label="Show password"
            aria-pressed="false"
            hidden>
            <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
            <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
        </button>
    </div>
    <?php
}
