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

/* =========================================================
   FETCH GROUPS FOR THIS DELIVERY BOY
   1) Apartment groups → grouped by apartment_code
   2) Individual House groups → grouped by full_address
   All data lives inside orders.address_json.
   ========================================================= */
$apartments      = [];   // grouped by apartment_code
$individualHouses = [];  // grouped by full_address

try {
    /* ---- Apartment groups ---- */
    $aptStmt = $pdo->prepare(
        "SELECT
            JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_code')) AS apartment_code,
            JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_name')) AS apartment_name,
            COUNT(*) AS total_count,
            SUM(CASE WHEN o.status <> 'delivered' AND o.status <> 'cancelled' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count
         FROM orders o
         WHERE o.delivery_boy_id = ?
           AND o.delivery_mode = 'delivery'
           AND o.address_type = 'apartment'
           AND o.status <> 'cancelled'
           AND JSON_VALID(o.address_json)
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_code')) IS NOT NULL
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.apartment_code')) <> ''
         GROUP BY apartment_code, apartment_name
         ORDER BY apartment_name ASC"
    );
    $aptStmt->execute([$boyId]);
    $apartments = $aptStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Fetch real apartment_address from apartments table (optional) */
    if (!empty($apartments)) {
        $codes = array_map(fn($a) => $a['apartment_code'], $apartments);
        $in = implode(',', array_fill(0, count($codes), '?'));
        try {
            $addrStmt = $pdo->prepare(
                "SELECT apartment_code, apartment_address FROM apartments
                 WHERE apartment_code IN ($in)"
            );
            $addrStmt->execute($codes);
            $addrMap = [];
            while ($r = $addrStmt->fetch(PDO::FETCH_ASSOC)) {
                $addrMap[$r['apartment_code']] = $r['apartment_address'];
            }
            foreach ($apartments as &$a) {
                $a['apartment_address'] = $addrMap[$a['apartment_code']] ?? '';
            }
            unset($a);
        } catch (PDOException $e) {
        }
    }

    /* ---- Individual House groups ---- */
    $ihStmt = $pdo->prepare(
        "SELECT
            JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) AS full_address,
            COUNT(*) AS total_count,
            SUM(CASE WHEN o.status <> 'delivered' AND o.status <> 'cancelled' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count
         FROM orders o
         WHERE o.delivery_boy_id = ?
           AND o.delivery_mode = 'delivery'
           AND o.address_type = 'individual_house'
           AND o.status <> 'cancelled'
           AND JSON_VALID(o.address_json)
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) IS NOT NULL
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) <> ''
         GROUP BY full_address
         ORDER BY full_address ASC"
    );
    $ihStmt->execute([$boyId]);
    $individualHouses = $ihStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $apartments = [];
    $individualHouses = [];
}

/* Total counters for the header card */
$totalPending = 0;
foreach ($apartments as $a) $totalPending += (int)$a['pending_count'];
foreach ($individualHouses as $h) $totalPending += (int)$h['pending_count'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        .do-page {
            padding: 24px 26px 40px;
        }

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

        .do-search>i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        /* Apartment cards */
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
            top: 0;
            left: 0;
            right: 0;
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

        .apt-card:hover::before {
            opacity: 1;
        }

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

        .apt-card-icon.house {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            box-shadow: 0 8px 20px rgba(46, 125, 50, .25);
        }

        .apt-card-info {
            flex: 1;
            min-width: 0;
        }

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

        .apt-count i {
            font-size: 11px;
        }

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

        /* Section header */
        .do-sec-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: "Playfair Display", serif;
            font-size: 18px;
            font-weight: 700;
            color: #302923;
            margin: 26px 0 14px;
        }

        .do-sec-title i {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .do-sec-title.apt i {
            background: #fbe8e9;
            color: #b51f2c;
        }

        .do-sec-title.house i {
            background: #eef7f0;
            color: #2e7d32;
        }

        .do-sec-title .count {
            font-family: "DM Sans", sans-serif;
            background: #f7f2ec;
            color: #6f675f;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: none;
            margin-left: 4px;
        }

        .do-sec-title.house .count {
            background: #eef7f0;
            color: #2e7d32;
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

        .do-empty p {
            font-size: 12.5px;
            margin: 0;
        }

        @media (max-width: 640px) {
            .do-page {
                padding: 18px 14px 40px;
            }

            .do-search {
                min-width: 100%;
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
                <h2>My Deliveries</h2>
                <p>Click a group to view and deliver its orders</p>
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
                    <h1>My Deliveries</h1>
                    <p>
                        <?php if ($totalPending > 0): ?>
                            You have <strong><?= $totalPending ?></strong> pending order<?= $totalPending === 1 ? '' : 's' ?>.
                        <?php else: ?>
                            Only groups with your orders are shown here.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="do-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="aptSearch" placeholder="Search apartment or address...">
                </div>
            </div>

            <?php if (empty($apartments) && empty($individualHouses)): ?>

                <div class="do-empty">
                    <i class="bi bi-building"></i>
                    <h3>No orders yet</h3>
                    <p>You don't have any orders right now. Once orders are assigned to you, they'll appear here.</p>
                </div>

            <?php else: ?>


                <?php if (!empty($apartments)): ?>

                    <h2 class="do-sec-title apt">
                        <i class="bi bi-building"></i>
                        Apartments
                        <span class="count"><?= count($apartments) ?></span>
                    </h2>

                    <div class="apt-grid" id="aptGrid">
                        <?php foreach ($apartments as $a):
                            $pending   = (int)$a['pending_count'];
                            $delivered = (int)$a['delivered_count'];
                            $addr      = $a['apartment_address'] ?? '';
                        ?>
                            <div class="apt-card"
                                data-type="apartment"
                                data-code="<?= htmlspecialchars($a['apartment_code']) ?>"
                                data-name="<?= htmlspecialchars($a['apartment_name']) ?>"
                                data-search="<?= htmlspecialchars(strtolower(
                                                    $a['apartment_name'] . ' ' . $a['apartment_code'] . ' ' . $addr
                                                )) ?>">

                                <div class="apt-card-head">
                                    <div class="apt-card-icon">
                                        <i class="bi bi-building"></i>
                                    </div>
                                    <div class="apt-card-info">
                                        <p class="apt-card-name"><?= htmlspecialchars($a['apartment_name']) ?></p>
                                        <p class="apt-card-code">#<?= htmlspecialchars($a['apartment_code']) ?></p>
                                    </div>
                                </div>

                                <?php if ($addr !== ''): ?>
                                    <p class="apt-card-addr"><?= htmlspecialchars($addr) ?></p>
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


                <?php if (!empty($individualHouses)): ?>

                    <h2 class="do-sec-title house">
                        <i class="bi bi-house-door"></i>
                        Individual Houses
                        <span class="count"><?= count($individualHouses) ?></span>
                    </h2>

                    <div class="apt-grid" id="ihGrid">
                        <?php foreach ($individualHouses as $h):
                            $pending   = (int)$h['pending_count'];
                            $delivered = (int)$h['delivered_count'];
                            $addr      = $h['full_address'] ?? '';
                        ?>
                            <div class="apt-card"
                                data-type="individual_house"
                                data-address="<?= htmlspecialchars($addr) ?>"
                                data-search="<?= htmlspecialchars(strtolower($addr)) ?>">

                                <div class="apt-card-head">
                                    <div class="apt-card-icon house">
                                        <i class="bi bi-house-door"></i>
                                    </div>
                                    <div class="apt-card-info">
                                        <p class="apt-card-name">Individual House</p>
                                        <p class="apt-card-code">Delivery Address</p>
                                    </div>
                                </div>

                                <p class="apt-card-addr" style="
                                    background:#eef7f0;color:#2e7d32;
                                    border-radius:9px;padding:8px 10px;
                                    -webkit-line-clamp:3;line-clamp:3;
                                    font-weight:600;">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <?= htmlspecialchars($addr) ?>
                                </p>

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

    <!-- APARTMENT / HOUSE SEARCH + CARD CLICK -->
    <script>
        (function() {
            "use strict";

            const BASE_URL = window.BASE_URL || "./";
            const search = document.getElementById("aptSearch");

            /* Live search across BOTH grids */
            search?.addEventListener("input", function() {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll(".apt-card").forEach(card => {
                    const hay = card.dataset.search || "";
                    card.style.display = (!q || hay.includes(q)) ? "" : "none";
                });
            });

            /* Card click → navigate to order list for that group */
            document.querySelectorAll(".apt-card").forEach(card => {
                card.addEventListener("click", function() {
                    const type = card.dataset.type;

                    if (type === "individual_house") {
                        const addr = card.dataset.address || "";
                        if (!addr) return;
                        window.location.href = BASE_URL + "individual-house-orders.php?address=" + encodeURIComponent(addr);
                        return;
                    }

                    const code = card.dataset.code;
                    if (!code) return;
                    window.location.href = BASE_URL + "apartment-orders.php?code=" + encodeURIComponent(code);
                });
            });
        })();
    </script>

</body>

</html>