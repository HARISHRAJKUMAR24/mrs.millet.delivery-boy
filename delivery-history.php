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

/* ---------------- FILTERS ---------------- */
$filter   = $_GET['filter']   ?? 'all';      // all | today | yesterday | week | month | custom
$status   = $_GET['status']   ?? 'all';      // all | pending | confirmed | processing | delivered | cancelled
$from     = $_GET['from']     ?? '';
$to       = $_GET['to']       ?? '';
$q        = trim($_GET['q']   ?? '');

$where  = ["o.delivery_boy_id = ?"];
$params = [$boyId];

/* Date filter */
if ($filter === 'today') {
    $where[] = "DATE(o.created_at) = CURDATE()";
} elseif ($filter === 'yesterday') {
    $where[] = "DATE(o.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($filter === 'week') {
    $where[] = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
} elseif ($filter === 'month') {
    $where[] = "YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
} elseif ($filter === 'custom') {
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = "DATE(o.created_at) >= ?";
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = "DATE(o.created_at) <= ?";
        $params[] = $to;
    }
}

/* Status filter */
if (in_array($status, ['pending','confirmed','processing','delivered','cancelled'], true)) {
    $where[] = "o.status = ?";
    $params[] = $status;
}

/* Search */
if ($q !== '') {
    $where[] = "(o.order_code LIKE ? OR c.full_name LIKE ? OR c.mobile_number LIKE ?
                 OR o.address_json LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ---------------- FETCH ROWS ---------------- */
$rows = [];
try {
    $sql = "SELECT
                o.id,
                o.order_code,
                o.customer_id,
                c.full_name     AS customer_name,
                c.mobile_number AS customer_mobile,
                o.address_type,
                o.address_json,
                o.delivery_mode,
                o.total_amount,
                o.status,
                o.payment_status,
                o.order_note,
                o.created_at,
                o.paid_at,
                b.full_name AS boy_name
            FROM orders o
            LEFT JOIN customers c ON c.customer_id = o.customer_id
            LEFT JOIN delivery_boys b ON b.id = o.delivery_boy_id
            $whereSql
            ORDER BY o.id DESC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

/* ---------------- SUMMARY ---------------- */
$summary = [
    'total'     => count($rows),
    'delivered' => 0,
    'pending'   => 0,
    'cancelled' => 0,
    'revenue'   => 0.0,
];
foreach ($rows as $r) {
    if ($r['status'] === 'delivered') $summary['delivered']++;
    if ($r['status'] === 'cancelled') $summary['cancelled']++;
    if (!in_array($r['status'], ['delivered','cancelled'], true)) $summary['pending']++;
    if ($r['payment_status'] === 'paid') $summary['revenue'] += (float)$r['total_amount'];
}

/* Helper to render address line */
function renderAddress(array $r): array {
    $json = json_decode($r['address_json'] ?? '', true) ?: [];
    if ($r['delivery_mode'] === 'pickup') {
        return ['Pickup', 'bi-shop'];
    }
    if ($r['address_type'] === 'individual_house') {
        return [$json['full_address'] ?? 'Individual House', 'bi-house-door'];
    }
    $apt   = $json['apartment_name'] ?? 'Apartment';
    $label = $json['division_label'] ?? '';
    return [$apt . ($label ? ' · ' . $label : ''), 'bi-building'];
}

$statusClassMap = [
    'pending'    => 'status-pending',
    'confirmed'  => 'status-confirmed',
    'processing' => 'status-processing',
    'delivered'  => 'status-delivered',
    'cancelled'  => 'status-cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        .dh-page { padding: 24px 26px 40px; }

        .dh-head {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .dh-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px; font-weight: 700; color: #302923; margin: 0 0 4px;
        }
        .dh-head p { margin: 0; font-size: 12.5px; color: #817a71; }

        /* Summary cards */
        .dh-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .dh-stat {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            padding: 14px 16px;
        }
        .dh-stat-label {
            font-size: 10.5px; font-weight: 800; color: #948c82;
            letter-spacing: .5px; text-transform: uppercase; margin-bottom: 6px;
        }
        .dh-stat-value {
            font-family: "Playfair Display", serif;
            font-size: 22px; font-weight: 700; color: #302923; line-height: 1;
        }
        .dh-stat.green .dh-stat-value { color: #2e7d32; }
        .dh-stat.red   .dh-stat-value { color: #b51f2c; }

        /* Filters row */
        .dh-filters {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 16px; flex-wrap: wrap;
        }

        .dh-tabs {
            display: inline-flex; background: #fdfaf4;
            border: 1.5px solid #ece5da; border-radius: 11px;
            padding: 4px; gap: 4px; flex-wrap: wrap;
        }
        .dh-tab {
            border: none; background: transparent; padding: 8px 14px;
            font-family: "DM Sans", sans-serif; font-size: 11.5px;
            font-weight: 700; color: #6f675f; border-radius: 8px;
            cursor: pointer; white-space: nowrap; transition: .15s ease;
        }
        .dh-tab:hover { color: #b51f2c; }
        .dh-tab.active {
            background: #fff; color: #b51f2c;
            box-shadow: 0 4px 12px rgba(48, 41, 35, .08);
        }

        .dh-select {
            height: 40px; padding: 0 32px 0 14px;
            border: 1.5px solid #ece5da; background: #fff;
            border-radius: 11px; font-family: inherit; font-size: 12px;
            font-weight: 700; color: #4e4841; cursor: pointer;
            appearance: none; outline: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 16 16'><path fill='%23817a71' d='M8 11L3 6h10z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 11px center;
        }

        .dh-daterange {
            display: none; align-items: center; gap: 8px;
            height: 40px; padding: 0 10px; background: #fff;
            border: 1.5px solid #ece5da; border-radius: 11px;
            font-size: 11.5px; color: #817a71;
        }
        .dh-daterange.show { display: inline-flex; }
        .dh-daterange input[type="date"] {
            border: none; background: transparent;
            font-family: inherit; font-size: 11.5px; font-weight: 700;
            color: #302923; cursor: pointer; outline: none;
        }

        .dh-search {
            flex: 1; min-width: 220px; max-width: 340px; position: relative;
        }
        .dh-search input {
            width: 100%; height: 40px; border: 1.5px solid #ece5da;
            background: #fff; border-radius: 11px; padding: 0 14px 0 40px;
            font-family: inherit; font-size: 12.5px; color: #292521; outline: none;
            transition: .2s ease;
        }
        .dh-search input:focus {
            border-color: #b51f2c;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }
        .dh-search > i {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%); color: #b0a79c;
            font-size: 14px; pointer-events: none;
        }

        /* List */
        .dh-card {
            background: #fff; border: 1.5px solid #ece5da;
            border-radius: 16px; overflow: hidden;
        }

        .dh-row {
            display: grid;
            grid-template-columns: 44px 1fr auto;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border-bottom: 1px solid #f4efe8;
            cursor: pointer;
            transition: .15s ease;
        }
        .dh-row:last-child { border-bottom: 0; }
        .dh-row:hover { background: #fffcf5; }

        .dh-avatar {
            width: 44px; height: 44px; border-radius: 12px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 800;
        }

        .dh-main { min-width: 0; }
        .dh-code {
            font-size: 12.5px; font-weight: 800; color: #b51f2c; margin: 0;
        }
        .dh-cust {
            font-size: 12.5px; font-weight: 700; color: #302923; margin: 2px 0 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .dh-meta {
            font-size: 10.5px; color: #948c82; margin: 3px 0 0;
            display: flex; gap: 10px; flex-wrap: wrap;
        }
        .dh-meta i { color: #b51f2c; margin-right: 3px; }

        .dh-side {
            display: flex; flex-direction: column; align-items: flex-end; gap: 6px;
            text-align: right;
        }
        .dh-amount {
            font-family: "Playfair Display", serif;
            font-size: 14px; font-weight: 700; color: #b51f2c;
        }
        .dh-date {
            font-size: 10.5px; color: #948c82;
            display: inline-flex; align-items: center; gap: 4px;
        }

        .dh-badge {
            display: inline-block; padding: 4px 10px; border-radius: 20px;
            font-size: 10px; font-weight: 800; letter-spacing: .4px;
            text-transform: capitalize; white-space: nowrap;
        }
        .status-pending    { background: #fdf1e2; color: #a35a0e; }
        .status-confirmed  { background: #e8f1e8; color: #2e7d32; }
        .status-processing { background: #e5eefb; color: #1565c0; }
        .status-delivered  { background: #e8f6ea; color: #1b5e20; }
        .status-cancelled  { background: #fdeaea; color: #b51f2c; }

        .dh-empty {
            text-align: center; padding: 60px 20px; color: #948c82;
            background: #fff; border: 1.5px dashed #ece5da; border-radius: 18px;
        }
        .dh-empty i {
            font-size: 44px; color: #ece5da; display: block; margin-bottom: 10px;
        }
        .dh-empty h3 {
            font-family: "Playfair Display", serif; font-size: 18px;
            color: #302923; margin: 0 0 6px;
        }
        .dh-empty p { font-size: 12.5px; margin: 0; }

        @media (max-width: 768px) {
            .dh-stats { grid-template-columns: repeat(2, 1fr); }
            .dh-row { grid-template-columns: 40px 1fr auto; gap: 10px; padding: 12px 14px; }
            .dh-search { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .dh-stats { grid-template-columns: 1fr; }
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
                <h2>Delivery History</h2>
                <p>All past deliveries</p>
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

        <main class="dh-page">

            <div class="dh-head">
                <div>
                    <h1>Delivery History</h1>
                    <p>Showing <strong><?= $summary['total'] ?></strong> order<?= $summary['total'] === 1 ? '' : 's' ?> in the selected range.</p>
                </div>
            </div>

            <div class="dh-stats">
                <div class="dh-stat">
                    <div class="dh-stat-label">Total</div>
                    <div class="dh-stat-value"><?= $summary['total'] ?></div>
                </div>
                <div class="dh-stat green">
                    <div class="dh-stat-label">Delivered</div>
                    <div class="dh-stat-value"><?= $summary['delivered'] ?></div>
                </div>
                <div class="dh-stat red">
                    <div class="dh-stat-label">Pending</div>
                    <div class="dh-stat-value"><?= $summary['pending'] ?></div>
                </div>
                <div class="dh-stat">
                    <div class="dh-stat-label">Collected</div>
                    <div class="dh-stat-value">₹<?= number_format($summary['revenue'], 0) ?></div>
                </div>
            </div>

            <form method="get" class="dh-filters" id="dhFilterForm">
                <div class="dh-tabs" id="dhDateTabs">
                    <button type="button" class="dh-tab <?= $filter === 'all'       ? 'active' : '' ?>" data-filter="all">All</button>
                    <button type="button" class="dh-tab <?= $filter === 'today'     ? 'active' : '' ?>" data-filter="today">Today</button>
                    <button type="button" class="dh-tab <?= $filter === 'yesterday' ? 'active' : '' ?>" data-filter="yesterday">Yesterday</button>
                    <button type="button" class="dh-tab <?= $filter === 'week'      ? 'active' : '' ?>" data-filter="week">Last 7 Days</button>
                    <button type="button" class="dh-tab <?= $filter === 'month'     ? 'active' : '' ?>" data-filter="month">This Month</button>
                    <button type="button" class="dh-tab <?= $filter === 'custom'    ? 'active' : '' ?>" data-filter="custom">Custom</button>
                </div>

                <select name="status" class="dh-select" onchange="this.form.submit()">
                    <option value="all"        <?= $status === 'all'        ? 'selected' : '' ?>>All Statuses</option>
                    <option value="pending"    <?= $status === 'pending'    ? 'selected' : '' ?>>Pending</option>
                    <option value="confirmed"  <?= $status === 'confirmed'  ? 'selected' : '' ?>>Confirmed</option>
                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="delivered"  <?= $status === 'delivered'  ? 'selected' : '' ?>>Delivered</option>
                    <option value="cancelled"  <?= $status === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                </select>

                <div class="dh-daterange <?= $filter === 'custom' ? 'show' : '' ?>" id="dhDateRange">
                    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                    <span>to</span>
                    <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
                </div>

                <div class="dh-search">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                           placeholder="Search order code, name, mobile…">
                </div>

                <input type="hidden" name="filter" id="dhFilterValue" value="<?= htmlspecialchars($filter) ?>">
            </form>

            <?php if (empty($rows)): ?>

                <div class="dh-empty">
                    <i class="bi bi-receipt"></i>
                    <h3>No deliveries found</h3>
                    <p>Try changing the filters or date range.</p>
                </div>

            <?php else: ?>

                <div class="dh-card">
                    <?php foreach ($rows as $r):
                        [$addrLabel, $addrIcon] = renderAddress($r);
                        $initial = strtoupper(substr(trim($r['customer_name'] ?: '?'), 0, 1));
                        $dt      = date('d M Y · h:i A', strtotime($r['created_at']));
                        $badge   = $statusClassMap[$r['status']] ?? 'status-pending';
                    ?>
                        <div class="dh-row">

                            <div class="dh-avatar"><?= htmlspecialchars($initial) ?></div>

                            <div class="dh-main">
                                <p class="dh-code">#<?= htmlspecialchars($r['order_code']) ?></p>
                                <p class="dh-cust"><?= htmlspecialchars($r['customer_name'] ?: '—') ?></p>
                                <p class="dh-meta">
                                    <span><i class="bi bi-telephone-fill"></i><?= htmlspecialchars($r['customer_mobile'] ?: '—') ?></span>
                                    <span><i class="bi <?= $addrIcon ?>"></i><?= htmlspecialchars($addrLabel) ?></span>
                                </p>
                            </div>

                            <div class="dh-side">
                                <span class="dh-badge <?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                <span class="dh-amount">₹<?= number_format((float)$r['total_amount'], 0) ?></span>
                                <span class="dh-date"><i class="bi bi-clock"></i><?= htmlspecialchars($dt) ?></span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        window.BASE_URL  = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
    </script>

    <!-- Sidebar toggle -->
    <script>
        (function() {
            const sidebar  = document.getElementById("sidebar");
            const toggle   = document.getElementById("menuToggle");
            const closeBtn = document.getElementById("sidebarClose");
            const overlay  = document.getElementById("sbOverlay");

            function openSidebar()  {
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

            toggle?.addEventListener("click", openSidebar);
            closeBtn?.addEventListener("click", closeSidebar);
            overlay?.addEventListener("click", closeSidebar);
        })();
    </script>

    <!-- Filters behavior -->
    <script>
        (function() {
            const form     = document.getElementById("dhFilterForm");
            const tabs     = document.querySelectorAll("#dhDateTabs .dh-tab");
            const hidden   = document.getElementById("dhFilterValue");
            const rangeBox = document.getElementById("dhDateRange");

            tabs.forEach(tab => {
                tab.addEventListener("click", () => {
                    const val = tab.dataset.filter;
                    hidden.value = val;

                    tabs.forEach(t => t.classList.toggle("active", t === tab));
                    rangeBox.classList.toggle("show", val === "custom");

                    // Auto-submit unless custom (let user pick dates first)
                    if (val !== "custom") form.submit();
                });
            });

            // If custom + both dates → submit
            rangeBox.querySelectorAll("input").forEach(inp => {
                inp.addEventListener("change", () => {
                    const [f, t] = rangeBox.querySelectorAll("input");
                    if (f.value && t.value) form.submit();
                });
            });
        })();
    </script>

</body>
</html>