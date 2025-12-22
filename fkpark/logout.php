<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

/* 1. Clear all session data */
$_SESSION = [];
session_unset();

/* 2. Destroy the session */
session_destroy();

/* 3. Delete the session cookie (if cookies are used) */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* 4. Prevent browser caching of this response */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* 5. Redirect to login page with timeout flag */
header("Location: login.php?timeout=1");
exit;
