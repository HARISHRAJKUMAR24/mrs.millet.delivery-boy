<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');

/* Only delivery boys can send requests */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId          = (int)$_SESSION['delivery_boy_id'];
$customerId     = (int)($_POST['customer_id']     ?? 0);
$customerMobile = trim($_POST['customer_mobile']  ?? '');
$customerName   = trim($_POST['customer_name']    ?? '');
$txnType        = trim($_POST['txn_type']         ?? '');
$amount         = (float)($_POST['amount']        ?? 0);
$note           = trim($_POST['note']             ?? '');

if (!in_array($txnType, ['credit', 'debit'], true)) jsonResponse(false, 'Invalid type.');
if ($amount <= 0)                                   jsonResponse(false, 'Amount must be > 0.');

try {

    /* ---------- Resolve customer ---------- */
    $cust = null;

    if ($customerId > 0) {
        $cs = $pdo->prepare(
            "SELECT id, full_name, mobile_number, wallet_balance
             FROM customers WHERE id = ? LIMIT 1"
        );
        $cs->execute([$customerId]);
        $cust = $cs->fetch(PDO::FETCH_ASSOC);
    }

    /* Fallback: look up by mobile if id missing or not found */
    if (!$cust && $customerMobile !== '') {
        $cs = $pdo->prepare(
            "SELECT id, full_name, mobile_number, wallet_balance
             FROM customers WHERE mobile_number = ? LIMIT 1"
        );
        $cs->execute([$customerMobile]);
        $cust = $cs->fetch(PDO::FETCH_ASSOC);
    }

    /* Fill in any missing fields */
    if ($cust) {
        $customerId     = (int)$cust['id'];
        $customerMobile = $cust['mobile_number'] ?: $customerMobile;
        $customerName   = $cust['full_name']     ?: $customerName;
    } else {
        /* Customer not found — allow request by mobile (admin will create wallet) */
        if ($customerMobile === '' && $customerName === '') {
            jsonResponse(false, 'Customer not found and no mobile provided.');
        }
        $customerId = 0;
    }

    /* Debit validation (only when customer exists) */
    if ($txnType === 'debit' && $cust && $amount > (float)$cust['wallet_balance']) {
        jsonResponse(false, 'Insufficient wallet balance.');
    }

    /* Duplicate check */
    $typeStr = $txnType === 'credit' ? 'wallet_credit' : 'wallet_debit';

    if ($customerId > 0) {
        $dup = $pdo->prepare(
            "SELECT id FROM delivery_boy_requests
             WHERE delivery_boy_id = ? AND customer_id = ?
               AND request_type = ?
               AND amount = ?
               AND status = 'pending'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             LIMIT 1"
        );
        $dup->execute([$boyId, $customerId, $typeStr, $amount]);
    } else {
        $dup = $pdo->prepare(
            "SELECT id FROM delivery_boy_requests
             WHERE delivery_boy_id = ?
               AND customer_mobile = ?
               AND request_type = ?
               AND amount = ?
               AND status = 'pending'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             LIMIT 1"
        );
        $dup->execute([$boyId, $customerMobile, $typeStr, $amount]);
    }
    if ($dup->fetch()) {
        jsonResponse(false, 'Similar request already pending. Please wait for admin approval.');
    }

    /* Insert request */
    $ins = $pdo->prepare(
        "INSERT INTO delivery_boy_requests
            (delivery_boy_id, customer_id, customer_name, customer_mobile,
             request_type, amount, note, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
    );
    $ins->execute([
        $boyId,
        $customerId,
        $customerName   !== '' ? $customerName   : '—',
        $customerMobile !== '' ? $customerMobile : '—',
        $typeStr,
        $amount,
        $note !== '' ? $note : null
    ]);

    jsonResponse(true, 'Request sent to admin for approval.', [
        'request_id'      => (int)$pdo->lastInsertId(),
        'customer_id'     => $customerId,
        'customer_mobile' => $customerMobile,
        'customer_exists' => (bool)$cust
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error: ' . $e->getMessage());
}