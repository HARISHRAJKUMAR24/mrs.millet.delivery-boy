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
           SETTINGS PAGE
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

        .st-card-title>i {
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

        /* Form fields */
        .st-field {
            margin-bottom: 14px;
        }

        .st-field:last-child {
            margin-bottom: 0;
        }

        .st-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #4e4841;
            margin-bottom: 6px;
        }

        .st-field label .required {
            color: #b51f2c;
        }

        .st-input-wrap {
            position: relative;
        }

        .st-input-wrap>i.lead {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        .st-input {
            width: 100%;
            height: 44px;
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

        .st-input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }

        .st-input[readonly] {
            background: #f4efe8;
            color: #6f5a3f;
            cursor: not-allowed;
            border-style: dashed;
        }

        .st-eye-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: 0;
            background: transparent;
            color: #b0a79c;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            transition: .15s;
        }

        .st-eye-btn:hover {
            color: #b51f2c;
            background: #fbe8e9;
        }

        .st-input.has-eye {
            padding-right: 46px;
        }

        /* Save button */
        .st-save-btn {
            width: 100%;
            height: 46px;
            border: none;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            border-radius: 11px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: .3px;
            cursor: pointer;
            transition: .2s ease;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 6px;
        }

        .st-save-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(181, 31, 44, .3);
        }

        .st-save-btn:disabled {
            opacity: .7;
            cursor: not-allowed;
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

        /* Toast */
        #mmToast {
            position: fixed;
            bottom: 26px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 12px 22px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            font-family: "DM Sans", sans-serif;
            color: #fff;
            background: #2e7d32;
            box-shadow: 0 15px 40px rgba(0, 0, 0, .25);
            z-index: 99999;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
            max-width: 90vw;
            text-align: center;
        }

        #mmToast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        #mmToast.error {
            background: #b51f2c;
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
                <p>Manage your profile and password</p>
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
                <p>Update your name, email, mobile and password.</p>
            </div>


            <div class="st-layout">

                <!-- LEFT: FORMS -->
                <div>

                    <!-- PROFILE -->
                    <div class="st-card">
                        <div class="st-card-title">
                            <i class="bi bi-person-circle"></i>
                            <div>
                                <h3>Profile Details</h3>
                                <span>Update your basic information</span>
                            </div>
                        </div>

                        <form id="profileForm" novalidate>

                            <div class="st-field">
                                <label>Full Name <span class="required">*</span></label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-person lead"></i>
                                    <input type="text"
                                        id="st_full_name"
                                        class="st-input"
                                        value="<?= htmlspecialchars($boy['full_name']) ?>"
                                        maxlength="150"
                                        required>
                                </div>
                            </div>

                            <div class="st-field">
                                <label>Mobile Number <span class="required">*</span></label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-phone lead"></i>
                                    <input type="text"
                                        id="st_mobile"
                                        class="st-input"
                                        value="<?= htmlspecialchars($boy['mobile_number']) ?>"
                                        maxlength="15"
                                        inputmode="numeric"
                                        required>
                                </div>
                            </div>

                            <div class="st-field">
                                <label>Email Address</label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-envelope lead"></i>
                                    <input type="email"
                                        id="st_email"
                                        class="st-input"
                                        value="<?= htmlspecialchars($boy['email_address'] ?? '') ?>"
                                        maxlength="190"
                                        placeholder="(optional)">
                                </div>
                            </div>

                            <button type="submit" class="st-save-btn" id="saveProfileBtn">
                                <i class="bi bi-check-lg"></i>
                                <span id="saveProfileText">Save Profile</span>
                            </button>

                        </form>
                    </div>


                    <!-- PASSWORD -->
                    <div class="st-card">
                        <div class="st-card-title">
                            <i class="bi bi-shield-lock"></i>
                            <div>
                                <h3>Change Password</h3>
                                <span>Minimum 6 characters</span>
                            </div>
                        </div>

                        <form id="passwordForm" novalidate>

                            <div class="st-field">
                                <label>Current Password <span class="required">*</span></label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-lock lead"></i>
                                    <input type="password"
                                        id="st_current_password"
                                        class="st-input has-eye"
                                        placeholder="Enter current password"
                                        maxlength="100"
                                        autocomplete="current-password">
                                    <button type="button" class="st-eye-btn" data-toggle="st_current_password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="st-field">
                                <label>New Password <span class="required">*</span></label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-lock-fill lead"></i>
                                    <input type="password"
                                        id="st_new_password"
                                        class="st-input has-eye"
                                        placeholder="Enter new password (min 6 chars)"
                                        maxlength="100"
                                        autocomplete="new-password">
                                    <button type="button" class="st-eye-btn" data-toggle="st_new_password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="st-field">
                                <label>Confirm New Password <span class="required">*</span></label>
                                <div class="st-input-wrap">
                                    <i class="bi bi-lock-fill lead"></i>
                                    <input type="password"
                                        id="st_confirm_password"
                                        class="st-input has-eye"
                                        placeholder="Re-enter new password"
                                        maxlength="100"
                                        autocomplete="new-password">
                                    <button type="button" class="st-eye-btn" data-toggle="st_confirm_password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="st-save-btn" id="savePasswordBtn">
                                <i class="bi bi-shield-check"></i>
                                <span id="savePasswordText">Update Password</span>
                            </button>

                        </form>
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

    <script src="<?= BASE_URL ?>js/settings.js"></script>

</body>

</html>