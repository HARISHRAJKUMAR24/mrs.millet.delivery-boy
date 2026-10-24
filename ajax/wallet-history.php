<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

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
    if ($customerId <= 0 && $mobile !== '') {
        $s = $pdo->prepare("SELECT id FROM customers WHERE mobile_number = ? LIMIT 1");
        $s->execute([$mobile]);
        $customerId = (int)$s->fetchColumn();
    }
    if ($customerId <= 0) jsonResponse(true, 'OK', ['requests' => [], 'transactions' => []]);

    /* Recent delivery boy requests for this customer (last 30) */
    $req = $pdo->prepare(
        "SELECT id, request_type, amount, status, note, created_at
         FROM delivery_boy_requests
         WHERE customer_id = ?
         ORDER BY id DESC
         LIMIT 30"
    );
    $req->execute([$customerId]);
    $requests = [];
    while ($r = $req->fetch(PDO::FETCH_ASSOC)) {
        $requests[] = [
            'id'           => (int)$r['id'],
            'request_type' => $r['request_type'],
            'amount'       => (float)$r['amount'],
            'status'       => $r['status'],
            'note'         => $r['note'] ?? '',
            'created_at'   => $r['created_at'],
        ];
    }

    /* Wallet transactions (last 50) */
    $t = $pdo->prepare(
        "SELECT txn_code, txn_type, amount, balance_before, balance_after,
                source, note, created_by_name, created_at
         FROM customer_wallet_transactions
         WHERE customer_id = ?
         ORDER BY id DESC
         LIMIT 50"
    );
    $t->execute([$customerId]);
    $transactions = [];
    while ($r = $t->fetch(PDO::FETCH_ASSOC)) {
        $transactions[] = [
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

    jsonResponse(true, 'OK', [
        'requests'     => $requests,
        'transactions' => $transactions,
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load history.');
}