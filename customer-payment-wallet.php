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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        .pw-page { padding: 24px 26px 40px; }

        .pw-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .pw-header h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }
        .pw-header p { margin: 0; color: #817a71; font-size: 12.5px; }

        .pw-card {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            padding: 22px;
        }

        .pw-list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .pw-list-title h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #302923;
        }
        .pw-list-title span {
            display: block;
            margin-top: 4px;
            font-size: 10px;
            color: #817a71;
        }
        .pw-search {
            position: relative;
            width: 260px;
        }
        .pw-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa198;
            font-size: 13px;
            pointer-events: none;
        }
        .pw-search input {
            width: 100%;
            height: 42px;
            border: 1.5px solid #ece5da;
            border-radius: 11px;
            padding: 0 14px 0 38px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            background: #fffdf9;
            outline: none;
            transition: .15s ease;
        }
        .pw-search input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181,31,44,.08);
        }

        /* Customer grid */
        .pw-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
        }

        .pw-cust {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: .2s ease;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .pw-cust:hover {
            border-color: #d98a91;
            transform: translateY(-3px);
            box-shadow: 0 12px 28px -10px rgba(48,41,35,.15);
        }

        .pw-cust-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .pw-cust-avatar {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(181,31,44,.22);
        }
        .pw-cust-info { flex: 1; min-width: 0; }
        .pw-cust-name {
            font-weight: 800;
            color: #302923;
            font-size: 13.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pw-cust-mobile {
            font-size: 11.5px;
            color: #948c82;
            margin-top: 3px;
            font-weight: 600;
        }

        /* Address row: apartment + division */
        .pw-address {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 10px 12px;
            background: #fdfaf4;
            border: 1px solid #f0ebe4;
            border-radius: 10px;
        }
        .pw-address-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            color: #4e4841;
            font-weight: 600;
        }
        .pw-address-row i {
            color: #b51f2c;
            font-size: 12px;
            flex-shrink: 0;
            width: 14px;
            text-align: center;
        }
        .pw-address-row span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pw-address-row .lbl {
            font-weight: 700;
            color: #302923;
            margin-right: 2px;
        }

        .pw-cust-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px dashed #f0ebe4;
            gap: 10px;
        }
        .pw-wallet {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: #e8f6ea;
            color: #1f7a3d;
        }
        .pw-wallet.zero {
            background: #f4efe8;
            color: #948c82;
        }
        .pw-cust-go {
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
        .pw-cust:hover .pw-cust-go {
            background: #b51f2c;
            color: #fff;
            transform: translateX(3px);
        }

        /* Empty state */
        .pw-empty {
            text-align: center;
            padding: 60px 20px;
            color: #948c82;
            grid-column: 1 / -1;
        }
        .pw-empty i {
            font-size: 44px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }
        .pw-empty h3 {
            font-family: "Playfair Display", serif;
            font-size: 17px;
            color: #302923;
            margin: 0 0 6px;
        }
        .pw-empty p { font-size: 12.5px; margin: 0; }

        /* Pagination */
        .pw-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #f2ede5;
            flex-wrap: wrap;
        }
        .pw-pag-info { font-size: 11px; color: #817a71; }
        .pw-pag-info strong { color: #302923; font-weight: 700; }
        .pw-pag-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .pw-perpage {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: #817a71;
        }
        .pw-perpage select {
            height: 32px;
            border: 1px solid #eee7dc;
            border-radius: 8px;
            background: #fffdf9;
            padding: 0 26px 0 10px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            color: #4c4640;
            cursor: pointer;
            outline: none;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 16 16'><path fill='%23817a71' d='M8 11L3 6h10z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 9px center;
        }
        .pw-pag-controls { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .pw-pag-controls button {
            min-width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1px solid #eee7dc;
            background: #fff;
            color: #6f675f;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 8px;
            font-family: inherit;
        }
        .pw-pag-controls button:hover:not(:disabled):not(.active) {
            background: #faf7f0;
            border-color: #e4ddd3;
            color: #302923;
        }
        .pw-pag-controls button.active {
            background: #b51f2c;
            border-color: #b51f2c;
            color: #fff;
            cursor: default;
        }
        .pw-pag-controls button:disabled { opacity: .4; cursor: not-allowed; }
        .pw-pag-controls .ellipsis {
            min-width: 26px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #b5aca2;
            font-size: 12px;
            font-weight: 700;
        }

        /* Modal */
        .pw-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(30, 25, 22, .55);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: .22s ease;
            overflow-y: auto;
        }
        .pw-modal-overlay.show { opacity: 1; visibility: visible; }

        .pw-modal {
            background: #fff;
            border-radius: 20px;
            padding: 26px;
            max-width: 720px;
            width: 100%;
            box-shadow: 0 30px 80px rgba(0,0,0,.28);
            transform: translateY(15px) scale(.96);
            transition: transform .25s cubic-bezier(.2,.9,.3,1.2);
            position: relative;
            margin: auto;
            max-height: 92vh;
            overflow-y: auto;
        }
        .pw-modal-overlay.show .pw-modal { transform: translateY(0) scale(1); }

        .pw-modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1.5px solid #eee7dc;
            background: #fff;
            color: #6f675f;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .pw-modal-close:hover { background: #fbe8e9; border-color: #f1c8cc; color: #b51f2c; }

        .pw-modal-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            padding-right: 40px;
        }
        .pw-modal-avatar {
            width: 54px;
            height: 54px;
            border-radius: 15px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(181,31,44,.25);
        }
        .pw-modal-head h3 {
            margin: 0 0 3px;
            font-family: "Playfair Display", serif;
            font-size: 19px;
            font-weight: 700;
            color: #302923;
        }
        .pw-modal-head p {
            margin: 0;
            font-size: 11.5px;
            color: #948c82;
            font-weight: 600;
        }

        .pw-balance-strip {
            background: linear-gradient(135deg, #fdfaf4 0%, #fff5f5 100%);
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .pw-balance-strip .lbl {
            font-size: 10px;
            font-weight: 800;
            color: #948c82;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 4px;
        }
        .pw-balance-strip .val {
            font-family: "Playfair Display", serif;
            font-size: 24px;
            font-weight: 700;
            color: #b51f2c;
            line-height: 1.1;
        }

        .pw-tabs {
            display: flex;
            gap: 4px;
            background: #faf7f0;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 18px;
        }
        .pw-tab {
            flex: 1;
            height: 42px;
            border-radius: 9px;
            border: none;
            background: transparent;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 800;
            color: #817a71;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: .15s ease;
        }
        .pw-tab.active {
            background: #fff;
            color: #302923;
            box-shadow: 0 3px 10px rgba(48,41,35,.07);
        }
        .pw-tab.credit.active { color: #1b5e20; }
        .pw-tab.debit.active { color: #b51f2c; }

        .pw-field { margin-bottom: 14px; }
        .pw-field label {
            display: block;
            font-size: 10.5px;
            font-weight: 800;
            color: #4e4841;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .pw-field input,
        .pw-field textarea {
            width: 100%;
            height: 46px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 11px;
            padding: 0 14px;
            font-family: "DM Sans", sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: #292521;
            outline: none;
            transition: .15s ease;
        }
        .pw-field textarea {
            height: auto;
            min-height: 70px;
            padding: 12px 14px;
            resize: vertical;
        }
        .pw-field input:focus,
        .pw-field textarea:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181,31,44,.08);
        }

        .pw-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .pw-preset {
            height: 32px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1.5px solid #ece5da;
            background: #fff;
            font-family: "DM Sans", sans-serif;
            font-size: 11px;
            font-weight: 800;
            color: #6f675f;
            cursor: pointer;
            transition: .15s ease;
        }
        .pw-preset:hover {
            background: #fbe8e9;
            border-color: #f1c8cc;
            color: #b51f2c;
        }

        .pw-preview {
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: 11px;
            background: #fdfaf4;
            border: 1px solid #f0ebe4;
            font-size: 12px;
            font-weight: 700;
            color: #6f675f;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .pw-preview .to { color: #1b5e20; font-family: "Playfair Display", serif; font-size: 16px; font-weight: 700; }
        .pw-preview.warn .to { color: #b51f2c; }

        .pw-actions { display: flex; gap: 10px; margin-top: 20px; }
        .pw-btn {
            flex: 1;
            height: 46px;
            border-radius: 11px;
            border: none;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: .18s ease;
        }
        .pw-btn.primary {
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            box-shadow: 0 8px 20px rgba(181,31,44,.22);
        }
        .pw-btn.primary:hover:not(:disabled) { transform: translateY(-1px); }
        .pw-btn.primary:disabled { opacity: .55; cursor: not-allowed; }
        .pw-btn.ghost {
            background: #fff;
            border: 1.5px solid #e4ddd3;
            color: #6f675f;
        }
        .pw-btn.ghost:hover { background: #faf7f0; }

        /* History */
        .pw-history-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 24px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .pw-history-head h4 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 14px;
            font-weight: 700;
            color: #302923;
        }
        .pw-history-head h4 i { color: #b51f2c; margin-right: 4px; }

        .pw-refresh {
            height: 32px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            font-family: "DM Sans", sans-serif;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .pw-refresh:hover { background: #faf7f0; color: #302923; }

        .pw-history-wrap {
            max-height: 340px;
            overflow-y: auto;
            border-radius: 12px;
            border: 1.5px solid #ece5da;
            background: #fff;
        }
        .pw-history-wrap::-webkit-scrollbar { width: 6px; }
        .pw-history-wrap::-webkit-scrollbar-thumb { background: #e0d8cd; border-radius: 4px; }

        .pw-history-table { width: 100%; border-collapse: collapse; }
        .pw-history-table th {
            background: #faf7f0;
            color: #938a80;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: 10px 12px;
            border-bottom: 1px solid #eee7dc;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .pw-history-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #f2ede5;
            font-size: 11.5px;
            color: #4c4640;
            vertical-align: middle;
        }
        .pw-history-table tr:last-child td { border-bottom: 0; }

        .pw-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .pw-badge.credit { background: #e8f1e8; color: #52745b; }
        .pw-badge.debit { background: #fbeaea; color: #b51f2c; }

        .pw-amt-credit { color: #1b5e20; font-weight: 800; }
        .pw-amt-debit { color: #b51f2c; font-weight: 800; }
        .pw-bal-cell { font-family: "Playfair Display", serif; font-weight: 700; color: #302923; }

        /* Toast */
        .pw-toast-wrap {
            position: fixed;
            top: 22px;
            right: 22px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 10000;
            pointer-events: none;
        }
        .pw-toast {
            min-width: 260px;
            max-width: 360px;
            background: #fff;
            border-radius: 12px;
            padding: 13px 15px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 12px;
            color: #302923;
            border: 1px solid #eee7dc;
            box-shadow: 0 14px 34px rgba(0,0,0,.14);
            transform: translateX(120%);
            opacity: 0;
            transition: transform .3s cubic-bezier(.2,.9,.3,1.2), opacity .3s ease;
            pointer-events: auto;
        }
        .pw-toast.show { transform: translateX(0); opacity: 1; }
        .pw-toast-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
            color: #fff;
        }
        .pw-toast.success .pw-toast-icon { background: #4caf50; }
        .pw-toast.error .pw-toast-icon { background: #c62828; }
        .pw-toast.info .pw-toast-icon { background: #3b82f6; }
        .pw-toast-body { flex: 1; padding-top: 3px; line-height: 1.5; }
        .pw-toast-close {
            background: transparent;
            border: 0;
            color: #b5aca2;
            cursor: pointer;
            font-size: 14px;
            padding: 0 2px;
            line-height: 1;
        }

        @media (max-width: 640px) {
            .pw-page { padding: 18px 14px 40px; }
            .pw-card { padding: 17px; border-radius: 17px; }
            .pw-header { flex-direction: column; align-items: flex-start; }
            .pw-list-head { flex-direction: column; align-items: stretch; }
            .pw-search { width: 100%; }
            .pw-grid { grid-template-columns: 1fr; }
            .pw-modal { padding: 20px 16px; }
            .pw-pagination { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 480px) {
            .pw-toast-wrap { top: 14px; right: 14px; left: 14px; }
            .pw-toast { min-width: 0; width: 100%; }
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
                <h2>Payment Wallet</h2>
                <p>Manage customer wallet balances</p>
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


        <main class="pw-page">

            <div class="pw-header">
                <div>
                    <h1>Payment Wallet</h1>
                    <p>Click any customer to add or deduct wallet money.</p>
                </div>
                <button type="button" class="pw-btn ghost" id="pwRefresh" style="flex:0 0 auto; padding:0 20px;">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <div class="pw-card">

                <div class="pw-list-head">
                    <div class="pw-list-title">
                        <h3>All Customers</h3>
                        <span>Loaded from the database</span>
                    </div>
                    <div class="pw-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="pwSearch" placeholder="Search name or mobile...">
                    </div>
                </div>

                <div class="pw-grid" id="pwGrid">
                    <div class="pw-empty">
                        <i class="bi bi-people"></i>
                        <h3>Loading…</h3>
                        <p>Fetching customer list</p>
                    </div>
                </div>

                <div class="pw-pagination" id="pwPagination" style="display:none;">
                    <div class="pw-pag-left">
                        <div class="pw-pag-info" id="pwPagInfo">
                            Showing <strong>0</strong>–<strong>0</strong> of <strong>0</strong>
                        </div>
                        <label class="pw-perpage">
                            Show
                            <select id="pwPerPage">
                                <option value="12">12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                                <option value="96">96</option>
                            </select>
                            entries
                        </label>
                    </div>
                    <div class="pw-pag-controls" id="pwPagControls"></div>
                </div>

            </div>
        </main>
    </div>


    <!-- ============ WALLET MODAL ============ -->
    <div class="pw-modal-overlay" id="pwModal" aria-hidden="true">
        <div class="pw-modal">
            <button type="button" class="pw-modal-close" id="pwModalClose">
                <i class="bi bi-x-lg"></i>
            </button>

            <div class="pw-modal-head">
                <div class="pw-modal-avatar" id="pwModalAvatar">?</div>
                <div>
                    <h3 id="pwModalName">Customer</h3>
                    <p id="pwModalMobile">—</p>
                </div>
            </div>

            <div class="pw-balance-strip">
                <div>
                    <div class="lbl">Current Balance</div>
                    <div class="val" id="pwModalBalance">₹0</div>
                </div>
                <span style="font-size:11px;color:#948c82;font-weight:700;" id="pwModalStatus">Active</span>
            </div>

            <div class="pw-tabs">
                <button type="button" class="pw-tab credit active" data-tab="credit">
                    <i class="bi bi-plus-circle"></i> Add Money
                </button>
                <button type="button" class="pw-tab debit" data-tab="debit">
                    <i class="bi bi-dash-circle"></i> Deduct Money
                </button>
            </div>

            <form id="pwForm" autocomplete="off">
                <input type="hidden" id="pwTxnType" value="credit">
                <input type="hidden" id="pwCustomerId" value="">

                <div class="pw-field">
                    <label>Amount (₹) <span style="color:#b51f2c;">*</span></label>
                    <input type="number" id="pwAmount" min="1" step="0.01" required placeholder="0.00">
                    <div class="pw-presets">
                        <button type="button" class="pw-preset" data-amt="100">₹100</button>
                        <button type="button" class="pw-preset" data-amt="500">₹500</button>
                        <button type="button" class="pw-preset" data-amt="1000">₹1000</button>
                        <button type="button" class="pw-preset" data-amt="2000">₹2000</button>
                        <button type="button" class="pw-preset" data-amt="5000">₹5000</button>
                    </div>
                </div>

                <div class="pw-field">
                    <label>Note (optional)</label>
                    <textarea id="pwNote" maxlength="250" placeholder="e.g. Cash received, order adjustment..."></textarea>
                </div>

                <div class="pw-preview" id="pwPreview">
                    <span>New Balance will be</span>
                    <span class="to" id="pwPreviewValue">₹0</span>
                </div>

                <div class="pw-actions">
                    <button type="button" class="pw-btn ghost" id="pwCancel">Cancel</button>
                    <button type="submit" class="pw-btn primary" id="pwSubmit">
                        <i class="bi bi-check-lg"></i>
                        <span id="pwSubmitText">Add Money</span>
                    </button>
                </div>
            </form>

            <div class="pw-history-head">
                <h4><i class="bi bi-clock-history"></i> Transaction History</h4>
                <button type="button" class="pw-refresh" id="pwRefreshHistory">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <div class="pw-history-wrap" id="pwHistory">
                <div style="padding:30px;text-align:center;color:#948c82;font-size:11.5px;">
                    Loading transactions…
                </div>
            </div>

        </div>
    </div>


    <div class="pw-toast-wrap" id="pwToastWrap"></div>


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

    <script src="<?= BASE_URL ?>js/payment-wallet.js"></script>

</body>

</html>