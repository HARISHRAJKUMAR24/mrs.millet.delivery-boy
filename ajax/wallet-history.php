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

if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) {
    $loggedIn = true;
}
if (!$loggedIn && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
    $loggedIn = true;
}

if (!$loggedIn) {
    jsonResponse(false, 'Not logged in.');
}

$customerId = (int)($_GET['customer_id'] ?? 0);
$mobile     = trim($_GET['mobile'] ?? '');

if ($customerId <= 0 && $mobile === '') {
    jsonResponse(false, 'Missing customer_id or mobile.');
}

try {
    /* Ensure table exists */
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
            KEY customer_id (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {}

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
    jsonResponse(false, 'Failed to load history.');
}