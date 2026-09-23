<?php
/* =========================================================
   MRS MILL@ — AJAX: DELIVERY BOY MY ORDERS
   File: ./ajax/my-orders.php
   Returns only orders of the logged-in delivery boy.
   Accepts: filter (all|paid|unpaid|today), q (search)
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId  = (int)$_SESSION['delivery_boy_id'];
$filter = trim($_GET['filter'] ?? 'all');
$q      = trim($_GET['q'] ?? '');

$where  = ["o.delivery_boy_id = ?"];
$params = [$boyId];

/* Filter */
if ($filter === 'paid') {
    $where[] = "o.payment_status = 'paid'";
} elseif ($filter === 'unpaid') {
    $where[] = "o.payment_status = 'unpaid'";
} elseif ($filter === 'today') {
    $where[] = "DATE(o.created_at) = CURDATE()";
}

/* Search */
if ($q !== '') {
    $where[] = "(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.customer_mobile LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {

    $sql = "SELECT o.id, o.order_code, o.customer_name, o.customer_mobile,
                   o.apartment_code, o.apartment_name, o.division,
                   o.division_charge, o.subtotal, o.total_amount,
                   o.products_json, o.status, o.payment_status,
                   o.paid_at, o.created_at,
                   (SELECT COALESCE(SUM(qty_issued),0)
                      FROM order_containers
                     WHERE order_id = o.id) AS container_issued,
                   (SELECT COALESCE(SUM(qty_returned),0)
                      FROM order_containers
                     WHERE order_id = o.id) AS container_returned
            FROM orders o
            $whereSql
            ORDER BY o.id DESC
            LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {

        $products = json_decode($r['products_json'] ?? '[]', true);
        if (!is_array($products)) $products = [];

        $out[] = [
            'id'                 => (int)$r['id'],
            'order_code'         => $r['order_code'],
            'customer_name'      => $r['customer_name'],
            'customer_mobile'    => $r['customer_mobile'],
            'apartment_code'     => $r['apartment_code'],
            'apartment_name'     => $r['apartment_name'],
            'division'           => $r['division'],
            'division_charge'    => (float)$r['division_charge'],
            'subtotal'           => (float)$r['subtotal'],
            'total_amount'       => (float)$r['total_amount'],
            'products'           => $products,
            'status'             => $r['status'],
            'payment_status'     => $r['payment_status'],
            'paid_at'            => $r['paid_at'],
            'created_at'         => $r['created_at'],
            'container_issued'   => (int)$r['container_issued'],
            'container_returned' => (int)$r['container_returned']
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load orders.');
}