<?php
// Sign-up now lives alongside sign-in on login.php. Kept so existing links keep working.
require_once __DIR__ . '/config/auth.php';

redirectTo(url('php/login.php?form=signup'));
