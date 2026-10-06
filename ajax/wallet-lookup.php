<?php
/* =========================================================
   MRS MILL@ — AJAX: WALLET LOOKUP
   File: ./ajax/wallet-lookup.php
   Returns: id, name, mobile, wallet_balance, status
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* Auth: delivery boy OR admin */
$loggedIn = false;

if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) {
    $loggedIn = true;
}
if (!$loggedIn && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
    $loggedIn = true;
}

if (!$loggedIn) {
    jsonResponse(false, 'Not logged in.');
}

$mobile = trim($_GET['mobile'] ?? '');

if ($mobile === '') {
    jsonResponse(false, 'Missing mobile.');
}

try {
    /* Ensure wallet_balance column exists */
    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER division_charge");
    } catch (PDOException $e) {}

    $stmt = $pdo->prepare(
        "SELECT id, full_name, mobile_number,
                COALESCE(wallet_balance, 0) AS wallet_balance,
                status
         FROM customers
         WHERE mobile_number = ?
         LIMIT 1"
    );
    $stmt->execute([$mobile]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        jsonResponse(true, 'Not found.', ['found' => false]);
    }

    jsonResponse(true, 'OK', [
        'found'          => true,
        'id'             => (int)$c['id'],
        'name'           => $c['full_name'],
        'mobile'         => $c['mobile_number'],
        'wallet_balance' => (float)$c['wallet_balance'],
        'status'         => (int)$c['status'],
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Lookup failed.');
}