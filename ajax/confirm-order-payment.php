<?php
/* =========================================================
   MRS MILL@ — AJAX: CONFIRM ORDER PAYMENT
   File: ./ajax/confirm-order-payment.php
   Marks order as paid + confirmed.
   Accepts: order_id, payment_ref (optional)
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId    = (int)$_SESSION['delivery_boy_id'];
$orderId  = (int)($_POST['order_id'] ?? 0);
$payRef   = trim($_POST['payment_ref'] ?? '');

if ($orderId <= 0) {
    jsonResponse(false, 'Invalid order ID.');
}

try {

    /* Verify order belongs to this boy and is still pending */
    $chk = $pdo->prepare(
        "SELECT id, order_code, payment_status, status
         FROM orders
         WHERE id = ? AND delivery_boy_id = ?
         LIMIT 1"
    );
    $chk->execute([$orderId, $boyId]);
    $order = $chk->fetch();

    if (!$order) {
        jsonResponse(false, 'Order not found.');
    }

    if ($order['payment_status'] === 'paid') {
        jsonResponse(true, 'Payment already confirmed.', [
            'order_id'   => $orderId,
            'order_code' => $order['order_code']
        ]);
    }

    /* Mark as paid + confirmed */
    $up = $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'paid',
             status         = 'confirmed',
             payment_ref    = ?,
             paid_at        = NOW()
         WHERE id = ?"
    );
    $up->execute([
        $payRef !== '' ? $payRef : null,
        $orderId
    ]);

    jsonResponse(true, 'Payment confirmed successfully.', [
        'order_id'   => $orderId,
        'order_code' => $order['order_code']
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}