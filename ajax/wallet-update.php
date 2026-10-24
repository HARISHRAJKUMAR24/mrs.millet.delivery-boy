<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');

/* Only delivery boys can send requests */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId      = (int)$_SESSION['delivery_boy_id'];
$customerId = (int)($_POST['customer_id'] ?? 0);
$txnType    = trim($_POST['txn_type'] ?? '');
$amount     = (float)($_POST['amount'] ?? 0);
$note       = trim($_POST['note'] ?? '');

if ($customerId <= 0) jsonResponse(false, 'Invalid customer.');
if (!in_array($txnType, ['credit', 'debit'], true)) jsonResponse(false, 'Invalid type.');
if ($amount <= 0) jsonResponse(false, 'Amount must be > 0.');

try {
    // Fetch customer details
    $cs = $pdo->prepare("SELECT id, full_name, mobile_number, wallet_balance FROM customers WHERE id = ? LIMIT 1");
    $cs->execute([$customerId]);
    $cust = $cs->fetch(PDO::FETCH_ASSOC);

    if (!$cust) jsonResponse(false, 'Customer not found.');

    // Debit validation
    if ($txnType === 'debit' && $amount > (float)$cust['wallet_balance']) {
        jsonResponse(false, 'Insufficient wallet balance.');
    }

    // Check for duplicate pending request (same customer + type + amount in last 5 mins)
    $dup = $pdo->prepare(
        "SELECT id FROM delivery_boy_requests
         WHERE delivery_boy_id = ? AND customer_id = ?
           AND request_type = ?
           AND amount = ?
           AND status = 'pending'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         LIMIT 1"
    );
    $dup->execute([$boyId, $customerId, $txnType === 'credit' ? 'wallet_credit' : 'wallet_debit', $amount]);
    if ($dup->fetch()) {
        jsonResponse(false, 'Similar request already pending. Please wait for admin approval.');
    }

    // Insert request
    $typeStr = $txnType === 'credit' ? 'wallet_credit' : 'wallet_debit';

    $ins = $pdo->prepare(
        "INSERT INTO delivery_boy_requests
            (delivery_boy_id, customer_id, customer_name, customer_mobile,
             request_type, amount, note, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
    );
    $ins->execute([
        $boyId,
        $customerId,
        $cust['full_name'],
        $cust['mobile_number'],
        $typeStr,
        $amount,
        $note !== '' ? $note : null
    ]);

    jsonResponse(true, 'Request sent to admin for approval.', [
        'request_id' => (int)$pdo->lastInsertId()
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error.');
}