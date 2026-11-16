<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    log_activity('logout', "User '" . $_SESSION['user']['username'] . "' logged out");
}

// Destroy the old session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Start a brand-new session (new ID) just to carry the flash message
session_start();
session_regenerate_id(true);
flash('success', 'You have been logged out.');

// Stop the browser from showing cached private pages with the Back button
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
redirect('login.php');