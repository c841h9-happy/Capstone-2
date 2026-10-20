<?php

declare(strict_types=1);

/**
 * Render pop-up notifications in a corner stack.
 *
 * $toasts is a list of ['type' => 'error'|'success', 'message' => string].
 *
 * These are rendered by PHP rather than built in JavaScript, because every
 * message here comes from the server after a form submission. Doing it this way
 * means the notification still appears with JavaScript switched off -- it just
 * stays on screen instead of fading out on a timer.
 */
function renderToasts(array $toasts): void
{
    if ($toasts === []) {
        return;
    }
    ?>
    <div class="toast-stack" data-toast-stack>
        <?php foreach ($toasts as $toast): ?>
            <?php
            $isError = ($toast['type'] ?? 'error') === 'error';
            // Errors get longer on screen: there is more to read, and acting on
            // one means retyping something.
            $duration = $isError ? 8000 : 5000;
            ?>
            <div
                class="toast toast-<?php echo $isError ? 'error' : 'success'; ?>"
                role="<?php echo $isError ? 'alert' : 'status'; ?>"
                data-toast
                data-toast-duration="<?php echo $duration; ?>">

                <?php if ($isError): ?>
                    <svg class="toast-icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                <?php else: ?>
                    <svg class="toast-icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                <?php endif; ?>

                <p class="toast-message"><?php echo escape($toast['message']); ?></p>

                <!-- Hidden until script.js runs, for the same reason as the password eye. -->
                <button type="button" class="toast-close" data-toast-close aria-label="Dismiss notification" hidden>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
