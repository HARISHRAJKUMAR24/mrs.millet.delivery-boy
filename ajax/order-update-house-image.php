<?php
/* =========================================================
   MRS MILL@ — UPDATE HOUSE IMAGE (delivery boy)
   File: ./ajax/order-update-house-image.php   v2026-11-04b
   Saves into CUSTOMER site folder so admin + customer can see it
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$imgB64  = trim($_POST['house_image_data'] ?? '');
if ($orderId <= 0) jsonResponse(false, 'Invalid order.');

try {
    $st = $pdo->prepare(
        "SELECT o.customer_id, c.id AS cust_row_id, c.address_json
         FROM orders o
         INNER JOIN customers c ON c.customer_id = o.customer_id
         WHERE o.id = ? LIMIT 1"
    );
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(false, 'Order not found.');
} catch (PDOException $e) {
    jsonResponse(false, 'Server error.');
}

if ($imgB64 === '') jsonResponse(false, 'No image provided.');

if (!preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,/i', $imgB64, $m)) {
    jsonResponse(false, 'Invalid image format.');
}

$ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
$data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imgB64));

if ($data === false || strlen($data) > 3 * 1024 * 1024) {
    jsonResponse(false, 'Image too large (max 3 MB).');
}

/* =========================================================
   Resolve CUSTOMER site root folder (not delivery-boy folder)
   ========================================================= */
$customerRoot = '';

$urlPath = parse_url(MAIN_URL, PHP_URL_PATH);     // e.g. /mrs.millet.customer/
$urlPath = rtrim($urlPath ?: '', '/');            // /mrs.millet.customer
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

/* Candidate 1: DOCUMENT_ROOT + MAIN_URL path */
if ($docRoot !== '' && $urlPath !== '') {
    $cand = $docRoot . $urlPath;
    if (is_dir($cand)) $customerRoot = $cand;
}

/* Candidate 2: sibling folder inside DOCUMENT_ROOT */
if ($customerRoot === '' && $docRoot !== '') {
    foreach (['mrs.millet.customer', 'mrs.millet.customer/', 'mrs.millet.customer\\'] as $alt) {
        $cand = $docRoot . '/' . trim($alt, '/\\');
        if (is_dir($cand)) { $customerRoot = $cand; break; }
    }
}

/* Candidate 3: hard fallback (local dev) */
if ($customerRoot === '') {
    $fallbacks = [
        'C:/xampp/htdocs/mrs.millet.customer',
        'D:/xampp/htdocs/mrs.millet.customer',
        '/var/www/html/mrs.millet.customer',
    ];
    foreach ($fallbacks as $cand) {
        if (is_dir($cand)) { $customerRoot = $cand; break; }
    }
}

if ($customerRoot === '' || !is_dir($customerRoot)) {
    jsonResponse(false, 'Customer uploads folder not found. Configure MAIN_URL or create mrs.millet.customer folder.');
}

/* =========================================================
   Save
   ========================================================= */
$folder = 'uploads/houses/' . date('Ymd') . '/' . bin2hex(random_bytes(5));
$absDir = rtrim($customerRoot, '/\\') . '/' . $folder;
if (!is_dir($absDir)) @mkdir($absDir, 0775, true);

$fileName = 'house.' . $ext;
$absFile  = $absDir . '/' . $fileName;

if (@file_put_contents($absFile, $data) === false) {
    jsonResponse(false, 'Failed to save image. Check folder permissions: ' . $absDir);
}

$relPath = $folder . '/' . $fileName;

/* =========================================================
   Update DB
   ========================================================= */
$addr = json_decode($row['address_json'] ?? '[]', true);
if (!is_array($addr)) $addr = [];
$addr['house_image'] = $relPath;

try {
    $pdo->prepare("UPDATE customers SET address_json = ?, updated_at = NOW() WHERE id = ?")
        ->execute([json_encode($addr, JSON_UNESCAPED_UNICODE), (int)$row['cust_row_id']]);
} catch (PDOException $e) {
    jsonResponse(false, 'DB update failed.');
}

jsonResponse(true, 'House image updated.', [
    'path'     => $relPath,
    'url'      => rtrim(MAIN_URL, '/') . '/' . $relPath,
    'saved_to' => $absFile
]);