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
$QRlogoUrl  = !empty($settings['favicon_image']) ? ADMIN_URL . $settings['favicon_image'] : '';
$logoUrl  = !empty($settings['logo_image']) ? ADMIN_URL . $settings['logo_image'] : '';

$firstName = explode(' ', trim($boy['full_name']))[0];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include './includes/head.php'; ?>

    <style>
        /* =====================================================
           TAKE ORDER PAGE
        ===================================================== */
        .to-page {
            padding: 24px 26px 40px;
        }

        .to-head {
            margin-bottom: 18px;
        }

        .to-head h1 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
            font-weight: 700;
            color: #302923;
            margin: 0 0 4px;
        }

        .to-head p {
            margin: 0;
            font-size: 12.5px;
            color: #817a71;
        }

        .to-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 20px;
            align-items: start;
        }

        .to-card {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            padding: 22px;
        }

        .to-section {
            margin-bottom: 20px;
        }

        .to-section:last-child {
            margin-bottom: 0;
        }

        .to-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .to-section-title i {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: #fbe8e9;
            color: #b51f2c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .to-section-title h3 {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            font-weight: 700;
            margin: 0;
            color: #302923;
        }

        .to-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #4e4841;
            margin-bottom: 6px;
        }

        .to-label .required {
            color: #b51f2c;
        }

        .to-input {
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

        .to-input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }

        .to-input-wrap {
            position: relative;
        }

        .to-input-wrap>i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        /* =====================================================
           SEARCHABLE DROPDOWN
        ===================================================== */
        .sd-wrap {
            position: relative;
        }

        .sd-toggle {
            width: 100%;
            height: 44px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 11px;
            padding: 0 38px 0 40px;
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 500;
            color: #292521;
            text-align: left;
            cursor: pointer;
            outline: none;
            transition: .2s ease;
            display: flex;
            align-items: center;
            position: relative;
        }

        .sd-toggle:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }

        .sd-toggle>i.lead {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        .sd-toggle>i.caret {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 12px;
            pointer-events: none;
            transition: transform .2s;
        }

        .sd-wrap.open .sd-toggle>i.caret {
            transform: translateY(-50%) rotate(180deg);
        }

        .sd-toggle .sd-placeholder {
            color: #b8afa3;
            font-weight: 400;
        }

        .sd-toggle.has-value {
            color: #292521;
            font-weight: 600;
        }

        .sd-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(48, 41, 35, .12);
            z-index: 30;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: .18s ease;
            overflow: hidden;
        }

        .sd-wrap.open .sd-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .sd-search {
            position: relative;
            padding: 10px;
            border-bottom: 1px solid #f0ebe4;
        }

        .sd-search input {
            width: 100%;
            height: 38px;
            border: 1.5px solid #ece5da;
            background: #fffdf9;
            border-radius: 9px;
            padding: 0 12px 0 34px;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            outline: none;
        }

        .sd-search input:focus {
            border-color: #d98a91;
            background: #fff;
        }

        .sd-search>i {
            position: absolute;
            left: 22px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 13px;
            pointer-events: none;
        }

        .sd-list {
            max-height: 240px;
            overflow-y: auto;
            padding: 6px;
        }

        .sd-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 9px;
            font-size: 12.5px;
            color: #4e4841;
            cursor: pointer;
            transition: .15s ease;
        }

        .sd-option:hover {
            background: #fbe8e9;
            color: #b51f2c;
        }

        .sd-option.selected {
            background: #b51f2c;
            color: #fff;
        }

        .sd-option.selected i {
            color: #fff;
        }

        .sd-option i {
            color: #b0a79c;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sd-option .name {
            flex: 1;
            min-width: 0;
        }

        .sd-option .code {
            font-size: 10px;
            color: #948c82;
            font-weight: 600;
        }

        .sd-option.selected .code {
            color: rgba(255, 255, 255, .8);
        }

        .sd-empty {
            padding: 18px;
            text-align: center;
            font-size: 12px;
            color: #948c82;
        }

        /* =====================================================
           PRODUCT TABS
        ===================================================== */
        .pd-tabs {
            display: flex;
            background: #fdfaf4;
            border: 1.5px solid #ece5da;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 14px;
            gap: 4px;
        }

        .pd-tab {
            flex: 1;
            border: none;
            background: transparent;
            padding: 10px 14px;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 700;
            color: #6f675f;
            border-radius: 9px;
            cursor: pointer;
            transition: .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .pd-tab:hover {
            color: #b51f2c;
        }

        .pd-tab.active {
            background: #fff;
            color: #b51f2c;
            box-shadow: 0 4px 12px rgba(48, 41, 35, .08);
        }

        .pd-tab i {
            font-size: 14px;
        }

        /* =====================================================
           PRODUCT SEARCH + SUGGESTIONS WRAPPER
        ===================================================== */
        .pd-search-wrap {
            position: relative;
            margin-bottom: 14px;
        }

        .pd-search {
            position: relative;
            margin-bottom: 0;
        }

        .pd-search input {
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

        .pd-search input:focus {
            border-color: #b51f2c;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(181, 31, 44, .08);
        }

        .pd-search>i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #b0a79c;
            font-size: 15px;
            pointer-events: none;
        }

        /* ---- Suggestion dropdown ---- */
        .pd-suggest {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(48, 41, 35, .12);
            z-index: 40;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: .18s ease;
            overflow: hidden;
            max-height: 380px;
        }

        .pd-suggest.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .pd-suggest-list {
            max-height: 340px;
            overflow-y: auto;
            padding: 6px;
        }

        .pd-suggest-list::-webkit-scrollbar {
            width: 6px;
        }

        .pd-suggest-list::-webkit-scrollbar-thumb {
            background: #e0d8cd;
            border-radius: 4px;
        }

        .pd-suggest-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: .15s ease;
        }

        .pd-suggest-item:hover,
        .pd-suggest-item.active {
            background: #fbe8e9;
        }

        .pd-suggest-thumb {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #f7efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-size: 20px;
        }

        .pd-suggest-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pd-suggest-info {
            flex: 1;
            min-width: 0;
        }

        .pd-suggest-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #302923;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pd-suggest-name mark {
            background: #fff3c4;
            color: #302923;
            padding: 0 2px;
            border-radius: 3px;
        }

        .pd-suggest-meta {
            font-size: 10.5px;
            color: #948c82;
            margin-top: 2px;
            font-weight: 600;
        }

        .pd-suggest-meta .price {
            color: #b51f2c;
            font-weight: 800;
        }

        .pd-suggest-add {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #b51f2c;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            opacity: 0;
            transform: scale(.8);
            transition: .18s ease;
        }

        .pd-suggest-item:hover .pd-suggest-add,
        .pd-suggest-item.active .pd-suggest-add {
            opacity: 1;
            transform: scale(1);
        }

        .pd-suggest-empty {
            padding: 24px 18px;
            text-align: center;
            font-size: 12px;
            color: #948c82;
        }

        .pd-suggest-empty i {
            font-size: 24px;
            color: #d5cbbd;
            display: block;
            margin-bottom: 6px;
        }

        .pd-suggest-loading {
            padding: 18px;
            text-align: center;
            font-size: 12px;
            color: #948c82;
        }

        .pd-suggest-loading .dots {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #ece5da;
            border-top-color: #b51f2c;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }

        /* =====================================================
           PRODUCT GRID
        ===================================================== */
        .pd-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            max-height: 620px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .pd-grid::-webkit-scrollbar {
            width: 6px;
        }

        .pd-grid::-webkit-scrollbar-thumb {
            background: #e0d8cd;
            border-radius: 4px;
        }

        .pd-card {
            background: #fffdf9;
            border: 1.5px solid #ece5da;
            border-radius: 14px;
            padding: 12px;
            cursor: pointer;
            transition: .2s ease;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .pd-card:hover {
            border-color: #d98a91;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(48, 41, 35, .08);
        }

        .pd-thumb {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: #f7efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-size: 22px;
        }

        .pd-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pd-info {
            flex: 1;
            min-width: 0;
        }

        .pd-name {
            font-size: 12px;
            font-weight: 700;
            color: #302923;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pd-meta {
            font-size: 10.5px;
            color: #948c82;
            margin-top: 3px;
            font-weight: 600;
        }

        .pd-price {
            font-size: 11.5px;
            color: #b51f2c;
            font-weight: 800;
            margin-top: 3px;
        }

        .pd-badge {
            display: inline-block;
            background: #e8f6ea;
            color: #2e7d32;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
            letter-spacing: .3px;
        }

        .pd-empty {
            text-align: center;
            padding: 40px 20px;
            border: 1.5px dashed #e4ddd3;
            border-radius: 12px;
            color: #948c82;
            font-size: 12px;
            background: #fdfaf4;
            grid-column: 1 / -1;
        }

        .pd-empty i {
            font-size: 30px;
            color: #d5cbbd;
            display: block;
            margin-bottom: 8px;
        }

        /* =====================================================
           CART / SUMMARY PANEL
        ===================================================== */
        .cart-panel {
            position: sticky;
            top: 100px;
        }

        .cart-card {
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 20px;
            overflow: hidden;
        }

        .cart-head {
            padding: 16px 18px;
            border-bottom: 1.5px solid #f0ebe4;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cart-head h3 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 15px;
            font-weight: 700;
        }

        .cart-count {
            background: #b51f2c;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 20px;
            letter-spacing: .3px;
        }

        .cart-list {
            max-height: 340px;
            overflow-y: auto;
            padding: 10px;
        }

        .cart-list::-webkit-scrollbar {
            width: 6px;
        }

        .cart-list::-webkit-scrollbar-thumb {
            background: #e0d8cd;
            border-radius: 4px;
        }

        .cart-empty {
            text-align: center;
            padding: 34px 20px;
            color: #948c82;
            font-size: 12px;
        }

        .cart-empty i {
            font-size: 34px;
            color: #ece5da;
            display: block;
            margin-bottom: 10px;
        }

        .cart-item {
            display: flex;
            gap: 10px;
            padding: 10px;
            border-radius: 11px;
            background: #fffdf9;
            border: 1px solid #f0ebe4;
            margin-bottom: 8px;
            align-items: center;
            animation: fadeSlide .25s ease;
        }

        @keyframes fadeSlide {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .cart-item:last-child {
            margin-bottom: 0;
        }

        .cart-thumb {
            width: 42px;
            height: 42px;
            border-radius: 9px;
            background: #f7efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-size: 18px;
        }

        .cart-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-info {
            flex: 1;
            min-width: 0;
        }

        .cart-name {
            font-size: 11.5px;
            font-weight: 700;
            color: #302923;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cart-meta {
            font-size: 10px;
            color: #948c82;
            margin-top: 2px;
            font-weight: 600;
        }

        .cart-price {
            color: #b51f2c;
            font-weight: 800;
            font-family: "DM Sans", sans-serif;
            letter-spacing: -.2px;
        }

        .cart-qty {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fff;
            border: 1.5px solid #ece5da;
            border-radius: 8px;
            padding: 2px;
        }

        .cart-qty button {
            width: 24px;
            height: 24px;
            border: 0;
            background: transparent;
            color: #6f675f;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .cart-qty button:hover {
            background: #fbe8e9;
            color: #b51f2c;
        }

        .cart-qty span {
            font-size: 11px;
            font-weight: 800;
            color: #302923;
            min-width: 20px;
            text-align: center;
        }

        .cart-remove {
            width: 26px;
            height: 26px;
            border: 1.5px solid #f0d6d8;
            background: #fff;
            color: #b51f2c;
            border-radius: 7px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }

        .cart-remove:hover {
            background: #fde6e6;
        }

        .cart-footer {
            padding: 16px 18px;
            border-top: 1.5px solid #f0ebe4;
            background: #fdfaf4;
        }

        .cart-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11.5px;
            color: #6f675f;
            margin-bottom: 8px;
        }

        .cart-row strong {
            color: #302923;
            font-weight: 700;
        }

        .cart-row.total {
            font-size: 14px;
            color: #302923;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1.5px dashed #e4ddd3;
        }

        .cart-row.total strong {
            font-family: "DM Sans", sans-serif;
            font-size: 17px;
            color: #b51f2c;
            font-weight: 800;
            letter-spacing: -.3px;
        }

        .cart-actions {
            display: flex;
            gap: 8px;
            margin-top: 14px;
        }

        .btn-cancel {
            flex: 1;
            border: 1.5px solid #ece5da;
            background: #fff;
            color: #6f675f;
            border-radius: 11px;
            padding: 11px 16px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            text-align: center;
        }

        .btn-cancel:hover {
            background: #faf7f0;
            color: #302923;
        }

        .btn-save {
            flex: 2;
            border: none;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            border-radius: 11px;
            padding: 11px 18px;
            font-family: "DM Sans", sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: .2s;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .22);
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(181, 31, 44, .3);
        }

        .btn-save:disabled {
            opacity: .7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-spinner {
            width: 13px;
            height: 13px;
            border: 2px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* =====================================================
           VARIANT MODAL
        ===================================================== */
        .variant-overlay {
            position: fixed;
            inset: 0;
            background: rgba(30, 25, 22, .55);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9998;
            opacity: 0;
            visibility: hidden;
            transition: .2s ease;
        }

        .variant-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .variant-modal {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            max-width: 440px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .25);
            transform: translateY(15px) scale(.96);
            transition: transform .25s cubic-bezier(.2, .9, .3, 1.2);
        }

        .variant-overlay.show .variant-modal {
            transform: translateY(0) scale(1);
        }

        .variant-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }

        .variant-thumb {
            width: 62px;
            height: 62px;
            border-radius: 14px;
            background: #f7efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-size: 26px;
        }

        .variant-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .variant-head-info h3 {
            margin: 0 0 3px;
            font-size: 14px;
            font-weight: 800;
            color: #302923;
            line-height: 1.3;
        }

        .variant-head-info span {
            font-size: 11px;
            color: #948c82;
            font-weight: 600;
        }

        .variant-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .variant-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1.5px solid #ece5da;
            border-radius: 12px;
            cursor: pointer;
            transition: .15s ease;
            background: #fffdf9;
        }

        .variant-option:hover {
            border-color: #d98a91;
            background: #fff5f5;
        }

        .variant-radio {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #d5cbbd;
            flex-shrink: 0;
            position: relative;
            transition: .15s;
        }

        .variant-option.selected .variant-radio {
            border-color: #b51f2c;
        }

        .variant-option.selected .variant-radio::after {
            content: "";
            position: absolute;
            inset: 3px;
            background: #b51f2c;
            border-radius: 50%;
        }

        .variant-body {
            flex: 1;
            min-width: 0;
        }

        .variant-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #302923;
        }

        .variant-qty {
            font-size: 10.5px;
            color: #948c82;
            margin-top: 2px;
            font-weight: 600;
        }

        .variant-price {
            font-size: 13px;
            font-weight: 800;
            color: #b51f2c;
            font-family: "Playfair Display", serif;
        }

        .variant-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* Container note inside variant option */
        .variant-container-note {
            font-size: 10px;
            color: #b8893c;
            background: #fdf7ec;
            border: 1px dashed #e8d5a8;
            border-radius: 6px;
            padding: 3px 7px;
            margin-top: 5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 700;
        }

        .variant-container-note i {
            font-size: 11px;
        }

        /* Container note inside cart item */
        .cart-container-note {
            font-size: 9.5px;
            color: #b8893c;
            background: #fdf7ec;
            border-radius: 5px;
            padding: 2px 6px;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 700;
        }

        .cart-container-note i {
            font-size: 10px;
        }

        /* Popups */
        .mm-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(30, 25, 22, .55);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: .25s ease;
        }

        .mm-modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .mm-modal {
            background: #fff;
            border-radius: 18px;
            padding: 30px 26px 24px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .25);
            transform: translateY(15px) scale(.96);
            transition: transform .25s cubic-bezier(.2, .9, .3, 1.2);
        }

        .mm-modal-overlay.show .mm-modal {
            transform: translateY(0) scale(1);
        }

        .mm-modal-icon {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            background: #e8f6ea;
            color: #2e7d32;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 16px;
            animation: popIn .35s cubic-bezier(.2, .9, .3, 1.4);
        }

        .mm-modal-icon.error {
            background: #fdecec;
            color: #b51f2c;
        }

        @keyframes popIn {
            0% {
                transform: scale(.5);
                opacity: 0;
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .mm-modal-title {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 800;
            color: #302923;
        }

        .mm-modal-text {
            margin: 0 0 20px;
            font-size: 12.5px;
            color: #756d65;
            line-height: 1.6;
        }

        .mm-modal-code {
            display: inline-block;
            background: #faf7f0;
            border: 1px dashed #d8c9b8;
            color: #6f5a3f;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            margin-bottom: 18px;
            letter-spacing: 1px;
        }

        .mm-modal-actions {
            display: flex;
            gap: 10px;
        }

        .mm-btn {
            flex: 1;
            height: 44px;
            border-radius: 11px;
            border: none;
            font-family: "DM Sans", sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            transition: .2s;
        }

        .mm-btn-primary {
            background: #b51f2c;
            color: #fff;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .22);
        }

        .mm-btn-primary:hover {
            background: #8e1722;
            color: #fff;
        }

        .mm-btn-ghost {
            background: #fff;
            border: 1.5px solid #e4ddd3;
            color: #6f675f;
        }

        .mm-btn-ghost:hover {
            background: #faf7f0;
            color: #302923;
        }

        /* =====================================================
           UPI QR PAYMENT MODAL
        ===================================================== */
        .upi-overlay {
            position: fixed;
            inset: 0;
            background: rgba(30, 25, 22, .65);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: .25s ease;
        }

        .upi-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .upi-modal {
            background: #fff;
            border-radius: 22px;
            padding: 26px 24px 22px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .3);
            transform: translateY(20px) scale(.95);
            transition: transform .3s cubic-bezier(.2, .9, .3, 1.2);
            max-height: 92vh;
            overflow-y: auto;
        }

        .upi-overlay.show .upi-modal {
            transform: translateY(0) scale(1);
        }

        .upi-head {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
            margin-bottom: 6px;
        }

        .upi-head .icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: linear-gradient(135deg, #b51f2c 0%, #8e1722 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 8px 20px rgba(181, 31, 44, .25);
        }

        .upi-head h3 {
            margin: 0;
            font-family: "Playfair Display", serif;
            font-size: 19px;
            font-weight: 700;
            color: #302923;
        }

        .upi-sub {
            font-size: 12px;
            color: #817a71;
            margin: 4px 0 16px;
        }

        .upi-code-badge {
            display: inline-block;
            background: #faf7f0;
            border: 1px dashed #d8c9b8;
            color: #6f5a3f;
            font-size: 11px;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 8px;
            letter-spacing: 1.5px;
            margin-bottom: 14px;
        }

        .upi-amount {
            font-family: "DM Sans", sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: #b51f2c;
            margin: 4px 0 18px;
            line-height: 1;
            letter-spacing: -.5px;
        }

        /* QR box — positions the logo on top */
        .upi-qr {
            width: 220px;
            height: 220px;
            margin: 0 auto 16px;
            border-radius: 16px;
            background: #fff;
            padding: 12px;
            border: 2px solid #ece5da;
            box-shadow: 0 10px 30px rgba(48, 41, 35, .1);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .upi-qr canvas,
        .upi-qr img.qr-canvas,
        .upi-qr svg {
            width: 100% !important;
            height: 100% !important;
            display: block;
        }

        /* Center logo on top of the QR */
        .upi-qr .qr-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 52px;
            height: 52px;
            background: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .10);
            border: 2px solid #fff;
            pointer-events: none;
            z-index: 3;
        }

        .upi-qr .qr-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 8px;
            display: block;
        }

        .upi-qr .qr-logo .fallback {
            color: #b51f2c;
            font-family: "Playfair Display", serif;
            font-weight: 700;
            font-size: 24px;
            line-height: 1;
        }

        .upi-note {
            background: #fff7e7;
            border: 1px solid #f3dca5;
            color: #8a6a1e;
            font-size: 11px;
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .upi-actions {
            display: flex;
            gap: 10px;
        }

        .upi-actions .mm-btn {
            flex: 1;
        }

        .upi-actions .btn-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: inline-block;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */
        @media (max-width: 1024px) {
            .to-layout {
                grid-template-columns: 1fr;
            }

            .cart-panel {
                position: static;
            }

            .pd-grid {
                max-height: none;
            }
        }

        @media (max-width: 640px) {
            .to-page {
                padding: 18px 14px 30px;
            }

            .to-card,
            .cart-card {
                border-radius: 16px;
            }

            .pd-grid {
                grid-template-columns: 1fr;
            }

            .pd-tab {
                font-size: 11px;
                padding: 9px 10px;
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
                <h2>Take Order</h2>
                <p>Create a new order on behalf of the customer</p>
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


        <main class="to-page">

            <div class="to-head">
                <h1>New Order</h1>
                <p>Pick customer details, apartment, division, then add products from the menu or catalog.</p>
            </div>

            <form id="orderForm" novalidate>

                <div class="to-layout">

                    <!-- LEFT: Customer + Product picker -->
                    <div>

                        <!-- CUSTOMER -->
                        <div class="to-card" style="margin-bottom:20px;">
                            <div class="to-section-title">
                                <i class="bi bi-person-badge"></i>
                                <h3>Customer Details</h3>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="to-label">Customer Name <span class="required">*</span></label>
                                    <div class="to-input-wrap">
                                        <i class="bi bi-person"></i>
                                        <input type="text" id="customer_name" class="to-input"
                                            placeholder="Enter customer name" maxlength="150">
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="to-label">Mobile Number <span class="required">*</span></label>
                                    <div class="to-input-wrap">
                                        <i class="bi bi-telephone"></i>
                                        <input type="text" id="customer_mobile" class="to-input"
                                            placeholder="10-digit mobile" maxlength="15" inputmode="numeric">
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="to-label">Apartment <span class="required">*</span></label>
                                    <div class="sd-wrap" id="apartmentDd">
                                        <button type="button" class="sd-toggle" data-target="apartment">
                                            <i class="bi bi-building lead"></i>
                                            <span class="sd-placeholder">Select apartment</span>
                                            <i class="bi bi-chevron-down caret"></i>
                                        </button>
                                        <div class="sd-menu">
                                            <div class="sd-search">
                                                <i class="bi bi-search"></i>
                                                <input type="text" placeholder="Search apartment..." data-search="apartment">
                                            </div>
                                            <div class="sd-list" data-list="apartment">
                                                <div class="sd-empty">Loading...</div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="apartment_id">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="to-label">Division <span class="required">*</span></label>
                                    <div class="sd-wrap" id="divisionDd">
                                        <button type="button" class="sd-toggle" data-target="division" disabled>
                                            <i class="bi bi-grid-3x3-gap lead"></i>
                                            <span class="sd-placeholder">Select apartment first</span>
                                            <i class="bi bi-chevron-down caret"></i>
                                        </button>
                                        <div class="sd-menu">
                                            <div class="sd-search">
                                                <i class="bi bi-search"></i>
                                                <input type="text" placeholder="Search division..." data-search="division">
                                            </div>
                                            <div class="sd-list" data-list="division">
                                                <div class="sd-empty">Select apartment first</div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="division_name">
                                    <input type="hidden" id="division_charge">
                                </div>
                            </div>
                        </div>


                        <!-- PRODUCTS PICKER -->
                        <div class="to-card">
                            <div class="to-section-title">
                                <i class="bi bi-box-seam"></i>
                                <h3>Add Products</h3>
                            </div>

                            <div class="pd-tabs">
                                <button type="button" class="pd-tab active" data-tab="menu">
                                    <i class="bi bi-stars"></i>
                                    Menu Products
                                </button>
                                <button type="button" class="pd-tab" data-tab="all">
                                    <i class="bi bi-grid-3x3-gap-fill"></i>
                                    All Products
                                </button>
                            </div>

                            <!-- ✅ SEARCH WITH SUGGESTIONS WRAPPER -->
                            <div class="pd-search-wrap">
                                <div class="pd-search">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="productSearch" placeholder="Search product by name or code..." autocomplete="off">
                                </div>
                                <div class="pd-suggest" id="productSuggest"></div>
                            </div>

                            <div class="pd-grid" id="productGrid">
                                <div class="pd-empty">
                                    <i class="bi bi-hourglass-split"></i>
                                    Loading products...
                                </div>
                            </div>
                        </div>

                    </div>


                    <!-- RIGHT: Cart summary -->
                    <div class="cart-panel">
                        <div class="cart-card">

                            <div class="cart-head">
                                <h3>Order Summary</h3>
                                <span class="cart-count" id="cartCount">0</span>
                            </div>

                            <div class="cart-list" id="cartList">
                                <div class="cart-empty">
                                    <i class="bi bi-basket"></i>
                                    No products added yet.<br>
                                    Pick from the left to begin.
                                </div>
                            </div>

                            <div class="cart-footer">

                                <div class="cart-row">
                                    <span>Subtotal</span>
                                    <strong id="subtotalText">₹0.00</strong>
                                </div>

                                <div class="cart-row">
                                    <span>Delivery Charge</span>
                                    <strong id="deliveryText">₹0.00</strong>
                                </div>

                                <div class="cart-row total">
                                    <span>Total</span>
                                    <strong id="totalText">₹0.00</strong>
                                </div>

                                <div class="cart-actions">
                                    <a href="<?= BASE_URL ?>index.php" class="btn-cancel">Cancel</a>
                                    <button type="submit" class="btn-save" id="saveBtn">
                                        <i class="bi bi-check-lg"></i>
                                        <span id="saveBtnText">Place Order</span>
                                    </button>
                                </div>

                            </div>

                        </div>
                    </div>

                </div>

            </form>

        </main>

    </div>


    <!-- VARIANT PICKER MODAL -->
    <div class="variant-overlay" id="variantOverlay" aria-hidden="true">
        <div class="variant-modal">

            <div class="variant-head">
                <div class="variant-thumb" id="vThumb">📦</div>
                <div class="variant-head-info">
                    <h3 id="vName">Product</h3>
                    <span id="vCode">#PRD000</span>
                </div>
            </div>

            <div class="variant-list" id="vList"></div>

            <div class="variant-actions">
                <button type="button" class="mm-btn mm-btn-ghost" id="vCancel">Cancel</button>
                <button type="button" class="mm-btn mm-btn-primary" id="vAdd">
                    <i class="bi bi-cart-plus"></i>
                    Add to Order
                </button>
            </div>

        </div>
    </div>


    <!-- UPI PAYMENT MODAL -->
    <div class="upi-overlay" id="upiOverlay" aria-hidden="true">
        <div class="upi-modal" role="dialog" aria-modal="true">

            <div class="upi-head">
                <div class="icon">
                    <i class="bi bi-qr-code"></i>
                </div>
                <h3>Scan &amp; Pay</h3>
            </div>

            <p class="upi-sub">Scan this QR with any UPI app to pay</p>

            <div class="upi-code-badge" id="upiOrderCode">ORDER</div>

            <div class="upi-amount" id="upiAmount">₹0.00</div>

            <div class="upi-qr" id="upiQrBox">
                <div class="qr-logo" id="qrLogo">
                    <?php if ($QRlogoUrl): ?>
                        <img src="<?= htmlspecialchars($QRlogoUrl) ?>" alt="Logo">
                    <?php else: ?>
                        <span class="fallback">M</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="upi-note">
                <i class="bi bi-info-circle"></i>
                Pay to the UPI ID in the QR. Once paid, tap
                <strong>Confirm Payment</strong> to send the order.
            </div>

            <div class="upi-actions">
                <button type="button" class="mm-btn mm-btn-ghost" id="upiCancelBtn">
                    <i class="bi bi-x-lg"></i>
                    Cancel
                </button>
                <button type="button" class="mm-btn mm-btn-primary" id="upiConfirmBtn">
                    <i class="bi bi-check-lg"></i>
                    <span id="upiConfirmText">Confirm Payment</span>
                </button>
            </div>

        </div>
    </div>


    <!-- SUCCESS POPUP -->
    <div class="mm-modal-overlay" id="successOverlay" aria-hidden="true">
        <div class="mm-modal">
            <div class="mm-modal-icon">
                <i class="bi bi-check-lg"></i>
            </div>
            <h3 class="mm-modal-title">Order Placed!</h3>
            <p class="mm-modal-text" id="successText">
                The order has been created successfully.
            </p>
            <div class="mm-modal-code" id="successCode" style="display:none;"></div>
            <div class="mm-modal-actions">
                <a href="<?= BASE_URL ?>take-order.php" class="mm-btn mm-btn-ghost">
                    <i class="bi bi-plus-lg"></i>
                    New Order
                </a>
                <a href="<?= BASE_URL ?>orders.php" class="mm-btn mm-btn-primary">
                    <i class="bi bi-list-ul"></i>
                    View Orders
                </a>
            </div>
        </div>
    </div>

    <!-- ERROR POPUP -->
    <div class="mm-modal-overlay" id="errorOverlay" aria-hidden="true">
        <div class="mm-modal">
            <div class="mm-modal-icon error">
                <i class="bi bi-exclamation-lg"></i>
            </div>
            <h3 class="mm-modal-title">Oops!</h3>
            <p class="mm-modal-text" id="errorText">
                Please check the form and try again.
            </p>
            <div class="mm-modal-actions">
                <button type="button" class="mm-btn mm-btn-primary" id="errorOkBtn">
                    <i class="bi bi-check2"></i>
                    Got it
                </button>
            </div>
        </div>
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

    <!-- QR generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <script src="<?= BASE_URL ?>js/take-order.js"></script>

</body>

</html>