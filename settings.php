<?php
require_once './config/config.php';
require_once './config/function.php';

/* ---------------- AUTH CHECK ---------------- */
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    header('Location: login.php');
    exit;
}

$boyId = (int)$_SESSION['delivery_boy_id'];

$stmt = $pdo->prepare(
    "SELECT id, delivery_code, full_name, mobile_number,
            email_address, status, last_login_at
     FROM delivery_boys
     WHERE id = ? LIMIT 1"
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

$firstName = explode(' ', trim($boy['full_name']))[0];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        /* =====================================================
           SETTINGS PAGE (READ-ONLY)
        ===================================================== */
        .st-page {
            padding: 24px 26px 40px;
        }

        .st-head {
            margin-bottom: 18px;
        }

        .st-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }

        .st-head p {
            margin: 0;
            font-size: 12.5px;
            color: #817a71;
        }

        .st-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 20px;
            align-items: start;
        }

        .st-card {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            padding: 22px;
            margin-bottom: 18px;
        }

        .st-card:last-child {
            margin-bottom: 0;
        }

        .st-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0ebe4;
        }

        .st-card-title > i {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #fbe8e9;
            color: #b51f2c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .st-card-title h3 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 15px;
            font-weight: 700;
            color: #302923;
        }

        .st-card-title span {
            display: block;
            font-size: 10px;
            color: #948c82;
            margin-top: 2px;
        }

        /* Read-only field row */
        .st-ro-field {
            padding: 12px 14px;
            border: 1.5px dashed #ece5da;
            background: #fdfaf4;
            border-radius: 11px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .st-ro-field:last-child {
            margin-bottom: 0;
        }

        .st-ro-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }

        .st-ro-left > i {
            color: #b51f2c;
            font-size: 15px;
            flex-shrink: 0;
        }

        .st-ro-label {
            font-size: 10.5px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 2px;
        }

        .st-ro-value {
            font-size: 13px;
            font-weight: 700;
            color: #302923;
            word-break: break-word;
        }

        .st-ro-value.empty {
            color: #a19a90;
            font-weight: 500;
            font-style: italic;
        }

        /* Info notice */
        .st-notice {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 11px;
            background: #fff8e6;
            border: 1.5px solid #f0dca0;
            font-size: 11.5px;
            color: #8a6a1e;
            font-weight: 600;
            display: flex;
            gap: 9px;
            align-items: flex-start;
            line-height: 1.5;
        }

        .st-notice i {
            color: #b8893c;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* Profile side */
        .st-profile-box {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            padding: 22px;
            text-align: center;
        }

        .st-profile-avatar {
            width: 88px;
            height: 88px;
            border-radius: 24px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Playfair Display", serif;
            font-size: 34px;
            font-weight: 700;
            margin: 0 auto 14px;
            box-shadow: 0 15px 35px rgba(181, 31, 44, .25);
        }

        .st-profile-name {
            font-family: "Playfair Display", serif;
            font-size: 18px;
            font-weight: 700;
            color: #302923;
            margin-bottom: 4px;
        }

        .st-profile-code {
            display: inline-block;
            background: #faf7f0;
            border: 1px dashed #d8c9b8;
            color: #6f5a3f;
            font-size: 10px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 7px;
            letter-spacing: 1px;
            margin-bottom: 14px;
        }

        .st-profile-meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px dashed #f0ebe4;
        }

        .st-profile-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11.5px;
            color: #6f675f;
        }

        .st-profile-meta-row span {
            color: #948c82;
        }

        .st-profile-meta-row strong {
            color: #302923;
            font-weight: 700;
            text-align: right;
        }

        .st-status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .3px;
        }

        .st-status-pill.active {
            background: #e8f6ea;
            color: #1b5e20;
        }

        .st-status-pill.inactive {
            background: #fdeaea;
            color: #b51f2c;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .st-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .st-page {
                padding: 18px 14px 30px;
            }

            .st-card,
            .st-profile-box {
                padding: 17px;
                border-radius: 16px;
            }
        }
    </style>
</head>

<body>

    <?php include './templates/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- MAIN -->
    <div class="main">

        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>

            <div class="tb-title">
                <h2>Settings</h2>
                <p>Your profile information</p>
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


        <main class="st-page">

            <div class="st-head">
                <h1>Settings</h1>
                <p>Your account details are managed by the admin.</p>
            </div>


            <div class="st-layout">

                <!-- LEFT: READ-ONLY INFO -->
                <div>

                    <!-- PERSONAL INFO -->
                    <div class="st-card">
                        <div class="st-card-title">
                            <i class="bi bi-person-circle"></i>
                            <div>
                                <h3>Personal Information</h3>
                                <span>Managed by admin</span>
                            </div>
                        </div>

                        <div class="st-ro-field">
                            <div class="st-ro-left">
                                <i class="bi bi-person"></i>
                                <div>
                                    <div class="st-ro-label">Full Name</div>
                                    <div class="st-ro-value"><?= htmlspecialchars($boy['full_name']) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="st-ro-field">
                            <div class="st-ro-left">
                                <i class="bi bi-phone"></i>
                                <div>
                                    <div class="st-ro-label">Mobile Number</div>
                                    <div class="st-ro-value"><?= htmlspecialchars($boy['mobile_number']) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="st-ro-field">
                            <div class="st-ro-left">
                                <i class="bi bi-envelope"></i>
                                <div>
                                    <div class="st-ro-label">Email Address</div>
                                    <?php if (!empty($boy['email_address'])): ?>
                                        <div class="st-ro-value"><?= htmlspecialchars($boy['email_address']) ?></div>
                                    <?php else: ?>
                                        <div class="st-ro-value empty">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="st-ro-field">
                            <div class="st-ro-left">
                                <i class="bi bi-upc-scan"></i>
                                <div>
                                    <div class="st-ro-label">Delivery Code</div>
                                    <div class="st-ro-value">#<?= htmlspecialchars($boy['delivery_code']) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="st-notice">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>If you need to update your details, please contact the admin.</span>
                        </div>
                    </div>

                </div>


                <!-- RIGHT: PROFILE CARD -->
                <div>
                    <div class="st-profile-box">
                        <div class="st-profile-avatar">
                            <?= htmlspecialchars(strtoupper(substr($boy['full_name'], 0, 1))) ?>
                        </div>
                        <div class="st-profile-name">
                            <?= htmlspecialchars($boy['full_name']) ?>
                        </div>
                        <div class="st-profile-code">
                            #<?= htmlspecialchars($boy['delivery_code']) ?>
                        </div>

                        <div class="st-profile-meta">
                            <div class="st-profile-meta-row">
                                <span>Mobile</span>
                                <strong><?= htmlspecialchars($boy['mobile_number']) ?></strong>
                            </div>
                            <div class="st-profile-meta-row">
                                <span>Email</span>
                                <strong>
                                    <?= !empty($boy['email_address'])
                                        ? htmlspecialchars($boy['email_address'])
                                        : '—' ?>
                                </strong>
                            </div>
                            <div class="st-profile-meta-row">
                                <span>Status</span>
                                <span class="st-status-pill <?= (int)$boy['status'] === 1 ? 'active' : 'inactive' ?>">
                                    <?= (int)$boy['status'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="st-profile-meta-row">
                                <span>Last Login</span>
                                <strong>
                                    <?= !empty($boy['last_login_at'])
                                        ? date('d M, h:i A', strtotime($boy['last_login_at']))
                                        : '—' ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </main>

    </div>


    <script>
        window.BASE_URL = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
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

</body>

</html>