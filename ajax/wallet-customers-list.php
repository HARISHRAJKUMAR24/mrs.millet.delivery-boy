<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

try {
    /* Ensure wallet_balance column exists */
    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER division_charge");
    } catch (PDOException $e) {}

    $stmt = $pdo->query(
        "SELECT id, full_name, mobile_number, apartment_name, division,
                COALESCE(wallet_balance, 0) AS wallet_balance,
                status
         FROM customers
         WHERE status = 1
         ORDER BY full_name ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'             => (int)$r['id'],
            'full_name'      => $r['full_name'],
            'mobile_number'  => $r['mobile_number'],
            'apartment_name' => $r['apartment_name'] ?? '',
            'division'       => $r['division'] ?? '',
            'wallet_balance' => (float)$r['wallet_balance'],
            'status'         => (int)$r['status'],
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load customers.');
}