<?php
require_once './config/config.php';
require_once './config/function.php';

/* ---------------- AUTH CHECK ---------------- */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    header('Location: login.php');
    exit;
}


/* ---------------- SETTINGS / LOGO ---------------- */
$settings = getSettings($pdo);
$siteName = $settings['username'] ?? 'Mrs Mill@';
$logoUrl  = !empty($settings['logo_image']) ? ADMIN_URL . $settings['logo_image'] : '';

$firstName = explode(' ', trim($boy['full_name']))[0];

/* ---------------- STATS (stubs) ---------------- */
$assignedCount   = 0;
$inProgressCount = 0;
$deliveredCount  = 0;
$totalCount      = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
        <?php include './includes/head.php'; ?>


</head>

<body>

    <!-- ============ SIDEBAR ============ -->

    <?php include './templates/sidebar.php'; ?>


    <!-- Overlay -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- ============ MAIN ============ -->
    <div class="main">

        <!-- Topbar -->
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

        <!-- Content -->
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
                    <div class="stat-sub">Completed</div>
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

            <!-- RECENT ACTIVITY -->
            <section class="section">
                <div class="section-head">
                    <h3>Recent Activity</h3>
                    <a href="<?= BASE_URL ?>history.php">View all</a>
                </div>

                <div class="empty">
                    <i class="bi bi-inbox"></i>
                    <p>No recent activity yet. Your deliveries will appear here.</p>
                </div>
            </section>

        </main>
    </div>
    <script src="<?= BASE_URL; ?>js/main.js"></script>
</body>

</html>