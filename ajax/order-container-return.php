<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId    = (int)$_SESSION['delivery_boy_id'];
$mobile   = trim($_POST['customer_mobile'] ?? '');
$received = (int)($_POST['received_containers'] ?? 0);
$note     = trim($_POST['note'] ?? '');

if ($mobile === '') jsonResponse(false, 'Missing customer mobile.');
if ($received <= 0) jsonResponse(false, 'Enter a valid count.');

try {
    /* Ensure wallet column exists */
    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER division_charge");
    } catch (PDOException $e) {}

    /* Ensure wallet transactions table */
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

    /* Ensure columns exist on order_containers */
    foreach ([
        "ALTER TABLE order_containers ADD COLUMN received_containers INT(11) NOT NULL DEFAULT 0 AFTER total_containers",
        "ALTER TABLE order_containers ADD COLUMN status ENUM('not_received','partial','received') NOT NULL DEFAULT 'not_received' AFTER container_amount",
        "ALTER TABLE order_containers ADD COLUMN received_at DATETIME DEFAULT NULL AFTER status",
        "ALTER TABLE order_containers ADD COLUMN received_by_id INT(11) DEFAULT NULL AFTER received_at",
        "ALTER TABLE order_containers ADD COLUMN received_by_name VARCHAR(150) DEFAULT NULL AFTER received_by_id",
        "ALTER TABLE order_containers ADD COLUMN note VARCHAR(255) DEFAULT NULL AFTER received_by_name",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {}
    }

    $pdo->beginTransaction();

    /* Fetch ALL pending container rows for this customer (this boy's orders) */
    $stmt = $pdo->prepare(
        "SELECT oc.*, o.order_code, o.customer_name, o.customer_mobile
         FROM order_containers oc
         INNER JOIN orders o ON o.id = oc.order_id
         WHERE o.customer_mobile = ?
           AND o.delivery_boy_id = ?
           AND o.status <> 'cancelled'
           AND (oc.total_containers - oc.received_containers) > 0
         ORDER BY oc.id ASC
         FOR UPDATE"
    );
    $stmt->execute([$mobile, $boyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->rollBack();
        jsonResponse(false, 'No pending containers for this customer.');
    }

    /* Compute total pending */
    $totalPending = 0;
    foreach ($rows as $r) {
        $totalPending += ((int)$r['total_containers'] - (int)$r['received_containers']);
    }

    if ($received > $totalPending) {
        $pdo->rollBack();
        jsonResponse(false, 'Cannot return more than ' . $totalPending . ' pending.');
    }

    /* ============================================================
       FIX: Resolve customer id — prefer from container row,
       fallback to lookup by mobile
       ============================================================ */
    $customerId   = 0;
    $customerName = '';

    /* Try container rows first */
    foreach ($rows as $r) {
        if (!empty($r['customer_id'])) {
            $customerId = (int)$r['customer_id'];
            break;
        }
    }
    foreach ($rows as $r) {
        if (!empty($r['customer_name'])) {
            $customerName = $r['customer_name'];
            break;
        }
    }

    /* Fallback: lookup by mobile from orders/customers */
    if ($customerId <= 0) {
        $custLookup = $pdo->prepare(
            "SELECT id, full_name FROM customers WHERE mobile_number = ? LIMIT 1"
        );
        $custLookup->execute([$mobile]);
        $custRow = $custLookup->fetch(PDO::FETCH_ASSOC);

        if ($custRow) {
            $customerId = (int)$custRow['id'];
            if ($customerName === '') $customerName = $custRow['full_name'];
        }
    }

    /* Distribute received count across orders (oldest first) */
    $remaining   = $received;
    $totalRefund = 0;
    $affected    = [];

    foreach ($rows as $r) {
        if ($remaining <= 0) break;

        $rowPending = (int)$r['total_containers'] - (int)$r['received_containers'];
        if ($rowPending <= 0) continue;

        $take = min($rowPending, $remaining);

        $total   = (int)$r['total_containers'];
        $unitAmt = $total > 0 ? ((float)$r['container_amount'] / $total) : 0;

        $refund = round($unitAmt * $take, 2);
        $totalRefund += $refund;

        $newReceived = (int)$r['received_containers'] + $take;
        $newStatus = ($newReceived >= $total) ? 'received' : 'partial';

        /* Also backfill customer_id on the container row while we're here */
        $upd = $pdo->prepare(
            "UPDATE order_containers
             SET received_containers = ?,
                 status = ?,
                 customer_id = COALESCE(customer_id, ?),
                 received_at = NOW(),
                 received_by_id = ?,
                 received_by_name = ?,
                 note = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([
            $newReceived,
            $newStatus,
            $customerId > 0 ? $customerId : null,
            $boyId,
            $_SESSION['delivery_boy_name'] ?? 'Delivery Boy',
            $note !== '' ? $note : $r['note'],
            (int)$r['id'],
        ]);

        $affected[] = [
            'order_code' => $r['order_code'],
            'received'   => $take,
            'refund'     => $refund,
        ];

        $remaining -= $take;
    }

    /* Credit the customer's wallet */
    $walletInfo = null;

    if ($totalRefund > 0 && $customerId > 0) {

        $cStmt = $pdo->prepare(
            "SELECT id, full_name, mobile_number, wallet_balance
             FROM customers WHERE id = ? LIMIT 1 FOR UPDATE"
        );
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);

        if ($cust) {
            $before = (float)$cust['wallet_balance'];
            $after  = $before + $totalRefund;

            $updC = $pdo->prepare(
                "UPDATE customers SET wallet_balance = ?, updated_at = NOW() WHERE id = ?"
            );
            $updC->execute([$after, $customerId]);

            try {
                $txnCode = 'TXN' . date('ymd') . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                $log = $pdo->prepare(
                    "INSERT INTO customer_wallet_transactions
                        (customer_id, customer_name, customer_mobile, txn_code,
                         txn_type, amount, balance_before, balance_after,
                         source, note, created_by_id, created_by_name, created_at)
                     VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, 'refund', ?, ?, ?, NOW())"
                );
                $log->execute([
                    $customerId,
                    $cust['full_name'],
                    $cust['mobile_number'],
                    $txnCode,
                    $totalRefund,
                    $before,
                    $after,
                    'Container return (' . $received . ' pcs)',
                    $boyId,
                    $_SESSION['delivery_boy_name'] ?? 'Delivery Boy',
                ]);
            } catch (PDOException $e) {}

            $walletInfo = [
                'balance_before' => $before,
                'balance_after'  => $after,
                'amount'         => $totalRefund,
            ];
        }
    }

    $pdo->commit();

    $msg = $received . ' container' . ($received === 1 ? '' : 's') .
           ' returned · ₹' . number_format($totalRefund, 2) . ' refunded to wallet.';

    jsonResponse(true, $msg, [
        'received'        => $received,
        'refund_amount'   => $totalRefund,
        'affected_orders' => $affected,
        'wallet'          => $walletInfo,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, 'Server error: ' . $e->getMessage());
}