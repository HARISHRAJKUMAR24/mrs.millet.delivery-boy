<?php
require_once './config/config.php';

if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) {
    header('Location: index.php');
    exit;
}

$settings = getSettings($pdo);
$logoUrl  = !empty($settings['logo_image']) ? ADMIN_URL . $settings['logo_image'] : '';
$siteName = $settings['username'] ?? 'Mrs Mill@';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Register · <?= htmlspecialchars($siteName) ?></title>

    <?php if (!empty($settings['favicon_image'])): ?>
        <link rel="icon" href="<?= ADMIN_URL . htmlspecialchars($settings['favicon_image']) ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: "DM Sans", sans-serif;
            background: #faf7f2;
            min-height: 100vh;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image:
                radial-gradient(circle at 12% 20%, rgba(181, 31, 44, .06), transparent 42%),
                radial-gradient(circle at 88% 80%, rgba(46, 125, 50, .05), transparent 45%),
                linear-gradient(180deg, #faf7f2 0%, #f5efe5 100%);
            position: relative;
            overflow-x: hidden;
            animation: bgShift 18s ease-in-out infinite alternate;
        }

        @keyframes bgShift {
            0%   { background-position: 0% 0%; }
            100% { background-position: 100% 100%; }
        }

        .bubble {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            filter: blur(.3px);
        }

        .bubble.b1 {
            width: 260px; height: 260px;
            top: -80px; left: -80px;
            background: radial-gradient(circle at 30% 30%, #fbe8e9, #f3c8cc);
            opacity: .55;
            animation: floatA 9s ease-in-out infinite;
        }

        .bubble.b2 {
            width: 200px; height: 200px;
            bottom: -70px; right: -60px;
            background: radial-gradient(circle at 30% 30%, #e8f6ea, #b6e0bd);
            opacity: .5;
            animation: floatB 11s ease-in-out infinite;
        }

        .bubble.b3 {
            width: 140px; height: 140px;
            top: 40%; right: 8%;
            background: radial-gradient(circle at 30% 30%, #fff5ec, #ffe3cf);
            opacity: .6;
            animation: floatA 7s ease-in-out infinite reverse;
        }

        @keyframes floatA {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%      { transform: translate(14px, -22px) scale(1.06); }
        }
        @keyframes floatB {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%      { transform: translate(-18px, 16px) scale(1.05); }
        }

        /* CARD */
        .login-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 940px;
            background: #fff;
            border-radius: 26px;
            box-shadow:
                0 40px 80px rgba(48, 41, 35, .10),
                0 4px 14px rgba(48, 41, 35, .05);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            min-height: 620px;
            animation: cardIn .7s cubic-bezier(.2, .9, .3, 1.05) both;
        }

        @keyframes cardIn {
            0%   { opacity: 0; transform: translateY(18px) scale(.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* LEFT SIDE */
        .side-left {
            background: radial-gradient(circle at 100% 0%, rgba(255, 255, 255, .18), transparent 55%),
                        linear-gradient(155deg, #8e1722 0%, #b51f2c 45%, #7a121b 100%);
            padding: 44px 40px;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background-size: 200% 200%;
            animation: panelShift 14s ease-in-out infinite alternate;
        }

        @keyframes panelShift {
            0%   { background-position: 0% 0%; }
            100% { background-position: 100% 100%; }
        }

        .side-left::before {
            content: "";
            position: absolute;
            top: -60px; left: -80px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, .10), transparent 70%);
            animation: floatA 12s ease-in-out infinite;
        }

        .side-left::after {
            content: "";
            position: absolute;
            bottom: -100px; right: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, .06), transparent 70%);
            animation: floatB 15s ease-in-out infinite;
        }

        /* ===== LOGO with PULSE + SHIMMER ===== */
        .brand-logo-big {
            position: relative;
            z-index: 1;
            width: 130px;
            height: 130px;
            background: #fff;
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .22), 0 0 0 0 rgba(255, 255, 255, .35);
            animation: logoPop .9s .15s cubic-bezier(.2, .9, .3, 1.2) both, logoPulse 3.2s 1.1s ease-in-out infinite;
        }

        /* Glass shimmer on logo */
        .brand-logo-big::before {
            content: "";
            position: absolute;
            top: 0;
            left: -60%;
            width: 40%;
            height: 100%;
            background: linear-gradient(
                120deg,
                transparent 0%,
                rgba(255, 255, 255, .55) 50%,
                transparent 100%
            );
            animation: logoShimmer 3.2s ease-in-out infinite;
            pointer-events: none;
            z-index: 2;
            border-radius: 30px;
        }

        @keyframes logoShimmer {
            0%   { left: -60%; }
            55%  { left: 130%; }
            100% { left: 130%; }
        }

        @keyframes logoPop {
            0%   { opacity: 0; transform: scale(.6) rotate(-6deg); }
            60%  { opacity: 1; transform: scale(1.06) rotate(2deg); }
            100% { opacity: 1; transform: scale(1) rotate(0); }
        }

        @keyframes logoPulse {
            0%, 100% {
                box-shadow: 0 20px 50px rgba(0, 0, 0, .22), 0 0 0 0 rgba(255, 255, 255, .35);
            }
            50% {
                box-shadow: 0 24px 60px rgba(0, 0, 0, .28), 0 0 0 14px rgba(255, 255, 255, 0);
            }
        }

        .brand-logo-big img,
        .brand-logo-big .fallback {
            position: relative;
            z-index: 3;
        }

        .brand-logo-big img {
            max-width: 78%;
            max-height: 78%;
            object-fit: contain;
        }

        .brand-logo-big .fallback {
            color: #b51f2c;
            font-family: "Playfair Display", serif;
            font-size: 64px;
            font-weight: 700;
            line-height: 1;
        }

        .side-left-tag {
            position: relative;
            z-index: 1;
            margin-top: 26px;
            text-align: center;
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .78);
            font-weight: 600;
            animation: fadeUp .9s .55s ease both;
        }

        .side-left-tag::before,
        .side-left-tag::after {
            content: "";
            display: inline-block;
            width: 22px;
            height: 1px;
            background: rgba(255, 255, 255, .35);
            vertical-align: middle;
            margin: 0 10px;
        }

        .feature-list {
            list-style: none;
            margin: 40px 0 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 280px;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 11px;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255, 255, 255, .9);
            opacity: 0;
            animation: fadeUp .7s ease forwards;
        }

        .feature-list li:nth-child(1) { animation-delay: .75s; }
        .feature-list li:nth-child(2) { animation-delay: .9s; }
        .feature-list li:nth-child(3) { animation-delay: 1.05s; }

        .feature-list li i {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: rgba(255, 255, 255, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            animation: iconGlow 3s ease-in-out infinite;
        }

        .feature-list li:nth-child(2) i { animation-delay: .4s; }
        .feature-list li:nth-child(3) i { animation-delay: .8s; }

        @keyframes iconGlow {
            0%, 100% { background: rgba(255, 255, 255, .15); }
            50%      { background: rgba(255, 255, 255, .28); }
        }

        /* RIGHT SIDE */
        .side-right {
            padding: 36px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-head {
            margin-bottom: 20px;
            opacity: 0;
            animation: fadeUp .7s .35s ease forwards;
        }

        @keyframes fadeUp {
            0%   { opacity: 0; transform: translateY(10px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .form-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 24px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 6px;
        }

        .form-head p {
            font-size: 12px;
            color: #817a71;
            margin: 0;
        }

        .form-group {
            margin-bottom: 12px;
            opacity: 0;
            animation: fadeUp .7s ease forwards;
        }

        .form-group:nth-of-type(1) { animation-delay: .40s; }
        .form-group:nth-of-type(2) { animation-delay: .48s; }
        .form-group:nth-of-type(3) { animation-delay: .56s; }
        .form-group:nth-of-type(4) { animation-delay: .64s; }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #4e4841;
            margin-bottom: 5px;
            letter-spacing: .2px;
        }

        .input-wrap { position: relative; }

        .input-wrap > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
            transition: .2s;
        }

        .input-wrap input {
            width: 100%;
            height: 44px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 12px;
            padding: 0 44px 0 42px;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: #292521;
            outline: none;
            transition: .2s ease;
        }

        .input-wrap input::placeholder {
            color: #b8afa3;
            font-weight: 400;
        }

        .input-wrap input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
            transform: translateY(-1px);
        }

        .input-wrap input:focus + i,
        .input-wrap input:focus ~ i {
            color: #b51f2c;
        }

        .eye-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: 0;
            background: transparent;
            color: #b0a79c;
            cursor: pointer;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            transition: .15s;
        }

        .eye-btn:hover {
            color: #b51f2c;
            background: #fbe8e9;
        }

        /* ===== CREATE ACCOUNT BUTTON — clean, no shimmer ===== */
        .btn-register {
            width: 100%;
            height: 46px;
            margin-top: 6px;
            border: none;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .4px;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
            box-shadow: 0 8px 22px rgba(181, 31, 44, .25);
            opacity: 0;
            animation: fadeUp .7s .72s ease forwards;
        }

        .btn-register:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(181, 31, 44, .32);
        }

        .btn-register:active:not(:disabled) { transform: translateY(0); }
        .btn-register:disabled { opacity: .85; cursor: not-allowed; }

        .btn-spinner {
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: inline-block;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .foot-note {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px dashed #f0ebe4;
            text-align: center;
            font-size: 11.5px;
            color: #948c82;
            opacity: 0;
            animation: fadeUp .7s .80s ease forwards;
        }

        .foot-note a {
            color: #b51f2c;
            font-weight: 700;
            text-decoration: none;
        }

        .foot-note a:hover { text-decoration: underline; }

        /* ===== CUSTOM POPUP ===== */
        .popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(48, 41, 35, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity .25s ease, visibility .25s ease;
            padding: 20px;
        }

        .popup-overlay.show { opacity: 1; visibility: visible; }

        .popup-box {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 380px;
            padding: 28px 26px 22px;
            text-align: center;
            box-shadow: 0 30px 70px rgba(48, 41, 35, .25);
            transform: translateY(14px) scale(.96);
            transition: transform .28s cubic-bezier(.2, .9, .3, 1.05);
        }

        .popup-overlay.show .popup-box {
            transform: translateY(0) scale(1);
        }

        .popup-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
            animation: popIcon .45s cubic-bezier(.2, .9, .3, 1.2) both;
        }

        @keyframes popIcon {
            0%   { transform: scale(.4); opacity: 0; }
            100% { transform: scale(1);  opacity: 1; }
        }

        .popup-icon.success {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            box-shadow: 0 8px 20px rgba(46, 125, 50, .35);
        }

        .popup-icon.error {
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            box-shadow: 0 8px 20px rgba(181, 31, 44, .35);
        }

        .popup-icon.info {
            background: linear-gradient(135deg, #f9a825 0%, #ef6c00 100%);
            box-shadow: 0 8px 20px rgba(249, 168, 37, .35);
        }

        .popup-title {
            font-family: "Playfair Display", serif;
            font-size: 19px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 6px;
        }

        .popup-msg {
            font-size: 12.5px;
            color: #817a71;
            line-height: 1.55;
            margin: 0 0 20px;
        }

        .popup-btn {
            width: 100%;
            height: 42px;
            border: none;
            border-radius: 10px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: .3px;
            color: #fff;
            cursor: pointer;
            transition: transform .2s ease, filter .2s ease;
        }

        .popup-btn.success {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            box-shadow: 0 6px 16px rgba(46, 125, 50, .3);
        }

        .popup-btn.error {
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            box-shadow: 0 6px 16px rgba(181, 31, 44, .3);
        }

        .popup-btn.info {
            background: linear-gradient(135deg, #f9a825 0%, #ef6c00 100%);
            box-shadow: 0 6px 16px rgba(249, 168, 37, .3);
        }

        .popup-btn:hover  { transform: translateY(-1px); filter: brightness(1.05); }
        .popup-btn:active { transform: translateY(0); }

        /* MOBILE */
        @media (max-width: 860px) {
            .login-wrap {
                grid-template-columns: 1fr;
                min-height: auto;
                max-width: 460px;
            }
            .side-left { padding: 36px 28px 30px; min-height: auto; }
            .brand-logo-big { width: 96px; height: 96px; border-radius: 24px; }
            .brand-logo-big::before { border-radius: 24px; }
            .brand-logo-big .fallback { font-size: 48px; }
            .side-left-tag { margin-top: 18px; }
            .feature-list { display: none; }
            .side-right { padding: 30px 24px 26px; }
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .login-wrap { border-radius: 20px; }
            .side-left { padding: 28px 22px 24px; }
            .side-right { padding: 24px 20px 20px; }
            .form-head h1 { font-size: 20px; }
            .brand-logo-big { width: 84px; height: 84px; border-radius: 20px; }
            .brand-logo-big::before { border-radius: 20px; }
        }
    </style>
</head>

<body>

    <div class="bubble b1"></div>
    <div class="bubble b2"></div>
    <div class="bubble b3"></div>

    <div class="login-wrap">

        <!-- LEFT -->
        <aside class="side-left">
            <div class="brand-logo-big">
                <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                <?php else: ?>
                    <span class="fallback">M</span>
                <?php endif; ?>
            </div>

            <div class="side-left-tag">Delivery Boy</div>

            <ul class="feature-list">
                <li><i class="bi bi-person-plus"></i> Quick registration</li>
                <li><i class="bi bi-shield-check"></i> Admin approval required</li>
                <li><i class="bi bi-truck"></i> Start delivering after approval</li>
            </ul>
        </aside>

        <!-- RIGHT -->
        <section class="side-right">

            <div class="form-head">
                <h1>Delivery Register</h1>
                <p>Create your account. Admin will approve it shortly.</p>
            </div>

            <form id="registerForm" novalidate>

                <div class="form-group">
                    <label>Full Name</label>
                    <div class="input-wrap">
                        <i class="bi bi-person"></i>
                        <input type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter your full name"
                            maxlength="100"
                            autocomplete="name"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Mobile Number</label>
                    <div class="input-wrap">
                        <i class="bi bi-phone"></i>
                        <input type="text"
                            id="mobile_number"
                            name="mobile_number"
                            placeholder="Enter 10-digit mobile"
                            maxlength="15"
                            autocomplete="tel"
                            spellcheck="false"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock"></i>
                        <input type="password"
                            id="password"
                            name="password"
                            placeholder="Create a password"
                            maxlength="100"
                            autocomplete="new-password"
                            required>
                        <button type="button" class="eye-btn" id="togglePwd" aria-label="Show password">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock-fill"></i>
                        <input type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter password"
                            maxlength="100"
                            autocomplete="new-password"
                            required>
                        <button type="button" class="eye-btn" id="togglePwd2" aria-label="Show password">
                            <i class="bi bi-eye" id="eyeIcon2"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-register" id="registerBtn">
                    <i class="bi bi-person-plus"></i>
                    <span id="registerBtnText">Create Account</span>
                </button>

            </form>

            <div class="foot-note">
                Already have an account?
                <a href="<?= BASE_URL ?>login.php">Sign in</a>
            </div>

        </section>

    </div>

    <!-- CUSTOM POPUP -->
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box">
            <div class="popup-icon" id="popupIcon">
                <i class="bi bi-check-lg" id="popupIconGlyph"></i>
            </div>
            <h3 class="popup-title" id="popupTitle">Success</h3>
            <p class="popup-msg" id="popupMsg">Message goes here.</p>
            <button type="button" class="popup-btn" id="popupBtn">OK</button>
        </div>
    </div>

    <script>
        window.BASE_URL  = "<?= BASE_URL ?>";
        window.ADMIN_URL = "<?= ADMIN_URL ?>";
    </script>
    <script src="<?= BASE_URL ?>js/register.js"></script>

</body>

</html>