<?php
/* =========================================================
   MRS MILL@ — AJAX: UPDATE DELIVERY BOY PROFILE
   File: ./ajax/update-profile.php
   Accepts: full_name, mobile_number, email_address
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

$boyId  = (int)$_SESSION['delivery_boy_id'];
$name   = trim($_POST['full_name'] ?? '');
$mobile = trim($_POST['mobile_number'] ?? '');
$email  = trim($_POST['email_address'] ?? '');

/* Validate */
if ($name === '' || mb_strlen($name) < 3) {
    jsonResponse(false, 'Name must be at least 3 characters.');
}

if (!preg_match('/^[0-9]{10,15}$/', $mobile)) {
    jsonResponse(false, 'Mobile number must be 10–15 digits.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please enter a valid email address.');
}

try {

    /* Verify account */
    $chk = $pdo->prepare("SELECT id FROM delivery_boys WHERE id = ? LIMIT 1");
    $chk->execute([$boyId]);
    if (!$chk->fetch()) {
        jsonResponse(false, 'Account not found.');
    }

    /* Mobile uniqueness */
    $dup = $pdo->prepare(
        "SELECT id FROM delivery_boys WHERE mobile_number = ? AND id <> ? LIMIT 1"
    );
    $dup->execute([$mobile, $boyId]);
    if ($dup->fetch()) {
        jsonResponse(false, 'This mobile number is already registered.');
    }

    /* Update */
    $up = $pdo->prepare(
        "UPDATE delivery_boys
         SET full_name     = ?,
             mobile_number = ?,
             email_address = ?
         WHERE id = ?"
    );
    $up->execute([
        $name,
        $mobile,
        $email !== '' ? $email : null,
        $boyId
    ]);

    /* Update session name so topbar reflects change */
    $_SESSION['delivery_boy_name']   = $name;
    $_SESSION['delivery_boy_mobile'] = $mobile;

    jsonResponse(true, 'Profile updated successfully.', [
        'full_name'     => $name,
        'mobile_number' => $mobile,
        'email_address' => $email
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}