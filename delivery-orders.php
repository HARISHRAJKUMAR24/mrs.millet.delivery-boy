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
$logoUrl  = !empty($settings['logo_image']) ? ADMIN_URL . $settings['logo_image'] : '';

$firstName = explode(' ', trim($boy['full_name']))[0];

/* ---------------- FETCH APARTMENTS ALLOCATED TO THIS BOY ---------------- */
$apartments = [];
try {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.apartment_code, a.apartment_name, a.apartment_address,
                (SELECT COUNT(*)
                 FROM orders o
                 WHERE o.apartment_code = a.apartment_code
                   AND o.delivery_boy_id = ?
                   AND o.delivery_status = 'disabled'
                   AND o.status <> 'cancelled') AS pending_count,
                (SELECT COUNT(*)
                 FROM orders o
                 WHERE o.apartment_code = a.apartment_code
                   AND o.delivery_boy_id = ?
                   AND o.delivery_status = 'enabled') AS delivered_count
         FROM apartments a
         INNER JOIN apartment_delivery_boys adb ON adb.apartment_code = a.apartment_code
         WHERE adb.delivery_boy_id = ?
           AND a.status = 1
         ORDER BY a.apartment_name ASC"
    );
    $stmt->execute([$boyId, $boyId, $boyId]);
    $apartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $apartments = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        /* =====================================================
           APARTMENT CARDS — DELIVERY BOY PANEL
           ===================================================== */
        .do-page { padding: 24px 26px 40px; }

        .do-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .do-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }
        .do-head p {
            margin: 0;
            font-size: 12.5px;
            color: #817a71;
        }

        .do-search {
            position: relative;
            min-width: 260px;
        }
        .do-search input {
            width: 100%;
            height: 42px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 11px;
            padding: 0 14px 0 40px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            color: #292521;
            outline: none;
            transition: .2s ease;
        }
        .do-search input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }
        .do-search > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        /* Card grid */
        .apt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }

        .apt-card {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 18px;
            padding: 18px;
            cursor: pointer;
            transition: .2s ease;
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            overflow: hidden;
        }
        .apt-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #b51f2c 0%, #8e1722 100%);
            opacity: 0;
            transition: opacity .2s ease;
        }
        .apt-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px -10px rgba(48, 41, 35, .15);
            border-color: #d98a91;
        }
        .apt-card:hover::before { opacity: 1; }

        .apt-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .apt-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .25);
        }

        .apt-card-info { flex: 1; min-width: 0; }
        .apt-card-name {
            font-size: 14px;
            font-weight: 800;
            color: #302923;
            margin: 0;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .apt-card-code {
            font-size: 10.5px;
            color: #948c82;
            margin: 3px 0 0;
            font-weight: 700;
            letter-spacing: .3px;
        }
        .apt-card-addr {
            font-size: 11px;
            color: #948c82;
            margin: 6px 0 0;
            line-height: 1.4;
            display: -webkit-box;
            line-clamp: 2;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .apt-card-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #f5efe5;
            gap: 10px;
        }

        .apt-count {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .apt-count.pending {
            background: #fdf7ec;
            color: #b8893c;
        }
        .apt-count.delivered {
            background: #e7f6ec;
            color: #1f7a3d;
        }
        .apt-count.zero {
            background: #f1ece4;
            color: #948c82;
        }
        .apt-count i { font-size: 11px; }

        .apt-card-go {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f7f2ec;
            color: #6f675f;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: .2s ease;
        }
        .apt-card:hover .apt-card-go {
            background: #b51f2c;
            color: #fff;
            transform: translateX(3px);
        }

        /* Empty state */
        .do-empty {
            text-align: center;
            padding: 60px 20px;
            color: #948c82;
            background: #fff;
            border: 1.5px dashed #ece5da;
            border-radius: 18px;
        }
        .do-empty i {
            font-size: 44px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }
        .do-empty h3 {
            font-family: "Playfair Display", serif;
            font-size: 18px;
            color: #302923;
            margin: 0 0 6px;
        }
        .do-empty p { font-size: 12.5px; margin: 0; }

        @media (max-width: 640px) {
            .do-page { padding: 18px 14px 40px; }
            .do-search { min-width: 100%; }
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
                <h2>My Apartments</h2>
                <p>Click an apartment to view and deliver its orders</p>
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


        <main class="do-page">

            <div class="do-head">
                <div>
                    <h1>My Apartments</h1>
                    <p>Only apartments allocated to you are shown here.</p>
                </div>

                <div class="do-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="aptSearch" placeholder="Search apartment...">
                </div>
            </div>

            <?php if (empty($apartments)): ?>
                <div class="do-empty">
                    <i class="bi bi-building"></i>
                    <h3>No apartments allocated</h3>
                    <p>The admin hasn't allocated any apartments to you yet.</p>
                </div>
            <?php else: ?>
                <div class="apt-grid" id="aptGrid">
                    <?php foreach ($apartments as $a):
                        $pending   = (int)$a['pending_count'];
                        $delivered = (int)$a['delivered_count'];
                    ?>
                        <div class="apt-card"
                             data-code="<?= htmlspecialchars($a['apartment_code']) ?>"
                             data-name="<?= htmlspecialchars($a['apartment_name']) ?>"
                             data-search="<?= htmlspecialchars(strtolower($a['apartment_name'] . ' ' . $a['apartment_code'] . ' ' . $a['apartment_address'])) ?>">

                            <div class="apt-card-head">
                                <div class="apt-card-icon">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div class="apt-card-info">
                                    <p class="apt-card-name"><?= htmlspecialchars($a['apartment_name']) ?></p>
                                    <p class="apt-card-code">#<?= htmlspecialchars($a['apartment_code']) ?></p>
                                </div>
                            </div>

                            <?php if (!empty($a['apartment_address'])): ?>
                                <p class="apt-card-addr"><?= htmlspecialchars($a['apartment_address']) ?></p>
                            <?php endif; ?>

                            <div class="apt-card-foot">
                                <?php if ($pending > 0): ?>
                                    <span class="apt-count pending">
                                        <i class="bi bi-hourglass-split"></i>
                                        <?= $pending ?> pending
                                    </span>
                                <?php elseif ($delivered > 0): ?>
                                    <span class="apt-count delivered">
                                        <i class="bi bi-check-circle-fill"></i>
                                        All done
                                    </span>
                                <?php else: ?>
                                    <span class="apt-count zero">
                                        <i class="bi bi-inbox"></i>
                                        No orders
                                    </span>
                                <?php endif; ?>

                                <span class="apt-card-go">
                                    <i class="bi bi-arrow-right"></i>
                                </span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>


    <!-- ================= GLOBALS ================= -->
    <script>
        window.BASE_URL = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
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

    <!-- APARTMENT SEARCH + CARD CLICK -->
    <script>
        (function () {
            "use strict";
            const BASE_URL = window.BASE_URL || "./";
            const grid = document.getElementById("aptGrid");
            const search = document.getElementById("aptSearch");

            /* Live search */
            search?.addEventListener("input", function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll(".apt-card").forEach(card => {
                    const hay = card.dataset.search || "";
                    card.style.display = (!q || hay.includes(q)) ? "" : "none";
                });
            });

            /* Card click → go to apartment page */
            grid?.addEventListener("click", function (e) {
                const card = e.target.closest(".apt-card");
                if (!card) return;

                const code = card.dataset.code;
                if (!code) return;

                window.location.href = BASE_URL + "apartment-orders.php?code=" + encodeURIComponent(code);
            });
        })();
    </script>

</body>

</html>