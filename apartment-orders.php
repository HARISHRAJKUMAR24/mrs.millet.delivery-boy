<?php
require_once './config/config.php';
require_once './config/function.php';

/* ---------------- AUTH ---------------- */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    header('Location: login.php');
    exit;
}

$boyId = (int)$_SESSION['delivery_boy_id'];

$stmt = $pdo->prepare(
    "SELECT id, delivery_code, full_name, mobile_number, status
     FROM delivery_boys WHERE id = ? LIMIT 1"
);
$stmt->execute([$boyId]);
$boy = $stmt->fetch();

if (!$boy || (int)$boy['status'] !== 1) {
    session_destroy();
    header('Location: login.php?reason=not_approved');
    exit;
}

/* ---------------- APARTMENT ---------------- */
$aptCode = trim($_GET['code'] ?? '');
if ($aptCode === '') {
    header('Location: delivery-orders.php');
    exit;
}

$apartment = null;
try {
    $stmt = $pdo->prepare(
        "SELECT id, apartment_code, apartment_name, apartment_address
         FROM apartments WHERE apartment_code = ? AND status = 1 LIMIT 1"
    );
    $stmt->execute([$aptCode]);
    $apartment = $stmt->fetch();
} catch (PDOException $e) {
}

if (!$apartment) {
    header('Location: delivery-orders.php');
    exit;
}

/* ---------------- FILTERS ---------------- */
$statusFilter = trim($_GET['status'] ?? 'pending');
$dateFilter   = trim($_GET['date']   ?? '');

$allowedStatus = ['pending', 'delivered', 'all'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'pending';

/* ---------------- ORDERS ---------------- */
$orders = [];
try {
    $sql = "SELECT o.id, o.order_code, o.delivery_boy_id,
                   o.customer_name, o.customer_mobile,
                   o.apartment_name, o.apartment_code, o.division, o.division_charge,
                   o.subtotal, o.total_amount,
                   o.products_json,
                   o.status, o.delivery_status, o.payment_status,
                   o.payment_ref, o.paid_at, o.created_at, o.updated_at,
                   oc.total_containers,
                   oc.received_containers,
                   (oc.total_containers - oc.received_containers) AS pending_containers,
                   oc.container_amount,
                   oc.status AS container_status
            FROM orders o
            LEFT JOIN order_containers oc ON oc.order_id = o.id
            WHERE o.apartment_code = ?
              AND o.delivery_boy_id = ?
              AND o.status <> 'cancelled'";

    $params = [$aptCode, $boyId];

    if ($statusFilter === 'pending') {
        $sql .= " AND o.delivery_status = 'disabled'";
    } elseif ($statusFilter === 'delivered') {
        $sql .= " AND o.delivery_status = 'enabled'";
    }

    if ($dateFilter !== '') {
        $sql .= " AND DATE(o.created_at) = ?";
        $params[] = $dateFilter;
    }

    $sql .= " ORDER BY o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $orders = [];
}

/* ---------------- CONTAINER SUMMARY ---------------- */
$containerSummary = [];
try {
    $sumStmt = $pdo->prepare(
        "SELECT o.customer_mobile,
                MAX(o.customer_name) AS customer_name,
                SUM(oc.total_containers)     AS total_containers,
                SUM(oc.received_containers)  AS received_containers,
                SUM(oc.total_containers - oc.received_containers) AS pending_containers,
                SUM(oc.container_amount)     AS container_amount,
                COUNT(DISTINCT oc.order_id)  AS order_count
         FROM order_containers oc
         INNER JOIN orders o ON o.id = oc.order_id
         WHERE o.apartment_code = ?
           AND o.delivery_boy_id = ?
           AND o.status <> 'cancelled'
         GROUP BY o.customer_mobile
         HAVING SUM(oc.total_containers - oc.received_containers) > 0"
    );
    $sumStmt->execute([$aptCode, $boyId]);
    $rows = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $containerSummary[$r['customer_mobile']] = [
            'customer_name'      => $r['customer_name'],
            'total_containers'   => (int)$r['total_containers'],
            'received_containers' => (int)$r['received_containers'],
            'pending_containers' => (int)$r['pending_containers'],
            'container_amount'   => (float)$r['container_amount'],
            'order_count'        => (int)$r['order_count'],
        ];
    }
} catch (PDOException $e) {
}

/* Prepare JS payload */
$ordersJson = array_map(function ($o) {
    return [
        'id'                 => (int)$o['id'],
        'order_code'         => $o['order_code'],
        'created_at'         => $o['created_at'],
        'updated_at'         => $o['updated_at'],
        'customer_name'      => $o['customer_name'],
        'customer_mobile'    => $o['customer_mobile'],
        'apartment_code'     => $o['apartment_code'],
        'apartment_name'     => $o['apartment_name'],
        'division'           => $o['division'],
        'division_charge'    => (float)$o['division_charge'],
        'subtotal'           => (float)$o['subtotal'],
        'total_amount'       => (float)$o['total_amount'],
        'status'             => $o['status'],
        'delivery_status'    => $o['delivery_status'],
        'payment_status'     => $o['payment_status'],
        'payment_ref'        => $o['payment_ref'],
        'paid_at'            => $o['paid_at'],
        'products'           => json_decode($o['products_json'] ?? '[]', true) ?: [],
        'total_containers'   => (int)($o['total_containers'] ?? 0),
        'received_containers' => (int)($o['received_containers'] ?? 0),
        'pending_containers' => (int)($o['pending_containers'] ?? 0),
        'container_amount'   => (float)($o['container_amount'] ?? 0),
        'container_status'   => $o['container_status'] ?? null,
    ];
}, $orders);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        .ao-page {
            padding: 24px 26px 40px;
        }

        .ao-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #6f675f;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            margin-bottom: 14px;
        }

        .ao-back:hover {
            color: #b51f2c;
        }

        .ao-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .ao-head-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ao-head-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .25);
        }

        .ao-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 24px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 3px;
        }

        .ao-head p {
            margin: 0;
            font-size: 12px;
            color: #817a71;
        }

        .ao-filters {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding: 14px 16px;
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 14px;
        }

        .ao-filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .ao-filter-lbl {
            font-size: 10px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .ao-tabs {
            display: inline-flex;
            background: #fdfaf4;
            border: 1.5px solid #ece5da;
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
        }

        .ao-tab {
            border: none;
            background: transparent;
            padding: 7px 14px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            color: #6f675f;
            border-radius: 7px;
            cursor: pointer;
            transition: .15s ease;
            white-space: nowrap;
        }

        .ao-tab:hover {
            color: #b51f2c;
        }

        .ao-tab.active {
            background: #fff;
            color: #b51f2c;
            box-shadow: 0 3px 8px rgba(48, 41, 35, .08);
        }

        .ao-date {
            height: 36px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 9px;
            padding: 0 12px;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            color: #302923;
            outline: none;
            cursor: pointer;
        }

        .ao-date:focus {
            border-color: #b51f2c;
            box-shadow: 0 0 0 3px rgba(181, 31, 44, .06);
        }

        .ao-clear-btn {
            height: 36px;
            padding: 0 14px;
            border-radius: 9px;
            border: 1.5px solid #e4ddd3;
            background: #fff;
            color: #6f675f;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 17px;
            text-decoration: none;
        }

        .ao-clear-btn:hover {
            background: #faf7f0;
            color: #302923;
        }

        .ao-table-wrap {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 18px;
            overflow: hidden;
        }

        .ao-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ao-table thead th {
            text-align: left;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #948c82;
            background: #fdfaf4;
            padding: 14px 16px;
            border-bottom: 1.5px solid #ece5da;
            white-space: nowrap;
        }

        .ao-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f5efe5;
            color: #302923;
            vertical-align: middle;
        }

        .ao-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .ao-table tbody tr:hover {
            background: #fffaf5;
        }

        .ao-code {
            font-weight: 800;
            color: #302923;
            font-size: 13px;
            letter-spacing: .3px;
            display: block;
        }

        .ao-date-cell {
            display: block;
            font-size: 10.5px;
            color: #a19a90;
            margin-top: 3px;
            font-weight: 600;
        }

        .ao-name {
            font-weight: 700;
            display: block;
        }

        .ao-phone {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            color: #b51f2c;
            font-weight: 700;
            text-decoration: none;
            margin-top: 3px;
            padding: 3px 8px;
            border-radius: 6px;
            background: #fff5f5;
            transition: .15s ease;
        }

        .ao-phone:hover {
            background: #b51f2c;
            color: #fff;
        }

        .ao-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ao-pill.is-paid,
        .ao-pill.is-enabled {
            background: #e7f6ec;
            color: #1f7a3d;
        }

        .ao-pill.is-unpaid,
        .ao-pill.is-disabled {
            background: #fbeaea;
            color: #b51f2c;
        }

        .ao-pill.is-container-done {
            background: #e7f6ec;
            color: #1f7a3d;
        }

        .ao-pill.is-container-pending {
            background: #fdf7ec;
            color: #b8893c;
        }

        .ao-icon-btn {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: .18s ease;
            font-size: 14px;
        }

        .ao-icon-btn:hover {
            border-color: #b51f2c;
            color: #b51f2c;
            background: #fff5f5;
        }

        .ao-toggle {
            height: 34px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1.5px solid #b51f2c;
            background: #fff;
            color: #b51f2c;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 800;
            cursor: pointer;
            transition: .18s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .ao-toggle:hover:not(:disabled) {
            background: #b51f2c;
            color: #fff;
        }

        .ao-toggle.is-on {
            background: #1f7a3d;
            border-color: #1f7a3d;
            color: #fff;
        }

        .ao-toggle.is-on:hover:not(:disabled) {
            background: #155c2c;
            border-color: #155c2c;
        }

        .ao-toggle:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .ao-toggle.is-locked {
            background: #f4efe8;
            border-color: #d5cbbd;
            color: #948c82;
            cursor: not-allowed;
        }

        .ao-mini-btn {
            height: 34px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            font-family: "DM Sans", sans-serif;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: .18s ease;
            white-space: nowrap;
        }

        .ao-mini-btn:hover:not(:disabled) {
            background: #fdf7ec;
            border-color: #e8d5a8;
            color: #b8893c;
        }

        .ao-mini-btn.wallet:hover:not(:disabled) {
            background: #f0f9f1;
            border-color: #a7c8a9;
            color: #1f7a3d;
        }

        .ao-mini-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .ao-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .ao-empty {
            text-align: center;
            padding: 60px 20px;
            color: #948c82;
        }

        .ao-empty i {
            font-size: 40px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }

        /* Modal */
        .ao-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(30, 25, 22, .55);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: .22s ease;
        }

        .ao-modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .ao-modal {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .28);
            transform: translateY(15px) scale(.97);
            transition: transform .25s cubic-bezier(.2, .9, .3, 1.2);
        }

        .ao-modal-overlay.show .ao-modal {
            transform: translateY(0) scale(1);
        }

        .ao-modal-head {
            padding: 22px 22px 14px;
            border-bottom: 1.5px solid #f0ebe4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2;
        }

        .ao-modal-head h3 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 19px;
            font-weight: 700;
            color: #302923;
        }

        .ao-modal-head p {
            margin: 2px 0 0;
            font-size: 11.5px;
            color: #948c82;
            font-weight: 600;
        }

        .ao-modal-close {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 0;
            background: #f7f2ec;
            color: #6f675f;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: .18s ease;
            flex-shrink: 0;
            font-size: 14px;
        }

        .ao-modal-close:hover {
            background: #fbeaea;
            color: #b51f2c;
        }

        .ao-modal-body {
            padding: 20px 22px 22px;
        }

        .ao-modal-section {
            margin-bottom: 22px;
        }

        .ao-modal-section:last-child {
            margin-bottom: 0;
        }

        .ao-modal-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #948c82;
            margin: 0 0 12px;
        }

        .ao-modal-title i {
            color: #b51f2c;
        }

        .ao-info-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: #fdfaf4;
            border: 1px solid #f0ebe4;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .ao-info-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12.5px;
        }

        .ao-info-row .lbl {
            color: #948c82;
            font-weight: 600;
        }

        .ao-info-row .val {
            color: #302923;
            font-weight: 700;
            text-align: right;
            word-break: break-word;
        }

        .ao-call {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #fff5f5;
            color: #b51f2c;
            font-weight: 800;
            border-radius: 8px;
            text-decoration: none;
        }

        .ao-call:hover {
            background: #b51f2c;
            color: #fff;
        }

        .ao-products {
            display: flex;
            flex-direction: column;
            gap: 8px;
            border: 1px solid #f0ebe4;
            border-radius: 12px;
            padding: 8px;
        }

        .ao-prod {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: #fdfaf4;
            border-radius: 10px;
        }

        .ao-prod-thumb {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #f7efe3;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .ao-prod-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .ao-prod-info {
            flex: 1;
            min-width: 0;
        }

        .ao-prod-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #302923;
            margin: 0;
            line-height: 1.3;
        }

        .ao-prod-meta {
            font-size: 11px;
            color: #948c82;
            margin: 2px 0 0;
            font-weight: 600;
        }

        .ao-prod-price {
            font-size: 12.5px;
            font-weight: 800;
            color: #b51f2c;
            white-space: nowrap;
        }

        .ao-totals {
            background: #fdfaf4;
            border: 1px solid #f0ebe4;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .ao-trow {
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            color: #6f675f;
            font-weight: 600;
            padding: 4px 0;
        }

        .ao-trow strong {
            color: #302923;
            font-weight: 800;
        }

        .ao-trow.grand {
            font-size: 15px;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1.5px dashed #e4ddd3;
        }

        .ao-trow.grand strong {
            color: #b51f2c;
            font-size: 17px;
        }

        .ao-field {
            margin-bottom: 14px;
        }

        .ao-field label {
            display: block;
            font-size: 10.5px;
            font-weight: 800;
            color: #4e4841;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .ao-field input,
        .ao-field textarea {
            width: 100%;
            height: 46px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 11px;
            padding: 0 14px;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: #292521;
            outline: none;
            transition: .15s ease;
        }

        .ao-field textarea {
            height: auto;
            min-height: 70px;
            padding: 12px 14px;
            resize: vertical;
        }

        .ao-field input:focus,
        .ao-field textarea:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }

        .ao-field .hint {
            font-size: 11px;
            color: #948c82;
            margin-top: 6px;
            font-weight: 600;
        }

        .ao-field .hint.green {
            color: #1b5e20;
        }

        .ao-field .hint.red {
            color: #b51f2c;
        }

        .ao-quick-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .ao-quick-btn {
            height: 30px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1.5px solid #ece5da;
            background: #fff;
            font-family: "DM Sans", sans-serif;
            font-size: 11px;
            font-weight: 800;
            color: #6f675f;
            cursor: pointer;
            transition: .15s ease;
        }

        .ao-quick-btn:hover {
            background: #fbe8e9;
            border-color: #f1c8cc;
            color: #b51f2c;
        }

        .ao-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .ao-modal-btn {
            flex: 1;
            height: 46px;
            border-radius: 11px;
            border: none;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: .18s ease;
        }

        .ao-modal-btn.primary {
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .22);
        }

        .ao-modal-btn.primary:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .ao-modal-btn.primary:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .ao-modal-btn.green {
            background: linear-gradient(135deg, #1f7a3d 0%, #14532d 100%);
            color: #fff;
            box-shadow: 0 8px 20px rgba(31, 122, 61, .25);
        }

        .ao-modal-btn.green:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .ao-modal-btn.green:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .ao-modal-btn.ghost {
            background: #fff;
            border: 1.5px solid #e4ddd3;
            color: #6f675f;
        }

        .ao-modal-btn.ghost:hover {
            background: #faf7f0;
        }

        /* Wallet tabs inside modal */
        .ao-wallet-tabs {
            display: flex;
            gap: 4px;
            background: #faf7f0;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 16px;
        }

        .ao-wallet-tab {
            flex: 1;
            height: 42px;
            border-radius: 9px;
            border: none;
            background: transparent;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 800;
            color: #817a71;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: .15s ease;
        }

        .ao-wallet-tab.active {
            background: #fff;
            color: #302923;
            box-shadow: 0 3px 10px rgba(48, 41, 35, .07);
        }

        .ao-wallet-tab.credit.active {
            color: #1b5e20;
        }

        .ao-wallet-tab.debit.active {
            color: #b51f2c;
        }

        .ao-wallet-balance {
            background: linear-gradient(135deg, #fdfaf4 0%, #fff5f5 100%);
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .ao-wallet-balance .lbl {
            font-size: 10px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 4px;
        }

        .ao-wallet-balance .val {
            font-family: "Playfair Display", serif;
            font-size: 24px;
            font-weight: 700;
            color: #b51f2c;
            line-height: 1.1;
        }

        .ao-history-wrap {
            max-height: 280px;
            overflow-y: auto;
            border: 1.5px solid #ece5da;
            border-radius: 12px;
            background: #fff;
        }

        .ao-history-wrap::-webkit-scrollbar {
            width: 6px;
        }

        .ao-history-wrap::-webkit-scrollbar-thumb {
            background: #e0d8cd;
            border-radius: 4px;
        }

        .ao-history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .ao-history-table th {
            background: #faf7f0;
            color: #938a80;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: 10px 12px;
            border-bottom: 1px solid #eee7dc;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .ao-history-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #f2ede5;
            font-size: 11.5px;
            color: #4c4640;
            vertical-align: middle;
        }

        .ao-history-table tr:last-child td {
            border-bottom: 0;
        }

        .ao-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .ao-badge.credit {
            background: #e8f1e8;
            color: #52745b;
        }

        .ao-badge.debit {
            background: #fbeaea;
            color: #b51f2c;
        }

        .ao-amt-credit {
            color: #1b5e20;
            font-weight: 800;
        }

        .ao-amt-debit {
            color: #b51f2c;
            font-weight: 800;
        }

        .ao-bal-cell {
            font-family: "Playfair Display", serif;
            font-weight: 700;
            color: #302923;
        }

        .ao-toast {
            position: fixed;
            top: 84px;
            left: 50%;
            transform: translateX(-50%) translateY(-10px);
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            color: #fff;
            background: #1f7a3d;
            box-shadow: 0 10px 26px rgba(0, 0, 0, .18);
            z-index: 99999;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
            white-space: nowrap;
            max-width: 90vw;
        }

        .ao-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .ao-toast.error {
            background: #b51f2c;
        }

        .btn-spinner {
            width: 12px;
            height: 12px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: aoSpin .7s linear infinite;
            display: inline-block;
        }

        @keyframes aoSpin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 900px) {
            .ao-table-wrap {
                overflow-x: auto;
            }

            .ao-table {
                min-width: 900px;
            }
        }
    </style>
</head>

<body>

    <?php include './templates/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <div class="main">

        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>

            <div class="tb-title">
                <h2>Apartment Orders</h2>
                <p><?= htmlspecialchars($apartment['apartment_name']) ?></p>
            </div>

            <div class="tb-actions">
                <a href="<?= BASE_URL ?>notifications.php" class="tb-icon-btn">
                    <i class="bi bi-bell"></i>
                    <span class="tb-dot"></span>
                </a>
                <a href="<?= BASE_URL ?>profile.php" class="tb-icon-btn">
                    <i class="bi bi-person"></i>
                </a>
            </div>
        </header>


        <main class="ao-page">

            <a href="<?= BASE_URL ?>delivery-orders.php" class="ao-back">
                <i class="bi bi-arrow-left"></i> Back to My Apartments
            </a>

            <div class="ao-head">
                <div class="ao-head-left">
                    <div class="ao-head-icon">
                        <i class="bi bi-building"></i>
                    </div>
                    <div>
                        <h1><?= htmlspecialchars($apartment['apartment_name']) ?></h1>
                        <p>
                            #<?= htmlspecialchars($apartment['apartment_code']) ?>
                            <?php if (!empty($apartment['apartment_address'])): ?>
                                · <?= htmlspecialchars($apartment['apartment_address']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <form class="ao-filters" method="GET" id="filterForm">
                <input type="hidden" name="code" value="<?= htmlspecialchars($aptCode) ?>">

                <div class="ao-filter-group">
                    <span class="ao-filter-lbl">Status</span>
                    <div class="ao-tabs" id="aoTabs">
                        <button type="button" class="ao-tab <?= $statusFilter === 'pending'   ? 'active' : '' ?>" data-status="pending">Pending</button>
                        <button type="button" class="ao-tab <?= $statusFilter === 'delivered' ? 'active' : '' ?>" data-status="delivered">Delivered</button>
                        <button type="button" class="ao-tab <?= $statusFilter === 'all'       ? 'active' : '' ?>" data-status="all">All</button>
                    </div>
                    <input type="hidden" name="status" id="statusInput" value="<?= htmlspecialchars($statusFilter) ?>">
                </div>

                <div class="ao-filter-group">
                    <span class="ao-filter-lbl">Date</span>
                    <input type="date" name="date" class="ao-date" id="dateInput"
                        value="<?= htmlspecialchars($dateFilter) ?>">
                </div>

                <button type="submit" class="ao-clear-btn" id="applyBtn">
                    <i class="bi bi-funnel"></i> Apply
                </button>

                <a href="?code=<?= urlencode($aptCode) ?>&status=pending" class="ao-clear-btn">
                    <i class="bi bi-arrow-counterclockwise"></i> Clear
                </a>
            </form>


            <?php if (empty($orders)): ?>
                <div class="ao-table-wrap">
                    <div class="ao-empty">
                        <i class="bi bi-inbox"></i>
                        <?php if ($statusFilter === 'pending' && $dateFilter === ''): ?>
                            No pending orders right now.
                        <?php elseif ($statusFilter === 'delivered'): ?>
                            No delivered orders match your filter.
                        <?php else: ?>
                            No orders match your filter.
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="ao-table-wrap">
                    <table class="ao-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Division</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Container</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="aoBody">
                            <?php foreach ($orders as $o):
                                $isEnabled    = ($o['delivery_status'] === 'enabled');
                                $isPaid       = ($o['payment_status'] === 'paid');
                                $mobile       = preg_replace('/[^0-9+]/', '', (string)$o['customer_mobile']);

                                $totalCont    = (int)($o['total_containers'] ?? 0);
                                $recvCont     = (int)($o['received_containers'] ?? 0);
                                $hasContainer = $totalCont > 0;

                                $cust = $containerSummary[$o['customer_mobile']] ?? null;
                                $custPending  = $cust ? $cust['pending_containers'] : 0;
                                $custTotal    = $cust ? $cust['total_containers'] : $totalCont;
                                $custReceived = $cust ? $cust['received_containers'] : $recvCont;

                                $isLocked = false;
                                if ($isEnabled && !empty($o['updated_at'])) {
                                    $updatedTs = strtotime($o['updated_at']);
                                    if ($updatedTs && (time() - $updatedTs) > 5 * 3600) {
                                        $isLocked = true;
                                    }
                                }
                            ?>
                                <tr
                                    data-id="<?= (int)$o['id'] ?>"
                                    data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>"
                                    data-delivery="<?= $isEnabled ? 'enabled' : 'disabled' ?>"
                                    data-locked="<?= $isLocked ? '1' : '0' ?>">
                                    <td>
                                        <span class="ao-code">#<?= htmlspecialchars($o['order_code']) ?></span>
                                        <span class="ao-date-cell"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="ao-name"><?= htmlspecialchars($o['customer_name']) ?></span>
                                        <?php if ($mobile !== ''): ?>
                                            <a class="ao-phone" href="tel:<?= htmlspecialchars($mobile) ?>">
                                                <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($o['customer_mobile']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>Division <?= htmlspecialchars($o['division'] ?? '—') ?></td>
                                    <td><strong>₹<?= number_format((float)$o['total_amount'], 2) ?></strong></td>
                                    <td>
                                        <span class="ao-pill <?= $isPaid ? 'is-paid' : 'is-unpaid' ?>">
                                            <i class="bi <?= $isPaid ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                                            <?= $isPaid ? 'Paid' : 'Unpaid' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!$hasContainer): ?>
                                            <span class="ao-pill is-disabled" style="background:#f1ece4;color:#948c82;">
                                                <i class="bi bi-dash-circle"></i> None
                                            </span>
                                        <?php else: ?>
                                            <span class="ao-pill <?= ($custPending > 0) ? 'is-container-pending' : 'is-container-done' ?>">
                                                <?php if ($custPending > 0): ?>
                                                    <i class="bi bi-hourglass-split"></i>
                                                    <?= $custReceived ?>/<?= $custTotal ?> · <?= $custPending ?> pending
                                                <?php else: ?>
                                                    <i class="bi bi-check-circle-fill"></i>
                                                    <?= $custReceived ?>/<?= $custTotal ?> returned
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="ao-actions">
                                            <button type="button" class="ao-icon-btn js-view" data-id="<?= (int)$o['id'] ?>" title="View details">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <?php if ($isLocked): ?>
                                                <button type="button"
                                                    class="ao-toggle is-on is-locked"
                                                    disabled
                                                    title="Delivery locked (5 hours passed)">
                                                    <i class="bi bi-lock-fill"></i> Locked
                                                </button>
                                            <?php else: ?>
                                                <button type="button"
                                                    class="ao-toggle js-toggle <?= $isEnabled ? 'is-on' : '' ?>"
                                                    data-id="<?= (int)$o['id'] ?>"
                                                    data-enabled="<?= $isEnabled ? '1' : '0' ?>"
                                                    title="Mark delivered">
                                                    <?php if ($isEnabled): ?>
                                                        <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                    <?php else: ?>
                                                        <i class="bi bi-truck"></i> Delivered
                                                    <?php endif; ?>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($hasContainer && $custPending > 0): ?>
                                                <button type="button"
                                                    class="ao-mini-btn js-container"
                                                    data-id="<?= (int)$o['id'] ?>"
                                                    data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>"
                                                    title="Container return">
                                                    <i class="bi bi-box2-heart"></i> Return
                                                </button>
                                            <?php endif; ?>

                                            <button type="button"
                                                class="ao-mini-btn wallet js-wallet"
                                                data-id="<?= (int)$o['id'] ?>"
                                                data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>"
                                                data-name="<?= htmlspecialchars($o['customer_name']) ?>"
                                                title="Manage wallet">
                                                <i class="bi bi-wallet2"></i> Wallet
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>


    <!-- ================= ORDER DETAILS MODAL ================= -->
    <div class="ao-modal-overlay" id="aoModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true">
            <div class="ao-modal-head">
                <div>
                    <h3 id="amCode">#ORDER</h3>
                    <p id="amDate">—</p>
                </div>
                <button type="button" class="ao-modal-close" id="amClose">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="ao-modal-body" id="amBody"></div>
        </div>
    </div>


    <!-- ================= CONTAINER RETURN MODAL ================= -->
    <div class="ao-modal-overlay" id="aoContainerModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true" style="max-width:520px;">
            <div class="ao-modal-head">
                <div>
                    <h3>Container Return</h3>
                    <p id="containerModalSub">Refund will be credited to customer wallet.</p>
                </div>
                <button type="button" class="ao-modal-close" id="containerModalClose">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="ao-modal-body">

                <div class="ao-info-list" style="margin-bottom:14px;" id="containerModalInfo"></div>

                <div id="containerBreakdown" style="margin-bottom:16px;"></div>

                <form id="containerForm" autocomplete="off">
                    <input type="hidden" id="containerOrderId" value="">
                    <input type="hidden" id="containerMobile" value="">

                    <div class="ao-field">
                        <label>Returned Containers <span style="color:#b51f2c;">*</span></label>
                        <input type="number" id="containerCount" min="1" step="1" required placeholder="0">
                        <div class="ao-quick-btns" id="containerQuickBtns">
                            <button type="button" class="ao-quick-btn" data-qty="1">1</button>
                            <button type="button" class="ao-quick-btn" data-qty="2">2</button>
                            <button type="button" class="ao-quick-btn" data-qty="5">5</button>
                            <button type="button" class="ao-quick-btn" id="containerFullBtn">Full</button>
                        </div>
                        <div class="hint" id="containerQtyHint">—</div>
                    </div>

                    <div class="ao-field">
                        <label>Note (optional)</label>
                        <textarea id="containerNote" maxlength="250" placeholder="e.g. returned in good condition"></textarea>
                    </div>

                    <div class="ao-info-list" style="background:#e8f6ea;border-color:#a7c8a9;">
                        <div class="ao-info-row">
                            <span class="lbl" style="color:#1b5e20;">Refund to wallet</span>
                            <span class="val" id="containerRefund" style="color:#1b5e20;">₹0</span>
                        </div>
                    </div>

                    <div class="ao-modal-actions">
                        <button type="button" class="ao-modal-btn ghost" id="containerCancel">Cancel</button>
                        <button type="submit" class="ao-modal-btn green" id="containerSubmit">
                            <i class="bi bi-check-lg"></i>
                            <span id="containerSubmitText">Mark Returned</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- ================= WALLET MODAL ================= -->
    <div class="ao-modal-overlay" id="aoWalletModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true" style="max-width:520px;">
            <div class="ao-modal-head">
                <div>
                    <h3>Manage Wallet</h3>
                    <p id="walletModalSub">Add or deduct money from customer wallet</p>
                </div>
                <button type="button" class="ao-modal-close" id="walletModalClose">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="ao-modal-body">

                <div class="ao-info-list" style="margin-bottom:14px;" id="walletModalInfo"></div>

                <div class="ao-wallet-balance">
                    <div>
                        <div class="lbl">Current Balance</div>
                        <div class="val" id="walletBalance">₹0</div>
                    </div>
                    <span style="font-size:11px;color:#948c82;font-weight:700;" id="walletStatusLabel">—</span>
                </div>

                <div class="ao-wallet-tabs" id="walletTabs">
                    <button type="button" class="ao-wallet-tab credit active" data-wtab="credit">
                        <i class="bi bi-plus-circle"></i> Add Money
                    </button>
                    <button type="button" class="ao-wallet-tab debit" data-wtab="debit">
                        <i class="bi bi-dash-circle"></i> Deduct Money
                    </button>
                </div>

                <form id="walletForm" autocomplete="off">
                    <input type="hidden" id="walletMobile" value="">
                    <input type="hidden" id="walletTxnType" value="credit">

                    <div class="ao-field">
                        <label>Amount (₹) <span style="color:#b51f2c;">*</span></label>
                        <input type="number" id="walletAmount" min="1" step="0.01" required placeholder="0.00">
                        <div class="ao-quick-btns">
                            <button type="button" class="ao-quick-btn" data-wamt="100">₹100</button>
                            <button type="button" class="ao-quick-btn" data-wamt="500">₹500</button>
                            <button type="button" class="ao-quick-btn" data-wamt="1000">₹1000</button>
                            <button type="button" class="ao-quick-btn" data-wamt="2000">₹2000</button>
                        </div>
                    </div>

                    <div class="ao-field">
                        <label>Note (optional)</label>
                        <textarea id="walletNote" maxlength="250" placeholder="e.g. cash received"></textarea>
                    </div>

                    <div class="ao-info-list" style="background:#fdfaf4;border:1px solid #f0ebe4;margin-bottom:6px;">
                        <div class="ao-info-row">
                            <span class="lbl">New Balance will be</span>
                            <span class="val" id="walletPreview" style="font-family:'Playfair Display',serif;font-size:16px;color:#1f7a3d;font-weight:700;">₹0</span>
                        </div>
                    </div>

                    <div class="ao-modal-actions">
                        <button type="button" class="ao-modal-btn ghost" id="walletCancel">Cancel</button>
                        <button type="submit" class="ao-modal-btn primary" id="walletSubmit">
                            <i class="bi bi-check-lg"></i>
                            <span id="walletSubmitText">Add Money</span>
                        </button>
                    </div>
                </form>

                <div style="margin-top:24px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">
                        <h4 style="margin:0;font-family:'Playfair Display',serif;font-size:14px;font-weight:700;color:#302923;">
                            <i class="bi bi-clock-history" style="color:#b51f2c;margin-right:4px;"></i> Recent Transactions
                        </h4>
                        <button type="button" class="ao-mini-btn" id="walletRefreshHistory">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    <div class="ao-history-wrap" id="walletHistory">
                        <div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">
                            Loading…
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>


    <div class="ao-toast" id="aoToast"></div>


    <script>
        window.BASE_URL = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
        window.ORDERS = <?= json_encode($ordersJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        window.CURRENT_STATUS = "<?= htmlspecialchars($statusFilter) ?>";
        window.CONTAINER_SUMMARY = <?= json_encode($containerSummary, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    </script>

    <script>
        (function() {
            const sidebar = document.getElementById("sidebar");
            const toggle = document.getElementById("menuToggle");
            const closeBtn = document.getElementById("sidebarClose");
            const overlay = document.getElementById("sbOverlay");

            function openSidebar() {
                sidebar.classList.add("open");
                overlay.classList.add("show");
                document.body.style.overflow = "hidden";
            }

            function closeSidebar() {
                sidebar.classList.remove("open");
                overlay.classList.remove("show");
                document.body.style.overflow = "";
            }
            if (toggle) toggle.addEventListener("click", openSidebar);
            if (closeBtn) closeBtn.addEventListener("click", closeSidebar);
            if (overlay) overlay.addEventListener("click", closeSidebar);
        })();
    </script>

    <script src="<?= BASE_URL ?>js/apartment-orders.js"></script>

</body>

</html>