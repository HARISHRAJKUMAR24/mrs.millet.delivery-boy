<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');

/* Allow BOTH delivery boy AND admin sessions */
$userId   = 0;
$userName = 'System';
$source   = 'admin';

if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) {
    $userId   = (int)$_SESSION['delivery_boy_id'];
    $userName = $_SESSION['delivery_boy_name'] ?? 'Delivery Boy';
    $source   = 'staff';
} elseif (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
    $userId   = (int)$_SESSION['admin_id'];
    $userName = $_SESSION['admin_name'] ?? 'Admin';
    $source   = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') ? 'admin' : 'staff';
} else {
    jsonResponse(false, 'Not logged in.');
}

$customerId = (int)($_POST['customer_id'] ?? 0);
$txnType    = trim($_POST['txn_type'] ?? '');
$amount     = (float)($_POST['amount'] ?? 0);
$note       = trim($_POST['note'] ?? '');

if ($customerId <= 0) jsonResponse(false, 'Invalid customer.');
if (!in_array($txnType, ['credit', 'debit'], true)) jsonResponse(false, 'Invalid transaction type.');
if ($amount <= 0) jsonResponse(false, 'Amount must be greater than 0.');
$amount = round($amount, 2);

try {
    /* Ensure columns/tables exist */
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER division_charge"); } catch (PDOException $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_wallet_transactions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            customer_id INT(11) NOT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_mobile VARCHAR(30) NOT NULL,
            txn_code VARCHAR(30) NOT NULL,
            txn_type ENUM('credit','debit') NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            balance_before DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            balance_after DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            source ENUM('admin','staff','system','order','refund') NOT NULL DEFAULT 'admin',
            note VARCHAR(255) DEFAULT NULL,
            created_by_id INT(11) DEFAULT NULL,
            created_by_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            PRIMARY KEY (id),
            UNIQUE KEY txn_code (txn_code),
            KEY customer_id (customer_id),
            KEY txn_type (txn_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {}

    $pdo->beginTransaction();

    /* Lock customer */
    $stmt = $pdo->prepare(
        "SELECT id, full_name, mobile_number, wallet_balance
         FROM customers WHERE id = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cust) {
        $pdo->rollBack();
        jsonResponse(false, 'Customer not found.');
    }

    $before = (float)$cust['wallet_balance'];

    if ($txnType === 'debit' && $amount > $before) {
        $pdo->rollBack();
        jsonResponse(false, 'Insufficient balance. Available: ₹' . number_format($before, 2));
    }

    $after = $txnType === 'credit' ? ($before + $amount) : ($before - $amount);

    /* Update customer balance */
    $upd = $pdo->prepare(
        "UPDATE customers SET wallet_balance = ?, updated_at = NOW() WHERE id = ?"
    );
    $upd->execute([$after, $customerId]);

    /* Insert transaction row */
    $txnCode = 'TXN' . date('ymd') . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

    $log = $pdo->prepare(
        "INSERT INTO customer_wallet_transactions
            (customer_id, customer_name, customer_mobile, txn_code,
             txn_type, amount, balance_before, balance_after,
             source, note, created_by_id, created_by_name, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $log->execute([
        $customerId,
        $cust['full_name'],
        $cust['mobile_number'],
        $txnCode,
        $txnType,
        $amount,
        $before,
        $after,
        $source,
        $note !== '' ? $note : null,
        $userId,
        $userName,
    ]);

    $pdo->commit();

    $msg = $txnType === 'credit'
        ? '₹' . number_format($amount, 2) . ' added. New balance: ₹' . number_format($after, 2)
        : '₹' . number_format($amount, 2) . ' deducted. New balance: ₹' . number_format($after, 2);

    jsonResponse(true, $msg, [
        'txn_code'       => $txnCode,
        'balance_before' => $before,
        'balance_after'  => $after,
        'amount'         => $amount,
        'txn_type'       => $txnType,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, 'Server error.');
}