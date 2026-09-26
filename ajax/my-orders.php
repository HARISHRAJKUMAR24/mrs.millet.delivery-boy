<?php
/* =========================================================
   MRS MILL@ — AJAX: DELIVERY BOY MY ORDERS
   File: ./ajax/my-orders.php
   Returns only orders of the logged-in delivery boy.
   Accepts:
     filter = all | paid | unpaid | today | yesterday | week | month
     date   = all | today | yesterday | week | month          (optional)
     from   = YYYY-MM-DD                                       (optional)
     to     = YYYY-MM-DD                                       (optional)
     q      = search
   Container counts derived from products_json (no order_containers table).
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId  = (int)$_SESSION['delivery_boy_id'];
$filter = trim($_GET['filter'] ?? 'all');
$date   = trim($_GET['date']   ?? '');
$from   = trim($_GET['from']   ?? '');
$to     = trim($_GET['to']     ?? '');
$q      = trim($_GET['q']      ?? '');

$where  = ["o.delivery_boy_id = ?"];
$params = [$boyId];

/* ---------- Payment filter ---------- */
if ($filter === 'paid') {
    $where[] = "o.payment_status = 'paid'";
} elseif ($filter === 'unpaid') {
    $where[] = "o.payment_status = 'unpaid'";
} elseif ($filter === 'today') {
    $where[] = "DATE(o.created_at) = CURDATE()";
} elseif ($filter === 'yesterday') {
    $where[] = "DATE(o.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($filter === 'week') {
    $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
} elseif ($filter === 'month') {
    $where[] = "YEAR(o.created_at) = YEAR(CURDATE())
                AND MONTH(o.created_at) = MONTH(CURDATE())";
}

/* ---------- Date filter ---------- */
if ($date !== '' && $date !== 'all') {
    if ($date === 'today') {
        $where[] = "DATE(o.created_at) = CURDATE()";
    } elseif ($date === 'yesterday') {
        $where[] = "DATE(o.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($date === 'week') {
        $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    } elseif ($date === 'month') {
        $where[] = "YEAR(o.created_at) = YEAR(CURDATE())
                    AND MONTH(o.created_at) = MONTH(CURDATE())";
    }
}

/* ---------- Custom range ---------- */
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = "DATE(o.created_at) >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = "DATE(o.created_at) <= ?";
    $params[] = $to;
}

/* ---------- Search ---------- */
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
                   o.paid_at, o.created_at
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

        /* ---- Derive container counts from products_json ---- */
        $containerIssued   = 0;
        $containerReturned = 0;

        foreach ($products as $p) {
            if ((int)($p['container_enabled'] ?? 0) !== 1) continue;

            $qty = (int)($p['qty'] ?? 0);
            $containerIssued += $qty;
            $containerReturned += (int)($p['container_returned'] ?? 0);
        }

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
            'container_issued'   => $containerIssued,
            'container_returned' => $containerReturned
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load orders: ' . $e->getMessage());
}