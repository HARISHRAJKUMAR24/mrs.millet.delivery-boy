<?php
// 1. Start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Clear all session variables
$_SESSION = array();

// 3. Destroy the session cookie
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

// 4. Destroy the session
session_destroy();

// 5. Redirect to the delivery boy login page
header('Location: login.php');
exit;