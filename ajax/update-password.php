<?php
/* =========================================================
   MRS MILL@ — AJAX: UPDATE DELIVERY BOY PASSWORD
   File: ./ajax/update-password.php
   Accepts: current_password, new_password, confirm_password
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId   = (int)$_SESSION['delivery_boy_id'];
$current = trim($_POST['current_password'] ?? '');
$newPwd  = trim($_POST['new_password'] ?? '');
$confirm = trim($_POST['confirm_password'] ?? '');

/* Validate */
if ($current === '' || $newPwd === '' || $confirm === '') {
    jsonResponse(false, 'All password fields are required.');
}

if (strlen($newPwd) < 6) {
    jsonResponse(false, 'New password must be at least 6 characters.');
}

if ($newPwd === $current) {
    jsonResponse(false, 'New password must be different from current.');
}

if ($newPwd !== $confirm) {
    jsonResponse(false, 'New passwords do not match.');
}

try {

    /* Fetch hash */
    $stmt = $pdo->prepare(
        "SELECT id, password_hash FROM delivery_boys WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$boyId]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(false, 'Account not found.');
    }

    /* Verify current password */
    if (!password_verify($current, $row['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }

    /* Generate new hash */
    $newHash = password_hash($newPwd, PASSWORD_DEFAULT);

    /* Update + invalidate token (force re-login on other devices) */
    $up = $pdo->prepare(
        "UPDATE delivery_boys
         SET password_hash = ?,
             token = NULL
         WHERE id = ?"
    );
    $up->execute([$newHash, $boyId]);

    jsonResponse(true, 'Password updated successfully.');

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}