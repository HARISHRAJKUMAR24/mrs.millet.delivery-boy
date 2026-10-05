<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId   = (int)$_SESSION['delivery_boy_id'];
$orderId = (int)($_POST['order_id'] ?? 0);

if ($orderId <= 0) jsonResponse(false, 'Invalid order.');

try {
    $chk = $pdo->prepare(
        "SELECT id, order_code, delivery_boy_id, payment_status, status
         FROM orders
         WHERE id = ? AND delivery_boy_id = ?
         LIMIT 1"
    );
    $chk->execute([$orderId, $boyId]);
    $order = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$order) jsonResponse(false, 'Order not found.');
    if ($order['status'] === 'cancelled') jsonResponse(false, 'Cancelled order.');
    if ($order['payment_status'] === 'paid') {
        jsonResponse(true, 'Already paid.', ['order_code' => $order['order_code']]);
    }

    $upd = $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'paid',
             paid_at = NOW(),
             updated_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([$orderId]);

    jsonResponse(true, 'Payment marked as paid.', [
        'order_id'   => $orderId,
        'order_code' => $order['order_code'],
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error.');
}