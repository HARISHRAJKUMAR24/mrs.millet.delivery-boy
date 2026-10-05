<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$status  = trim($_POST['delivery_status'] ?? '');

if ($orderId <= 0) jsonResponse(false, 'Invalid order.');
if (!in_array($status, ['enabled', 'disabled'], true)) {
    jsonResponse(false, 'Invalid delivery status.');
}

try {
    $chk = $pdo->prepare(
        "SELECT id, status, delivery_status, updated_at
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );
    $chk->execute([$orderId]);
    $order = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$order) jsonResponse(false, 'Order not found.');
    if ($order['status'] === 'cancelled') jsonResponse(false, 'Cancelled orders cannot be updated.');

    /* ============================================================
       5-HOUR LOCK:
       If the order is currently "enabled" (delivered) and the last
       update is more than 5 hours ago → refuse to change status.
       ============================================================ */
    if (
        $order['delivery_status'] === 'enabled'
        && !empty($order['updated_at'])
    ) {
        $updatedTs = strtotime($order['updated_at']);
        if ($updatedTs && (time() - $updatedTs) > (5 * 3600)) {
            jsonResponse(
                false,
                'Delivery is locked. This order was delivered more than 5 hours ago and cannot be changed.'
            );
        }
    }

    if ($status === 'enabled') {
        $upd = $pdo->prepare(
            "UPDATE orders
             SET delivery_status = 'enabled',
                 status = 'delivered',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$orderId]);
    } else {
        $upd = $pdo->prepare(
            "UPDATE orders
             SET delivery_status = 'disabled',
                 status = 'pending',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$orderId]);
    }

    jsonResponse(true, 'Delivery status updated.', [
        'order_id'        => $orderId,
        'delivery_status' => $status,
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Server error.');
}
