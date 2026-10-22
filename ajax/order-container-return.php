<?php
/* =========================================================
   MRS MILL@ — ORDER CONTAINER RETURN (delivery boy)
   File: ./ajax/order-container-return.php

   Inputs (POST):
     - customer_mobile    : string
     - received_containers: int
     - note               : string (optional)

   Fixes vs. previous version:
     - Removed bogus ALTER TABLE customers … AFTER division_charge
     - Lookup by order_containers.customer_id (INT) instead of
       non-existent orders.customer_mobile
     - Falls back to orders.customer_id (varchar MRSMCxxxxx) → customers.id
   ========================================================= */

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

    $pdo->beginTransaction();

    /* ------------------------------------------------------------------
       1) Fetch the customer row (customers.id) from the mobile
          We need the INT id for both the container update and wallet.
       ------------------------------------------------------------------ */
    $custRow = null;
    try {
        $cs = $pdo->prepare(
            "SELECT id, customer_id, full_name, mobile_number, wallet_balance
             FROM customers WHERE mobile_number = ? LIMIT 1
             FOR UPDATE"
        );
        $cs->execute([$mobile]);
        $custRow = $cs->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    if (!$custRow) {
        $pdo->rollBack();
        jsonResponse(false, 'Customer not found for this mobile.');
    }

    $customerId     = (int)$custRow['id'];         // INT
    $customerCode   = (string)$custRow['customer_id']; // MRSMCxxxxx
    $customerName   = $custRow['full_name'];
    $customerMobile = $custRow['mobile_number'];
    $walletBalance  = (float)$custRow['wallet_balance'];

    /* ------------------------------------------------------------------
       2) Fetch pending container rows for THIS delivery boy.
          Join orders by customer_id (MRSMCxxxxx) so we only touch
          containers that belong to this boy's deliveries.
       ------------------------------------------------------------------ */
    $stmt = $pdo->prepare(
        "SELECT oc.*, o.order_code, o.customer_id AS order_customer_code
         FROM order_containers oc
         INNER JOIN orders o ON o.id = oc.order_id
         WHERE o.customer_id = ?
           AND o.delivery_boy_id = ?
           AND o.status <> 'cancelled'
           AND (oc.total_containers - oc.received_containers) > 0
         ORDER BY oc.id ASC
         FOR UPDATE"
    );
    $stmt->execute([$customerCode, $boyId]);
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

    /* ------------------------------------------------------------------
       3) Distribute received count across rows (oldest first)
       ------------------------------------------------------------------ */
    $remaining   = $received;
    $totalRefund = 0.0;
    $affected    = [];

    foreach ($rows as $r) {
        if ($remaining <= 0) break;

        $rowPending = (int)$r['total_containers'] - (int)$r['received_containers'];
        if ($rowPending <= 0) continue;

        $take = min($rowPending, $remaining);

        $total   = (int)$r['total_containers'];
        $unitAmt = $total > 0 ? ((float)$r['container_amount'] / $total) : 0;
        $refund  = round($unitAmt * $take, 2);

        $totalRefund += $refund;

        $newReceived = (int)$r['received_containers'] + $take;
        $newStatus   = ($newReceived >= $total) ? 'received' : 'partial';

        $upd = $pdo->prepare(
            "UPDATE order_containers
             SET received_containers = ?,
                 status              = ?,
                 customer_id         = COALESCE(customer_id, ?),
                 received_at         = NOW(),
                 received_by_id      = ?,
                 received_by_name    = ?,
                 note                = ?,
                 updated_at          = NOW()
             WHERE id = ?"
        );
        $upd->execute([
            $newReceived,
            $newStatus,
            $customerId,
            $boyId,
            $_SESSION['delivery_boy_name'] ?? 'Delivery Boy',
            $note !== '' ? $note : ($r['note'] ?? null),
            (int)$r['id'],
        ]);

        $affected[] = [
            'order_code' => $r['order_code'],
            'received'   => $take,
            'refund'     => $refund,
        ];

        $remaining -= $take;
    }

    /* ------------------------------------------------------------------
       4) Credit the customer's wallet (INT id)
       ------------------------------------------------------------------ */
    $walletInfo = null;

    if ($totalRefund > 0) {
        $before = $walletBalance;
        $after  = round($before + $totalRefund, 2);

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
                $customerName,
                $customerMobile,
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