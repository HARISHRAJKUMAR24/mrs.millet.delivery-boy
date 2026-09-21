<?php
/* =========================================================
   MRS MILL@ — AJAX: DELIVERY BOY REGISTER
   File: ./ajax/delivery-register.php
   - Creates new delivery boy with status = 0 (pending approval)
   - Returns success message (waiting for admin verify)
   ========================================================= */

require_once __DIR__ . '/../config/config.php';


header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

$full_name        = trim($_POST['full_name']        ?? '');
$mobile           = trim($_POST['mobile_number']    ?? '');
$password         = trim($_POST['password']         ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');

/* ---------------- VALIDATION ---------------- */

if ($full_name === '' || $mobile === '' || $password === '' || $confirm_password === '') {
    jsonResponse(false, 'Please fill in all required fields.');
}

if (mb_strlen($full_name) < 3) {
    jsonResponse(false, 'Name must be at least 3 characters long.');
}

if (!preg_match('/^[0-9]{10,15}$/', $mobile)) {
    jsonResponse(false, 'Mobile number must be 10–15 digits.');
}

if (strlen($password) < 6) {
    jsonResponse(false, 'Password must be at least 6 characters long.');
}

if ($password !== $confirm_password) {
    jsonResponse(false, 'Passwords do not match.');
}

try {

    /* ---------------- CHECK DUPLICATE MOBILE ---------------- */
    $stmt = $pdo->prepare(
        "SELECT id, status
         FROM delivery_boys
         WHERE mobile_number = ?
         LIMIT 1"
    );
    $stmt->execute([$mobile]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ((int)$existing['status'] === 1) {
            jsonResponse(false, 'This mobile number is already registered. Please login.');
        } else {
            jsonResponse(false, 'This mobile number is already registered and pending approval.');
        }
    }

    /* ---------------- GENERATE UNIQUE DELIVERY CODE ---------------- */
    // Format: DB + 6 digits, e.g. DB000001
    $codeStmt = $pdo->query(
        "SELECT delivery_code
         FROM delivery_boys
         WHERE delivery_code LIKE 'DB%'
         ORDER BY id DESC
         LIMIT 1"
    );
    $last = $codeStmt->fetch();

    if ($last && preg_match('/DB(\d+)/', $last['delivery_code'], $m)) {
        $nextNum = (int)$m[1] + 1;
    } else {
        $nextNum = 1;
    }
    $delivery_code = 'DB' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

    /* ---------------- HASH PASSWORD ---------------- */
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    /* ---------------- INSERT (status = 0 pending) ---------------- */
    $insert = $pdo->prepare(
        "INSERT INTO delivery_boys
            (delivery_code, full_name, mobile_number, password_hash, status, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())"
    );
    $insert->execute([
        $delivery_code,
        $full_name,
        $mobile,
        $password_hash
    ]);

    /* ---------------- SUCCESS RESPONSE ---------------- */
    jsonResponse(
        true,
        'Your account has been created successfully. Please wait for admin approval before logging in.',
        [
            'id'            => (int)$pdo->lastInsertId(),
            'delivery_code' => $delivery_code,
            'name'          => $full_name,
            'mobile'        => $mobile,
            'status'        => 0
        ]
    );

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}