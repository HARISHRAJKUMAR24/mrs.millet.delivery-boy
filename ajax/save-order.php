<?php
/* =========================================================
   MRS MILL@ — SAVE ORDER (delivery boy)
   File: ./ajax/save-order.php
   Saves order as PENDING (payment_status = unpaid)
   Container details are stored INSIDE products_json only.
   Container is NOT added to the order subtotal / total_amount.
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId = (int)$_SESSION['delivery_boy_id'];

$raw     = $_POST['payload'] ?? '';
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    jsonResponse(false, 'Invalid payload.');
}

$customerName   = trim($payload['customer_name']   ?? '');
$customerMobile = trim($payload['customer_mobile'] ?? '');
$apartmentId    = (int) ($payload['apartment_id']  ?? 0);
$division       = trim($payload['division']        ?? '');
$divisionCharge = (float) ($payload['division_charge'] ?? 0);
$products       = $payload['products'] ?? [];

/* ---------------- VALIDATION ---------------- */
if ($customerName === '' || mb_strlen($customerName) < 2) {
    jsonResponse(false, 'Customer name is required.');
}
if (!preg_match('/^[0-9]{10,15}$/', $customerMobile)) {
    jsonResponse(false, 'Invalid mobile number.');
}
if ($apartmentId <= 0) {
    jsonResponse(false, 'Apartment is required.');
}
if ($division === '') {
    jsonResponse(false, 'Division is required.');
}
if (!is_array($products) || count($products) === 0) {
    jsonResponse(false, 'Please add at least one product.');
}

/* ---------------- VERIFY APARTMENT + DIVISION ---------------- */
try {
    $stmt = $pdo->prepare(
        "SELECT id, apartment_code, apartment_name, divisions
         FROM apartments WHERE id = ? AND status = 1 LIMIT 1"
    );
    $stmt->execute([$apartmentId]);
    $apt = $stmt->fetch();

    if (!$apt) {
        jsonResponse(false, 'Apartment not found.');
    }

    $divs = json_decode($apt['divisions'] ?? '[]', true);
    $matchedCharge = null;
    if (is_array($divs)) {
        foreach ($divs as $d) {
            if (strcasecmp(trim($d['division'] ?? ''), $division) === 0) {
                $matchedCharge = (float)($d['charge'] ?? 0);
                break;
            }
        }
    }
    if ($matchedCharge === null) {
        jsonResponse(false, 'Division is not valid for this apartment.');
    }
    $divisionCharge = $matchedCharge;
} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}

/* ---------------- CLEAN PRODUCTS ---------------- */
$cleanProducts  = [];
$subtotal       = 0;
$containerTotal = 0;

foreach ($products as $p) {

    $pid        = (int)($p['product_id'] ?? 0);
    $qty        = max(1, (int)($p['qty'] ?? 1));
    $price      = (float)($p['price'] ?? 0);
    $code       = trim((string)($p['code'] ?? ''));
    $name       = trim((string)($p['name'] ?? ''));
    $image      = trim((string)($p['image'] ?? ''));
    $variantId  = isset($p['variant_id']) && $p['variant_id'] !== null ? (int)$p['variant_id'] : null;
    $variantNm  = trim((string)($p['variant_name'] ?? ''));
    $variantQty = trim((string)($p['variant_qty'] ?? ''));

    /* ---- Container info (kept inside JSON only) ---- */
    $containerEnabled = (int)($p['container_enabled'] ?? 0) === 1 ? 1 : 0;
    $containerPrice   = (float)($p['container_price'] ?? 0);

    if ($pid <= 0 || $qty <= 0) continue;

    if ($name === '' || $price <= 0) {
        $q = $pdo->prepare(
            "SELECT product_name, product_code, product_image
             FROM products WHERE id = ? AND status = 1 LIMIT 1"
        );
        $q->execute([$pid]);
        $prod = $q->fetch();
        if (!$prod) continue;

        if ($name === '')  $name  = $prod['product_name'];
        if ($code === '')  $code  = $prod['product_code'];
        if ($image === '' && !empty($prod['product_image'])) {
            $image = ADMIN_URL . $prod['product_image'];
        }

        if ($price <= 0 && $variantId) {
            $v = $pdo->prepare("SELECT price FROM product_variants WHERE id = ? LIMIT 1");
            $v->execute([$variantId]);
            $vr = $v->fetch();
            if ($vr) $price = (float)$vr['price'];
        }
    }

    $lineTotal = $price * $qty;
    $subtotal += $lineTotal;

    /* Container: per-line container charge (NOT added to subtotal) */
    $containerLineTotal = 0;
    if ($containerEnabled && $containerPrice > 0) {
        $containerLineTotal = $containerPrice * $qty;
        $containerTotal    += $containerLineTotal;
    }

    $cleanProducts[] = [
        'product_id'           => $pid,
        'code'                 => $code,
        'name'                 => $name,
        'image'                => $image,
        'variant_id'           => $variantId,
        'variant_name'         => $variantNm,
        'variant_qty'          => $variantQty,
        'price'                => $price,
        'qty'                  => $qty,
        'line_total'           => $lineTotal,

        /* Container info — stored INSIDE products_json, not a separate table */
        'container_enabled'    => $containerEnabled,
        'container_price'      => $containerEnabled ? $containerPrice : 0,
        'container_line_total' => $containerLineTotal
    ];
}

if (empty($cleanProducts)) {
    jsonResponse(false, 'No valid products to save.');
}

/* ---------------- GENERATE ORDER CODE ---------------- */
try {
    $cStmt = $pdo->query(
        "SELECT order_code
         FROM orders
         WHERE order_code REGEXP '^ORD[0-9]+$'
         ORDER BY CAST(SUBSTRING(order_code, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );

    $last = $cStmt->fetch();

    if ($last && preg_match('/^ORD(\d+)$/i', $last['order_code'], $m)) {
        $nextNum = (int)$m[1] + 1;
    } else {
        $nextNum = 1;
    }

    $orderCode = 'ORD' . $nextNum;
} catch (PDOException $e) {
    $orderCode = 'ORD1';
}

/* Order total = subtotal + delivery charge (container NOT included) */
$total = $subtotal + $divisionCharge;

/* ---------------- SETTINGS → UPI ---------------- */
$settings = getSettings($pdo);
$upiId    = trim($settings['upi_id'] ?? '');
$siteName = trim($settings['username'] ?? 'Mrs Mill@');

if ($upiId === '') {
    jsonResponse(false, 'UPI ID is not configured. Please contact admin.');
}

/* ---------------- BUILD UPI STRING ---------------- */
$upiParams = [
    'pa' => $upiId,
    'pn' => $siteName,
    'am' => number_format($total, 2, '.', ''),
    'cu' => 'INR',
    'tn' => 'Order ' . $orderCode
];

$upiString = 'upi://pay?' . http_build_query($upiParams);
$upiLink   = $upiString;

/* ---------------- INSERT ---------------- */
try {

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        "INSERT INTO orders
            (order_code, delivery_boy_id,
             customer_name, customer_mobile,
             apartment_id, apartment_code, apartment_name,
             division, division_charge,
             subtotal, total_amount,
             products_json,
             status, payment_status, payment_upi_string,
             created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', ?, NOW())"
    );

    $insert->execute([
        $orderCode,
        $boyId,
        $customerName,
        $customerMobile,
        $apartmentId,
        $apt['apartment_code'],
        $apt['apartment_name'],
        $division,
        $divisionCharge,
        $subtotal,
        $total,
        json_encode($cleanProducts, JSON_UNESCAPED_UNICODE),
        $upiString
    ]);

    $orderId = (int)$pdo->lastInsertId();

    $pdo->commit();

    jsonResponse(true, 'Order created. Waiting for payment.', [
        'order_id'        => $orderId,
        'order_code'      => $orderCode,
        'total'           => $total,
        'subtotal'        => $subtotal,
        'container_total' => $containerTotal,   // informational only
        'upi_string'      => $upiString,
        'upi_link'        => $upiLink,
        'payee_name'      => $siteName
    ]);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, 'Failed to place order: ' . $e->getMessage());
}