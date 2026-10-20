console.log("Hello World");

// todo: super idol

/* ====================================================================
                        Mobile navigation
==================================================================== */

function setUpNavbar() {
    const navbar = document.querySelector('[data-navbar]');
    const toggle = navbar ? navbar.querySelector('[data-nav-toggle]') : null;

    if (!toggle) {
        return;
    }

    // Tells the stylesheet the button is now working, which is what allows it to
    // collapse the menu. Setting it here rather than in the markup means a browser
    // without JavaScript keeps the menu open instead of hiding it behind a dead
    // button.
    navbar.dataset.navJs = 'true';

    function setOpen(open) {
        toggle.setAttribute('aria-expanded', String(open));

        if (open) {
            navbar.dataset.navOpen = 'true';
        } else {
            delete navbar.dataset.navOpen;
        }
    }

    toggle.addEventListener('click', function () {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    // The open menu lies over the page, so tapping the part of the page it covers
    // should put it away. Anything inside the navbar is left to its own handler --
    // that includes the button itself, which would otherwise reopen and close in
    // the same click.
    document.addEventListener('click', function (event) {
        if (toggle.getAttribute('aria-expanded') === 'true' && !navbar.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            // Put focus somewhere useful, not on whatever the menu just hid.
            toggle.focus();
        }
    });
}

function setUpProductBackLink() {
    const link = document.querySelector('[data-product-back]');

    if (!link) {
        return;
    }

    link.addEventListener('click', function (event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const referrer = document.referrer;

        if (!referrer || window.history.length <= 1) {
            return;
        }

        const previousPage = new URL(referrer);

        if (previousPage.origin !== window.location.origin || previousPage.href === window.location.href) {
            return;
        }

        event.preventDefault();
        window.history.back();
    });
}

/* ====================================================================
                        Toast notifications
==================================================================== */

// Must match the toast-out animation length in css/main.css.
const TOAST_EXIT_MS = 200;

function dismissToast(toast) {
    if (toast.dataset.leaving) {
        return;
    }

    // Marked so a click and an expiring timer cannot both remove the same toast.
    toast.dataset.leaving = 'true';
    toast.classList.add('toast-leaving');
    setTimeout(function () {
        toast.remove();
    }, TOAST_EXIT_MS);
}

function dismissAllToasts() {
    document.querySelectorAll('[data-toast]').forEach(dismissToast);
}

function setUpToasts() {
    document.querySelectorAll('[data-toast]').forEach(function (toast) {
        const closeButton = toast.querySelector('[data-toast-close]');

        // Hidden in the markup for the same reason as the password eye: with no
        // JavaScript there is nothing to close it with.
        if (closeButton) {
            closeButton.hidden = false;
            closeButton.addEventListener('click', function () {
                dismissToast(toast);
            });
        }

        const duration = Number(toast.dataset.toastDuration);

        if (!duration) {
            return;
        }

        let timer = null;

        function start() {
            timer = setTimeout(function () {
                dismissToast(toast);
            }, duration);
        }

        function stop() {
            clearTimeout(timer);
        }

        // Reading a message should not race a countdown, so pointing at the toast
        // or tabbing into it holds it open.
        toast.addEventListener('mouseenter', stop);
        toast.addEventListener('mouseleave', start);
        toast.addEventListener('focusin', stop);
        toast.addEventListener('focusout', start);

        start();
    });
}

/* ====================================================================
                    Sign in / Create account switching
==================================================================== */

function setUpAuthPanels() {
    const card = document.querySelector('[data-auth-card]');

    if (!card) {
        return;
    }

    const panels = card.querySelectorAll('[data-auth-panel]');

    function showForm(target) {
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.authPanel !== target;
        });

        card.dataset.activeForm = target;

        // A message about the form you just left has nothing to say about the one
        // you are moving to.
        dismissAllToasts();

        // Keep the URL in step so a refresh or a shared link lands on the same form.
        const url = target === 'signup' ? '?form=signup' : 'login.php';
        history.replaceState(null, '', url);
    }

    // The "New here? / Already have an account?" links swap panels in place.
    card.querySelectorAll('[data-auth-tab]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            showForm(trigger.dataset.authTab);

            const panel = card.querySelector('[data-auth-panel="' + trigger.dataset.authTab + '"]');
            const firstInput = panel ? panel.querySelector('input:not([type="hidden"])') : null;

            if (firstInput) {
                firstInput.focus();
            }
        });
    });
}

/* ====================================================================
                        Show / hide password
==================================================================== */

function setUpPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            return;
        }

        // The button is markup-hidden by default, because it can only do its job
        // once this script has run.
        button.hidden = false;

        button.addEventListener('click', function () {
            const reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');

            // Clicking the button moves focus off the field, so put the caret back
            // where the user left it instead of at the start of the value.
            const caret = input.value.length;
            input.focus();
            input.setSelectionRange(caret, caret);
        });
    });
}

/* ====================================================================
                    Live password strength checklist
==================================================================== */

// These must stay in step with passwordStrengthErrors() in php/config/auth.php.
// That function is what actually blocks a weak password; this is only a hint, so a
// mismatch is confusing rather than dangerous.
const PASSWORD_RULES = {
    // bcrypt counts bytes, so measure bytes here too -- otherwise an accented
    // password could show a tick that the server then disagrees with.
    length: function (value) { return new TextEncoder().encode(value).length >= 8; },
    lower: function (value) { return /[a-z]/.test(value); },
    upper: function (value) { return /[A-Z]/.test(value); },
    number: function (value) { return /\d/.test(value); },
    symbol: function (value) { return /[^A-Za-z0-9]/.test(value); }
};

/*
 * Every checklist on the page, each naming its own field.
 *
 * This used to look up the ids 'password-rules' and 'signup-password' directly, which
 * worked while sign-up was the only page that chose a password. php/profile.php also
 * chooses one, and the only way to get a checklist there would have been to name a
 * profile field 'signup-password' -- so the list now says which input it belongs to
 * with data-password-rules, and a page may carry as many as it likes.
 */
function setUpPasswordRules() {
    document.querySelectorAll('[data-password-rules]').forEach(function (list) {
        const input = document.getElementById(list.dataset.passwordRules);

        if (!input) {
            return;
        }

        function check() {
            list.querySelectorAll('[data-rule]').forEach(function (item) {
                const test = PASSWORD_RULES[item.dataset.rule];

                if (test) {
                    item.classList.toggle('met', test(input.value));
                }
            });
        }

        input.addEventListener('input', check);
        check();
    });
}

/* ====================================================================
                        Catalogue filters
==================================================================== */

/*
 * Make the catalogue's dropdowns apply themselves on change.
 *
 * Enhancement only. php/products.php ships a real GET form with a real Apply button,
 * so with no JavaScript the filters still work -- this just saves the second click
 * for the two controls where picking a value *is* the whole decision. The search box
 * is left alone on purpose: it fires on every keystroke, and submitting halfway
 * through a word would be maddening.
 */
function setUpCatalogueFilters() {
    const form = document.querySelector('[data-catalogue-filters]');

    if (!form) {
        return;
    }

    form.querySelectorAll('[data-filter-select]').forEach(function (select) {
        select.addEventListener('change', function () {
            // requestSubmit(), not submit(), so the form's own validation still runs.
            form.requestSubmit();
        });
    });
}

/* ====================================================================
                Admin forms: fields that belong to one kind
==================================================================== */

/*
 * Show a field only for the kind of thing it applies to.
 *
 * Two forms use it, both in php/admin/, and both work the same way: one select carries
 * data-product-kind, and every element that belongs to exactly one of its values carries
 * data-kind-only with that value.
 *
 *   product-edit.php   the flower count, shown only for data-kind-only="bouquet"
 *   component-edit.php the 3D shape,     shown only for data-kind-only="bloom"
 *
 * The hook keeps the name data-product-kind on the parts form even though a part's kind is
 * a bloom rather than a bouquet. Renaming it to something neutral was weighed and dropped:
 * it would edit a working products form, and the table behind both pages is
 * product_components, so the name is not a lie -- it is just broader than it looks.
 *
 * Enhancement only, on both. validateProductInput() in php/config/products-admin.php is
 * what forces stem_count to NULL for a single flower, and validateComponentInput() in
 * php/config/components-admin.php is what forces shape to 'none' for a wire, a wrap or an
 * accent -- whatever was submitted. This just stops a form asking how many flowers are in
 * one flower, or what shape a length of wire is.
 *
 * The parts of a hidden field each carry data-kind-only rather than sharing a wrapper,
 * because .panel-form is a grid and a wrapper would collapse three of its rows into one.
 * Note that neither hidden input has a `required` attribute: a required field that is
 * hidden cannot be focused, and the browser then refuses to submit the form while pointing
 * at something nobody can see.
 */
function setUpProductKind() {
    const select = document.querySelector('[data-product-kind]');

    if (!select) {
        return;
    }

    const fields = document.querySelectorAll('[data-kind-only]');

    function apply() {
        fields.forEach(function (field) {
            field.hidden = field.dataset.kindOnly !== select.value;
        });
    }

    select.addEventListener('change', apply);
    apply();
}

/* ====================================================================
                        Confirm before doing it
==================================================================== */

/*
 * A second pair of eyes on something that cannot be undone -- deleting a product.
 *
 * Cancelling blocks the click, so the form never submits. With no JavaScript there is no
 * dialogue and the button works on the first press, which is why the server keeps its own
 * guard: deleteProduct() refuses anything that has not been hidden first, and hiding is a
 * separate deliberate act.
 */
function setUpConfirmButtons() {
    document.querySelectorAll('[data-confirm]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!window.confirm(button.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
}

/* ====================================================================
                    Print a record
==================================================================== */

/*
 * Opens the browser's print dialog, where "Save as PDF" is one of the destinations -- which is how
 * an admin gets a PDF of a report out of this project.
 *
 * NOT A SERVER-SIDE PDF, and that is a constraint rather than a shortcut: nothing is installed here
 * -- no composer, no vendor directory -- so there is no PDF library to call and adding one would be
 * a dependency this project has so far managed without. The browser already has a perfectly good
 * PDF writer in it.
 *
 * A real <button> wired here rather than an inline onclick, matching every other behaviour in this
 * file. It is progressive enhancement in the honest sense: with JavaScript off the button is simply
 * absent, and Ctrl+P on the same page produces exactly the same paper, because all the work is done
 * by the @media print rules in css/main.css rather than by this function.
 */
function setUpPrintButtons() {
    document.querySelectorAll('[data-print]').forEach(function (button) {
        // Shown only once the script is running, so a dead button never appears.
        button.hidden = false;

        button.addEventListener('click', function () {
            window.print();
        });
    });
}

/* ====================================================================
                    Verification code field and resend
==================================================================== */

function setUpCodeField() {
    const field = document.querySelector('[data-code-field]');

    if (field) {
        // Codes get pasted out of an email, and what comes along with them varies:
        // a trailing space, "123 456", sometimes a whole "Your code is 123456".
        // Keeping only the digits means all of those work.
        field.addEventListener('input', function () {
            const digits = field.value.replace(/\D/g, '').slice(0, 6);

            if (digits !== field.value) {
                field.value = digits;
            }

            // Six digits is the whole answer, so there is nothing left to do but
            // send it. requestSubmit() rather than submit() so the field's own
            // pattern check still runs, exactly as pressing Verify would.
            if (digits.length === 6 && field.form) {
                field.form.requestSubmit();
            }
        });
    }

    /*
     * The resend button. The server refuses an early resend regardless -- see
     * secondsUntilResend() in php/config/twofactor.php -- so this countdown only
     * saves a pointless round trip and explains the wait.
     */
    const button = document.querySelector('[data-resend-button]');

    if (!button) {
        return;
    }

    let left = Number(button.dataset.waitSeconds) || 0;
    const label = button.textContent;

    if (left <= 0) {
        return;
    }

    function tick() {
        if (left <= 0) {
            button.disabled = false;
            button.textContent = label;

            return;
        }

        button.disabled = true;
        button.textContent = label + ' in ' + left + 's';
        left -= 1;
        window.setTimeout(tick, 1000);
    }

    tick();
}

/* ====================================================================
                    Checkout: asking only what applies
==================================================================== */

/*
 * The address block only matters on a delivery, and the reference box only on a transfer. This
 * hides whichever does not apply as the choice is made.
 *
 * Enhancement only, and unusually literally so: validateCheckout() in php/config/orders.php empties
 * the address on a pickup and the reference on a cash order whatever was submitted, and the two
 * CHECK constraints in sql/orders-upgrade.sql refuse the row if it somehow did not. So with no
 * JavaScript both blocks stay on screen, each says when it is needed, and a customer who fills in
 * an address and then chooses collection still gets a clean order.
 *
 * The hidden attribute rather than a class, matching setUpProductKind(): .panel-form [hidden] in
 * css/main.css is what makes it stick, and a hidden field is also taken out of the tab order, which
 * a class setting display:none would have to be trusted to do as well.
 */
function setUpCheckout() {
    const form = document.querySelector('[data-checkout]');

    if (!form) {
        return;
    }

    const address = form.querySelector('[data-checkout-address]');
    const reference = form.querySelector('[data-checkout-reference]');

    function apply() {
        const fulfilment = form.querySelector('[data-checkout-fulfilment]:checked');
        const payment = form.querySelector('[data-checkout-payment]:checked');

        if (address) {
            // Nothing checked is not the same as pickup checked: a form in that state has not been
            // answered yet, and the answer it is waiting for is the one above the block.
            address.hidden = fulfilment !== null && fulfilment.value === 'pickup';
        }

        if (reference) {
            reference.hidden = payment === null || payment.value === 'cod';
        }
    }

    // One listener on the form rather than ten on the radios. They are the only controls in it that
    // change anything else, and change bubbles.
    form.addEventListener('change', apply);
    apply();
}

document.addEventListener('DOMContentLoaded', function () {
    setUpNavbar();
    setUpProductBackLink();
    setUpToasts();
    setUpAuthPanels();
    setUpPasswordToggles();
    setUpPasswordRules();
    setUpCatalogueFilters();
    setUpProductKind();
    setUpConfirmButtons();
setUpPrintButtons();
    setUpCodeField();
    setUpCheckout();
});
