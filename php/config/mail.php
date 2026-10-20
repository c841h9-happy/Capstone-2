<?php

declare(strict_types=1);

/**
 * Outgoing mail settings.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE HOLDS A PASSWORD. Do not share it, do not paste it into a chat, and
 * if you ever run `git init` in this project, add it to .gitignore first:
 *
 *     php/config/mail.php
 * ---------------------------------------------------------------------------
 *
 * To send real email you need a Google App Password. Gmail stopped accepting
 * ordinary account passwords from third-party programs in 2022, so your normal
 * Google password will simply be rejected here.
 *
 *   1. Turn on 2-Step Verification for the Google account:
 *      https://myaccount.google.com/security
 *      Google will not offer app passwords until this is on.
 *
 *   2. Create an app password:
 *      https://myaccount.google.com/apppasswords
 *      You get 16 characters, shown as four groups like "abcd efgh ijkl mnop".
 *      The spaces are only there to make it readable -- keep them or drop them,
 *      both work.
 *
 *   3. Fill in GMAIL_ADDRESS and GMAIL_APP_PASSWORD below, then change
 *      MAIL_TRANSPORT to 'smtp'.
 *
 *   4. Check it worked, from the project folder:
 *          php php/tools/mail-test.php your.name@gmail.com
 */

/**
 * 'log'  -- write messages to MAIL_LOG_FILE instead of sending them. The whole
 *           sign-in flow works; you read the code out of that file. Start here.
 * 'smtp' -- really send, through smtp.gmail.com. Needs the two constants below.
 */
const MAIL_TRANSPORT = 'smtp';

/** The Gmail account doing the sending, e.g. 'your.name@gmail.com'. */
const GMAIL_ADDRESS = 'dummy.electronic.mail.1@gmail.com';

/** The 16-character app password from step 2 above -- NOT your Google password. */
const GMAIL_APP_PASSWORD = 'poin aymv vfsq cgxe';

/** Shown as the sender's name in the recipient's inbox. */
const MAIL_FROM_NAME = 'Website';

/** Temporarily bypass login 2FA only for this admin account; empty this to restore it. */
const ADMIN_LOGIN_2FA_BYPASS_EMAIL = 'admin@capstone.test';

/**
 * Where the 'log' transport writes.
 *
 * Deliberately in the system temp folder rather than anywhere under htdocs:
 * these messages contain live verification codes, and a file inside the web root
 * could be opened by anyone who guessed the URL.
 */
define('MAIL_LOG_FILE', rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'capstone-mail.log');

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;

/** Seconds to wait on the connection and on each reply before giving up. */
const SMTP_TIMEOUT = 15;
