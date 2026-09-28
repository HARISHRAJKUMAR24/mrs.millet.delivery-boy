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

$settings = getSettings($pdo);
$siteName = $settings['username'] ?? 'Mrs Mill@';

/* =========================================================
   DATE FILTER
   ========================================================= */
$range = $_GET['range'] ?? 'today';
$from  = $_GET['from']  ?? '';
$to    = $_GET['to']    ?? '';

$dateFrom = null;
$dateTo   = null;

switch ($range) {
    case 'today':
        $dateFrom = date('Y-m-d 00:00:00');
        $dateTo   = date('Y-m-d 23:59:59');
        break;
    case 'yesterday':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $dateTo   = date('Y-m-d 23:59:59', strtotime('-1 day'));
        break;
    case 'week':
        $dateFrom = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $dateTo   = date('Y-m-d 23:59:59');
        break;
    case 'month':
        $dateFrom = date('Y-m-01 00:00:00');
        $dateTo   = date('Y-m-d 23:59:59');
        break;
    case 'custom':
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $dateFrom = $from . ' 00:00:00';
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $dateTo = $to . ' 23:59:59';
        }
        break;
    case 'all':
    default:
        $dateFrom = null;
        $dateTo   = null;
        break;
}

/* Build WHERE for delivered orders */
$where  = ["o.delivery_boy_id = ?", "o.status <> 'cancelled'", "o.delivery_status = 'enabled'"];
$params = [$boyId];

if ($dateFrom !== null) {
    $where[]  = "o.updated_at >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== null) {
    $where[]  = "o.updated_at <= ?";
    $params[] = $dateTo;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* =========================================================
   STATS (respect same filter)
   ========================================================= */
$totalDelivered = 0;
$totalRevenue   = 0;

try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total_amount), 0) AS revenue
         FROM orders o
         $whereSql"
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $totalDelivered = (int)$row['cnt'];
        $totalRevenue   = (float)$row['revenue'];
    }
} catch (PDOException $e) {}

/* Also total pending (not filtered by date for a cleaner overview) */
$totalPending = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE delivery_boy_id = ?
           AND status <> 'cancelled'
           AND delivery_status = 'disabled'"
    );
    $stmt->execute([$boyId]);
    $totalPending = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

/* =========================================================
   PAGINATION
   ========================================================= */
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));

$totalRows = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSql");
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

/* =========================================================
   FETCH ORDER LIST
   ========================================================= */
$orders = [];
try {
    $sql = "SELECT o.id, o.order_code, o.customer_name, o.customer_mobile,
                   o.apartment_name, o.division, o.delivery_mode, o.pickup_branch_name,
                   o.total_amount, o.products_json, o.payment_status,
                   o.paid_at, o.created_at, o.updated_at
            FROM orders o
            $whereSql
            ORDER BY o.updated_at DESC, o.id DESC
            LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

/* ---------- helpers ---------- */
function rupees($n) {
    return '₹' . number_format((int)round($n));
}

/* Preserve current query string for pagination links */
$qsBase = $_GET;
unset($qsBase['page']);

function pageUrl($p, $qsBase) {
    $qsBase['page'] = $p;
    return '?' . http_build_query($qsBase);
}

/* Label for active range */
$rangeLabels = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'week'      => 'Last 7 Days',
    'month'     => 'This Month',
    'custom'    => 'Custom Range',
    'all'       => 'All Time',
];
$activeLabel = $rangeLabels[$range] ?? 'Today';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <?php include './includes/head.php'; ?>

    <style>
        .hi-page { padding: 24px 26px 60px; }

        .hi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .hi-header h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }
        .hi-header p { margin: 0; color: #817a71; font-size: 12.5px; }

        .hi-range-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: #e8f6ea;
            color: #1b5e20;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .04em;
        }

        /* =====================================================
           FILTER BAR
           ===================================================== */
        .hi-filters {
            background: #fff;
            border: 1.5px solid #eee7dc;
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .hi-tabs {
            display: inline-flex;
            background: #fdfaf4;
            border: 1.5px solid #ece5da;
            border-radius: 11px;
            padding: 4px;
            gap: 4px;
            flex-wrap: wrap;
        }

        .hi-tab {
            border: none;
            background: transparent;
            padding: 8px 14px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            color: #6f675f;
            border-radius: 8px;
            cursor: pointer;
            transition: .2s ease;
            text-decoration: none;
            white-space: nowrap;
        }
        .hi-tab:hover { color: #b51f2c; }
        .hi-tab.active {
            background: #fff;
            color: #b51f2c;
            box-shadow: 0 4px 12px rgba(48, 41, 35, .08);
        }

        .hi-custom {
            display: none;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            color: #817a71;
            font-weight: 600;
        }
        .hi-custom.show { display: inline-flex; }

        .hi-custom input[type="date"] {
            height: 36px;
            border: 1.5px solid #ece5da;
            background: #fff;
            border-radius: 9px;
            padding: 0 10px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            color: #302923;
            font-weight: 700;
            cursor: pointer;
            outline: none;
        }
        .hi-custom input[type="date"]:focus {
            border-color: #b51f2c;
            box-shadow: 0 0 0 3px rgba(181,31,44,.08);
        }

        .hi-apply-btn {
            height: 36px;
            padding: 0 16px;
            border-radius: 9px;
            border: none;
            background: #b51f2c;
            color: #fff;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 800;
            cursor: pointer;
            transition: .2s ease;
        }
        .hi-apply-btn:hover { background: #8e1722; }

        /* =====================================================
           STAT CARDS
           ===================================================== */
        .hi-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .hi-stat {
            background: #fff;
            border: 1.5px solid #eee7dc;
            border-radius: 16px;
            padding: 16px 18px;
        }

        .hi-stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: #e8f6ea;
            color: #1b5e20;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            margin-bottom: 12px;
        }
        .hi-stat-icon.blue { background: #e5eefb; color: #1565c0; }
        .hi-stat-icon.gold { background: #fdf1e2; color: #a35a0e; }

        .hi-stat-label {
            font-size: 10.5px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 4px;
        }

        .hi-stat-value {
            font-family: "Playfair Display", serif;
            font-size: 24px;
            font-weight: 700;
            color: #302923;
            line-height: 1.1;
            margin-bottom: 3px;
        }
        .hi-stat-value.green { color: #1b5e20; }
        .hi-stat-value.gold  { color: #a35a0e; }

        .hi-stat-sub {
            font-size: 11px;
            color: #948c82;
            font-weight: 600;
        }

        /* =====================================================
           LIST CARD
           ===================================================== */
        .hi-card {
            background: #fff;
            border: 1.5px solid #eee7dc;
            border-radius: 18px;
            overflow: hidden;
        }

        .hi-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1.5px solid #f0ebe4;
            flex-wrap: wrap;
        }

        .hi-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: "Playfair Display", serif;
            font-size: 17px;
            font-weight: 700;
            color: #302923;
            margin: 0;
        }
        .hi-card-title i {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: #e8f6ea;
            color: #1b5e20;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .hi-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            background: #e8f6ea;
            color: #1b5e20;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
        }

        /* Orders list */
        .hi-list {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .hi-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border: 1.5px solid #f0ebe4;
            border-radius: 14px;
            background: #fffdf9;
            transition: .15s ease;
        }
        .hi-item:hover {
            border-color: #d98a91;
            background: #fff5f5;
        }

        .hi-item-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1f7a3d 0%, #2e7d32 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(31, 122, 61, .25);
        }

        .hi-item-info { flex: 1; min-width: 0; }

        .hi-item-top {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hi-code {
            font-family: "DM Sans", sans-serif;
            font-weight: 800;
            color: #b51f2c;
            font-size: 13px;
            letter-spacing: .3px;
        }

        .hi-mode {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            background: #f1ece4;
            color: #6f675f;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .hi-mode.pickup   { background: #fdf1e2; color: #a35a0e; }
        .hi-mode.delivery { background: #e5eefb; color: #1565c0; }

        .hi-name {
            font-weight: 700;
            color: #302923;
            font-size: 13px;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .hi-meta {
            font-size: 11px;
            color: #948c82;
            margin-top: 3px;
            font-weight: 600;
        }

        .hi-item-right {
            text-align: right;
            flex-shrink: 0;
        }

        .hi-amount {
            font-family: "Playfair Display", serif;
            font-weight: 700;
            color: #1b5e20;
            font-size: 15px;
            margin: 0;
        }

        .hi-time {
            font-size: 10.5px;
            color: #948c82;
            margin-top: 3px;
            font-weight: 600;
        }

        /* Empty */
        .hi-empty {
            padding: 60px 20px;
            text-align: center;
            color: #948c82;
        }
        .hi-empty i {
            font-size: 44px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }
        .hi-empty h3 {
            font-family: "Playfair Display", serif;
            font-size: 17px;
            color: #6f675f;
            margin: 0 0 6px;
            font-weight: 700;
        }
        .hi-empty p { margin: 0; font-size: 12.5px; }

        /* =====================================================
           PAGINATION
           ===================================================== */
        .hi-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            border-top: 1.5px solid #f0ebe4;
            flex-wrap: wrap;
        }

        .hi-page-info {
            font-size: 12px;
            color: #817a71;
        }
        .hi-page-info strong {
            color: #302923;
            font-weight: 800;
        }

        .hi-page-controls {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .hi-page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 9px;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: .15s ease;
        }
        .hi-page-btn:hover:not(.active):not(.disabled) {
            background: #fbe8e9;
            border-color: #d98a91;
            color: #b51f2c;
        }
        .hi-page-btn.active {
            background: #b51f2c;
            border-color: #b51f2c;
            color: #fff;
            box-shadow: 0 4px 10px rgba(181, 31, 44, .25);
            cursor: default;
        }
        .hi-page-btn.disabled {
            opacity: .4;
            cursor: not-allowed;
            pointer-events: none;
        }
        .hi-page-btn i { font-size: 11px; }

        /* Responsive */
        @media (max-width: 768px) {
            .hi-page { padding: 18px 14px 40px; }
            .hi-stats { grid-template-columns: 1fr; }
            .hi-item { flex-wrap: wrap; }
            .hi-item-right { width: 100%; text-align: left; margin-top: 8px; }
            .hi-filters { flex-direction: column; align-items: stretch; }
            .hi-tabs { justify-content: center; }
            .hi-pagination { flex-direction: column; align-items: stretch; }
            .hi-page-controls { justify-content: center; flex-wrap: wrap; }
            .hi-page-info { text-align: center; }
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
                <h2>My History</h2>
                <p>All orders you've delivered</p>
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


        <main class="hi-page">

            <div class="hi-header">
                <div>
                    <h1>Delivery History</h1>
                    <p>Everything you've delivered — with totals.</p>
                </div>
                <span class="hi-range-label">
                    <i class="bi bi-calendar3"></i>
                    <?= htmlspecialchars($activeLabel) ?>
                </span>
            </div>


            <!-- ================= FILTERS ================= -->
            <form class="hi-filters" method="GET" id="hiFilterForm">

                <div class="hi-tabs">
                    <a href="?range=today"     class="hi-tab <?= $range === 'today'     ? 'active' : '' ?>">Today</a>
                    <a href="?range=yesterday" class="hi-tab <?= $range === 'yesterday' ? 'active' : '' ?>">Yesterday</a>
                    <a href="?range=week"      class="hi-tab <?= $range === 'week'      ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="?range=month"     class="hi-tab <?= $range === 'month'     ? 'active' : '' ?>">This Month</a>
                    <a href="?range=all"       class="hi-tab <?= $range === 'all'       ? 'active' : '' ?>">All Time</a>
                    <a href="#" class="hi-tab <?= $range === 'custom' ? 'active' : '' ?>" id="hiCustomTab">Custom</a>
                </div>

                <div class="hi-custom <?= $range === 'custom' ? 'show' : '' ?>" id="hiCustomBox">
                    <input type="hidden" name="range" value="<?= $range === 'custom' ? 'custom' : '' ?>" id="hiRangeInput">
                    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                    <span>to</span>
                    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
                    <button type="submit" class="hi-apply-btn">Apply</button>
                </div>

            </form>


            <!-- ================= STAT CARDS ================= -->
            <div class="hi-stats">

                <div class="hi-stat">
                    <div class="hi-stat-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div class="hi-stat-label">Delivered</div>
                    <div class="hi-stat-value green"><?= $totalDelivered ?></div>
                    <div class="hi-stat-sub"><?= htmlspecialchars($activeLabel) ?></div>
                </div>

                <div class="hi-stat">
                    <div class="hi-stat-icon blue">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div class="hi-stat-label">Pending</div>
                    <div class="hi-stat-value"><?= $totalPending ?></div>
                    <div class="hi-stat-sub">Yet to deliver</div>
                </div>

                <div class="hi-stat">
                    <div class="hi-stat-icon gold">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div class="hi-stat-label">Delivered Value</div>
                    <div class="hi-stat-value gold"><?= rupees($totalRevenue) ?></div>
                    <div class="hi-stat-sub"><?= htmlspecialchars($activeLabel) ?></div>
                </div>

            </div>


            <!-- ================= LIST ================= -->
            <div class="hi-card">

                <div class="hi-card-head">
                    <h2 class="hi-card-title">
                        <i class="bi bi-clock-history"></i>
                        Delivered Orders
                    </h2>
                    <span class="hi-badge">
                        <i class="bi bi-check-circle-fill"></i>
                        <?= $totalRows ?> order<?= $totalRows === 1 ? '' : 's' ?>
                    </span>
                </div>

                <?php if (empty($orders)): ?>
                    <div class="hi-empty">
                        <i class="bi bi-inbox"></i>
                        <h3>No deliveries in this range</h3>
                        <p>Try a different date filter.</p>
                    </div>
                <?php else: ?>
                    <div class="hi-list">
                        <?php foreach ($orders as $o):
                            $isPickup = ($o['delivery_mode'] === 'pickup');

                            $products = json_decode($o['products_json'] ?? '[]', true);
                            $itemCount = 0;
                            if (is_array($products)) {
                                foreach ($products as $p) {
                                    $itemCount += (int)($p['qty'] ?? 0);
                                }
                            }
                        ?>
                            <div class="hi-item">

                                <div class="hi-item-icon">
                                    <i class="bi bi-check-lg"></i>
                                </div>

                                <div class="hi-item-info">
                                    <div class="hi-item-top">
                                        <span class="hi-code">#<?= htmlspecialchars($o['order_code']) ?></span>
                                        <span class="hi-mode <?= $isPickup ? 'pickup' : 'delivery' ?>">
                                            <i class="bi <?= $isPickup ? 'bi-shop' : 'bi-truck' ?>"></i>
                                            <?= $isPickup ? 'Pickup' : 'Delivery' ?>
                                        </span>
                                    </div>

                                    <div class="hi-name">
                                        <?= htmlspecialchars($o['customer_name']) ?>
                                    </div>

                                    <div class="hi-meta">
                                        <?php if ($isPickup): ?>
                                            <?= htmlspecialchars($o['pickup_branch_name'] ?: 'Store Pickup') ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($o['apartment_name'] ?: '—') ?>
                                            <?php if (!empty($o['division'])): ?>
                                                · Division <?= htmlspecialchars($o['division']) ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        · <?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?>
                                    </div>
                                </div>

                                <div class="hi-item-right">
                                    <p class="hi-amount"><?= rupees($o['total_amount']) ?></p>
                                    <p class="hi-time">
                                        <?= date('d M Y, h:i A', strtotime($o['updated_at'] ?: $o['created_at'])) ?>
                                    </p>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- ================= PAGINATION ================= -->
                    <?php if ($totalPages > 1): ?>
                        <div class="hi-pagination">

                            <div class="hi-page-info">
                                Showing
                                <strong><?= $offset + 1 ?></strong>–<strong><?= min($offset + $perPage, $totalRows) ?></strong>
                                of <strong><?= $totalRows ?></strong>
                            </div>

                            <div class="hi-page-controls">
                                <!-- Prev -->
                                <a href="<?= $page > 1 ? htmlspecialchars(pageUrl($page - 1, $qsBase)) : '#' ?>"
                                   class="hi-page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                                   aria-label="Previous">
                                    <i class="bi bi-chevron-left"></i>
                                </a>

                                <?php
                                /* Compact page numbers */
                                $window = 1;
                                $pages  = [];
                                for ($i = 1; $i <= $totalPages; $i++) {
                                    if ($i === 1 || $i === $totalPages ||
                                        ($i >= $page - $window && $i <= $page + $window)) {
                                        $pages[] = $i;
                                    }
                                }

                                $prev = 0;
                                foreach ($pages as $p):
                                    if ($prev && $p - $prev > 1): ?>
                                        <span class="hi-page-btn disabled" style="border:none;background:transparent;">…</span>
                                    <?php endif; ?>

                                    <a href="<?= htmlspecialchars(pageUrl($p, $qsBase)) ?>"
                                       class="hi-page-btn <?= $p === $page ? 'active' : '' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php $prev = $p; endforeach; ?>

                                <!-- Next -->
                                <a href="<?= $page < $totalPages ? htmlspecialchars(pageUrl($page + 1, $qsBase)) : '#' ?>"
                                   class="hi-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                   aria-label="Next">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </div>

                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </main>
    </div>


    <!-- Sidebar toggle -->
    <script>
        (function () {
            const sidebar  = document.getElementById("sidebar");
            const toggle   = document.getElementById("menuToggle");
            const closeBtn = document.getElementById("sidebarClose");
            const overlay  = document.getElementById("sbOverlay");

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

        /* Custom tab toggle */
        (function() {
            var tab = document.getElementById("hiCustomTab");
            var box = document.getElementById("hiCustomBox");
            var rangeInput = document.getElementById("hiRangeInput");
            if (!tab || !box) return;

            tab.addEventListener("click", function(e) {
                e.preventDefault();
                box.classList.add("show");
                rangeInput.value = "custom";
            });
        })();
    </script>

</body>

</html>