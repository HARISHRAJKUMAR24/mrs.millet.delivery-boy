<?php
require_once './config/config.php';
require_once './config/function.php';

/* ---------------- AUTH CHECK ---------------- */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    header('Location: login.php');
    exit;
}

$boyId = (int)$_SESSION['delivery_boy_id'];

/* ---------------- BOY DETAILS ---------------- */
$stmt = $pdo->prepare(
    "SELECT id, delivery_code, full_name, mobile_number, status
     FROM delivery_boys WHERE id = ? LIMIT 1"
);
$stmt->execute([$boyId]);
$boy = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$boy || (int)$boy['status'] !== 1) {
    session_destroy();
    header('Location: login.php?reason=not_approved');
    exit;
}

/* ---------------- SETTINGS / LOGO ---------------- */
$settings = getSettings($pdo);
$siteName = $settings['username'] ?? 'Mrs Mill@';

$firstName = explode(' ', trim($boy['full_name']))[0];

/* =========================================================
   REAL STATS FROM DB
   ========================================================= */
$assignedCount   = 0;
$inProgressCount = 0;
$deliveredCount  = 0;
$totalCount      = 0;

try {
    // Assigned today, still pending delivery (delivery_status = 'disabled')
    $s1 = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE delivery_boy_id = ?
           AND delivery_status = 'disabled'
           AND status NOT IN ('cancelled','delivered')
           AND DATE(created_at) = CURDATE()"
    );
    $s1->execute([$boyId]);
    $assignedCount = (int)$s1->fetchColumn();

    // In-progress today (pending/confirmed/processing)
    $s2 = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE delivery_boy_id = ?
           AND status IN ('pending','confirmed','processing')
           AND status NOT IN ('cancelled','delivered')"
    );
    $s2->execute([$boyId]);
    $inProgressCount = (int)$s2->fetchColumn();

    // Delivered today
    $s3 = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE delivery_boy_id = ?
           AND delivery_status = 'enabled'
           AND DATE(updated_at) = CURDATE()"
    );
    $s3->execute([$boyId]);
    $deliveredCount = (int)$s3->fetchColumn();

    // All time total
    $s4 = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE delivery_boy_id = ?
           AND status != 'cancelled'"
    );
    $s4->execute([$boyId]);
    $totalCount = (int)$s4->fetchColumn();

} catch (PDOException $e) {
    // Leave at 0
}

/* =========================================================
   RECENT ACTIVITY (last 5 orders)
   ========================================================= */
$recentOrders = [];

try {
    $r = $pdo->prepare(
        "SELECT o.id, o.order_code, o.total_amount, o.status, o.delivery_status,
                o.payment_status, o.created_at,
                c.full_name AS customer_name, c.mobile_number AS customer_mobile
         FROM orders o
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         WHERE o.delivery_boy_id = ?
           AND o.status != 'cancelled'
         ORDER BY o.id DESC
         LIMIT 5"
    );
    $r->execute([$boyId]);
    $recentOrders = $r->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentOrders = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>
    <style>
        /* Real-data specific tweaks */
        .recent-list { display: flex; flex-direction: column; gap: 10px; }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff;
            border: 1.5px solid #ece5da;
            transition: .18s ease;
            text-decoration: none;
            color: inherit;
        }

        .recent-item:hover {
            border-color: #d98a91;
            background: #fffaf8;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(48, 41, 35, .06);
        }

        .recent-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            flex-shrink: 0;
        }

        .recent-info { flex: 1; min-width: 0; }

        .recent-code {
            font-size: 13px;
            font-weight: 800;
            color: #b51f2c;
        }

        .recent-cust {
            font-size: 11.5px;
            color: #6f675f;
            margin-top: 2px;
            font-weight: 600;
        }

        .recent-meta {
            font-size: 10.5px;
            color: #948c82;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .recent-meta i {
            font-size: 10px;
            margin-right: 2px;
        }

        .recent-right {
            text-align: right;
            flex-shrink: 0;
        }

        .recent-amount {
            font-family: "Playfair Display", serif;
            font-size: 15px;
            font-weight: 700;
            color: #b51f2c;
            white-space: nowrap;
        }

        .recent-status {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding: 3px 8px;
            border-radius: 6px;
            margin-top: 4px;
        }

        .recent-status.delivered { background: #e8f6ea; color: #1b5e20; }
        .recent-status.pending   { background: #fdf1e2; color: #a35a0e; }
        .recent-status.cancelled { background: #fdeaea; color: #b51f2c; }
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
                <h2>Hello, <?= htmlspecialchars($firstName) ?> 👋</h2>
            </div>

            <div class="tb-actions">
                <a href="<?= BASE_URL ?>notifications.php" class="tb-icon-btn" title="Notifications">
                    <i class="bi bi-bell"></i>
                    <span class="tb-dot"></span>
                </a>
                <a href="<?= BASE_URL ?>profile.php" class="tb-icon-btn" title="Profile">
                    <i class="bi bi-person"></i>
                </a>
            </div>
        </header>

        <main class="content">

            <!-- HERO -->
            <section class="hero">
                <div class="hero-inner">
                    <div class="hero-text">
                        <h1>Ready to deliver, <?= htmlspecialchars($firstName) ?>?</h1>
                        <p>Check your assigned orders and start delivering.</p>
                        <div class="hero-status">
                            <span class="dot"></span>
                            Online · Approved
                        </div>
                    </div>
                    <div class="hero-avatar">
                        <?= htmlspecialchars(strtoupper(substr($boy['full_name'], 0, 1))) ?>
                    </div>
                </div>
            </section>

            <!-- STATS -->
            <section class="stats">
                <div class="stat">
                    <div class="stat-icon red"><i class="bi bi-bag-check"></i></div>
                    <div class="stat-label">Assigned</div>
                    <div class="stat-value"><?= (int)$assignedCount ?></div>
                    <div class="stat-sub">Today</div>
                </div>

                <div class="stat">
                    <div class="stat-icon orange"><i class="bi bi-truck"></i></div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?= (int)$inProgressCount ?></div>
                    <div class="stat-sub">Currently delivering</div>
                </div>

                <div class="stat">
                    <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
                    <div class="stat-label">Delivered</div>
                    <div class="stat-value"><?= (int)$deliveredCount ?></div>
                    <div class="stat-sub">Completed today</div>
                </div>

                <div class="stat">
                    <div class="stat-icon blue"><i class="bi bi-clock-history"></i></div>
                    <div class="stat-label">Total</div>
                    <div class="stat-value"><?= (int)$totalCount ?></div>
                    <div class="stat-sub">All-time</div>
                </div>
            </section>

            <!-- QUICK ACTIONS -->
            <section class="section">
                <div class="section-head">
                    <h3>Quick Actions</h3>
                </div>

                <div class="actions">
                    <a href="<?= BASE_URL ?>orders.php" class="action">
                        <i class="bi bi-bag-check"></i>
                        View Orders
                    </a>
                    <a href="<?= BASE_URL ?>active.php" class="action">
                        <i class="bi bi-truck"></i>
                        Active Delivery
                    </a>
                    <a href="<?= BASE_URL ?>history.php" class="action">
                        <i class="bi bi-clock-history"></i>
                        Delivery History
                    </a>
                    <a href="<?= BASE_URL ?>profile.php" class="action">
                        <i class="bi bi-person-circle"></i>
                        My Profile
                    </a>
                </div>
            </section>

            <!-- RECENT ACTIVITY (REAL DATA) -->
            <section class="section">
                <div class="section-head">
                    <h3>Recent Activity</h3>
                    <a href="<?= BASE_URL ?>history.php">View all</a>
                </div>

                <?php if (empty($recentOrders)): ?>
                    <div class="empty">
                        <i class="bi bi-inbox"></i>
                        <p>No recent activity yet. Your deliveries will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="recent-list">
                        <?php foreach ($recentOrders as $o):
                            $initial = strtoupper(substr($o['customer_name'] ?? '?', 0, 1));
                            $isDelivered = ($o['delivery_status'] === 'enabled');
                            $statusClass = $isDelivered ? 'delivered' : ($o['status'] === 'cancelled' ? 'cancelled' : 'pending');
                            $statusLabel = $isDelivered ? 'Delivered' : ucfirst($o['status']);
                        ?>
                            <a href="<?= BASE_URL ?>order-view.php?id=<?= (int)$o['id'] ?>" class="recent-item">
                                <div class="recent-avatar"><?= htmlspecialchars($initial) ?></div>
                                <div class="recent-info">
                                    <div class="recent-code">#<?= htmlspecialchars($o['order_code']) ?></div>
                                    <div class="recent-cust"><?= htmlspecialchars($o['customer_name'] ?: '—') ?></div>
                                    <div class="recent-meta">
                                        <span><i class="bi bi-clock"></i><?= date('d M, h:i A', strtotime($o['created_at'])) ?></span>
                                        <?php if (!empty($o['customer_mobile'])): ?>
                                            <span><i class="bi bi-telephone"></i><?= htmlspecialchars($o['customer_mobile']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="recent-right">
                                    <div class="recent-amount">₹<?= number_format((float)$o['total_amount'], 0) ?></div>
                                    <span class="recent-status <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>
    <script src="<?= BASE_URL; ?>js/main.js"></script>
</body>

</html>