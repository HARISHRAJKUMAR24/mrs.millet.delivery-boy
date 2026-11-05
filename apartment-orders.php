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

/* =========================================================
   ORDERS
   ========================================================= */
$orders = [];
try {
    $sql = "SELECT
                o.id, o.order_code, o.delivery_boy_id,
                o.customer_id,
                c.full_name       AS customer_name,
                c.mobile_number   AS customer_mobile,
                o.address_type, o.address_json,
                o.division_charge, o.subtotal, o.total_amount,
                o.products_json, o.order_note,
                o.status, o.delivery_status, o.payment_status,
                o.payment_ref, o.paid_at, o.created_at, o.updated_at,

                oc.total_containers,
                oc.received_containers,
                (oc.total_containers - oc.received_containers) AS pending_containers,
                oc.container_amount,
                oc.status AS container_status
            FROM orders o
            LEFT JOIN customers c ON c.customer_id = o.customer_id
            LEFT JOIN order_containers oc ON oc.order_id = o.id
            WHERE JSON_VALID(o.address_json)
              AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_code')) = ?
              AND o.delivery_boy_id = ?
              AND o.delivery_mode = 'delivery'
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---------- Preload house_image + map_link from customer_address_requests ---------- */
    $requestImages = [];
    $requestMaps   = [];
    try {
        $ri = $pdo->prepare(
            "SELECT customer_mobile, house_image, map_link
             FROM customer_address_requests
             WHERE request_type = 'apartment'
               AND (requested_apartment = ? OR created_apartment_id = ?)
             ORDER BY id DESC"
        );
        $ri->execute([$apartment['apartment_name'], (int)$apartment['id']]);
        foreach ($ri->fetchAll(PDO::FETCH_ASSOC) as $rr) {
            $k = ($rr['customer_mobile'] !== '' ? $rr['customer_mobile'] : '_');

            if (!empty($rr['house_image'])) {
                if (!isset($requestImages[$k])) $requestImages[$k] = $rr['house_image'];
                if (!isset($requestImages['_'])) $requestImages['_'] = $rr['house_image'];
            }
            if (!empty($rr['map_link'])) {
                if (!isset($requestMaps[$k])) $requestMaps[$k] = $rr['map_link'];
                if (!isset($requestMaps['_'])) $requestMaps['_'] = $rr['map_link'];
            }
        }
    } catch (PDOException $e) {
    }

    foreach ($rows as &$r) {
        $addr = json_decode($r['address_json'] ?? '[]', true);
        if (!is_array($addr)) $addr = [];

        $r['apartment_name'] = $addr['apartment_name'] ?? ($apartment['apartment_name'] ?? '');
        $r['apartment_code'] = $addr['apartment_code'] ?? ($apartment['apartment_code'] ?? '');
        $r['division']       = $addr['division_label'] ?? '';

        $r['full_address'] = trim(
            ($r['apartment_name'] ?? '') . ' ' .
                ($addr['division_label'] ?? '') . ' ' .
                ($apartment['apartment_address'] ?? '')
        );

        $mobileRaw = $r['customer_mobile'] ?: '';
        $mobileKey = $mobileRaw !== '' ? $mobileRaw : '_';

        /* Map link: address_json first, then customer_address_requests */
        $r['map_link'] = $addr['map_link']
            ?? $requestMaps[$mobileKey]
            ?? $requestMaps['_']
            ?? '';

        /* House image: requests table first, then address_json */
        $r['house_image'] = $requestImages[$mobileKey]
            ?? $requestImages['_']
            ?? ($addr['house_image'] ?? '');

        $r['customer_name']   = $r['customer_name']   ?: '—';
        $r['customer_mobile'] = $mobileRaw            ?: '—';

        $r['house_image_url'] = $r['house_image'] !== ''
            ? rtrim(MAIN_URL, '/') . '/' . ltrim($r['house_image'], '/')
            : '';
    }
    unset($r);

    $orders = $rows;
} catch (PDOException $e) {
    $orders = [];
}

/* =========================================================
   CONTAINER SUMMARY
   ========================================================= */
$containerSummary = [];
try {
    $sumStmt = $pdo->prepare(
        "SELECT
            COALESCE(oc.customer_id,
                     (SELECT c2.id FROM customers c2 WHERE c2.customer_id = o.customer_id LIMIT 1)
            ) AS customer_row_id,
            c.full_name     AS customer_name,
            c.mobile_number AS customer_mobile,
            SUM(oc.total_containers)     AS total_containers,
            SUM(oc.received_containers)  AS received_containers,
            SUM(oc.total_containers - oc.received_containers) AS pending_containers,
            SUM(oc.container_amount)     AS container_amount,
            COUNT(DISTINCT oc.order_id)  AS order_count
         FROM order_containers oc
         INNER JOIN orders o    ON o.id = oc.order_id
         LEFT  JOIN customers c ON c.customer_id = o.customer_id
         WHERE JSON_VALID(o.address_json)
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_code')) = ?
           AND o.delivery_boy_id = ?
           AND o.status <> 'cancelled'
         GROUP BY customer_row_id, c.full_name, c.mobile_number
         HAVING SUM(oc.total_containers - oc.received_containers) > 0"
    );
    $sumStmt->execute([$aptCode, $boyId]);
    $rows = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = (string)($r['customer_mobile'] ?: ('cid_' . (int)$r['customer_row_id']));
        $containerSummary[$key] = [
            'customer_id'         => (int)($r['customer_row_id'] ?? 0),
            'customer_name'       => $r['customer_name'] ?: '—',
            'customer_mobile'     => $r['customer_mobile'] ?: '',
            'total_containers'    => (int)$r['total_containers'],
            'received_containers' => (int)$r['received_containers'],
            'pending_containers'  => (int)$r['pending_containers'],
            'container_amount'    => (float)$r['container_amount'],
            'order_count'         => (int)$r['order_count'],
        ];
    }
} catch (PDOException $e) {
}

/* =========================================================
   JS payload
   ========================================================= */
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
        'full_address'       => $o['full_address'],
        'map_link'           => $o['map_link'],
        'house_image'        => $o['house_image'],
        'house_image_url'    => $o['house_image_url'],
        'division_charge'    => (float)$o['division_charge'],
        'subtotal'           => (float)$o['subtotal'],
        'total_amount'       => (float)$o['total_amount'],
        'status'             => $o['status'],
        'delivery_status'    => $o['delivery_status'],
        'payment_status'     => $o['payment_status'],
        'payment_ref'        => $o['payment_ref'],
        'paid_at'            => $o['paid_at'],
        'order_note'         => $o['order_note'] ?? '',
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
            flex-wrap: wrap;
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
            vertical-align: top;
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

        .ao-cell-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 340px;
            min-width: 260px;
        }

        .ao-cell-addr {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-size: 12px;
            color: #302923;
            font-weight: 700;
            line-height: 1.45;
            padding: 10px 12px;
            background: #f6faf5;
            border: 1px solid #dbe9d9;
            border-radius: 10px;
            word-break: break-word;
        }

        .ao-cell-addr i {
            color: #2e7d32;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .ao-cell-addr-text {
            min-width: 0;
        }

        .ao-cell-note {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            padding: 8px 12px;
            background: #fdf7ec;
            border: 1px dashed #f3dca5;
            border-radius: 10px;
            font-size: 11.5px;
            color: #6f5a3f;
            font-weight: 600;
            line-height: 1.45;
        }

        .ao-cell-note i {
            color: #b8893c;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .ao-cell-note-text {
            min-width: 0;
        }

        .ao-cell-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .ao-cell-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            height: 32px;
            padding: 0 12px;
            border-radius: 9px;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            font-family: "DM Sans", sans-serif;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            transition: .15s ease;
            white-space: nowrap;
        }

        .ao-cell-btn:hover {
            background: #fdf7ec;
            color: #b8893c;
            border-color: #e8d5a8;
        }

        .ao-cell-btn.map {
            background: #fbe8e9;
            color: #b51f2c;
            border-color: #f0c1c5;
        }

        .ao-cell-btn.map:hover {
            background: #b51f2c;
            color: #fff;
            border-color: #b51f2c;
        }

        .ao-cell-btn.image {
            background: #eef7f0;
            color: #2e7d32;
            border-color: #c9e3ce;
        }

        .ao-cell-btn.image:hover {
            background: #2e7d32;
            color: #fff;
            border-color: #2e7d32;
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
            margin-bottom: 18px;
        }

        .ao-modal-title {
            margin: 0 0 10px;
            font-size: 13px;
            font-weight: 800;
            color: #302923;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: "DM Sans", sans-serif;
        }

        .ao-modal-title i {
            color: #b51f2c;
            font-size: 14px;
        }

        .ao-info-list {
            border: 1.5px solid #f0ebe4;
            border-radius: 12px;
            overflow: hidden;
        }

        .ao-info-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid #f5efe5;
            font-size: 12.5px;
            gap: 12px;
        }

        .ao-info-row:last-child {
            border-bottom: 0;
        }

        .ao-info-row .lbl {
            color: #948c82;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
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
            gap: 4px;
            color: #b51f2c;
            text-decoration: none;
            font-weight: 800;
        }

        .ao-products {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .ao-prod {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 1.5px solid #f0ebe4;
            border-radius: 12px;
            background: #fffdf9;
        }

        .ao-prod-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f4efe8;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 20px;
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
            margin: 0 0 3px;
            font-size: 13px;
            font-weight: 700;
            color: #302923;
            line-height: 1.3;
        }

        .ao-prod-meta {
            margin: 0;
            font-size: 10.5px;
            color: #948c82;
            font-weight: 600;
        }

        .ao-prod-price {
            font-weight: 800;
            color: #302923;
            font-size: 13px;
            flex-shrink: 0;
        }

        .ao-totals {
            border: 1.5px solid #f0ebe4;
            border-radius: 12px;
            overflow: hidden;
        }

        .ao-trow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 14px;
            font-size: 12.5px;
            color: #6f675f;
            border-bottom: 1px solid #f5efe5;
        }

        .ao-trow:last-child {
            border-bottom: 0;
        }

        .ao-trow strong {
            color: #302923;
            font-weight: 800;
        }

        .ao-trow.grand {
            background: #fdfaf4;
            color: #302923;
            font-weight: 800;
            font-size: 13.5px;
        }

        .ao-trow.grand strong {
            font-family: "Playfair Display", serif;
            font-size: 15px;
            color: #b51f2c;
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

        .ao-field {
            margin-bottom: 14px;
        }

        .ao-field label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
        }

        .ao-field input,
        .ao-field textarea {
            width: 100%;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 9px;
            padding: 10px 12px;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            color: #302923;
            outline: none;
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
        }

        .ao-quick-btn:hover {
            border-color: #b51f2c;
            color: #b51f2c;
        }

        .hint {
            font-size: 11px;
            color: #948c82;
            margin-top: 6px;
            font-weight: 600;
        }

        .hint.red {
            color: #b51f2c;
        }

        .hint.green {
            color: #1f7a3d;
        }

        .ao-modal-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .ao-modal-btn {
            height: 38px;
            padding: 0 16px;
            border-radius: 10px;
            border: 0;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .ao-modal-btn.ghost {
            background: #f7f2ec;
            color: #6f675f;
        }

        .ao-modal-btn.primary {
            background: #b51f2c;
            color: #fff;
        }

        .ao-modal-btn.green {
            background: #1f7a3d;
            color: #fff;
        }

        .ao-wallet-balance {
            background: #fdfaf4;
            border: 1.5px solid #f0ebe4;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .ao-wallet-balance .lbl {
            font-size: 10px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .ao-wallet-balance .val {
            font-family: "Playfair Display", serif;
            font-size: 20px;
            font-weight: 700;
            color: #302923;
            margin-top: 2px;
        }

        .ao-wallet-tabs {
            display: flex;
            gap: 4px;
            background: #fdfaf4;
            border: 1.5px solid #ece5da;
            border-radius: 10px;
            padding: 3px;
            margin-bottom: 14px;
        }

        .ao-wallet-tab {
            flex: 1;
            border: 0;
            background: transparent;
            padding: 8px;
            border-radius: 7px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 800;
            color: #6f675f;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .ao-wallet-tab.active {
            background: #fff;
            color: #b51f2c;
            box-shadow: 0 3px 8px rgba(48, 41, 35, .08);
        }

        .ao-history-wrap {
            border: 1.5px solid #f0ebe4;
            border-radius: 12px;
            overflow: auto;
            max-height: 260px;
        }

        .ao-history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }

        .ao-history-table th {
            text-align: left;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #948c82;
            background: #fdfaf4;
            padding: 8px 10px;
            border-bottom: 1px solid #f0ebe4;
            white-space: nowrap;
        }

        .ao-history-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f5efe5;
        }

        .ao-badge {
            display: inline-flex;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .ao-badge.credit {
            background: #e7f6ec;
            color: #1f7a3d;
        }

        .ao-badge.debit {
            background: #fbeaea;
            color: #b51f2c;
        }

        .ao-amt-credit {
            color: #1f7a3d;
            font-weight: 800;
        }

        .ao-amt-debit {
            color: #b51f2c;
            font-weight: 800;
        }

        .ao-bal-cell {
            font-weight: 700;
            color: #302923;
        }

        .ao-img-preview {
            position: fixed;
            inset: 0;
            z-index: 100000;
            background: rgba(15, 12, 10, .94);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .ao-img-preview.show {
            display: flex;
        }

        .ao-img-preview img {
            max-width: 92vw;
            max-height: 80vh;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .6);
            object-fit: contain;
            cursor: default;
        }

        .ao-img-preview-close {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .15);
            color: #fff;
            border: 0;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
            transition: .15s ease;
            z-index: 2;
        }

        .ao-img-preview-close:hover {
            background: #b51f2c;
        }

        .ao-img-preview-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }

        .ao-img-preview-action {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 42px;
            padding: 0 20px;
            border-radius: 11px;
            border: 1.5px solid rgba(255, 255, 255, .25);
            background: rgba(255, 255, 255, .14);
            color: #fff;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            backdrop-filter: blur(6px);
            transition: .15s ease;
            text-decoration: none;
        }

        .ao-img-preview-action:hover {
            background: rgba(255, 255, 255, .24);
            border-color: rgba(255, 255, 255, .45);
        }

        .ao-img-preview-action.green {
            background: #1f7a3d;
            border-color: #1f7a3d;
        }

        .ao-img-preview-action.green:hover {
            background: #2a9a4f;
            border-color: #2a9a4f;
        }

        .ao-loc-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 18px;
            max-width: 620px;
            width: 100%;
            text-align: center;
        }

        .ao-loc-icon {
            width: 84px;
            height: 84px;
            border-radius: 22px;
            background: rgba(181, 31, 44, .22);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #fff;
            backdrop-filter: blur(6px);
        }

        .ao-loc-title {
            color: #fff;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .ao-loc-addr {
            color: rgba(255, 255, 255, .78);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.6;
            max-width: 480px;
        }

        .ao-lb-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            text-align: center;
            max-width: 380px;
            padding: 24px;
        }

        .ao-lb-empty-icon {
            width: 84px;
            height: 84px;
            border-radius: 22px;
            background: rgba(255, 255, 255, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #fff;
            backdrop-filter: blur(6px);
        }

        .ao-lb-empty-title {
            color: #fff;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .ao-lb-empty-sub {
            color: rgba(255, 255, 255, .72);
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
            line-height: 1.55;
        }

        @media (max-width:900px) {
            .ao-page {
                padding: 16px 12px 32px;
            }

            .ao-head h1 {
                font-size: 20px;
            }

            .ao-filters {
                padding: 12px;
                gap: 8px;
            }

            .ao-clear-btn {
                margin-top: 0;
            }

            .ao-table thead {
                display: none;
            }

            .ao-table,
            .ao-table tbody,
            .ao-table tr,
            .ao-table td {
                display: block;
                width: 100%;
            }

            .ao-table tbody tr {
                background: #fff;
                border: 1.5px solid #ece5da;
                border-radius: 16px;
                padding: 14px;
                margin-bottom: 12px;
                box-shadow: 0 4px 12px rgba(48, 41, 35, .04);
            }

            .ao-table tbody tr:last-child {
                margin-bottom: 0;
            }

            .ao-table tbody td {
                border: 0;
                padding: 6px 0;
                text-align: left;
            }

            .ao-table tbody td[data-lbl]::before {
                content: attr(data-lbl);
                display: block;
                font-size: 9.5px;
                font-weight: 800;
                letter-spacing: .08em;
                text-transform: uppercase;
                color: #a19a90;
                margin-bottom: 4px;
            }

            .ao-cell-details {
                max-width: none;
                min-width: 0;
            }

            .ao-table tbody tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px 12px;
            }

            .ao-table tbody tr>td:nth-child(1) {
                grid-column: 1;
            }

            .ao-table tbody tr>td:nth-child(2) {
                grid-column: 2;
            }

            .ao-table tbody tr>td:nth-child(3) {
                grid-column: 1 / -1;
            }

            .ao-table tbody tr>td:nth-child(4) {
                grid-column: 1;
            }

            .ao-table tbody tr>td:nth-child(5) {
                grid-column: 2;
            }

            .ao-table tbody tr>td:nth-child(6) {
                grid-column: 1;
            }

            .ao-table tbody tr>td:nth-child(7) {
                grid-column: 2;
            }

            .ao-table tbody tr>td:nth-child(8) {
                grid-column: 1 / -1;
            }

            .ao-actions {
                justify-content: flex-start;
                margin-top: 6px;
            }

            .ao-toggle,
            .ao-mini-btn,
            .ao-icon-btn {
                flex: 1 1 auto;
                min-width: 0;
            }

            .ao-icon-btn {
                flex: 0 0 auto;
            }

            .ao-modal-overlay {
                padding: 12px;
            }

            .ao-modal {
                max-width: 100%;
                border-radius: 16px;
                max-height: 92vh;
            }

            .ao-modal-head {
                padding: 16px 16px 12px;
            }

            .ao-modal-head h3 {
                font-size: 16px;
            }

            .ao-modal-body {
                padding: 14px 16px 18px;
            }

            .ao-modal-section {
                margin-bottom: 14px;
            }

            .ao-modal-title {
                font-size: 12.5px;
                margin-bottom: 8px;
            }

            .ao-info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
                padding: 8px 10px;
            }

            .ao-info-row .lbl {
                font-size: 9.5px;
            }

            .ao-info-row .val {
                text-align: left;
                font-size: 12.5px;
                width: 100%;
            }

            .ao-info-row[style*="column"] {
                flex-direction: column !important;
            }

            .ao-prod {
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px;
            }

            .ao-prod-thumb {
                width: 44px;
                height: 44px;
                flex: 0 0 44px;
            }

            .ao-prod-info {
                flex: 1 1 60%;
                min-width: 0;
            }

            .ao-prod-name {
                font-size: 12.5px;
            }

            .ao-prod-price {
                flex: 1 1 100%;
                text-align: right;
                font-size: 13px;
                margin-top: 4px;
            }

            .ao-trow {
                font-size: 12px;
                padding: 7px 12px;
            }

            .ao-trow.grand {
                font-size: 13px;
            }

            .ao-modal-body .ao-pill {
                font-size: 10px;
                padding: 3px 8px;
            }
        }

        @media (max-width:480px) {
            .ao-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .ao-table tbody tr {
                grid-template-columns: 1fr;
            }

            .ao-table tbody tr>td {
                grid-column: 1 / -1 !important;
            }

            .ao-cell-btns .ao-cell-btn {
                flex: 1 1 100%;
                justify-content: center;
            }

            .ao-actions {
                flex-wrap: wrap;
            }

            .ao-actions>* {
                flex: 1 1 auto;
            }

            .ao-icon-btn {
                flex: 0 0 auto;
            }

            .ao-modal-overlay {
                padding: 8px;
            }

            .ao-modal-head {
                padding: 14px 14px 10px;
            }

            .ao-modal-head h3 {
                font-size: 15px;
            }

            .ao-modal-head p {
                font-size: 10.5px;
            }

            .ao-modal-body {
                padding: 12px 14px 16px;
            }

            .ao-modal-title {
                font-size: 11.5px;
            }

            .ao-prod {
                flex-wrap: wrap;
            }

            .ao-prod-price {
                text-align: left;
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
                    <i class="bi bi-bell"></i><span class="tb-dot"></span>
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
                    <div class="ao-head-icon"><i class="bi bi-building"></i></div>
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
                    <input type="date" name="date" class="ao-date" id="dateInput" value="<?= htmlspecialchars($dateFilter) ?>">
                </div>
                <button type="submit" class="ao-clear-btn" id="applyBtn"><i class="bi bi-funnel"></i> Apply</button>
                <a href="?code=<?= urlencode($aptCode) ?>&status=pending" class="ao-clear-btn"><i class="bi bi-arrow-counterclockwise"></i> Clear</a>
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
                                <th>Division &amp; Address</th>
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

                                $sumKey = $o['customer_mobile'] !== '' ? $o['customer_mobile'] : '';
                                $cust = $containerSummary[$sumKey] ?? null;
                                $custPending  = $cust ? $cust['pending_containers'] : 0;
                                $custTotal    = $cust ? $cust['total_containers'] : $totalCont;
                                $custReceived = $cust ? $cust['received_containers'] : $recvCont;

                                $isLocked = false;
                                if ($isEnabled && !empty($o['updated_at'])) {
                                    $updatedTs = strtotime($o['updated_at']);
                                    if ($updatedTs && (time() - $updatedTs) > 5 * 3600) $isLocked = true;
                                }

                                $oNote     = trim((string)($o['order_note'] ?? ''));
                                $oMapLink  = $o['map_link'];
                                $oImage    = $o['house_image_url'];
                                $oHasImage = $oImage !== '';

                                $mobileSafe = ($o['customer_mobile'] === '—') ? '' : $o['customer_mobile'];
                                $nameSafe   = $o['customer_name'];
                                $addrSafe   = $o['full_address'] ?: $apartment['apartment_name'];
                                $timeCache  = time();
                            ?>
                                <tr
                                    data-id="<?= (int)$o['id'] ?>"
                                    data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>"
                                    data-delivery="<?= $isEnabled ? 'enabled' : 'disabled' ?>"
                                    data-locked="<?= $isLocked ? '1' : '0' ?>">
                                    <td data-lbl="Order">
                                        <span class="ao-code">#<?= htmlspecialchars($o['order_code']) ?></span>
                                        <span class="ao-date-cell"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                                    </td>
                                    <td data-lbl="Customer">
                                        <span class="ao-name"><?= htmlspecialchars($o['customer_name']) ?></span>
                                        <?php if ($mobile !== '' && $mobile !== '—'): ?>
                                            <a class="ao-phone" href="tel:<?= htmlspecialchars($mobile) ?>">
                                                <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($o['customer_mobile']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                    <td data-lbl="Division & Address">
                                        <div class="ao-cell-details">
                                            <?php if (!empty($o['division'])): ?>
                                                <div style="font-size:11px;font-weight:800;color:#948c82;text-transform:uppercase;letter-spacing:.06em;">
                                                    Division <?= htmlspecialchars($o['division']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="ao-cell-addr">
                                                <i class="bi bi-geo-alt-fill"></i>
                                                <div class="ao-cell-addr-text"><?= htmlspecialchars($addrSafe) ?></div>
                                            </div>

                                            <?php if ($oNote !== ''): ?>
                                                <div class="ao-cell-note">
                                                    <i class="bi bi-sticky-fill"></i>
                                                    <div class="ao-cell-note-text"><?= htmlspecialchars($oNote) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="ao-cell-btns">
                                                <?php if ($oMapLink !== ''): ?>
                                                    <button type="button"
                                                        class="ao-cell-btn map js-open-location"
                                                        data-order-id="<?= (int)$o['id'] ?>"
                                                        data-map="<?= htmlspecialchars($oMapLink) ?>"
                                                        data-address="<?= htmlspecialchars($addrSafe) ?>">
                                                        <i class="bi bi-geo-alt-fill"></i> Open Location
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button"
                                                    class="ao-cell-btn image js-upload-house-photo"
                                                    data-address="<?= htmlspecialchars($addrSafe) ?>"
                                                    data-mobile="<?= htmlspecialchars($mobileSafe) ?>"
                                                    data-name="<?= htmlspecialchars($nameSafe) ?>"
                                                    data-has-image="<?= $oHasImage ? '1' : '0' ?>">
                                                    <i class="bi bi-camera"></i>
                                                    <?= $oHasImage ? 'Replace Photo' : 'Upload Photo' ?>
                                                </button>

                                                <?php if ($oHasImage): ?>
                                                    <button type="button"
                                                        class="ao-cell-btn image js-open-house-photo"
                                                        data-order-id="<?= (int)$o['id'] ?>"
                                                        data-image-src="<?= htmlspecialchars($oImage . '?t=' . $timeCache) ?>">
                                                        <i class="bi bi-image"></i> View Photo
                                                    </button>

                                                    <button type="button"
                                                        class="ao-cell-btn js-delete-house-photo"
                                                        data-address="<?= htmlspecialchars($addrSafe) ?>"
                                                        data-mobile="<?= htmlspecialchars($mobileSafe) ?>">
                                                        <i class="bi bi-trash"></i> Remove
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-lbl="Total"><strong>₹<?= number_format((float)$o['total_amount'], 2) ?></strong></td>
                                    <td data-lbl="Payment">
                                        <span class="ao-pill <?= $isPaid ? 'is-paid' : 'is-unpaid' ?>">
                                            <i class="bi <?= $isPaid ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                                            <?= $isPaid ? 'Paid' : 'Unpaid' ?>
                                        </span>
                                    </td>
                                    <td data-lbl="Container">
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
                                    <td data-lbl="Actions">
                                        <div class="ao-actions">
                                            <button type="button" class="ao-icon-btn js-view" data-id="<?= (int)$o['id'] ?>" title="View details">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <?php if ($isLocked): ?>
                                                <button type="button" class="ao-toggle is-on is-locked" disabled>
                                                    <i class="bi bi-lock-fill"></i> Locked
                                                </button>
                                            <?php else: ?>
                                                <button type="button"
                                                    class="ao-toggle js-toggle <?= $isEnabled ? 'is-on' : '' ?>"
                                                    data-id="<?= (int)$o['id'] ?>"
                                                    data-enabled="<?= $isEnabled ? '1' : '0' ?>">
                                                    <?php if ($isEnabled): ?>
                                                        <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                    <?php else: ?>
                                                        <i class="bi bi-truck"></i> Delivered
                                                    <?php endif; ?>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($hasContainer && $custPending > 0 && !empty($o['customer_mobile']) && $o['customer_mobile'] !== '—'): ?>
                                                <button type="button"
                                                    class="ao-mini-btn js-container"
                                                    data-id="<?= (int)$o['id'] ?>"
                                                    data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>">
                                                    <i class="bi bi-box2-heart"></i> Return
                                                </button>
                                            <?php endif; ?>

                                            <?php if (!empty($o['customer_mobile']) && $o['customer_mobile'] !== '—'): ?>
                                                <button type="button"
                                                    class="ao-mini-btn wallet js-wallet"
                                                    data-id="<?= (int)$o['id'] ?>"
                                                    data-mobile="<?= htmlspecialchars($o['customer_mobile']) ?>"
                                                    data-name="<?= htmlspecialchars($o['customer_name']) ?>">
                                                    <i class="bi bi-wallet2"></i> Wallet
                                                </button>
                                            <?php endif; ?>
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

    <!-- Order details modal -->
    <div class="ao-modal-overlay" id="aoModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true">
            <div class="ao-modal-head">
                <div>
                    <h3 id="amCode">#ORDER</h3>
                    <p id="amDate">—</p>
                </div>
                <button type="button" class="ao-modal-close" id="amClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="ao-modal-body" id="amBody"></div>
        </div>
    </div>

    <!-- Container modal -->
    <div class="ao-modal-overlay" id="aoContainerModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true" style="max-width:520px;">
            <div class="ao-modal-head">
                <div>
                    <h3>Container Return</h3>
                    <p id="containerModalSub">Refund will be credited to customer wallet.</p>
                </div>
                <button type="button" class="ao-modal-close" id="containerModalClose"><i class="bi bi-x-lg"></i></button>
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
                            <i class="bi bi-check-lg"></i> <span id="containerSubmitText">Mark Returned</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Wallet modal -->
    <div class="ao-modal-overlay" id="aoWalletModal" aria-hidden="true">
        <div class="ao-modal" role="dialog" aria-modal="true" style="max-width:520px;">
            <div class="ao-modal-head">
                <div>
                    <h3>Manage Wallet</h3>
                    <p id="walletModalSub">Add or deduct money from customer wallet</p>
                </div>
                <button type="button" class="ao-modal-close" id="walletModalClose"><i class="bi bi-x-lg"></i></button>
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
                    <button type="button" class="ao-wallet-tab credit active" data-wtab="credit"><i class="bi bi-plus-circle"></i> Add Money</button>
                    <button type="button" class="ao-wallet-tab debit" data-wtab="debit"><i class="bi bi-dash-circle"></i> Deduct Money</button>
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
                            <i class="bi bi-check-lg"></i> <span id="walletSubmitText">Add Money</span>
                        </button>
                    </div>
                </form>
                <div style="margin-top:24px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">
                        <h4 style="margin:0;font-family:'Playfair Display',serif;font-size:14px;font-weight:700;color:#302923;">
                            <i class="bi bi-clock-history" style="color:#b51f2c;margin-right:4px;"></i> Recent Transactions
                        </h4>
                        <button type="button" class="ao-mini-btn" id="walletRefreshHistory"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                    <div class="ao-history-wrap" id="walletHistory">
                        <div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">Loading…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LIGHTBOX -->
    <div class="ao-img-preview" id="aoLightbox">
        <button type="button" class="ao-img-preview-close" id="aoLightboxClose"><i class="bi bi-x-lg"></i></button>

        <div id="aoLbPhotoMode" style="display:none;flex-direction:column;align-items:center;gap:16px;">
            <img src="" alt="House Preview" id="aoLbPhotoImg">
        </div>

        <div id="aoLbEmptyMode" class="ao-lb-empty" style="display:none;">
            <div class="ao-lb-empty-icon"><i class="bi bi-image"></i></div>
            <div>
                <div class="ao-lb-empty-title">No House Photo</div>
                <div class="ao-lb-empty-sub">No photo is available for this house.</div>
            </div>
        </div>

        <div id="aoLbLocationMode" class="ao-loc-wrap" style="display:none;">
            <div class="ao-loc-icon"><i class="bi bi-geo-alt-fill"></i></div>
            <div class="ao-loc-title">Delivery Location</div>
            <div class="ao-loc-addr" id="aoLbLocationAddr"></div>
            <div class="ao-img-preview-actions">
                <a href="#" target="_blank" rel="noopener noreferrer" class="ao-img-preview-action green" id="aoLbMapLink">
                    <i class="bi bi-geo-alt-fill"></i> Open in Maps
                </a>
                <button type="button" class="ao-img-preview-action" id="aoLbCopyAddr">
                    <i class="bi bi-clipboard"></i> Copy Address
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden file input for house photo upload -->
    <input type="file" id="housePhotoInput" accept="image/jpeg,image/png,image/webp"
        style="position:fixed;left:-9999px;top:-9999px;opacity:0;width:1px;height:1px;pointer-events:none;">

    <div class="ao-toast" id="aoToast"></div>

    <script>
        window.BASE_URL = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
        window.MAIN_URL = "<?= MAIN_URL ?>";
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
                if (!sidebar) return;
                sidebar.classList.add("open");
                overlay.classList.add("show");
                document.body.style.overflow = "hidden";
            }

            function closeSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove("open");
                overlay.classList.remove("show");
                document.body.style.overflow = "";
            }
            if (toggle) toggle.addEventListener("click", openSidebar);
            if (closeBtn) closeBtn.addEventListener("click", closeSidebar);
            if (overlay) overlay.addEventListener("click", closeSidebar);
        })();
    </script>

    <script src="<?= BASE_URL ?>js/apartment-orders.js?v=<?= time() ?>"></script>

</body>

</html>