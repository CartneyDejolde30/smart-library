<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'Smart Library');
define('BASE_PATH', dirname(__DIR__));
// URL path where the project lives under htdocs, e.g. /smart_library
define('BASE_URL', '/smart_library');
define('APP_DEBUG', true);          // set true only during development

define('MAX_LOGIN_ATTEMPTS', 5);     // per username+IP
define('LOGIN_LOCKOUT_MINUTES', 15);
define('SESSION_LIFETIME', 3600);
define('PAGE_SIZE', 15);

error_reporting(APP_DEBUG ? E_ALL : E_ALL & ~E_DEPRECATED);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('SMARTLIBSESS');
    session_start();
}