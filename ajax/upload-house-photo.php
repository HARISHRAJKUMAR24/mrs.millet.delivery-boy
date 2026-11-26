<?php
/* =========================================================
   MRS MILL@ — DELIVERY BOY: UPLOAD / REPLACE / REMOVE HOUSE PHOTO
   File: ./ajax/upload-house-photo.php

   Same as admin customer save:
   Folder : <admin>/uploads/houses/Y/m/d/customer_<id>/house.<ext>
   DB     : customers.address_json -> house_image
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* >>> ADMIN FOLDER ON THE SERVER <<< */
$ADMIN_FOLDER = '/home/mrsmille/domains/admin.mrsmillet.in/public_html';

/* Second place to look, if admin is inside the main website folder */
if (!is_dir($ADMIN_FOLDER . '/ajax')) {
    $ADMIN_FOLDER = '/home/mrsmille/domains/mrsmillet.in/public_html/admin';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');
if (empty($_SESSION['delivery_boy_id'])) jsonResponse(false, 'Not logged in.');

$orderId = (int)($_POST['order_id'] ?? 0);
$action  = $_POST['action'] ?? 'upload';   // upload | delete

if (!is_file($ADMIN_FOLDER . '/ajax/add-customer-save.php')) {
    jsonResponse(false, 'Admin folder not found or blocked. Set $ADMIN_FOLDER in upload-house-photo.php. open_basedir: '
        . (ini_get('open_basedir') ?: 'none'));
}

/* Customer of this delivery boy's order */
$stmt = $pdo->prepare(
    "SELECT c.id, c.address_json
     FROM orders o
     INNER JOIN customers c ON c.customer_id = o.customer_id
     WHERE o.id = ? AND o.delivery_boy_id = ? LIMIT 1"
);
$stmt->execute([$orderId, (int)$_SESSION['delivery_boy_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) jsonResponse(false, 'Customer not found. Refresh the page and try again.');

$address = json_decode($customer['address_json'] ?: '{}', true);
if (!is_array($address)) jsonResponse(false, 'Invalid address data for this customer.');

$oldImage = explode('?', (string)($address['house_image'] ?? ''))[0];
$newImage = '';

/* ---------- Save the new photo ---------- */
if ($action !== 'delete') {
    $file = $_FILES['house_image'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) jsonResponse(false, 'Image upload failed.');
    if ($file['size'] > 5 * 1024 * 1024) jsonResponse(false, 'Image must be under 5 MB.');

    $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime])) jsonResponse(false, 'Only JPG, PNG or WEBP allowed.');

    $newImage = 'uploads/houses/' . date('Y/m/d') . '/customer_' . (int)$customer['id'] . '/house.' . $types[$mime];
    $target   = $ADMIN_FOLDER . '/' . $newImage;

    if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        jsonResponse(false, 'Cannot create the folder. Check admin uploads permission.');
    }
    /* OVERRIDE: same file name = the old photo is overwritten */
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        jsonResponse(false, 'Cannot save the image. Check admin uploads permission.');
    }
    @chmod($target, 0644);
}

/* ---------- Update the database ---------- */
$address['house_image'] = $newImage;
$stmt = $pdo->prepare("UPDATE customers SET address_json = ? WHERE id = ?");
$stmt->execute([json_encode($address, JSON_UNESCAPED_UNICODE), (int)$customer['id']]);

/* ---------- Delete the old photo (other day / other type) ---------- */
if ($oldImage !== $newImage && strpos($oldImage, 'uploads/houses/') === 0 && strpos($oldImage, '..') === false) {
    @unlink($ADMIN_FOLDER . '/' . $oldImage);
}

if ($action === 'delete') jsonResponse(true, 'Photo removed.');

jsonResponse(true, 'Photo saved.', [
    'house_image'     => $newImage,
    'house_image_url' => rtrim(ADMIN_URL, '/') . '/' . $newImage . '?v=' . time(),
]);