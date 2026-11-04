<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* =========================================================
   AUTH
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId  = (int)$_SESSION['delivery_boy_id'];
$addr   = trim($_POST['address'] ?? '');
$mobile = trim($_POST['customer_mobile'] ?? '');
$name   = trim($_POST['customer_name'] ?? '');
$action = trim($_POST['action'] ?? 'upload');   // upload | delete

if ($addr === '') {
    jsonResponse(false, 'Missing address.');
}

/* =========================================================
   HELPERS
   ========================================================= */
function customerRoot(): ?string
{
    $root = realpath(__DIR__ . '/../../mrs.millet.customer');
    return ($root === false) ? null : $root;
}

function resolveCustomerFile(string $root, ?string $relative): ?string
{
    if (!$relative) return null;
    $abs = $root . '/' . ltrim($relative, '/');
    return is_file($abs) ? $abs : null;
}

/**
 * Delete a file then walk up and remove empty folders,
 * never going above <customer>/uploads/houses.
 */
function deleteFileAndCleanFolders(string $root, ?string $relative): void
{
    if (!$relative) return;

    $abs = $root . '/' . ltrim($relative, '/');
    if (is_file($abs)) @unlink($abs);

    $dir    = dirname($abs);
    $stopAt = rtrim($root . '/uploads/houses', '/');

    if (strpos($dir, $stopAt) !== 0) return;

    while (strlen($dir) >= strlen($stopAt) && is_dir($dir)) {
        $items = @scandir($dir);
        if ($items === false) break;

        $real = array_values(array_diff($items, ['.', '..']));
        if (count($real) > 0) break;

        @rmdir($dir);
        $dir = dirname($dir);

        if ($dir === $stopAt || $dir === dirname($stopAt)) break;
    }
}

/* =========================================================
   VERIFY ORDER
   ========================================================= */
try {
    $chk = $pdo->prepare(
        "SELECT o.id
         FROM orders o
         WHERE JSON_VALID(o.address_json)
           AND o.address_type = 'individual_house'
           AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) = ?
           AND o.delivery_boy_id = ?
           AND o.delivery_mode = 'delivery'
           AND o.status <> 'cancelled'
         LIMIT 1"
    );
    $chk->execute([$addr, $boyId]);
    if (!$chk->fetch()) {
        jsonResponse(false, 'Order not found for this address.');
    }
} catch (PDOException $e) {
    jsonResponse(false, 'DB error: ' . $e->getMessage());
}

/* =========================================================
   FIND OR CREATE REQUEST ROW
   ========================================================= */
try {
    if ($mobile !== '') {
        $rs = $pdo->prepare(
            "SELECT id, house_image
             FROM customer_address_requests
             WHERE request_type = 'individual_house'
               AND requested_apartment = ?
               AND customer_mobile = ?
             ORDER BY id DESC LIMIT 1"
        );
        $rs->execute([$addr, $mobile]);
    } else {
        $rs = $pdo->prepare(
            "SELECT id, house_image
             FROM customer_address_requests
             WHERE request_type = 'individual_house'
               AND requested_apartment = ?
             ORDER BY id DESC LIMIT 1"
        );
        $rs->execute([$addr]);
    }
    $row = $rs->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $ins = $pdo->prepare(
            "INSERT INTO customer_address_requests
                (customer_name, customer_mobile, requested_apartment,
                 request_type, status, created_at, updated_at)
             VALUES (?, ?, ?, 'individual_house', 0, NOW(), NOW())"
        );
        $ins->execute([$name, $mobile, $addr]);
        $row = ['id' => (int)$pdo->lastInsertId(), 'house_image' => null];
    }
} catch (PDOException $e) {
    jsonResponse(false, 'DB error: ' . $e->getMessage());
}

$requestId = (int)$row['id'];
$oldImage  = $row['house_image'];
$root      = customerRoot();
if ($root === null) {
    jsonResponse(false, 'Customer upload directory not found.');
}

/* =========================================================
   DELETE
   ========================================================= */
if ($action === 'delete') {
    deleteFileAndCleanFolders($root, $oldImage);

    try {
        $upd = $pdo->prepare(
            "UPDATE customer_address_requests
             SET house_image = NULL, updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$requestId]);
    } catch (PDOException $e) {
        jsonResponse(false, 'DB error: ' . $e->getMessage());
    }

    jsonResponse(true, 'Photo removed.', ['request_id' => $requestId]);
}

/* =========================================================
   UPLOAD
   ========================================================= */
if (empty($_FILES['house_image']) || $_FILES['house_image']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'No image uploaded.');
}

$file = $_FILES['house_image'];
if ($file['size'] > 5 * 1024 * 1024) {
    jsonResponse(false, 'Image too large (max 5 MB).');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    jsonResponse(false, 'Only JPG, PNG or WEBP allowed.');
}
$ext = $allowed[$mime];

/* Reuse folder of the old image so we truly OVERRIDE */
$subdir = null;
if (!empty($oldImage)) {
    $oldAbs  = $root . '/' . ltrim($oldImage, '/');
    $realDir = realpath(dirname($oldAbs));
    if ($realDir !== false && strpos($realDir, $root) === 0) {
        $subdir = substr($realDir, strlen($root) + 1) . '/';
    }
}
if ($subdir === null) {
    $subdir = 'uploads/houses/' . date('Ymd') . '/' . bin2hex(random_bytes(4)) . '/';
}

$absDir   = $root . '/' . $subdir;
$fileName = 'house.' . $ext;
$absFile  = $absDir . $fileName;

if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
    jsonResponse(false, 'Could not create upload folder.');
}

if (!move_uploaded_file($file['tmp_name'], $absFile)) {
    jsonResponse(false, 'Failed to save file.');
}

/* Delete old file + clean folders */
if (!empty($oldImage)) {
    $oldAbs = resolveCustomerFile($root, $oldImage);
    if ($oldAbs && $oldAbs !== $absFile) {
        deleteFileAndCleanFolders($root, $oldImage);
    }
    /* Remove any stale house.* with different extensions in same folder */
    if (is_dir($absDir)) {
        foreach (glob($absDir . 'house.*') as $f) {
            if ($f !== $absFile && is_file($f)) @unlink($f);
        }
    }
}

$relative = $subdir . $fileName;

try {
    $upd = $pdo->prepare(
        "UPDATE customer_address_requests
         SET house_image = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([$relative, $requestId]);
} catch (PDOException $e) {
    @unlink($absFile);
    jsonResponse(false, 'DB error: ' . $e->getMessage());
}

jsonResponse(true, 'Photo saved.', [
    'request_id'      => $requestId,
    'house_image'     => $relative,
    'house_image_url' => rtrim(MAIN_URL, '/') . '/' . $relative,
]);
