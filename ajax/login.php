<?php
/* =========================================================
   MRS MILL@ — AJAX: DELIVERY BOY LOGIN
   File: ./ajax/delivery-login.php
   ========================================================= */

require_once __DIR__ . '/../config/config.php';


header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

$mobile   = trim($_POST['mobile_number'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($mobile === '' || $password === '') {
    jsonResponse(false, 'Please enter mobile and password.');
}

if (!preg_match('/^[0-9]{10,15}$/', $mobile)) {
    jsonResponse(false, 'Invalid mobile number.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, delivery_code, full_name, mobile_number, password_hash, status
         FROM delivery_boys
         WHERE mobile_number = ?
         LIMIT 1"
    );
    $stmt->execute([$mobile]);
    $boy = $stmt->fetch();

    if (!$boy) {
        jsonResponse(false, 'Invalid mobile or password.');
    }

    /* ✅ STATUS CHECK: only status = 1 can log in */
    if ((int)$boy['status'] !== 1) {
        jsonResponse(false, 'Your account is not approved yet. Please contact admin.');
    }

    if (!password_verify($password, $boy['password_hash'])) {
        jsonResponse(false, 'Invalid mobile or password.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $token = bin2hex(random_bytes(32));

    $up = $pdo->prepare(
        "UPDATE delivery_boys
         SET token = ?, last_login_at = NOW()
         WHERE id = ?"
    );
    $up->execute([$token, $boy['id']]);

    $_SESSION['delivery_boy_id']     = (int)$boy['id'];
    $_SESSION['delivery_boy_code']   = $boy['delivery_code'];
    $_SESSION['delivery_boy_name']   = $boy['full_name'];
    $_SESSION['delivery_boy_mobile'] = $boy['mobile_number'];
    $_SESSION['delivery_boy_token']  = $token;

    jsonResponse(true, 'Login successful.', [
        'id'       => (int)$boy['id'],
        'code'     => $boy['delivery_code'],
        'name'     => $boy['full_name'],
        'redirect' => BASE_URL . 'index.php'
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}