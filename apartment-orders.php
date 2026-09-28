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

/* ---------------- GET APARTMENT CODE ---------------- */
$aptCode = trim($_GET['code'] ?? '');
if ($aptCode === '') {
    header('Location: delivery-orders.php');
    exit;
}

/* ---------------- VERIFY BOY IS ALLOCATED TO THIS APARTMENT ---------------- */
try {
    $check = $pdo->prepare(
        "SELECT 1 FROM apartment_delivery_boys
         WHERE apartment_code = ? AND delivery_boy_id = ?
         LIMIT 1"
    );
    $check->execute([$aptCode, $boyId]);
    if (!$check->fetch()) {
        header('Location: delivery-orders.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: delivery-orders.php');
    exit;
}

/* ---------------- FETCH APARTMENT ---------------- */
$apartment = null;
try {
    $stmt = $pdo->prepare(
        "SELECT id, apartment_code, apartment_name, apartment_address
         FROM apartments WHERE apartment_code = ? AND status = 1 LIMIT 1"
    );
    $stmt->execute([$aptCode]);
    $apartment = $stmt->fetch();
} catch (PDOException $e) {}

if (!$apartment) {
    header('Location: delivery-orders.php');
    exit;
}

/* ---------------- FILTERS ---------------- */
$statusFilter = trim($_GET['status'] ?? 'pending');   // pending | delivered | all
$dateFilter   = trim($_GET['date']   ?? '');          // yyyy-mm-dd, empty = all

$allowedStatus = ['pending', 'delivered', 'all'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'pending';

/* ---------------- FETCH ORDERS ---------------- */
$orders = [];
try {
    $sql = "SELECT id, order_code, delivery_boy_id,
                   customer_name, customer_mobile,
                   apartment_name, apartment_code, division, division_charge,
                   subtotal, total_amount,
                   products_json,
                   status, delivery_status, payment_status,
                   payment_ref, paid_at, created_at
            FROM orders
            WHERE apartment_code = ?
              AND delivery_boy_id = ?
              AND status <> 'cancelled'";

    $params = [$aptCode, $boyId];

    if ($statusFilter === 'pending') {
        $sql .= " AND delivery_status = 'disabled'";
    } elseif ($statusFilter === 'delivered') {
        $sql .= " AND delivery_status = 'enabled'";
    }

    if ($dateFilter !== '') {
        $sql .= " AND DATE(created_at) = ?";
        $params[] = $dateFilter;
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $orders = [];
}

/* Prepare JS payload */
$ordersJson = array_map(function ($o) {
    return [
        'id'               => (int)$o['id'],
        'order_code'       => $o['order_code'],
        'created_at'       => $o['created_at'],
        'customer_name'    => $o['customer_name'],
        'customer_mobile'  => $o['customer_mobile'],
        'apartment_code'   => $o['apartment_code'],
        'apartment_name'   => $o['apartment_name'],
        'division'         => $o['division'],
        'division_charge'  => (float)$o['division_charge'],
        'subtotal'         => (float)$o['subtotal'],
        'total_amount'     => (float)$o['total_amount'],
        'status'           => $o['status'],
        'delivery_status'  => $o['delivery_status'],
        'payment_status'   => $o['payment_status'],
        'payment_ref'      => $o['payment_ref'],
        'paid_at'          => $o['paid_at'],
        'products'         => json_decode($o['products_json'] ?? '[]', true) ?: [],
    ];
}, $orders);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        /* =====================================================
           APARTMENT ORDERS PAGE
           ===================================================== */
        .ao-page { padding: 24px 26px 40px; }

        .ao-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #6f675f;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            margin-bottom: 14px;
            transition: .15s ease;
        }
        .ao-back:hover { color: #b51f2c; }

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

        /* Filters row */
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
        .ao-tab:hover { color: #b51f2c; }
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
        }
        .ao-clear-btn:hover {
            background: #faf7f0;
            color: #302923;
        }

        /* Table */
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
        .ao-table tbody tr:last-child td { border-bottom: 0; }
        .ao-table tbody tr:hover { background: #fffaf5; }

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

        .ao-name { font-weight: 700; display: block; }
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
        .ao-phone:hover { background: #b51f2c; color: #fff; }

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
        .ao-pill.is-enabled { background: #e7f6ec; color: #1f7a3d; }
        .ao-pill.is-unpaid,
        .ao-pill.is-disabled { background: #fbeaea; color: #b51f2c; }

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
            padding: 0 14px;
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
        .ao-toggle:disabled { opacity: .55; cursor: not-allowed; }

        .ao-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            justify-content: flex-end;
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

        /* Modal (re-used) */
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
        .ao-modal-overlay.show { opacity: 1; visibility: visible; }

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
        .ao-modal-overlay.show .ao-modal { transform: translateY(0) scale(1); }

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
        .ao-modal-close:hover { background: #fbeaea; color: #b51f2c; }

        .ao-modal-body { padding: 20px 22px 22px; }

        .ao-modal-section { margin-bottom: 22px; }
        .ao-modal-section:last-child { margin-bottom: 0; }

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
        .ao-modal-title i { color: #b51f2c; }

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
        .ao-info-row .lbl { color: #948c82; font-weight: 600; }
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
        .ao-call:hover { background: #b51f2c; color: #fff; }

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
        .ao-prod-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .ao-prod-info { flex: 1; min-width: 0; }
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
        .ao-trow strong { color: #302923; font-weight: 800; }
        .ao-trow.grand {
            font-size: 15px;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1.5px dashed #e4ddd3;
        }
        .ao-trow.grand strong { color: #b51f2c; font-size: 17px; }

        /* Toast */
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
        }
        .ao-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .ao-toast.error { background: #b51f2c; }

        .btn-spinner {
            width: 12px;
            height: 12px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: aoSpin .7s linear infinite;
            display: inline-block;
        }
        @keyframes aoSpin { to { transform: rotate(360deg); } }

        @media (max-width: 900px) {
            .ao-table-wrap { overflow-x: auto; }
            .ao-table { min-width: 720px; }
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


            <!-- Filters -->
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


            <!-- Orders table -->
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
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="aoBody">
                            <?php foreach ($orders as $o):
                                $isEnabled = ($o['delivery_status'] === 'enabled');
                                $mobile    = preg_replace('/[^0-9+]/', '', (string)$o['customer_mobile']);
                            ?>
                                <tr
                                    data-id="<?= (int)$o['id'] ?>"
                                    data-delivery="<?= $isEnabled ? 'enabled' : 'disabled' ?>">
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
                                        <div class="ao-actions">
                                            <button type="button" class="ao-icon-btn js-view" data-id="<?= (int)$o['id'] ?>" title="View details">
                                                <i class="bi bi-eye"></i>
                                            </button>

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


    <div class="ao-toast" id="aoToast"></div>


    <!-- ================= GLOBALS ================= -->
    <script>
        window.BASE_URL  = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
        window.ORDERS    = <?= json_encode($ordersJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        window.CURRENT_STATUS = "<?= htmlspecialchars($statusFilter) ?>";
    </script>

    <!-- SIDEBAR TOGGLE -->
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

    <!-- PAGE SCRIPT -->
    <script src="<?= BASE_URL ?>js/apartment-orders.js"></script>

</body>

</html>