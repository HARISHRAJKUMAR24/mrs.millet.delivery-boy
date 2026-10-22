<?php
/* =========================================================
   MRS MILL@ — AJAX: WALLET HISTORY
   File: ./ajax/wallet-history.php
   Accepts: customer_id OR mobile
   Returns: list of transactions (newest first)
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* Auth: delivery boy OR admin */
$loggedIn = false;
if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) $loggedIn = true;
if (!$loggedIn && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) $loggedIn = true;
if (!$loggedIn) jsonResponse(false, 'Not logged in.');

$customerId = (int)($_GET['customer_id'] ?? 0);
$mobile     = trim($_GET['mobile'] ?? '');

if ($customerId <= 0 && $mobile === '') {
    jsonResponse(false, 'Missing customer_id or mobile.');
}

try {

    /* Resolve customer_id from mobile if needed */
    if ($customerId <= 0 && $mobile !== '') {
        $s = $pdo->prepare("SELECT id FROM customers WHERE mobile_number = ? LIMIT 1");
        $s->execute([$mobile]);
        $customerId = (int)$s->fetchColumn();
    }

    if ($customerId <= 0) {
        jsonResponse(true, 'OK', []);
    }

    $stmt = $pdo->prepare(
        "SELECT txn_code, txn_type, amount,
                balance_before, balance_after,
                source, note,
                created_by_name,
                created_at
         FROM customer_wallet_transactions
         WHERE customer_id = ?
         ORDER BY id DESC
         LIMIT 50"
    );
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'txn_code'        => $r['txn_code'],
            'txn_type'        => $r['txn_type'],
            'amount'          => (float)$r['amount'],
            'balance_before'  => (float)$r['balance_before'],
            'balance_after'   => (float)$r['balance_after'],
            'source'          => $r['source'],
            'note'            => $r['note'] ?? '',
            'created_by_name' => $r['created_by_name'] ?? '',
            'created_at'      => $r['created_at'],
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load history: ' . $e->getMessage());
}