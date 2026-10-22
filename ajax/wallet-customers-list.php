<?php
/* =========================================================
   MRS MILL@ — AJAX: WALLET CUSTOMERS LIST
   File: ./ajax/wallet-customers-list.php

   Schema (current mrs_millet):
     customers.id, customer_id, full_name, mobile_number,
     address_type, address_json, wallet_balance, status

   Address details live inside address_json.
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* Auth: delivery boy OR admin */
$loggedIn = false;
if (isset($_SESSION['delivery_boy_id']) && (int)$_SESSION['delivery_boy_id'] > 0) $loggedIn = true;
if (!$loggedIn && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) $loggedIn = true;
if (!$loggedIn) jsonResponse(false, 'Not logged in.');

try {

    $stmt = $pdo->query(
        "SELECT id, customer_id, full_name, mobile_number,
                address_type, address_json,
                COALESCE(wallet_balance, 0) AS wallet_balance,
                status
         FROM customers
         WHERE status = 1
         ORDER BY full_name ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {

        $addr = [];
        if (!empty($r['address_json'])) {
            $decoded = json_decode($r['address_json'], true);
            if (is_array($decoded)) $addr = $decoded;
        }

        $aptName = $addr['apartment_name'] ?? '';
        $divName = $addr['division_label'] ?? '';
        $fullAddr = $addr['full_address'] ?? '';

        /* For individual-house customers, show their full address instead of apartment */
        $displayAddressType = $r['address_type'] ?: 'apartment';
        if ($displayAddressType === 'individual_house' && $aptName === '') {
            $aptName = $fullAddr !== '' ? $fullAddr : 'Individual House';
            $divName = '';
        }

        $out[] = [
            'id'             => (int)$r['id'],
            'customer_id'    => $r['customer_id'],
            'full_name'      => $r['full_name'],
            'mobile_number'  => $r['mobile_number'],
            'address_type'   => $displayAddressType,
            'apartment_name' => $aptName,
            'division'       => $divName,
            'full_address'   => $fullAddr,
            'wallet_balance' => (float)$r['wallet_balance'],
            'status'         => (int)$r['status'],
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load customers: ' . $e->getMessage());
}