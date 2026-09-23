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
$logoUrl  = !empty($settings['logo_image']) ? ADMIN_URL . $settings['logo_image'] : '';

$firstName = explode(' ', trim($boy['full_name']))[0];

/* ---------------- LOAD BRANCHES ---------------- */
$branches = [];

try {
    $bStmt = $pdo->query(
        "SELECT id, branch_name, branch_address, branch_mobile, branch_email
         FROM settings_branches
         ORDER BY branch_name ASC"
    );
    $branches = $bStmt->fetchAll();
} catch (PDOException $e) {
    $branches = [];
}

/* ---------------- HELPERS ---------------- */
function cleanPhone($phone)
{
    return preg_replace('/[^0-9+]/', '', (string)$phone);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        .hp-page {
            padding: 24px 26px 40px;
        }

        .hp-head {
            margin-bottom: 20px;
        }

        .hp-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }

        .hp-head p {
            margin: 0;
            font-size: 12.5px;
            color: #817a71;
        }

        /* =====================================================
           HERO BANNER
        ===================================================== */
        .hp-hero {
            background: linear-gradient(135deg, #8e1722 0%, #b51f2c 55%, #7a121b 100%);
            border-radius: 20px;
            padding: 24px 26px;
            color: #fff;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 20px 40px rgba(142, 23, 34, .22);
        }

        .hp-hero::before {
            content: "";
            position: absolute;
            top: -60px;
            right: -40px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, .15), transparent 70%);
        }

        .hp-hero::after {
            content: "";
            position: absolute;
            bottom: -80px;
            left: -60px;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, .08), transparent 70%);
        }

        .hp-hero-inner {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .hp-hero-icon {
            width: 54px;
            height: 54px;
            border-radius: 15px;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .hp-hero-text h2 {
            font-family: "Playfair Display", serif;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .hp-hero-text p {
            font-size: 12px;
            color: rgba(255, 255, 255, .85);
            margin: 0;
        }

        /* =====================================================
           SECTION
        ===================================================== */
        .hp-section {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            padding: 22px;
            margin-bottom: 18px;
        }

        .hp-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0ebe4;
        }

        .hp-section-head>i {
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

        .hp-section-head h3 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 15px;
            font-weight: 700;
            color: #302923;
        }

        .hp-section-head span {
            display: block;
            font-size: 10px;
            color: #948c82;
            margin-top: 2px;
        }

        /* =====================================================
           2-COLUMN BRANCH GRID
        ===================================================== */
        .branch-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .branch-card {
            background: #fffdf9;
            border: 1.5px solid #f0ebe4;
            border-radius: 14px;
            padding: 16px;
            transition: .22s ease;
            display: flex;
            flex-direction: column;
        }

        .branch-card:hover {
            border-color: #d98a91;
            box-shadow: 0 10px 24px rgba(48, 41, 35, .06);
            transform: translateY(-2px);
        }

        .branch-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .branch-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(181, 31, 44, .2);
        }

        .branch-info {
            flex: 1;
            min-width: 0;
        }

        .branch-name {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            font-weight: 700;
            color: #302923;
            line-height: 1.3;
            word-break: break-word;
        }

        .branch-tag {
            display: inline-block;
            background: #faf7f0;
            border: 1px dashed #d8c9b8;
            color: #6f5a3f;
            font-size: 9.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: .5px;
            margin-top: 4px;
        }

        .branch-rows {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .branch-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 12px;
            color: #4e4841;
            line-height: 1.5;
        }

        .branch-row>i {
            color: #b51f2c;
            font-size: 14px;
            margin-top: 1px;
            flex-shrink: 0;
            width: 16px;
            text-align: center;
        }

        .branch-row .label {
            font-weight: 700;
            color: #302923;
            margin-right: 4px;
        }

        .branch-row .value {
            color: #4e4841;
            word-break: break-word;
        }

        /* =====================================================
           CALL BUTTON
        ===================================================== */
        .branch-call-wrap {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed #f0ebe4;
        }

        .btn-call {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            padding: 0 16px;
            width: 100%;
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            color: #fff !important;
            border-radius: 11px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: .3px;
            text-decoration: none;
            transition: .2s ease;
            box-shadow: 0 8px 20px rgba(46, 125, 50, .24);
        }

        .btn-call:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(46, 125, 50, .32);
            color: #fff !important;
        }

        .btn-call:active {
            transform: translateY(0);
        }

        .btn-call i {
            font-size: 15px;
        }

.btn-call-label {
    font-size: 12.5px;
    font-weight: 700;
    letter-spacing: .3px;
    opacity: .92;
}

.btn-call-number {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .4px;
    margin-left: 2px;
}
        /* Empty */
        .hp-empty {
            text-align: center;
            padding: 40px 20px;
            color: #948c82;
            font-size: 12px;
            grid-column: 1 / -1;
        }

        .hp-empty i {
            font-size: 40px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */
        @media (max-width: 860px) {
            .branch-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .hp-page {
                padding: 18px 14px 30px;
            }

            .hp-section {
                padding: 17px;
                border-radius: 16px;
            }

            .hp-hero {
                padding: 20px;
                border-radius: 16px;
            }

            .hp-hero-text h2 {
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .branch-card {
                padding: 14px;
            }

            .branch-avatar {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .btn-call {
                height: 46px;
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
                <h2>Help &amp; Support</h2>
                <p>Contact branches for assistance</p>
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


        <main class="hp-page">

            <div class="hp-head">
                <h1>Help &amp; Support</h1>
                <p>Call any branch directly for order or delivery help.</p>
            </div>


            <!-- HERO -->
            <div class="hp-hero">
                <div class="hp-hero-inner">
                    <div class="hp-hero-icon">
                        <i class="bi bi-headset"></i>
                    </div>
                    <div class="hp-hero-text">
                        <h2>Need help?</h2>
                        <p>Tap any branch below to call them directly.</p>
                    </div>
                </div>
            </div>


            <!-- BRANCHES -->
            <div class="hp-section">

                <div class="hp-section-head">
                    <i class="bi bi-shop"></i>
                    <div>
                        <h3>Branch Contacts</h3>
                        <span>Tap the call button to dial</span>
                    </div>
                </div>

                <?php if (empty($branches)): ?>

                    <div class="hp-empty">
                        <i class="bi bi-shop-window"></i>
                        No branches configured yet.<br>
                        Please contact admin.
                    </div>

                <?php else: ?>

                    <div class="branch-grid">

                        <?php foreach ($branches as $b):

                            $phone = trim((string)($b['branch_mobile'] ?? ''));
                            $phoneClean = cleanPhone($phone);
                        ?>

                            <div class="branch-card">

                                <div class="branch-head">
                                    <div class="branch-avatar">
                                        <i class="bi bi-shop"></i>
                                    </div>
                                    <div class="branch-info">
                                        <div class="branch-name">
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </div>
                                        <span class="branch-tag">
                                            Branch #<?= (int)$b['id'] ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="branch-rows">

                                    <?php if (!empty($b['branch_address'])): ?>
                                        <div class="branch-row">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <div>
                                                <span class="label">Address:</span>
                                                <span class="value"><?= nl2br(htmlspecialchars($b['branch_address'])) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($phone !== ''): ?>
                                        <div class="branch-row">
                                            <i class="bi bi-telephone-fill"></i>
                                            <div>
                                                <span class="label">Phone:</span>
                                                <span class="value"><?= htmlspecialchars($phone) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($b['branch_email'])): ?>
                                        <div class="branch-row">
                                            <i class="bi bi-envelope-fill"></i>
                                            <div>
                                                <span class="label">Email:</span>
                                                <span class="value"><?= htmlspecialchars($b['branch_email']) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                </div>

                                <?php if ($phoneClean !== ''): ?>
                                    <div class="branch-call-wrap">
                                        <a href="tel:<?= htmlspecialchars($phoneClean) ?>" class="btn-call">
                                            <i class="bi bi-telephone-outbound-fill"></i>
                                            <span class="btn-call-label">Tap to call</span>
                                            <strong class="btn-call-number"><?= htmlspecialchars($phone) ?></strong>
                                        </a>
                                    </div>
                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

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